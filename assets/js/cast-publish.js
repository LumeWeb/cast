/**
 * Cast Publish dashboard orchestrator (no page refresh).
 *
 * A dependency-free browser IIFE that keeps the publish dashboard card live:
 *
 *   - STATUS — fetch the cast/v1/publish/status report (GET) immediately and
 *     every poll tick, sending the wp_rest nonce as X-WP-Nonce so the REST
 *     surface's manage_options + nonce gate recognizes the caller.
 *   - POLL — a self-healing poll that keeps ticking while a run is queued or
 *     active (no short attempt budget), backs off to a longer delay while the
 *     report is unchanged, on an idle surface, or after a transient fetch
 *     failure, and stops at a terminal run. Exactly one poll is ever pending;
 *     a manual Refresh control retriggers it on demand. Every successful
 *     report livens an accessible @role=status state chip and a "last checked"
 *     timestamp. A failed fetch surfaces "status check unavailable" clearly
 *     and keeps retrying on the longer cadence instead of silently quitting.
 *   - ACTIONS — perform() POSTs start / now / mode / cancel / artifact to the
 *     matching cast/v1 route with the same nonce header, a JSON body and
 *     credentials: 'same-origin', then re-fetches status so the card resyncs.
 *     A click immediately represents the queued/working state (result line +
 *     aria-live announcement) and the fired action is held busy so a duplicate
 *     click can never fire a second request; Cancel stays available whenever a
 *     live run may cancel it.
 *   - ADDRESS — the "Your site address" wizard: the choose/review prompts
 *     open the inline wizard (three native radio choices with
 *     branch-specific fields), and confirmAddress() saves the destination
 *     (POST /publish/destination), confirms it
 *     (POST /publish/destination/confirm) and, for a first publish, starts
 *     the run — the plain review never re-types the domain.
 *   - DOMAIN — the "Connect your domain" card (custom destinations only):
 *     domainDns()/domainSsl() re-read the delegation/catalog routes and
 *     domainValidate() re-checks the selected domain's records. Every domain
 *     call is gated by the localized domain-endpoint allowlist + nonce and
 *     lands through textContent.
 *
 * Security/accessibility contract (mirrored by tests/js/cast-publish.test.js):
 *
 *   - The only actions this script will ever fire are the names the server
 *     localized into `castPublish.actions` (the publish REST route allowlist);
 *     a forged data-cast-publish-action button or a direct perform() call for
 *     anything else is inert and never reaches a route.
 *   - Without the localized nonce (or endpoints) nothing is requested at all.
 *   - Every status/action result lands in the DOM through textContent — never
 *     innerHTML — and status changes are announced through an aria-live
 *     "polite" region (created on the fly if the template did not render one),
 *     so screen readers hear the refresh/action outcome.
 *
 * The display mapping (run state/label, stage, progress, readiness, mode) is
 * the same deliberate table PublishDashboardView pins server-side, so the
 * no-refresh card can never drift from the server-rendered one.
 */
( function ( window, document ) {
	'use strict';

	var config = ( window && window.castPublish ) || {};
	var endpoints = config.endpoints || null;
	var nonce = config.nonce || null;
	var actions = config.actions || [];
	// Only the interval + backoff drive the poll cadence. The legacy
	// `maxAttempts` field is repurposed as the auto-poll ENABLE flag: a
	// non-positive value turns automatic polling off (embedded/domain-only
	// surfaces opt out), while any positive value arms CONTINUOUS polling —
	// the old attempt-count cap is intentionally ignored so a queued/active
	// run is followed until it reaches a terminal state (or the page goes
	// away), never stopped on a short fixed budget. The single re-armed timer
	// is the whole runaway/leak defence.
	var poll = config.poll || {};
	var intervalMs = poll.intervalMs || 0;
	var maxAttempts = poll.maxAttempts || 0;
	var backoffMs = poll.backoffMs || 0;
	// The queued ETA's tick delivery cadence, localized from the same
	// TickConfig::DEFAULT_TICK_INTERVAL_SECONDS the server derives the
	// "Starting within N seconds" line from, so a no-refresh countdown can
	// never drift from the server copy. When absent (an unlocalized embedded
	// surface) the countdown degrades to the honest waiting copy, never a
	// guessed number.
	var startWithinSeconds = ( typeof config.startWithinSeconds === 'number' && config.startWithinSeconds >= 1 )
		? config.startWithinSeconds
		: null;
	var post = ( config && config.fetch ) || ( window && window.fetch && window.fetch.bind( window ) ) || null;

	// A clock seam for the queued-wait timing (mirrors the server-side Clock):
	// the localized config may inject a deterministic now() in tests, and in
	// production it falls back to Date.now. Only the wall clock drives the
	// elapsed/waiting + escape timing — never a fake scheduler/timer.
	var nowMs = function () {
		if ( config && typeof config.nowMs === 'function' ) {
			return config.nowMs();
		}
		return Date.now ? Date.now() : ( new Date() ).getTime();
	};

	// How long a queued (not-started) run must have been waiting before the
	// recovery/start-now escape appears. Matches the worker's slow rearm
	// cadence: a normally-self-healing queue resumes well before this, so the
	// escape only surfaces for a genuinely stalled publish — never during a
	// normal brief wait or the 10-minute quiet-period debounce.
	var START_NOW_WAIT_SECONDS = 60;

	// Poll bookkeeping: the last report (for change detection), the single
	// pending poll handle, and whether the poll loop has been stopped (only a
	// terminal run — or an explicit page unmount — stops it; there is no
	// short attempt budget while a run is queued/active). `busy` is the action
	// currently being POSTed (duplicate-click guard) and `pollFailed` tracks a
	// transient status-fetch failure so it is announced once, not every tick.
	var lastStatus = null;
	var timer = null;
	var stopped = false;
	var busy = null;
	var pollFailed = false;
	var failureAnnounced = false;
	// The single queued-ETA countdown timer, refreshed once a second while a
	// run is queued. It never fetches — the status poll owns the data; this is
	// only the per-second cosmetic re-render of the ETA/elapsed progress line.
	var etaTimer = null;

	/* ------------------------- display mapping ------------------------- */

	/**
	 * The run-state derivation PublishDashboardView::runStateFor pins.
	 *
	 * @param {object} status
	 * @return {string}
	 */
	function runStateOf( status ) {
		status = status || {};

		switch ( status.run_status ) {
			case 'not_started':
				return status.run_active ? 'queued' : 'idle';
			case 'running':
				return 'running';
			case 'paused':
				return 'paused';
			case 'completed':
				return 'completed';
			case 'completed_with_warnings':
				return 'completed_with_warnings';
			case 'failed':
				return 'failed';
			case 'cancelled':
				return 'cancelled';
			default:
				return 'idle';
		}
	}

	/**
	 * The stage label table PublishDashboardView::stageLabelFor pins.
	 *
	 * @param {object} status
	 * @return {?string}
	 */
	function stageLabelOf( status ) {
		status = status || {};

		switch ( status.run_stage ) {
			case 'exporting':
				return 'Exporting content';
			case 'uploading':
				return 'Uploading to Pinner';
			case 'publishing':
				return 'Publishing';
			default:
				return null;
		}
	}

	/**
	 * The run label table PublishDashboardView::runLabelFor pins.
	 *
	 * @param {object} status
	 * @return {string}
	 */
	function runLabelOf( status ) {
		var state = runStateOf( status );
		var stage = stageLabelOf( status );

		switch ( state ) {
			case 'idle':
				return 'No publish yet';
			case 'queued':
				return 'Queued to publish';
			case 'running':
				return stage || 'Publishing…';
			case 'paused':
				return 'Paused';
			case 'completed':
				return 'Published';
			case 'completed_with_warnings':
				return 'Published with warnings';
			case 'failed':
				return 'Publish failed';
			case 'cancelled':
				return 'Cancelled';
			default:
				return 'No publish yet';
		}
	}

	/**
	 * The explicit queue/run status line PublishDashboardView::stateLabelFor
	 * pins: the single word an editor can read at a glance and the text the
	 * accessible status chip announces when the poll livens it.
	 *
	 * @param {object} status
	 * @return {string}
	 */
	function stateLabelOf( status ) {
		status = status || {};

		// A parked first publish awaiting a website swaps its chip for the
		// design-doc S1 stage label, mirroring PublishDashboardView
		// ::stateLabelFor: the run is deliberately "sent", not merely paused.
		if ( !! status.awaiting_website && runStateOf( status ) === 'paused' ) {
			return 'Sent — waiting for a website';
		}

		switch ( runStateOf( status ) ) {
			case 'idle':
				return 'Ready';
			case 'queued':
				return 'Queued — starting shortly';
			case 'running':
				return 'Publishing';
			case 'paused':
				return 'Paused';
			case 'completed':
				return 'Published';
			case 'completed_with_warnings':
				return 'Published with warnings';
			case 'failed':
				return 'Failed — retry available';
			case 'cancelled':
				return 'Cancelled — retry available';
			default:
				return 'Ready';
		}
	}

	/**
	 * The stage→percent table PublishDashboardView::progressPercentFor pins.
	 *
	 * @param {object} status
	 * @return {number}
	 */
	function progressPercentOf( status ) {
		status = status || {};

		switch ( status.run_stage ) {
			case 'exporting':
				return 25;
			case 'uploading':
				return 55;
			case 'publishing':
				return 85;
			case 'finished':
				return 100;
			default:
				return 0;
		}
	}

	function hasEnvProblems( status ) {
		return !!( status.env_problems && status.env_problems.length > 0 );
	}

	/**
	 * The readiness decision PublishDashboardView::readinessFor pins, as a
	 * { readiness, level } pair (error > warning > note > ok).
	 *
	 * @param {object} status
	 * @return {{readiness: string, level: string}}
	 */
	function readinessOf( status ) {
		status = status || {};

		if ( status.bootstrap_identity_complete === false || hasEnvProblems( status ) ) {
			return { readiness: 'config', level: 'error' };
		}

		if ( status.onboarding_complete === false ) {
			return { readiness: 'setup', level: 'warning' };
		}

		if ( status.has_eligible_content === false ) {
			return { readiness: 'no_content', level: 'note' };
		}

		return { readiness: 'ready', level: 'ok' };
	}

	/**
	 * The readiness copy the template maps in templates/admin/publish.php.
	 *
	 * @param {object} status
	 * @return {string}
	 */
	function readinessLabelOf( status ) {
		status = status || {};

		if ( status.bootstrap_identity_complete === false || hasEnvProblems( status ) ) {
			return 'Configuration required';
		}

		if ( status.onboarding_complete === false ) {
			// The setup slot is the one onboarding instruction and it is
			// skipped-aware: a deliberately SKIPPED onboarding never instructs
			// finishing it — the truthful next step is publishing.
			return status.onboarding_skipped === true
				? 'Onboarding skipped — publish when ready'
				: 'Finish onboarding to publish';
		}

		if ( status.has_eligible_content === false ) {
			return 'No publishable content yet';
		}

		return 'Ready to publish';
	}

	/**
	 * The mode copy the template maps in templates/admin/publish.php.
	 *
	 * @param {object} status
	 * @return {string}
	 */
	function modeLabelOf( status ) {
		status = status || {};

		switch ( status.mode ) {
			case 'manual':
				return 'Manual publishing';
			case 'on_update':
				return 'Publishes automatically when you update content';
			default:
				return 'Manual publishing';
		}
	}

	/**
	 * The short end-user mode name (Manual / On update).
	 *
	 * @param {?string} mode
	 * @return {string}
	 */
	function modeNameOf( mode ) {
		switch ( mode ) {
			case 'on_update':
				return 'On update';
			case 'manual':
			default:
				return 'Manual';
		}
	}

	/**
	 * The per-option end-user help copy, mirroring the $modeHelp table in
	 * templates/admin/publish.php and pinned to the exact scheduler semantics
	 * (Manual drift-only, On-update auto-schedules on content updates). Only
	 * these two modes exist — the server normalizes a legacy 'scheduled'
	 * value to On-update, so the client never receives it in a status report.
	 *
	 * @param {?string} mode
	 * @return {string}
	 */
	function modeHelpOf( mode ) {
		switch ( mode ) {
			case 'on_update':
				return 'Publish automatically. Eligible content changes queue a background publish for you — rapid edits are combined into one debounced publish instead of one per save.';
			case 'manual':
			default:
				return 'Publish only when you choose. Clicking Publish to Pinner queues a background publish — content edits wait until then and nothing publishes on its own.';
		}
	}

	/**
	 * The "why publish" copy PublishDashboardView::contextMessageFor pins.
	 *
	 * @param {object} status
	 * @return {string}
	 */
	function contextOf( status ) {
		status = status || {};

		// The parked "your upload was sent" copy, mirroring
		// PublishDashboardView::contextMessageFor: the run waits for a website,
		// so the plain "Publishing is paused." line would under-sell it.
		if ( !! status.awaiting_website ) {
			return 'Your upload was sent. The run is waiting for a website — open Pinner and create one or attach one to this workspace, then come back.';
		}

		if ( status.run_active ) {
			switch ( runStateOf( status ) ) {
				case 'queued':
					return 'Your publish is queued and will start automatically. Keep working — it runs in the background and this page tracks the progress.';
				case 'paused':
					return 'Publishing is paused.';
				default:
					return 'Publishing is in progress — progress updates live below.';
			}
		}

		switch ( readinessOf( status ).readiness ) {
			case 'config':
				return 'Publishing is unavailable until the configuration below is resolved.';
			case 'setup':
				// Skipped-aware, mirroring PublishDashboardView
				// ::contextMessageFor: an incomplete onboarding says "finish
				// onboarding"; a deliberately skipped one gives the truthful
				// next step (publishing), never a finish instruction.
				return status.onboarding_skipped === true
					? 'You skipped onboarding, so there is nothing to finish — publish your site whenever you are ready.'
					: 'Finish onboarding to publish your site.';
			case 'no_content':
				return 'Add publishable content, then publish your site to Pinner.';
			default:
				break;
		}

		// Terminal retry states: a finished publish that did not land. The copy
		// names what happened so the retry is honest, never a first-time frame.
		var terminalState = runStateOf( status );

		if ( terminalState === 'failed' || terminalState === 'cancelled' ) {
			if ( ! status.identity ) {
				return terminalState === 'failed'
					? 'Your last publish failed. Publish to Pinner to retry.'
					: 'Your last publish was cancelled. Publish to Pinner to start again.';
			}

			return terminalState === 'failed'
				? 'Your last publish failed. Publish again to retry.'
				: 'Your last publish was cancelled. Publish again to start over.';
		}

		if ( ! status.identity ) {
			return status.dirty
				? 'Your workspace has content ready — publish it to Pinner for the first time.'
				: 'Your site is ready — publish it to Pinner to make it live.';
		}

		return status.dirty
			? 'You have unpublished changes ready to go live.'
			: 'Your published site is up to date.';
	}

	/**
	 * Seconds the queued run has been waiting, from the server's queue-entry
	 * timestamp (status.queued_at, unix seconds) to the current wall clock.
	 * Null when the run is not waiting (or the field is absent), so callers
	 * can fall back to the static caption. Never negative.
	 *
	 * @param {object} status
	 * @param {number} nowMsValue Wall clock in milliseconds (nowMs()).
	 * @return {?number}
	 */
	function queuedElapsedSeconds( status, nowMsValue ) {
		status = status || {};

		if ( typeof status.queued_at !== 'number' ) {
			return null;
		}

		return Math.max( 0, Math.floor( nowMsValue / 1000 ) - status.queued_at );
	}

	/**
	 * Human "mm min ss sec" elapsed phrasing for the queued run's wait.
	 *
	 * @param {number} seconds
	 * @return {string}
	 */
	function formatElapsed( seconds ) {
		if ( seconds < 60 ) {
			return seconds + ' sec';
		}

		var minutes = Math.floor( seconds / 60 );
		var rest = seconds % 60;

		return minutes + ' min ' + rest + ' sec';
	}

	/**
	 * Seconds left until a queued run's expected start: queuedAt (server unix
	 * seconds) plus the tick delivery cadence, minus the current wall clock
	 * (ms). Null when the run is not waiting, the timestamp is missing, or no
	 * cadence is configured — callers fall back to the honest waiting copy.
	 * A past window reads as a non-positive number, never a negative promise.
	 *
	 * @param {object} status
	 * @param {number} nowMsValue Wall clock in milliseconds (nowMs()).
	 * @param {?number} withinSeconds The localized tick delivery cadence.
	 * @return {?number}
	 */
	function queuedEtaSecondsLeft( status, nowMsValue, withinSeconds ) {
		status = status || {};

		if ( typeof status.queued_at !== 'number' || typeof withinSeconds !== 'number' || withinSeconds < 1 ) {
			return null;
		}

		return status.queued_at + withinSeconds - Math.floor( nowMsValue / 1000 );
	}

	/**
	 * Human remaining-seconds phrasing for the queued ETA countdown: "30
	 * seconds" under a minute (singular handled), "1 min 5 sec" above it.
	 *
	 * @param {number} seconds
	 * @return {string}
	 */
	function formatRemaining( seconds ) {
		if ( seconds < 60 ) {
			return seconds === 1 ? '1 second' : seconds + ' seconds';
		}

		var minutes = Math.floor( seconds / 60 );
		var rest = seconds % 60;

		return minutes + ' min ' + rest + ' sec';
	}

	/**
	 * The secondary progress line PublishDashboardView::progressCountLabelFor
	 * pins — the same per-fine-stage table with the honest denominator
	 * (status.progress_total = DiscoverResult::enqueued) instead of the
	 * cumulative progress counter, which counts a URL once per pipeline pass.
	 *
	 * A queued (not-started) run has processed nothing, so the honest line
	 * NEVER shows a misleading item count: while the expected start (queuedAt
	 * + tick cadence) is still ahead it leads with a live countdown ("Starting
	 * in 23 seconds — waiting to begin"), and once that window passes it hands
	 * the line back to the honest elapsed "waiting" copy. When no cadence is
	 * localized the countdown is skipped entirely, matching the server base
	 * copy. Idle and terminal runs keep the legacy processed-count line.
	 *
	 * Every other run maps its fine stage (status.pipeline_stage) to the same
	 * copy the server renders: preparing/discovering before the total exists,
	 * the moving x-of-M summary while a stage runs (capture_done/rewrite_done
	 * are now LIVE reads of the item table in flight, so "Captured N of M URLs"
	 * advances every poll — a 0 is honest), the export-build line during
	 * pack/wrapup, the publish-only re-run line, and the coarse
	 * upload/publishing tail. Only when neither a summary nor a live number
	 * exists (capture_done/rewrite_done null) does the stage verb stand in.
	 * Never a fabricated per-tick count.
	 *
	 * @param {object} status
	 * @return {string}
	 */
	function progressCountLabelOf( status ) {
		status = status || {};

		// A parked run awaiting a website has no progress to show: the copy
		// names the wait (the bar/value are hidden by setProgress), never a
		// fabricated percentage or item count. Mirrors
		// PublishDashboardView::progressCountLabelFor.
		if ( !! status.awaiting_website ) {
			return 'Waiting for a website — the run is paused.';
		}

		if ( runStateOf( status ) === 'queued' ) {
			var remaining = queuedEtaSecondsLeft( status, nowMs(), startWithinSeconds );

			if ( remaining !== null && remaining > 0 ) {
				return 'Starting in ' + formatRemaining( remaining ) + ' — waiting to begin';
			}

			var elapsed = queuedElapsedSeconds( status, nowMs() );

			return elapsed === null
				? 'Waiting to begin'
				: 'Waiting to begin — in the queue for ' + formatElapsed( elapsed );
		}

		var state = runStateOf( status );

		// Idle (no run) and terminal states keep the legacy item-count line,
		// mirroring the server's unchanged finished/idle copy.
		if ( state === 'idle' || isTerminal( state ) ) {
			return ( typeof status.progress_count === 'number' ? status.progress_count : 0 ) + ' items processed';
		}

		var stage = status.pipeline_stage || null;
		var total = ( typeof status.progress_total === 'number' && status.progress_total !== null )
			? status.progress_total
			: null;

		// A publish-only re-run (resume 'publish|') never re-exported, so it
		// owns no discover total — the copy says it is republishing, never
		// pretending discovery is happening.
		if ( stage === 'publish' && total === null ) {
			return 'Republishing existing build…';
		}

		// Before discovery drains there is no denominator. Probe/setup — and a
		// freshly started run whose first tick has not pinned a cursor — is
		// "preparing"; once discovery is feeding the queue it is "discovering".
		if ( stage === 'probe' || stage === 'setup' || stage === null ) {
			return 'Preparing to export…';
		}

		if ( stage === 'discover' || total === null ) {
			return 'Discovering content…';
		}

		if ( stage === 'capture' ) {
			// Any numeric capture_done — a live per-tick read while capture
			// runs, or the terminal summary once it drains, including an
			// honest 0 — renders the moving x-of-M line; only its absence
			// (status.capture_done null) shows the stage verb.
			if ( typeof status.capture_done === 'number' ) {
				return 'Captured ' + status.capture_done + ' of ' + total + ' URLs';
			}

			return 'Capturing content… (' + total + ' URLs)';
		}

		if ( stage === 'rewrite' ) {
			// Same deal: rewrite_done is the live Rewritten count in flight
			// and the terminal summary (rewritten + passed-through) once it
			// drains; the verb only when neither exists.
			if ( typeof status.rewrite_done === 'number' ) {
				return 'Rewrote ' + status.rewrite_done + ' of ' + total;
			}

			return 'Rewriting content… (' + total + ' URLs)';
		}

		if ( stage === 'pack' || stage === 'wrapup' ) {
			return 'Building the export (' + total + ' URLs)';
		}

		// The coarse upload/publish tail once the fine table has no more
		// specific entry (a full run at the publish boundary, or an advanced
		// but unknown fine stage).
		if ( status.run_stage === 'uploading' ) {
			return 'Uploading… (' + total + ' URLs)';
		}

		if ( status.run_stage === 'publishing' ) {
			return 'Publishing…';
		}

		return ( typeof status.progress_count === 'number' ? status.progress_count : 0 ) + ' items processed';
	}

	/**
	 * Whether a run state is terminal: once the run is done the card is
	 * finished and there is nothing left to poll for.
	 *
	 * @param {string} state
	 * @return {boolean}
	 */
	function isTerminal( state ) {
		return state === 'completed' ||
			state === 'completed_with_warnings' ||
			state === 'failed' ||
			state === 'cancelled';
	}

	/**
	 * A fingerprint of the report's visual state, used to decide whether to
	 * back off. In addition to the run status + stage it folds in every field
	 * that advances without a stage change — the fine pipeline stage, the
	 * discover total, the stage numerators (which are now live reads while a
	 * stage runs, so they tick even mid-capture/rewrite), the processed-item
	 * count, the published CID, the actionable error and the drift/superseded
	 * flags — so a ticking live run counts as changed and stays on the fast
	 * cadence.
	 *
	 * @param {object} status
	 * @return {string}
	 */
	function fingerprint( status ) {
		status = status || {};

		return [
			String( status.run_status ),
			String( status.run_stage ),
			String( status.pipeline_stage || '' ),
			String( typeof status.progress_total === 'number' ? status.progress_total : '' ),
			String( typeof status.capture_done === 'number' ? status.capture_done : '' ),
			String( typeof status.rewrite_done === 'number' ? status.rewrite_done : '' ),
			String( typeof status.progress_count === 'number' ? status.progress_count : 0 ),
			String( status.publish_cid || '' ),
			String( status.last_error || '' ),
			String( !! status.dirty ),
			String( !! status.superseded ),
			String( !! status.awaiting_website )
		].join( '|' );
	}

	/* ---------------------------- live region --------------------------- */

	/**
	 * Announce a status/action outcome to assistive technology through a
	 * polite aria-live region. Creates the region on the fly when the template
	 * did not render one, so a bare card is still accessible without breaking
	 * the server-rendered markup.
	 *
	 * @param {string} message
	 */
	function announce( message ) {
		if ( ! message ) {
			return;
		}

		var region = document.getElementById( 'cast-publish-live' );

		if ( ! region ) {
			region = document.createElement( 'div' );
			region.id = 'cast-publish-live';
			region.className = 'cast-publish-live';
			region.setAttribute( 'role', 'status' );
			region.setAttribute( 'aria-live', 'polite' );

			if ( document.body ) {
				document.body.appendChild( region );
			}
		}

		region.textContent = message;
	}

	/* ------------------------------ DOM apply --------------------------- */

	function el( selector ) {
		return document.querySelector( selector );
	}

	function setText( selector, text ) {
		var node = el( selector );

		if ( node ) {
			node.textContent = text === null || text === undefined ? '' : String( text );
		}
	}

	function setTextAndToggle( selector, text, show ) {
		var node = el( selector );

		if ( ! node ) {
			return;
		}

		node.textContent = text === null || text === undefined ? '' : String( text );
		node.hidden = ! show;
	}

	/**
	 * Render one status report into the server-rendered publish card. Every
	 * write goes through textContent (or hidden toggling) so the card never
	 * builds markup and server-escaped values cannot become new HTML.
	 *
	 * @param {object} status
	 */
	function applyStatus( status ) {
		status = status || {};

		// A successful report clears a transient failure streak and records the
		// check time — the "last checked" line is the heartbeat of the live card.
		pollFailed = false;
		failureAnnounced = false;
		markChecked();

		var state = runStateOf( status );
		var stage = stageLabelOf( status );
		var readiness = readinessOf( status );
		var cid = status.publish_cid || null;
		var site = ( status.identity && status.identity.website_name ) || null;
		var error = status.last_error || null;

		// A non-terminal report re-arms the poll: a retry fired after a
		// terminal run must start polling the fresh (queued) run again, so the
		// terminal stop is never sticky across a retry.
		if ( ! isTerminal( state ) ) {
			stopped = false;
		}

		setStateChip( status );
		setText( '.cast-publish-run-label', runLabelOf( status ) );
		setTextAndToggle( '.cast-publish-stage', stage, stage !== null );

		setTextAndToggle( '.cast-publish-cid', cid ? 'Published CID: ' + cid : '', cid !== null );
		// The connected website always reads as a labelled line — same copy as
		// the server render — never a naked id or a blank paragraph.
		setTextAndToggle( '.cast-publish-site', site !== null ? 'Website: ' + site : '', site !== null );

		setTextAndToggle( '.cast-publish-dirty', status.dirty ? 'You have unpublished changes.' : '', !! status.dirty );
		setTextAndToggle( '.cast-publish-superseded', status.superseded ? 'A newer publish is queued after the current one.' : '', !! status.superseded );
		setTextAndToggle( '.cast-publish-error', error, error !== null );

		setText( '.cast-publish-mode', modeLabelOf( status ) );
		setText( '.cast-publish-mode-help', modeHelpOf( status.mode ) );

		var level = el( '.cast-publish-readiness-level' );

		if ( level ) {
			level.textContent = readinessLabelOf( status );
			// The template keys these classes on the readiness IDENTIFIER
			// ('ready', 'config', ...), not the severity level ('ok', 'error').
			level.className = 'cast-publish-readiness-level cast-publish-level-' + readiness.readiness;
			// The 'setup' line is hidden: its label would repeat the context
			// line's onboarding instruction verbatim — one clear instruction,
			// not two. Every other readiness is shown.
			level.hidden = readiness.readiness === 'setup';
		}

		// The workflow additions: the live progress bar, the "why publish"
		// copy, the recovery/start-now escape and the per-button
		// availability/mode-active state.
		setProgress( status );
		setText( '.cast-publish-context', contextOf( status ) );
		updateEscape( status );
		syncActions( status );

		// Arm the live queued-ETA countdown only while a run is actually queued
		// WITH a queue-entry timestamp to tick from; any other state stops it,
		// and a queued report without one renders a static waiting line (there
		// is nothing that changes per second, so no timer is warranted). This
		// also keeps the timer count honest for a freshly-requeued run whose
		// follow-up report has not been enriched yet.
		if ( runStateOf( status ) === 'queued' && typeof status.queued_at === 'number' ) {
			startEtaCountdown();
		} else {
			clearEtaCountdown();
		}

		// Once a live run (or a finished one) is reflected, the status card
		// owns the progress feedback — clear a stale one-line action message.
		if ( status.run_active || isTerminal( runStateOf( status ) ) ) {
			setText( '.cast-publish-result', '' );
		}

		// The address card mirrors the persisted destination: the summary
		// (source label + address + the durable note) and the review prompt are
		// re-derived from the latest report so a wizard confirm resyncs the
		// card without a page refresh.
		renderAddressCard( status );

		// Keep the state in sync so the next action gate and poll decision see
		// the report the card is actually showing.
		lastStatus = status;
	}

	/**
	 * Livens the explicit queue/run state chip: the text PublishDashboardView
	 * :stateLabelFor pins, plus the per-state class the stylesheet keys on, so
	 * the accessible @role=status element announces each transition (ready →
	 * queued → publishing → published/failed/cancelled).
	 *
	 * @param {object} status
	 */
	function setStateChip( status ) {
		status = status || {};
		var chip = el( '.cast-publish-state-chip' );

		if ( ! chip ) {
			return;
		}

		var state = runStateOf( status );
		chip.textContent = stateLabelOf( status );
		chip.className = 'cast-publish-state-chip cast-publish-state-' + state;
	}

	/**
	 * Record that a status check just succeeded — the "last checked" time the
	 * live card shows next to the state chip. Falls back to a clock time in the
	 * current locale through textContent only.
	 */
	function markChecked() {
		var now = new Date();
		var time = now.toLocaleTimeString ? now.toLocaleTimeString() : String( now.getHours() ) + ':' + String( now.getMinutes() );

		setText( '.cast-publish-last-checked-time', time );
	}

	/**
	 * Record that the latest status check failed: surface it clearly where the
	 * editor looks (the last-checked line), announce it once per streak, and
	 * leave the previous report visible rather than blanking the card.
	 */
	function markCheckFailed() {
		pollFailed = true;
		setText( '.cast-publish-last-checked-time', 'Unavailable — retrying' );

		if ( ! failureAnnounced ) {
			failureAnnounced = true;
			announce( 'The publish status could not be refreshed. Retrying.' );
		}
	}

	/**
	 * Reflect the report's progress into the bar and its value/count copy.
	 * Every write goes through textContent except the bar's fill width, which
	 * is a unit-safe percentage.
	 *
	 * A parked run awaiting a website shows no bar and no percentage value:
	 * the upload was sent but nothing is progressing, so the only progress
	 * line is the honest "waiting for a website" copy (mirroring the
	 * server-rendered template, which omits both nodes while awaiting).
	 *
	 * @param {object} status
	 */
	function setProgress( status ) {
		status = status || {};
		var awaiting = !! status.awaiting_website;
		var percent = awaiting ? 0 : progressPercentOf( status );

		setText( '.cast-publish-progress-value', awaiting ? '' : percent + '%' );
		setText( '.cast-publish-progress-count', progressCountLabelOf( status ) );

		var value = el( '.cast-publish-progress-value' );

		if ( value ) {
			value.hidden = awaiting;
		}

		var fill = el( '.cast-publish-progress-fill' );

		if ( fill ) {
			fill.style.width = percent + '%';
		}

		var track = el( '.cast-publish-progress-track' );

		if ( track ) {
			track.hidden = awaiting;
			track.setAttribute( 'aria-valuenow', String( percent ) );
		}
	}

	/**
	 * Reveal/hide the recovery/start-now escape for a queued run, mirroring
	 * PublishDashboardView::canStartNowEscape exactly so a no-refresh status
	 * can never show an escape a fresh server render would have omitted. The
	 * escape needs state queued, the same ready surface every publish route
	 * hard-requires (never a superseded run, never an unready environment),
	 * AND an honest wait of at least START_NOW_WAIT_SECONDS — a normal brief
	 * queue or the quiet-period debounce never sees it, while a genuinely
	 * stalled queue gets a one-click "kick it now" way out (the same
	 * manual-run-wins route the primary publish action uses). Never shown for
	 * a running/paused/terminal run.
	 *
	 * The server also hides this container through the `hidden` attribute from
	 * the initial render; the stylesheet keeps that attribute honoured (see
	 * .cast-publish-escape[hidden] in cast-admin.css) so the visibility toggle
	 * below actually takes effect.
	 *
	 * @param {object} status
	 */
	function updateEscape( status ) {
		var container = el( '.cast-publish-escape' );

		if ( ! container ) {
			return;
		}

		status = status || {};
		var state = runStateOf( status );
		var elapsed = queuedElapsedSeconds( status, nowMs() );
		var ready = readinessOf( status ).readiness === 'ready';

		container.hidden = !(
			state === 'queued' &&
			! status.superseded &&
			ready &&
			elapsed !== null &&
			elapsed >= START_NOW_WAIT_SECONDS
		);
	}

	/**
	 * Re-derive each server-rendered action button's availability from the
	 * current report, so a no-refresh state change (e.g. a run starting in
	 * another tab) reconciles the card exactly like a fresh page load — the
	 * button's disabled state AND, where the template would have omitted the
	 * control entirely, its visibility.
	 *
	 * Duplicate-click safety: while an action is being POSTed (`busy`) every
	 * publish action is disabled so a second click can never fire a second
	 * request, and the whole mode group is non-actionable until the current
	 * request settles.
	 *
	 * Primary key reconciliation:
	 *   - The prominent Publish button's action + enabled state mirror
	 *     primaryActionFor(): a disabled button rendered with an empty action
	 *     is re-armed to 'start'/'now' the moment a terminal/failed/cancelled
	 *     retry (or a re-publish) becomes available, and re-disabled + cleared
	 *     the moment a run is live. The rendered action attribute is written
	 *     back so a re-armed click fires the right route.
	 *   - Cancel mirrors canCancel(): shown and enabled only while a live run
	 *     (queued/running/paused) may cancel it and hidden exactly like a
	 *     fresh render that omits it when idle or terminal. (The server still
	 *     owns the real cancel policy — a direct perform('cancel') for an
	 *     idle/terminal run is the same harmless no-op it always was.)
	 *   - The mode group mirrors canChangeMode(): non-actionable while a run
	 *     is live or the surface is not ready, matching a fresh render that
	 *     omits the selector at those moments.
	 *
	 * The recovery/start-now escape button is the one deliberate exception to
	 * the normal gate chain: while a run is queued the primary publish actions
	 * stay disabled, but the escape (whose class marks it) is gated by
	 * canEscape() instead — so a hidden-then-shown escape is clickable the
	 * moment it appears, without ever re-enabling the primary Publish button
	 * mid-queue. Its container's visibility is reconciled separately by
	 * updateEscape().
	 *
	 * @param {object} status
	 */
	function syncActions( status ) {
		var buttons = document.querySelectorAll( '[data-cast-publish-action]' );
		var i;
		var button;
		var action;
		var value;

		for ( i = 0; i < buttons.length; i++ ) {
			button = buttons[ i ];
			action = button.getAttribute( 'data-cast-publish-action' );

			if ( action === 'mode' ) {
				value = button.getAttribute( 'data-cast-publish-value' );
				// The current mode's radio is checked AND non-actionable
				// (checked + disabled); every other option is unchecked and
				// selectable again. Any in-flight request holds the whole group
				// until it settles.
				if ( value === ( status ? status.mode : '' ) ) {
					button.checked = true;
					button.disabled = true;
				} else {
					button.checked = false;
					button.disabled = busy !== null;
				}
				continue;
			}

			if ( action === 'cancel' ) {
				// Cancel mirrors canCancel(): only a live run may cancel, and
				// only a cancel request currently in flight holds the button.
				// The `hidden` attribute removes it from layout + assistive
				// tech exactly like a fresh render that omits it entirely.
				var live = canCancel( status );
				button.hidden = ! live;
				button.disabled = ! live || busy === 'cancel';
				continue;
			}

			// The prominent Publish button: reconcile its action + enabled
			// state from the current report exactly like the server render.
			if ( /(?:^|\s)cast-publish-primary(?:\s|$)/.test( button.className || '' ) ) {
				var primary = primaryActionFor( status );
				button.setAttribute( 'data-cast-publish-action', primary || '' );
				button.disabled = busy !== null || ! primary;
				continue;
			}

			var isEscape = /(?:^|\s)cast-publish-start-now(?:\s|$)/.test( button.className || '' );
			var allowed = isEscape ? canEscape( action, status ) : can( action, status );

			if ( busy !== null || ! allowed ) {
				button.disabled = true;
			} else {
				button.disabled = false;
			}
		}
	}

	/**
	 * Whether a live run may be cancelled — the PublishDashboardView::canCancel
	 * mapping: queued, running or paused. Idle (no run at all) and every
	 * terminal state must never offer Cancel, which the server would refuse as
	 * a no-op.
	 *
	 * @param {object} status
	 * @return {boolean}
	 */
	function canCancel( status ) {
		var state = runStateOf( status );

		return state === 'queued' || state === 'running' || state === 'paused';
	}

	/* ------------------------- queued ETA countdown --------------------- */

	/**
	 * Arm the single per-second queued-ETA countdown: while a run is queued it
	 * re-renders the progress line from the latest report + wall clock so the
	 * "Starting in N seconds" / elapsed copy ticks live without a fetch. It is
	 * purely cosmetic — the status poll owns the data — and it is a single
	 * re-armed timer (cleared on every re-arm and on leaving the queued state)
	 * so it can never pile up or leak, exactly like the poll timer.
	 */
	function startEtaCountdown() {
		clearEtaCountdown();

		etaTimer = window.setTimeout( function etaTick() {
			etaTimer = null;

			// Re-arm only for the same condition that armed it: a queued AND
			// timestamped report. A fresh report without a queue-entry time is
			// rendered − and left alone − by applyStatus instead.
			if ( lastStatus && runStateOf( lastStatus ) === 'queued' && typeof lastStatus.queued_at === 'number' ) {
				setText( '.cast-publish-progress-count', progressCountLabelOf( lastStatus ) );
				startEtaCountdown();
			}
		}, 1000 );
	}

	/**
	 * Stop the queued-ETA countdown. Called on every re-arm and the moment a
	 * report leaves the queued state, so no timer outlives the queue it serves.
	 */
	function clearEtaCountdown() {
		if ( etaTimer ) {
			window.clearTimeout( etaTimer );
			etaTimer = null;
		}
	}

	/* --------------------------- self-healing polling ------------------- */

	/**
	 * Whether a run is live enough to warrant a fast poll cadence: queued
	 * (waiting for the worker), running, or paused — any non-terminal run that
	 * may change under the editor. Idle surfaces (and terminal ones, which are
	 * handled by the stop rule) poll slowly so a quiet card never hammers the
	 * server.
	 *
	 * @param {object} status
	 * @return {boolean}
	 */
	function isLiveRun( status ) {
		status = status || {};
		var state = runStateOf( status );

		return state === 'queued' || state === 'running' || state === 'paused';
	}

	/**
	 * Arm the single next poll. There is no short attempt budget: the card
	 * keeps polling while a run is queued/active and stops only at a terminal
	 * state (or an explicit stop), and exactly one timer is ever pending, so
	 * the loop cannot leak or pile up.
	 *
	 * Cadence: a live run with a changed report polls at the fast interval; a
	 * live run whose report is unchanged, an idle surface, or a transient
	 * fetch failure all back off to the longer delay (when configured).
	 *
	 * @param {boolean} [changed]
	 * @param {boolean} [failed]
	 */
	function scheduleNext( changed, failed ) {
		if ( timer ) {
			window.clearTimeout( timer );
			timer = null;
		}

		if ( stopped ) {
			return;
		}

		// A finished / failed / cancelled run needs no further polls.
		if ( lastStatus && isTerminal( runStateOf( lastStatus ) ) ) {
			stopped = true;
			return;
		}

		// Auto-poll is off for a non-positive localized maxAttempts (the
		// domain-only tests/embedded surfaces opt out); otherwise polling is
		// continuous — there is no attempt-count stop.
		if ( maxAttempts <= 0 ) {
			return;
		}

		var fast = ! failed && !! changed && isLiveRun( lastStatus );
		var delay = fast || backoffMs <= 0 ? intervalMs : backoffMs;

		timer = window.setTimeout( function poll() {
			timer = null;
			fetchStatus();
		}, delay );
	}

	/**
	 * Release an in-flight action once the follow-up report is in hand, so a
	 * successful POST never re-enables its button before the fresh (queued)
	 * status gate does — the duplicate-click lock stays true all the way.
	 */
	function settleActionIfPending() {
		if ( busy === null ) {
			return;
		}

		busy = null;
		syncActions( lastStatus );
	}

	/**
	 * Fetch the status report once and apply it, then re-arm the poll from the
	 * change/failure state. A failed fetch applies nothing, surfaces the
	 * failure clearly and keeps the loop on the longer cadence — it never
	 * silently quits while a live run may still be progressing (the manual
	 * Refresh control re-fetches on demand too).
	 *
	 * When called after perform(), the fired action stays `busy` until this
	 * fetch settles, closing the re-enable window between a successful POST
	 * and the queued status report.
	 *
	 * Returns a promise that resolves once the report was applied (or the
	 * fetch failed/hit the guard), so a caller that refreshes status AND the
	 * website picker after a card mutation can re-apply a refusal copy only
	 * after the re-render settles — a still-awaiting card's render clears the
	 * error/result lines, and must not wipe a message written before it.
	 *
	 * @return {Promise}
	 */
	function fetchStatus() {
		if ( ! nonce || ! post || ! endpoints || ! endpoints.status ) {
			return Promise.resolve();
		}

		return post( endpoints.status, {
			method: 'GET',
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin'
		} ).then( function ( response ) {
			if ( ! response || ! response.ok ) {
				settleActionIfPending();
				markCheckFailed();
				scheduleNext( false, true );

				return null;
			}

			return response.json();
		} ).then( function ( status ) {
			if ( ! status ) {
				return;
			}

			var changed = ! lastStatus || fingerprint( status ) !== fingerprint( lastStatus );

			// The change decision feeds the cadence; a failure streak is
			// cleared by the successful applyStatus().
			applyStatus( status );
			settleActionIfPending();
			scheduleNext( changed, false );
		} ).catch( function () {
			settleActionIfPending();
			markCheckFailed();
			scheduleNext( false, true );
		} );
	}

	/**
	 * The manual Refresh control: cancel any pending poll and fetch status
	 * immediately. Keeps the single-timer invariant (the previous timer is
	 * cleared before the new fetch schedules its follow-up).
	 */
	function refreshStatus() {
		if ( stopped ) {
			stopped = false;
		}

		if ( timer ) {
			window.clearTimeout( timer );
			timer = null;
		}

		fetchStatus();
	}

	/* ------------------------------ actions ----------------------------- */

	/**
	 * Whether an action may be offered given the current status. Mirrors the
	 * PublishDashboardView can* decisions; cancel is always offered (the server
	 * owns the real policy and treats an idle cancel as a no-op), while mode
	 * is offered only for a mode that is not already active — the active mode
	 * is non-actionable (disabled + aria-pressed=true).
	 *
	 * @param {string} action
	 * @param {?object} status
	 * @param {?string} value
	 * @return {boolean}
	 */
	function can( action, status, value ) {
		status = status || {};
		var state = runStateOf( status );
		var readiness = readinessOf( status ).readiness;
		var identity = !! status.identity;
		var runActive = !! status.run_active;

		switch ( action ) {
			case 'start':
				// A first publish can always start: never-ran (idle) or a
				// terminal failed/cancelled record the scheduler restarts over.
				return ( state === 'idle' || state === 'failed' || state === 'cancelled' ) &&
					readiness === 'ready' && ! runActive && ! identity;
			case 'now':
				return identity && readiness === 'ready' &&
					state !== 'queued' && state !== 'running' && state !== 'paused';
			case 'artifact':
				// Republish/repair from the intact artifact: the terminal-run
				// re-point path, OR a parked first publish awaiting a website
				// (Paused, no identity) whose resume reuses the preserved CID —
				// the same publishExisting route the server wired in slice 1 to
				// resume a parked first publish.
				return ( can( 'now', status ) && isTerminal( state ) )
					|| ( !! status.awaiting_website && state === 'paused' );
			case 'mode':
				// A disabled active-mode button must stay non-actionable even
				// if a direct perform() is attempted for the same mode.
				return value !== null && value !== undefined && value !== status.mode;
			default:
				// cancel is always allowlisted — the server decides.
				return true;
		}
	}

	/**
	 * Whether the recovery/start-now escape may fire for a queued run through
	 * the given action, mirroring PublishDashboardView::canStartNowEscape +
	 * ::escapeActionFor: only while a run is actually queued (not-started),
	 * not superseded, and the same ready surface the funnelled route hard
	 * requires — 'start' for a first publish (no identity yet), 'now' once an
	 * identity exists. Time is deliberately NOT a factor here: the client
	 * reveals the escape only after the wait threshold (the state eligibility
	 * is a pure status mapping); this gate is what keeps a hidden-then-shown
	 * escape clickable and a normal publish button disabled mid-queue.
	 *
	 * @param {string} action
	 * @param {?object} status
	 * @return {boolean}
	 */
	function canEscape( action, status ) {
		status = status || {};

		if ( runStateOf( status ) !== 'queued' || !! status.superseded ) {
			return false;
		}

		var ready = readinessOf( status ).readiness === 'ready';
		var identity = !! status.identity;

		if ( action === 'start' ) {
			return ready && ! identity;
		}

		if ( action === 'now' ) {
			return ready && identity;
		}

		return false;
	}

	/**
	 * The one prominent publish action the workflow leads with, mirroring
	 * PublishDashboardView::primaryActionFor exactly: 'start' for a first
	 * publish (no identity yet), 'now' for any re-publish, and null the moment
	 * the surface is not ready or a run is already live. syncActions() writes
	 * this back into the rendered primary button's action + disabled state so
	 * a no-refresh transition re-arms the "Publish to Pinner" retry the moment
	 * a terminal failed/cancelled (or published) run clears the way.
	 *
	 * @param {?object} status
	 * @return {?string}
	 */
	function primaryActionFor( status ) {
		status = status || {};
		var state = runStateOf( status );

		if ( state === 'queued' || state === 'running' || state === 'paused' ) {
			return null;
		}

		if ( can( 'start', status ) ) {
			return 'start';
		}

		if ( can( 'now', status ) ) {
			return 'now';
		}

		return null;
	}

	/**
	 * The short confirmation copy a successful action announces. A mode change
	 * names the mode that was picked so the confirmation is unambiguous.
	 *
	 * @param {string} action
	 * @param {?string} value
	 * @return {string}
	 */
	function successMessage( action, value ) {
		switch ( action ) {
			case 'mode':
				return 'Mode updated to ' + modeNameOf( value ) + '.';
			case 'cancel':
				return 'Publish cancelled.';
			default:
				return 'Publish queued.';
		}
	}

	/**
	 * The immediate "working" copy a fired action writes the moment it is
	 * clicked, so the queued/working state is represented before the POST — or
	 * the worker — answers. Never implies the publish already happened.
	 *
	 * @param {string} action
	 * @return {string}
	 */
	function workingMessage( action ) {
		switch ( action ) {
			case 'mode':
				return 'Saving your publish setting…';
			case 'cancel':
				return 'Cancelling your publish…';
			default:
				return 'Queueing your publish — this runs in the background…';
		}
	}

	/**
	 * Fire one protected action: POST to the matching localized route with the
	 * nonce header, JSON body and same-origin credentials, then re-fetch status
	 * so the card resyncs.
	 *
	 * Duplicate-click safety: while a request is in flight the fired action is
	 * held `busy`, which disables the button (via syncActions) and refuses any
	 * further perform() call until it settles — a double click can never POST
	 * twice. The working state (result line + aria-live announcement) is
	 * represented immediately; Cancel stays available whenever a live run may
	 * cancel it.
	 *
	 * Inert (resolves false, fires nothing) when the action is not in the
	 * localized allowlist, the nonce/endpoint is missing, or the current
	 * run-state gate refuses the action.
	 *
	 * @param {string} action
	 * @param {?string} value
	 * @return {Promise<boolean>}
	 */
	function perform( action, value ) {
		return new Promise( function ( resolve ) {
			if ( ! nonce || ! post || ! endpoints || ! endpoints[ action ] ) {
				resolve( false );
				return;
			}

			// The server-side route allowlist, mirrored client-side: a forged
			// or unknown action is refused before anything is requested.
			if ( actions.indexOf( action ) === -1 ) {
				resolve( false );
				return;
			}

			// The normal per-action gate, OR the queued recovery/start-now
			// escape gate (so perform('start'/'now') may kick a queued run
			// through the same routes the escape button uses).
			if ( ! can( action, lastStatus, value ) && ! canEscape( action, lastStatus ) ) {
				announce( 'This action is not available right now.' );
				resolve( false );
				return;
			}

			// A duplicate action while a request is already in flight never
			// fires a second request — the button is already disabled, and a
			// direct call is refused here too.
			if ( busy !== null ) {
				announce( 'Please wait — your previous action is still finishing.' );
				resolve( false );
				return;
			}

			var body = {};

			if ( value !== undefined && value !== null ) {
				body.mode = value;
			}

			// Represent the queued/working state immediately: hold the action
			// busy (disables the button) and surface the working copy.
			busy = action;
			setText( '.cast-publish-result', workingMessage( action ) );
			announce( workingMessage( action ) );
			syncActions( lastStatus );

			post( endpoints[ action ], {
				method: 'POST',
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json'
				},
				credentials: 'same-origin',
				body: JSON.stringify( body )
			} ).then( function ( response ) {
				// A non-ok response settles the action immediately (failure);
				// a success keeps it busy until the follow-up status fetch
				// applies the fresh queued report (see settleActionIfPending).
				if ( ! response || ! response.ok ) {
					settleActionIfPending();
					setText( '.cast-publish-result', 'The action could not be completed. See the status card above.' );
					announce( 'The action could not be completed.' );
					resolve( false );
					return;
				}

				var confirmation = successMessage( action, value );
				setText( '.cast-publish-result', confirmation );
				announce( confirmation );
				resolve( true );

				// A successful action always re-fetches so the card resyncs
				// (the re-fetch re-marks the active mode + its help copy).
				fetchStatus();
			} ).catch( function () {
				settleActionIfPending();
				setText( '.cast-publish-result', 'The action could not be completed. See the status card above.' );
				announce( 'The action could not be completed.' );
				resolve( false );
			} );
		} );
	}

	/* ---------------------- connect-your-domain card ---------------------- */

	// The "Connect your domain" card orchestrator (custom destinations only):
	// domainDns()/domainSsl() re-read the delegation/SSL routes for the
	// SELECTED domain and domainValidate() re-checks its records. The card is
	// only live when the server localized the domain endpoints + allowlist;
	// without them every domain call is inert, so a partial or forged payload
	// can never reach a domain route.
	var domainActions = config.domain_actions || [];

	/**
	 * Whether a domain client route may be used: the localized nonce, the
	 * fetch surface, the route endpoint and the domain-action allowlist must
	 * all exist. Mirrors the server's refusal posture and keeps every domain
	 * call inert for a forged or partial localized payload.
	 *
	 * @param {string} routeName
	 * @return {boolean}
	 */
	function domainRouteAvailable( routeName ) {
		return !!( nonce && post && endpoints && endpoints[ routeName ] && domainActions.indexOf( routeName ) !== -1 );
	}

	/**
	 * The marker action (data-domain-action) to the localized client route
	 * name. The template emits short, human actions; only allowlisted client
	 * operations may ever fire a network request.
	 *
	 * @param {?string} action
	 * @return {?string}
	 */
	function domainActionForMarker( action ) {
		switch ( action ) {
			case 'validate':
				return 'domain_validate';
			case 'dns':
				return 'domain_dns';
			case 'ssl':
				return 'domain_ssl';
			default:
				return null;
		}
	}

	/**
	 * The human-safe DNS state label the card's DNS line shows for one bound
	 * domain, mirroring the server's DomainDashboardView::dnsLabelFor mapping.
	 *
	 * @param {object} domain
	 * @return {string}
	 */
	function dnsLabelFor( domain ) {
		domain = domain || {};

		// Both active and onchain_managed count as fully validated bindings
		// (on-chain managed HNS is verified via its namespace token, needing no
		// delegation wait), mirroring the CLI's domainStatusIsValid.
		switch ( domain.status ) {
			case 'active':
			case 'onchain_managed':
				return 'DNS delegation confirmed';
			case 'waiting_delegation':
				return 'Publish the delegation records below';
			default:
				return 'DNS delegation records';
		}
	}

	/**
	 * One instruction paragraph for the DNS guidance copy.
	 *
	 * @param {string} text
	 * @return {Element}
	 */
	function renderDnsInstruction( text ) {
		var node = document.createElement( 'p' );

		node.className = 'cast-domain-dns-instruction';
		node.textContent = String( text );

		return node;
	}

	/**
	 * One section heading for a DNS guidance block.
	 *
	 * @param {string} text
	 * @return {Element}
	 */
	function renderDnsHeading( text ) {
		var node = document.createElement( 'h4' );

		node.className = 'cast-domain-dns-subheading';
		node.textContent = String( text );

		return node;
	}

	/**
	 * The managed-DNS NAME/TYPE/VALUE table of nameservers: each Pinner
	 * nameserver the operator points the registrar's delegation at.
	 *
	 * @param {string} name
	 * @param {Array} nameservers
	 * @return {Element}
	 */
	function renderNameserverTable( name, nameservers ) {
		var table = document.createElement( 'table' );
		var thead = document.createElement( 'thead' );
		var headRow = document.createElement( 'tr' );
		var ths = [ 'Name', 'Type', 'Value' ];
		var tbody = document.createElement( 'tbody' );
		var i;

		table.className = 'cast-domain-dns-table';
		thead.appendChild( headRow );

		for ( i = 0; i < ths.length; i++ ) {
			var th = document.createElement( 'th' );
			th.textContent = ths[ i ];
			headRow.appendChild( th );
		}

		for ( i = 0; i < nameservers.length; i++ ) {
			var tr = document.createElement( 'tr' );
			var tdName = document.createElement( 'td' );
			var tdType = document.createElement( 'td' );
			var tdValue = document.createElement( 'td' );

			tdName.textContent = String( name );
			tdType.textContent = 'NS';
			tdValue.textContent = String( nameservers[ i ] );
			tr.appendChild( tdName );
			tr.appendChild( tdType );
			tr.appendChild( tdValue );
			tbody.appendChild( tr );
		}

		table.appendChild( tbody );

		return table;
	}

	/**
	 * A TYPE/VALUE table of delegation records (parent or authoritative). An
	 * NS record may carry several nameservers comma-joined in a single value
	 * (e.g. "ns1.pinner.xyz,ns2.pinner.xyz"); each is split onto its own row
	 * so every value is visible and copyable, matching the CLI.
	 *
	 * @param {Array} records
	 * @return {Element}
	 */
	function renderRecordsTable( records ) {
		var table = document.createElement( 'table' );
		var thead = document.createElement( 'thead' );
		var headRow = document.createElement( 'tr' );
		var thType = document.createElement( 'th' );
		var thValue = document.createElement( 'th' );
		var tbody = document.createElement( 'tbody' );
		var i;

		table.className = 'cast-domain-dns-table';
		thType.textContent = 'Type';
		thValue.textContent = 'Value';
		headRow.appendChild( thType );
		headRow.appendChild( thValue );
		thead.appendChild( headRow );
		table.appendChild( thead );

		for ( i = 0; i < records.length; i++ ) {
			var record = records[ i ] || {};
			var type = String( record.type || '' );
			var value = String( record.value || '' );
			var values = [ value ];

			if ( type === 'NS' && value.indexOf( ',' ) !== -1 ) {
				values = value.split( ',' );
			}

			for ( var v = 0; v < values.length; v++ ) {
				var tr = document.createElement( 'tr' );
				var tdTypeCell = document.createElement( 'td' );
				var tdValueCell = document.createElement( 'td' );

				tdTypeCell.textContent = type;
				tdValueCell.textContent = String( values[ v ].trim ? values[ v ].trim() : values[ v ] );
				tr.appendChild( tdTypeCell );
				tr.appendChild( tdValueCell );
				tbody.appendChild( tr );
			}
		}

		table.appendChild( tbody );

		return table;
	}

	/**
	 * The per-record validation checks: a heading plus one definition row per
	 * check with the server-computed expected value ("Publish this record:")
	 * and, when the DNS currently holds a different value, the found row.
	 *
	 * @param {Array} checks
	 * @return {Element[]}
	 */
	function renderChecks( checks ) {
		var heading = document.createElement( 'h4' );
		var dl = document.createElement( 'dl' );
		var i;

		heading.className = 'cast-domain-dns-subheading';
		heading.textContent = 'Validation checks';
		dl.className = 'cast-domain-checks';

		for ( i = 0; i < checks.length; i++ ) {
			var check = checks[ i ] || {};
			var name = document.createElement( 'dt' );

			name.textContent = String( check.name || '' );
			dl.appendChild( name );

			if ( check.message ) {
				dl.appendChild( checkDd( String( check.message ) ) );
			}
			if ( check.expected ) {
				dl.appendChild( checkDt( 'Publish this record:' ) );
				dl.appendChild( checkDd( String( check.expected ) ) );
			}
			if ( check.found ) {
				dl.appendChild( checkDt( 'Found instead:' ) );
				dl.appendChild( checkDd( String( check.found ) ) );
			}
		}

		return [ heading, dl ];
	}

	function checkDt( text ) {
		var node = document.createElement( 'dt' );

		node.textContent = text;

		return node;
	}

	function checkDd( text ) {
		var node = document.createElement( 'dd' );

		node.textContent = text;

		return node;
	}

	/**
	 * Build the full DNS delegation guidance DOM for one bound domain: the
	 * summary rows, then the managed / HNS / self-managed copy with the
	 * nameserver NAME/TYPE/VALUE table (managed ICANN), the parent and
	 * authoritative record tables (comma-joined NS values split), the
	 * nameservers list, the DNSSEC state/error (rendered verbatim, never
	 * computed) and the per-record checks. Every node is built through
	 * createElement/textContent so a server value can never become markup.
	 *
	 * @param {object} domain
	 * @return {Element[]}
	 */
	function renderDnsGuidance( domain ) {
		var nodes = [];
		var delegation = domain.delegation || null;
		var checks = domain.checks || [];
		var hosted = !! domain.dns_hosting_enabled;
		var namespace = String( domain.namespace || '' );
		var icann = namespace === 'icann';
		var hns = namespace === 'hns';
		// On-chain managed (an HNS binding whose DNS is served by an external
		// on-chain contract): a distinct state checked BEFORE the managed /
		// self-managed delegation branches, so it never inherits their copy.
		var onchain = String( domain.status || '' ) === 'onchain_managed';
		var cells = [
			[ 'Domain', domain.domain ],
			[ 'Namespace', domain.namespace ],
			[ 'Status', domain.status ],
			[ 'Gateway', domain.gateway_host ]
		];
		var summary = document.createElement( 'dl' );
		var i;

		summary.className = 'cast-domain-dns-summary';

		for ( i = 0; i < cells.length; i++ ) {
			if ( cells[ i ][ 1 ] === undefined || cells[ i ][ 1 ] === null || cells[ i ][ 1 ] === '' ) {
				continue;
			}

			var sdt = document.createElement( 'dt' );
			var sdd = document.createElement( 'dd' );

			sdt.textContent = String( cells[ i ][ 0 ] );
			sdd.textContent = String( cells[ i ][ 1 ] );
			summary.appendChild( sdt );
			summary.appendChild( sdd );
		}

		nodes.push( summary );

		if ( onchain ) {
			// On-chain managed: the domain is held on-chain and its DNS records
			// are set on-chain, not in a Pinner-managed zone. Show only the
			// server-returned DNSLink/TLSA guidance (the delegation bundle's
			// records, when present, plus the per-record checks) and never claim
			// Pinner manages the DNS — that framing belongs to the delegated
			// managed / self-managed cases only.
			nodes.push( renderDnsInstruction( 'This domain is managed on-chain, so its DNS records are set on-chain, not by Pinner.' ) );
			nodes.push( renderDnsInstruction( 'Publish the DNSLink and TLSA records shown below on-chain, wherever you manage this domain\'s DNS.' ) );
			if ( delegation ) {
				var onchainParent = delegation.parent_records || [];
				var onchainAuthoritative = delegation.authoritative_records || [];

				if ( onchainParent.length || onchainAuthoritative.length ) {
					nodes.push( renderDnsHeading( 'Records to publish on-chain' ) );
					if ( onchainParent.length ) {
						nodes.push( renderRecordsTable( onchainParent ) );
					}
					if ( onchainAuthoritative.length ) {
						nodes.push( renderRecordsTable( onchainAuthoritative ) );
					}
				}
			}

			if ( checks.length ) {
				nodes = nodes.concat( renderChecks( checks ) );
			}

			return nodes;
		}

		if ( ! delegation ) {
			// No delegation bundle yet: say so rather than implying records
			// exist to publish.
			nodes.push( renderDnsInstruction( 'No delegation records are available for ' + String( domain.domain ) + '.' ) );
		} else {
			var nameservers = delegation.nameservers || [];
			var parent = delegation.parent_records || [];
			var authoritative = delegation.authoritative_records || [];
			var mode = String( delegation.mode || '' );

			if ( hosted && icann ) {
				// Managed ICANN: point the registrar at Pinner's nameservers
				// (the NAME/TYPE/VALUE table), then publish the parent records.
				// The authoritative side is handled for the operator.
				nodes.push( renderDnsInstruction( 'Update your domain\'s nameservers at your registrar.' ) );
				if ( nameservers.length ) {
					nodes.push( renderNameserverTable( String( domain.domain ), nameservers ) );
				}
				nodes.push( renderDnsInstruction( 'Point your registrar\'s nameservers to the records below.' ) );
				nodes.push( renderDnsInstruction( 'Pinner manages your DNS, so the authoritative side is handled for you.' ) );
				if ( parent.length ) {
					nodes.push( renderDnsHeading( 'Parent records (configure at your registrar)' ) );
					nodes.push( renderRecordsTable( parent ) );
				}
			} else if ( hosted && hns ) {
				// Managed HNS (incl. inline): the records live on-chain in the
				// HNS wallet; inline serves the authoritative side via
				// Pinner's synthetic nameservers, managed handles it for the
				// operator.
				nodes.push( renderDnsInstruction( 'Publish the records below in the DNS/records area of your HNS wallet (on-chain).' ) );
				if ( mode === 'inline' ) {
					nodes.push( renderDnsInstruction( 'The authoritative side is served via Pinner\'s synthetic nameservers.' ) );
				} else {
					nodes.push( renderDnsInstruction( 'Pinner manages your DNS, so the authoritative side is handled for you.' ) );
				}
				if ( parent.length ) {
					nodes.push( renderDnsHeading( 'Parent records (publish in your HNS wallet)' ) );
					nodes.push( renderRecordsTable( parent ) );
				}
			} else {
				// Self-managed: the operator configures the parent records at
				// the registrar (ICANN) or in the HNS wallet (HNS), then points
				// their own DNS server at the authoritative records.
				if ( icann ) {
					nodes.push( renderDnsInstruction( 'Configure the parent records at your registrar, then point your DNS server at the authoritative records below.' ) );
				} else {
					nodes.push( renderDnsInstruction( 'Publish the parent records in the DNS/records area of your HNS wallet (on-chain), then point your own DNS server at the authoritative records below.' ) );
				}
				if ( parent.length ) {
					nodes.push( renderDnsHeading( icann ? 'Parent records (configure at your registrar)' : 'Parent records (publish in your HNS wallet)' ) );
					nodes.push( renderRecordsTable( parent ) );
				}
			}

			if ( ! hosted && authoritative.length ) {
				nodes.push( renderDnsHeading( 'Authoritative records (configure on your DNS server)' ) );
				nodes.push( renderRecordsTable( authoritative ) );
			}

			// The nameservers list: managed ICANN already presented each
			// nameserver in its NAME/TYPE/VALUE table, so the list is only
			// emitted where that table was not (HNS and self-managed bindings).
			if ( nameservers.length && ! ( hosted && icann ) ) {
				var nsList = document.createElement( 'ul' );
				nsList.className = 'cast-domain-nameservers';

				for ( i = 0; i < nameservers.length; i++ ) {
					var li = document.createElement( 'li' );
					li.textContent = String( nameservers[ i ] );
					nsList.appendChild( li );
				}

				nodes.push( renderDnsHeading( 'Nameservers' ) );
				nodes.push( nsList );
			}

			if ( delegation.dnssec ) {
				var dnssec = document.createElement( 'p' );
				dnssec.className = 'cast-domain-dnssec';
				dnssec.textContent = 'DNSSEC: ' + String( delegation.dnssec );
				nodes.push( dnssec );
			}

			if ( delegation.dnssec_error ) {
				var dnssecError = document.createElement( 'p' );
				dnssecError.className = 'cast-domain-dnssec-error';
				dnssecError.textContent = 'DNSSEC error: ' + String( delegation.dnssec_error );
				nodes.push( dnssecError );
			}
		}

		if ( ! hosted ) {
			// Self-managed DNS: the records to add were shown above; validate
			// once they propagate. The per-record values are the checks below.
			nodes.push( renderDnsInstruction( 'Add the DNS records shown above at your registrar, then validate.' ) );
		}

		if ( checks.length ) {
			nodes = nodes.concat( renderChecks( checks ) );
		}

		return nodes;
	}

	/**
	 * Render a DNS requirements payload into the panel's delegation list.
	 *
	 * @param {object} data
	 */
	function renderDomainDns( data ) {
		data = data || {};
		var domain = data.domain || null;

		setText( '.cast-domain-dns-label', dnsLabelFor( domain ) );

		if ( ! domain ) {
			return;
		}

		var copy = el( '.cast-domain-dns-copy' );

		if ( ! copy ) {
			return;
		}

		copy.replaceChildren.apply( copy, renderDnsGuidance( domain ) );
	}

	/**
	 * Read a bound domain's DNS delegation requirements and render the records
	 * as an accessible definition list. GETs the DNS route with the nonce
	 * header; every value lands through textContent.
	 *
	 * @param {string} domainId
	 * @return {Promise<boolean>}
	 */
	function domainDns( domainId ) {
		return new Promise( function ( resolve ) {
			if ( ! domainRouteAvailable( 'domain_dns' ) ) {
				resolve( false );
				return;
			}

			post( endpoints.domain_dns, {
				method: 'GET',
				headers: { 'X-WP-Nonce': nonce },
				credentials: 'same-origin'
			} ).then( function ( response ) {
				if ( ! response || ! response.ok ) {
					announce( 'The delegation records could not be read.' );
					resolve( false );
					return null;
				}

				return response.json();
			} ).then( function ( data ) {
				if ( ! data ) {
					resolve( false );
					return;
				}

				renderDomainDns( data );
				announce( 'DNS delegation records updated.' );
				resolve( true );
			} ).catch( function () {
				announce( 'The delegation records could not be read.' );
				resolve( false );
			} );
		} );
	}

	// The DNS validation in-flight guard: exactly one validate request may be
	// outstanding, so a double-click never stacks a second call. The request is
	// a single check — the panel never background-polls DNS validation.
	var domainValidateInFlight = false;

	/**
	 * The propagation retry copy shown whenever DNS validation has not yet
	 * succeeded (an incomplete check or a failed request). Nameserver changes
	 * are not instant, so the operator is told to wait and try again rather
	 * than implied a permanent error.
	 */
	var DNS_VALIDATION_RETRY_COPY = 'Nameserver changes can take time to propagate. Try again in a few minutes.';

	/**
	 * Set or clear the DNS Validate button's in-flight state: while validating
	 * the button reads "Validating..." and is disabled; the panel label swaps
	 * to the working line. On the way back the button is restored to its idle
	 * label — the render function that follows owns the label copy.
	 *
	 * @param {boolean} working
	 */
	function setDomainValidateWorking( working ) {
		var markers = document.querySelectorAll( '[data-domain-action]' );
		var i;

		for ( i = 0; i < markers.length; i++ ) {
			if ( markers[ i ].getAttribute( 'data-domain-action' ) === 'validate' ) {
				markers[ i ].textContent = working ? 'Validating...' : 'I made the changes — check again';
				markers[ i ].disabled = !! working;
				markers[ i ].setAttribute( 'aria-busy', working ? 'true' : 'false' );
				break;
			}
		}

		if ( working ) {
			setText( '.cast-domain-dns-label', 'Validating DNS records...' );
		}
	}

	/**
	 * Render the propagation retry state into the panel (label + copy area).
	 */
	function renderDomainValidationFailure() {
		setText( '.cast-domain-dns-label', 'DNS validation could not be completed' );

		var copy = el( '.cast-domain-dns-copy' );

		if ( copy ) {
			copy.replaceChildren( renderDnsInstruction( DNS_VALIDATION_RETRY_COPY ) );
		}
	}

	/**
	 * Render the website validation result into the panel: the per-status label
	 * (confirmed when valid, records-to-publish otherwise), the server message,
	 * the server-computed per-record checks with the exact "Publish this
	 * record" / "Found instead" rows, and — until validation actually succeeds
	 * — the propagation retry copy. Every value lands through textContent.
	 *
	 * @param {object} data
	 */
	function renderDomainValidation( data ) {
		data = data || {};
		var validation = data.validation || null;

		if ( ! validation ) {
			renderDomainValidationFailure();
			return;
		}

		var nodes = [];
		var copy = el( '.cast-domain-dns-copy' );

		if ( validation.valid ) {
			setText( '.cast-domain-dns-label', 'DNS delegation confirmed' );
			nodes.push( renderDnsInstruction( 'DNS records validated.' ) );
		} else {
			setText( '.cast-domain-dns-label', 'Publish the delegation records below' );
			nodes.push( renderDnsInstruction( 'DNS validation is incomplete.' ) );
		}

		if ( validation.message ) {
			nodes.push( renderDnsInstruction( String( validation.message ) ) );
		}

		if ( validation.checks && validation.checks.length ) {
			nodes = nodes.concat( renderChecks( validation.checks ) );
		}

		if ( ! validation.valid ) {
			nodes.push( renderDnsInstruction( DNS_VALIDATION_RETRY_COPY ) );
		}

		if ( copy ) {
			copy.replaceChildren.apply( copy, nodes );
		}
	}

	/**
	 * Run a single DNS validation check for the registered website and render
	 * the server-computed result. The button shows "Validating..." while the
	 * request is in flight and is blocked from stacking a second call; there is
	 * no background polling loop — each click is one check.
	 *
	 * @param {string} websiteId
	 * @return {Promise<boolean>}
	 */
	function domainValidate( domainId ) {
		return new Promise( function ( resolve ) {
			if ( ! domainRouteAvailable( 'domain_validate' ) ) {
				resolve( false );
				return;
			}

			if ( domainValidateInFlight ) {
				resolve( false );
				return;
			}

			domainValidateInFlight = true;
			setDomainValidateWorking( true );

			post( endpoints.domain_validate, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json'
				},
				credentials: 'same-origin',
				body: JSON.stringify( { domain_id: String( domainId || '' ) } )
			} ).then( function ( response ) {
				if ( ! response || ! response.ok ) {
					renderDomainValidationFailure();
					announce( 'DNS validation failed.' );
					resolve( false );
					return null;
				}

				return response.json();
			} ).then( function ( data ) {
				if ( ! data ) {
					setDomainValidateWorking( false );
					domainValidateInFlight = false;
					resolve( false );
					return;
				}

				renderDomainValidation( data );
				announce( 'DNS validation complete.' );
				setDomainValidateWorking( false );
				domainValidateInFlight = false;
				resolve( true );
			} ).catch( function () {
				renderDomainValidationFailure();
				announce( 'DNS validation failed.' );
				setDomainValidateWorking( false );
				domainValidateInFlight = false;
				resolve( false );
			} );
		} );
	}

	/**
	 * The SSL label copy mirroring DomainDashboardView::sslLabelFor for the
	 * ready state (the only state this slice renders — pending/failed maps to
	 * the same fallback until a dedicated SSL state UI exists).
	 *
	 * @param {?object} ssl
	 * @return {string}
	 */
	function sslLabelFor( ssl ) {
		if ( ssl && ssl.status === 'ready' ) {
			return 'SSL active';
		}

		return 'SSL status unknown';
	}

	/**
	 * Read a bound domain's SSL/TLS status and render the label. GETs the SSL
	 * route with the nonce header; the copy lands through textContent.
	 *
	 * @param {string} domain
	 * @return {Promise<boolean>}
	 */
	function domainSsl( domain ) {
		return new Promise( function ( resolve ) {
			if ( ! domainRouteAvailable( 'domain_ssl' ) ) {
				resolve( false );
				return;
			}

			post( endpoints.domain_ssl, {
				method: 'GET',
				headers: { 'X-WP-Nonce': nonce },
				credentials: 'same-origin'
			} ).then( function ( response ) {
				if ( ! response || ! response.ok ) {
					announce( 'The SSL status could not be read.' );
					resolve( false );
					return null;
				}

				return response.json();
			} ).then( function ( data ) {
				if ( ! data ) {
					resolve( false );
					return;
				}

				setText( '.cast-domain-ssl-label', sslLabelFor( data.ssl || null ) );
				resolve( true );
			} ).catch( function () {
				announce( 'The SSL status could not be read.' );
				resolve( false );
			} );
		} );
	}

	/* --------------------------- address wizard (S5) ------------------------- */

	// The "Your site address" wizard: a focused guide, not a second dashboard.
	// The wizard is server-rendered (hidden in the initial paint) so the no-JS
	// prompt and the JS flow share one markup. Exactly three native radio
	// choices, each revealing only its branch's fields; the final review is a
	// plain read-out (the user never re-types the domain); one confirm button
	// saves the destination (POST /publish/destination), confirms it
	// (POST /publish/destination/confirm) and — for a first publish — starts
	// the run. Every destination call is gated by the localized
	// destination-endpoint allowlist + nonce.
	var destinationActions = config.destination_actions || [];
	var addressBusy = false;
	var availableWebsites = [];

	/**
	 * Whether a destination client route may be used: the localized nonce, the
	 * fetch surface, the route endpoint and the destination-action allowlist
	 * must all exist. Mirrors the server's refusal posture and keeps every
	 * destination call inert for a forged or partial localized payload.
	 *
	 * @param {string} routeName
	 * @return {boolean}
	 */
	function destinationRouteAvailable( routeName ) {
		return !!( nonce && post && endpoints && endpoints[ routeName ] && destinationActions.indexOf( routeName ) !== -1 );
	}

	/**
	 * Friendly copy for each destination refusal code, mirroring the server's
	 * PublishDestinationRefusal — the wizard never shows a raw code.
	 *
	 * @param {?string} refusal
	 * @return {string}
	 */
	function destinationRefusalCopy( refusal ) {
		switch ( refusal ) {
			case 'store_unavailable':
				return 'Your publish address could not be saved right now. Please try again.';
			case 'invalid_input':
				return 'Please check the address details and try again.';
			case 'confirmed_cannot_change':
				return 'This address is already confirmed and can no longer be changed.';
			case 'created_or_attached_cannot_change':
				return 'This address is live and can no longer be changed.';
			case 'website_already_attached':
				return 'This Pinner site is already attached to another workspace.';
			case 'attach_failed':
				return 'Pinner could not attach that site. Please try again.';
			default:
				return 'The address could not be saved. Please try again.';
		}
	}

	/**
	 * Friendly copy for the first-publish start refusals (the server's
	 * PublishStartRefusal) the address wizard can surface — the address IS
	 * confirmed by the time these land, so the copy says what unblocks the
	 * publish instead of a generic failure.
	 *
	 * @param {?string} refusal
	 * @return {string}
	 */
	function startRefusalCopy( refusal ) {
		switch ( refusal ) {
			case 'no_eligible_content':
				return 'There is no publishable content on your site yet. Add a page or post, then start a publish from this page.';
			default:
				return 'The publish could not be started right now. Please try again.';
		}
	}

	/**
	 * The confirm button's label, mirroring the template's
	 * $addressConfirmLabel: the button promises a publish only while a first
	 * publish can start right now (the same can('start') call the wizard's
	 * start attempt is gated on) — otherwise it names only what the click
	 * does: create and confirm the address.
	 *
	 * @param {?object} status
	 * @return {string}
	 */
	function addressConfirmLabelFor( status ) {
		return can( 'start', status ) ? 'Create address and publish' : 'Create address';
	}

	/**
	 * The source label the address summary shows, mirroring the template's
	 * $addressSourceLabel table so the no-refresh card never drifts from the
	 * server paint.
	 *
	 * @param {?string} source
	 * @return {string}
	 */
	function addressSourceLabelFor( source ) {
		switch ( source ) {
			case 'platform':
				return 'Pinner address';
			case 'custom':
				return 'Your own domain';
			case 'existing':
				return 'Existing Pinner site';
			default:
				return '';
		}
	}

	/**
	 * The display address the summary shows, mirroring the template's
	 * $addressValue composition (a generated platform address reads as a plain
	 * promise until the first publish creates it).
	 *
	 * @param {object} destination
	 * @return {string}
	 */
	function addressValueFor( destination ) {
		destination = destination || {};

		switch ( destination.source ) {
			case 'platform': {
				var platformDomain = destination.platform_domain || '';
				var label = destination.label || '';
				return label !== ''
					? ( platformDomain !== '' ? label + '.' + platformDomain : label )
					: 'A free Pinner address';
			}
			case 'custom':
			case 'existing':
				return destination.domain || '';
			default:
				return '';
		}
	}

	/**
	 * The persisted destination setup's lifecycle from the latest report
	 * ('draft' | 'confirmed' | 'created_or_attached'), or '' when no
	 * destination exists. The mutation wizard is legal only while the choice
	 * is still a draft: a confirmed (frozen) or created/attached status —
	 * including one that arrives via a routine poll — never opens or submits
	 * the wizard.
	 *
	 * @param {?object} status
	 * @return {string}
	 */
	function destinationLifecycleOf( status ) {
		return status && status.destination && typeof status.destination.lifecycle === 'string'
			? status.destination.lifecycle
			: '';
	}

	/**
	 * Re-derive the address summary from the latest report. The summary
	 * (source label + address + the durable note) resyncs with the report, but
	 * a normal status poll NEVER hides an open, unconfirmed wizard or touches
	 * its field selections: the wizard only closes on an explicit confirm
	 * (closeAddressWizard), so a routine 30-second poll can never discard an
	 * in-progress choice. A report that says the choice is NO LONGER a draft
	 * (confirmed elsewhere, or created/attached) is the exception: the
	 * mutation surface freezes — the wizard closes and the review entry is
	 * withheld.
	 *
	 * @param {?object} status
	 */
	function renderAddressCard( status ) {
		var view = status && status.destination;
		var destination = view ? view.destination : null;
		var lifecycle = view && typeof view.lifecycle === 'string' ? view.lifecycle : '';

		if ( ! destination ) {
			var prompt = el( '.cast-address-prompt' );
			if ( prompt ) {
				prompt.hidden = false;
			}
			return;
		}

		// A frozen (confirmed) or final (created/attached) choice is a summary,
		// not an editor: close any open wizard and withhold the review entry.
		if ( lifecycle === 'confirmed' || lifecycle === 'created_or_attached' ) {
			closeAddressWizard();
		}
		var review = el( '.cast-address-review' );
		if ( review ) {
			review.hidden = lifecycle !== 'draft';
		}

		var source = destination.source || '';

		if ( el( '.cast-address-state' ) ) {
			setText( '.cast-address-source', addressSourceLabelFor( source ) );
			setText( '.cast-address-value', addressValueFor( destination ) );
		} else {
			// First destination created through the wizard: build the summary
			// block (textContent only) so the card resyncs without a refresh.
			buildAddressSummary( source, addressValueFor( destination ) );
		}
	}

	/**
	 * Build the server-style summary block when the initial paint had none
	 * (no destination yet): a source-label line + the address line, inserted
	 * ahead of the prompt, which is then hidden.
	 *
	 * @param {string} source
	 * @param {string} value
	 */
	function buildAddressSummary( source, value ) {
		var card = el( '.cast-address-card' );
		if ( ! card ) {
			return;
		}

		var state = document.createElement( 'p' );
		state.className = 'cast-address-state';

		var label = document.createElement( 'span' );
		label.className = 'cast-address-source';
		label.textContent = addressSourceLabelFor( source );
		state.appendChild( label );

		var valueNode = document.createElement( 'p' );
		valueNode.className = 'cast-address-value';
		valueNode.textContent = value;

		var prompt = el( '.cast-address-prompt' );
		if ( prompt && prompt.parentNode === card ) {
			card.insertBefore( state, prompt );
			card.insertBefore( valueNode, prompt );
		} else {
			card.appendChild( state );
			card.appendChild( valueNode );
		}

		if ( prompt ) {
			prompt.hidden = true;
		}
		var choose = el( '.cast-address-choose' );
		if ( choose ) {
			choose.hidden = true;
		}
	}

	/**
	 * Reveal the inline wizard (the "Choose address" / "Review address"
	 * entry point), clear stale messages, reveal the checked source's branch
	 * and move focus to the first choice.
	 */
	function openAddressWizard() {
		var wizard = el( '.cast-address-wizard' );
		if ( ! wizard ) {
			return;
		}

		// The mutation wizard is draft-only: a confirmed (frozen) or
		// created/attached choice — including one a poll reported after the
		// entry point was painted — never opens the editor.
		var lifecycle = destinationLifecycleOf( lastStatus );
		if ( lifecycle !== '' && lifecycle !== 'draft' ) {
			return;
		}

		wizard.hidden = false;
		clearAddressWizardMessages();

		var radios = document.querySelectorAll( 'input[name="cast-address-source"]' );
		if ( radios.length ) {
			var checked = null;
			var i;
			for ( i = 0; i < radios.length; i++ ) {
				if ( radios[ i ].checked ) {
					checked = radios[ i ];
					break;
				}
			}
			var source = checked ? checked.value : 'platform';
			revealAddressBranch( source );
			updateAddressNamespaceNote();
			updateAddressReview();
			// The existing branch lists the account's sites on demand — never at
			// init, so an untouched page fetches only the status report.
			if ( source === 'existing' ) {
				refreshExistingPicker();
			}
			if ( typeof radios[ 0 ].focus === 'function' ) {
				radios[ 0 ].focus();
			}
		}
	}

	/** Hide the inline wizard again (after a confirm, or a status paint). */
	function closeAddressWizard() {
		var wizard = el( '.cast-address-wizard' );
		if ( wizard ) {
			wizard.hidden = true;
		}
	}

	/**
	 * The checked radio of a native group, or null when none is checked.
	 *
	 * @param {string} name
	 * @return {?Element}
	 */
	function checkedAddressRadio( name ) {
		var radios = document.querySelectorAll( 'input[name="' + name + '"]' );
		var i;

		for ( i = 0; i < radios.length; i++ ) {
			if ( radios[ i ].checked ) {
				return radios[ i ];
			}
		}

		return null;
	}

	/**
	 * Reveal exactly the selected source's branch; every other branch hides.
	 * The branches are keyed by the template's data-cast-address-branch
	 * attribute — the same marker the server render uses — so a radio change
	 * reveals precisely that source's fields and nothing else's.
	 *
	 * @param {string} source
	 */
	function revealAddressBranch( source ) {
		var branches = {
			platform: '[data-cast-address-branch="platform"]',
			custom: '[data-cast-address-branch="custom"]',
			existing: '[data-cast-address-branch="existing"]'
		};
		var name;

		for ( name in branches ) {
			if ( Object.prototype.hasOwnProperty.call( branches, name ) ) {
				var branch = el( branches[ name ] );
				if ( branch ) {
					branch.hidden = name !== source;
				}
			}
		}
	}

	/**
	 * Reveal the namespace-specific DNS note for the chosen domain type and
	 * hide the other one. The notes are server-rendered (one per namespace,
	 * keyed by data-cast-namespace-note); the orchestrator only switches
	 * which is visible, so the copy can never drift from the template.
	 */
	function updateAddressNamespaceNote() {
		var namespaceEl = document.getElementById( 'cast-address-custom-namespace' );
		var namespace = namespaceEl && namespaceEl.value ? String( namespaceEl.value ) : 'icann';
		var notes = document.querySelectorAll( '[data-cast-namespace-note]' );
		var i;

		for ( i = 0; i < notes.length; i++ ) {
			notes[ i ].hidden = notes[ i ].getAttribute( 'data-cast-namespace-note' ) !== namespace;
		}
	}

	/** Clear both wizard message lines (error + result). */
	function clearAddressWizardMessages() {
		var error = el( '.cast-address-wizard-error' );
		if ( error ) {
			error.hidden = true;
			error.textContent = '';
		}
		setText( '.cast-address-wizard-result', '' );
	}

	/** Show the wizard's error line (aria-live) and clear the result line. */
	function showAddressWizardError( message ) {
		var error = el( '.cast-address-wizard-error' );
		if ( error ) {
			error.textContent = message;
			error.hidden = false;
		}
		setText( '.cast-address-wizard-result', '' );
	}

	/** Show the wizard's result line (aria-live) and clear the error line. */
	function showAddressWizardResult( message ) {
		var error = el( '.cast-address-wizard-error' );
		if ( error ) {
			error.hidden = true;
			error.textContent = '';
		}
		setText( '.cast-address-wizard-result', message );
	}

	/**
	 * Read the visible wizard fields into the destination save payload. Only
	 * the selected source's fields are read — the server allowlists the fields
	 * again, so a forged extra field can never alter the stored choice.
	 *
	 * @return {object}
	 */
	function buildAddressPayload() {
		var radio = checkedAddressRadio( 'cast-address-source' );
		var source = radio ? radio.value : 'platform';

		if ( source === 'custom' ) {
			var domainEl = document.getElementById( 'cast-address-custom-domain' );
			var namespaceEl = document.getElementById( 'cast-address-custom-namespace' );
			var dnsRadio = checkedAddressRadio( 'cast-address-dns' );
			return {
				source: 'custom',
				domain: domainEl && domainEl.value ? String( domainEl.value ).trim() : '',
				namespace: namespaceEl && namespaceEl.value ? String( namespaceEl.value ) : 'icann',
				dns_hosting_enabled: ! dnsRadio || dnsRadio.value === 'managed'
			};
		}

		if ( source === 'existing' ) {
			var select = document.getElementById( 'cast-address-existing-website' );
			return {
				source: 'existing',
				website_id: select && select.value ? String( select.value ) : ''
			};
		}

		// The platform branch has no fields: the address is always generated
		// (there is no ambiguous optional name to read).
		return {
			source: 'platform',
			label: '',
			generate: true
		};
	}

	/**
	 * Branch-specific validity: a custom domain requires the domain, an
	 * existing attach requires a picked site, a platform address is always
	 * valid (the hostname is optional — an empty one auto-generates).
	 *
	 * @param {object} payload
	 * @return {boolean}
	 */
	function addressPayloadValid( payload ) {
		switch ( payload.source ) {
			case 'custom':
				return payload.domain !== '';
			case 'existing':
				return payload.website_id !== '';
			case 'platform':
				return true;
			default:
				return false;
		}
	}

	/**
	 * The human label for a picked account site (for the review read-out),
	 * from the most recent available-sites list.
	 *
	 * @param {string} websiteId
	 * @return {string}
	 */
	function existingLabelFor( websiteId ) {
		var i;

		for ( i = 0; i < availableWebsites.length; i++ ) {
			if ( String( availableWebsites[ i ].website_id ) === String( websiteId ) ) {
				return availableWebsites[ i ].domain || availableWebsites[ i ].website_name || websiteId;
			}
		}

		return websiteId;
	}

	/**
	 * The plain review read-out value for a payload — the user reviews the
	 * address they already typed/picked, never re-types it.
	 *
	 * @param {object} payload
	 * @return {string}
	 */
	function addressPreviewFor( payload ) {
		switch ( payload.source ) {
			case 'custom':
				return payload.domain;
			case 'existing':
				return existingLabelFor( payload.website_id );
			case 'platform':
				return payload.label !== '' ? payload.label : 'A free Pinner address';
			default:
				return '';
		}
	}

	/**
	 * The full plain review sentence for a payload, per source. A generated
	 * platform address is phrased as Pinner CREATING a free address — never
	 * "available at A free Pinner address", which no such address is.
	 *
	 * @param {object} payload
	 * @return {string}
	 */
	function addressReviewCopyFor( payload ) {
		switch ( payload.source ) {
			case 'platform':
				return 'Pinner will create a free address for your site. You cannot change this address after your first publish.';
			case 'custom':
				return 'Your site will be available at ' + payload.domain + '. You cannot change this address after your first publish.';
			case 'existing':
				return 'Your site will be available at ' + existingLabelFor( payload.website_id ) + '. You cannot change this address after your first publish.';
			default:
				return '';
		}
	}

	/**
	 * Keep the plain review read-out and the confirm button in step with the
	 * visible fields: the read-out shows the pending address (hidden until the
	 * payload is valid) and the confirm button only arms for a valid payload.
	 * The button's label also mirrors the latest report: it promises the
	 * publish only while a first publish can start right now.
	 */
	function updateAddressReview() {
		var payload = buildAddressPayload();
		var valid = addressPayloadValid( payload );

		var box = el( '.cast-address-review-box' );
		if ( box ) {
			box.hidden = ! valid;
		}
		setText( '.cast-address-review-copy', valid ? addressReviewCopyFor( payload ) : '' );

		var confirm = el( '.cast-address-confirm' );
		if ( confirm ) {
			confirm.disabled = ! valid || addressBusy;
			setText( '.cast-address-confirm', addressConfirmLabelFor( lastStatus ) );
		}
	}

	/**
	 * Show exactly one of the existing-site picker's states — loading, error
	 * or empty ("ready" shows none) — so the picker never silently does
	 * nothing: a fetch in flight says so, a failed fetch says so, and an empty
	 * account says so.
	 *
	 * @param {string} state 'loading' | 'error' | 'empty' | 'ready'
	 * @param {string} [message] The error copy (error state only).
	 */
	function setExistingPickerState( state, message ) {
		var loading = el( '.cast-address-existing-loading' );
		var error = el( '.cast-address-existing-error' );
		var empty = el( '.cast-address-existing-empty' );

		if ( loading ) {
			loading.hidden = state !== 'loading';
		}

		if ( error ) {
			error.hidden = state !== 'error';
			if ( state === 'error' && message ) {
				error.textContent = message;
			}
		}

		if ( empty ) {
			empty.hidden = state !== 'empty';
		}
	}

	/**
	 * Fetch the account's Pinner sites and populate the existing-site picker
	 * (textContent-built options). The picker's loading / error / empty states
	 * are always distinct: a failed or unlisted fetch surfaces the error state
	 * (never a silent no-op), an empty account the empty note, and a success
	 * the options themselves.
	 *
	 * @return {Promise<boolean>}
	 */
	function refreshExistingPicker() {
		if ( ! destinationRouteAvailable( 'website_available' ) ) {
			return Promise.resolve( false );
		}

		setExistingPickerState( 'loading' );

		return post( endpoints.website_available, {
			method: 'GET',
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin'
		} ).then( function ( response ) {
			if ( ! response || ! response.ok ) {
				throw new Error( 'website list failed' );
			}

			return response.json();
		} ).then( function ( data ) {
			if ( ! data || ! data.listed ) {
				throw new Error( 'website list unavailable' );
			}

			availableWebsites = data.websites || [];
			var select = document.getElementById( 'cast-address-existing-website' );
			if ( ! select ) {
				return false;
			}

			var i;
			var option;
			for ( i = 0; i < availableWebsites.length; i++ ) {
				option = document.createElement( 'option' );
				option.value = String( availableWebsites[ i ].website_id || '' );
				option.textContent =
					availableWebsites[ i ].domain || availableWebsites[ i ].website_name || option.value;
				select.appendChild( option );
			}

			setExistingPickerState( availableWebsites.length > 0 ? 'ready' : 'empty' );

			return true;
		} ).catch( function () {
			setExistingPickerState( 'error', 'We could not load your Pinner sites. Please try again.' );
			return false;
		} );
	}

	/**
	 * Hold the confirm button while a save/confirm request is in flight
	 * (duplicate-click guard; the button re-arms when the flow settles).
	 *
	 * @param {boolean} working
	 */
	function setAddressConfirmWorking( working ) {
		var confirm = el( '.cast-address-confirm' );
		if ( confirm ) {
			confirm.disabled = working;
			confirm.setAttribute( 'aria-busy', working ? 'true' : 'false' );
		}
	}

	/**
	 * Start the first publish from the address wizard: a direct, nonce- and
	 * action-allowlist-gated POST to the start route (like every other
	 * action) that returns the parsed result — `true` when the run queued,
	 * `{ refusal }` when the server's start gate refused, `false` when the
	 * route is unavailable or the request failed — so the wizard can state
	 * truthfully what happened.
	 *
	 * @return {Promise<true|{refusal: ?string}|false>}
	 */
	function startFirstPublishFromWizard() {
		if ( ! nonce || ! post || ! endpoints || ! endpoints.start || actions.indexOf( 'start' ) === -1 ) {
			return Promise.resolve( false );
		}

		return post( endpoints.start, {
			method: 'POST',
			headers: {
				'X-WP-Nonce': nonce,
				'Content-Type': 'application/json'
			},
			credentials: 'same-origin',
			body: JSON.stringify( {} )
		} ).then( function ( response ) {
			if ( ! response || ! response.ok ) {
				return false;
			}

			return response.json();
		} ).then( function ( data ) {
			if ( ! data ) {
				return false;
			}

			if ( data.queued === true ) {
				return true;
			}

			return { refusal: data.refusal || null };
		} ).catch( function () {
			return false;
		} );
	}

	/**
	 * The one-step confirm: save the destination, confirm it, re-fetch status
	 * and — only while the fresh report says a first publish can start — start
	 * the run. A not-publish-ready site (e.g. no eligible content yet) keeps
	 * its confirmed address with NO publish attempt, and the copy says exactly
	 * that. A refusal at any step surfaces friendly copy on the wizard's lines
	 * and nothing downstream fires.
	 *
	 * @return {Promise<boolean>}
	 */
	function confirmAddress() {
		if ( addressBusy ) {
			return Promise.resolve( false );
		}

		// A stale or polled confirmed/created status never reaches the
		// destination routes: the choice is frozen, so the mutation is refused
		// client-side with the same copy the server's refusal would carry.
		var lifecycle = destinationLifecycleOf( lastStatus );
		if ( lifecycle !== '' && lifecycle !== 'draft' ) {
			showAddressWizardError(
				destinationRefusalCopy( lifecycle === 'confirmed' ? 'confirmed_cannot_change' : 'created_or_attached_cannot_change' )
			);
			return Promise.resolve( false );
		}

		if ( ! destinationRouteAvailable( 'destination_save' ) || ! destinationRouteAvailable( 'destination_confirm' ) ) {
			showAddressWizardError( 'The address could not be saved. Please try again.' );
			return Promise.resolve( false );
		}

		var payload = buildAddressPayload();
		if ( ! addressPayloadValid( payload ) ) {
			showAddressWizardError(
				payload.source === 'existing' ? 'Please choose a site.' : 'Please enter the domain you own.'
			);
			return Promise.resolve( false );
		}

		addressBusy = true;
		setAddressConfirmWorking( true );
		showAddressWizardResult( 'Saving your address…' );

		var settle = function () {
			addressBusy = false;
			setAddressConfirmWorking( false );
		};

		return post( endpoints.destination_save, {
			method: 'POST',
			headers: {
				'X-WP-Nonce': nonce,
				'Content-Type': 'application/json'
			},
			credentials: 'same-origin',
			body: JSON.stringify( payload )
		} ).then( function ( response ) {
			if ( ! response || ! response.ok ) {
				throw new Error( 'destination save failed' );
			}

			return response.json();
		} ).then( function ( data ) {
			if ( ! data || data.status === 'refused' ) {
				showAddressWizardError( destinationRefusalCopy( data && data.refusal ) );
				announce( 'The address could not be saved.' );
				settle();
				return null;
			}

			showAddressWizardResult( 'Confirming your address…' );

			return post( endpoints.destination_confirm, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json'
				},
				credentials: 'same-origin',
				body: JSON.stringify( payload )
			} ).then( function ( response ) {
				if ( ! response || ! response.ok ) {
					throw new Error( 'destination confirm failed' );
				}

				return response.json();
			} ).then( function ( confirmData ) {
				if ( ! confirmData || confirmData.status === 'refused' ) {
					showAddressWizardError( destinationRefusalCopy( confirmData && confirmData.refusal ) );
					announce( 'The address could not be confirmed.' );
					settle();
					return null;
				}

				settle();

				// The address is now immutable. Whether this click ALSO starts
				// the first publish is the server's readiness call, re-read from
				// the fresh report — the server owns the real start policy, this
				// only mirrors it client-side.
				return fetchStatus().then( function () {
					if ( ! ( lastStatus && ! lastStatus.identity && can( 'start', lastStatus ) ) ) {
						showAddressWizardResult(
							'Your address is confirmed. Publishing is available once your site has publishable content.'
						);
						announce( 'Your address is confirmed.' );
						closeAddressWizard();
						return true;
					}

					showAddressWizardResult( 'Address confirmed. Starting your first publish…' );

					return startFirstPublishFromWizard().then( function ( started ) {
						if ( started === true ) {
							showAddressWizardResult( 'Your address is confirmed and your first publish is queued.' );
							announce( 'Your first publish is queued.' );
							closeAddressWizard();
						} else if ( started && started.refusal ) {
							// The race: the report looked ready, the server's start
							// gate refused (content changed between report and
							// start). The address IS confirmed — say so plus the
							// refusal copy, and keep the wizard open to be read.
							showAddressWizardResult( 'Your address is confirmed. ' + startRefusalCopy( started.refusal ) );
							announce( 'Your address is confirmed.' );
						} else {
							showAddressWizardError(
								'Your address is confirmed, but the publish could not be started. Please try again.'
							);
						}
						return fetchStatus();
					} );
				} );
			} );
		} ).catch( function () {
			showAddressWizardError( 'The address could not be saved. Please try again.' );
			announce( 'The address could not be saved.' );
			settle();
			return false;
		} );
	}

	/**
	 * Bind the address surface: the choose/review/confirm controls
	 * ([data-cast-address-action]; an unknown action is inert), the source
	 * radios' branch switching, the DNS radios + existing-site select review
	 * updates, and the existing-site picker fetch. A forged control never
	 * reaches a destination route.
	 */
	function bindAddressWizard() {
		var controls = document.querySelectorAll( '[data-cast-address-action]' );
		var i;
		var control;
		var action;

		for ( i = 0; i < controls.length; i++ ) {
			control = controls[ i ];
			action = control.getAttribute( 'data-cast-address-action' );

			( function ( action ) {
				control.addEventListener( 'click', function () {
					if ( action === 'choose' || action === 'review' ) {
						openAddressWizard();
					} else if ( action === 'confirm' ) {
						confirmAddress();
					}
					// Any other action is unknown and inert.
				} );
			} )( action );
		}

		var radios = document.querySelectorAll( 'input[name="cast-address-source"]' );
		for ( i = 0; i < radios.length; i++ ) {
			( function ( radio ) {
				radio.addEventListener( 'change', function () {
					revealAddressBranch( radio.value );
					updateAddressReview();
					if ( radio.value === 'existing' ) {
						refreshExistingPicker();
					}
				} );
			} )( radios[ i ] );
		}

		var dnsRadios = document.querySelectorAll( 'input[name="cast-address-dns"]' );
		for ( i = 0; i < dnsRadios.length; i++ ) {
			dnsRadios[ i ].addEventListener( 'change', updateAddressReview );
		}

		var namespaceSelect = document.getElementById( 'cast-address-custom-namespace' );
		if ( namespaceSelect ) {
			namespaceSelect.addEventListener( 'change', function () {
				updateAddressNamespaceNote();
				updateAddressReview();
			} );
		}

		var select = document.getElementById( 'cast-address-existing-website' );
		if ( select ) {
			select.addEventListener( 'change', updateAddressReview );
		}
	}

	/**
	 * Copy one pinned value to the clipboard (the Copy buttons next to the
	 * DNS record values). The value is read from the button's own
	 * data-cast-copy attribute — never re-parsed from the page — and the
	 * clipboard is only touched when the surface offers one.
	 *
	 * @param {string} value
	 */
	function copyToClipboard( value ) {
		if ( window.navigator && window.navigator.clipboard && typeof window.navigator.clipboard.writeText === 'function' ) {
			window.navigator.clipboard.writeText( value );
		}
	}

	/** Bind every [data-cast-copy] Copy button to its pinned value. */
	function bindCopyControls() {
		var controls = document.querySelectorAll( '[data-cast-copy]' );
		var i;

		for ( i = 0; i < controls.length; i++ ) {
			( function ( control ) {
				control.addEventListener( 'click', function () {
					var value = control.getAttribute( 'data-cast-copy' );
					if ( value !== null ) {
						copyToClipboard( value );
					}
				} );
			} )( controls[ i ] );
		}
	}

	/**
	 * Bind the connect-your-domain card's action markers ([data-domain-action]
	 * + [data-domain-id]): only the allowlisted validate/dns/ssl operations
	 * may fire — a forged marker is inert, like a forged publish button.
	 */
	function bindDomainMarkers() {
		var markers = document.querySelectorAll( '[data-domain-action]' );
		var i;

		for ( i = 0; i < markers.length; i++ ) {
			( function ( marker ) {
				marker.addEventListener( 'click', function () {
					var action = domainActionForMarker( marker.getAttribute( 'data-domain-action' ) );
					var domainId = marker.getAttribute( 'data-domain-id' );

					if ( ! action ) {
						return;
					}

					if ( action === 'domain_validate' ) {
						domainValidate( domainId );
					} else if ( action === 'domain_dns' ) {
						domainDns( domainId );
					} else if ( action === 'domain_ssl' ) {
						domainSsl( domainId );
					}
				} );
			} )( markers[ i ] );
		}
	}

	/* ------------------------------ buttons ----------------------------- */

	/**
	 * Bind the server-rendered action buttons. A button's action is read from
	 * data-cast-publish-action and validated by the localized allowlist, so a
	 * forged button can never reach a route.
	 */
	function bindButtons() {
		var buttons = document.querySelectorAll( '[data-cast-publish-action]' );
		var i;

		for ( i = 0; i < buttons.length; i++ ) {
			( function ( button ) {
				button.addEventListener( 'click', function () {
					perform(
						button.getAttribute( 'data-cast-publish-action' ),
						button.getAttribute( 'data-cast-publish-value' )
					);
				} );
			} )( buttons[ i ] );
		}
	}

	/**
	 * Bind the server-rendered manual Refresh control
	 * ([data-cast-publish-refresh]). It is a read-only client control — it
	 * never POSTs, so it is deliberately NOT part of the action allowlist — and
	 * without JS it simply renders inert (progressive enhancement).
	 */
	function bindRefreshControls() {
		var controls = document.querySelectorAll( '[data-cast-publish-refresh]' );
		var i;

		for ( i = 0; i < controls.length; i++ ) {
			( function ( control ) {
				control.addEventListener( 'click', function () {
					refreshStatus();
				} );
			} )( controls[ i ] );
		}
	}

	/**
	 * Start the orchestrator: bind action buttons + the Refresh control + the
	 * address wizard + the Copy controls + the connect-your-domain markers and
	 * fetch status immediately. Fully inert (no requests, no bindings) without
	 * a nonce or endpoint, so a partial/forged localized payload can never
	 * talk to the REST surface.
	 */
	function init() {
		if ( ! nonce || ! post || ! endpoints ) {
			return;
		}

		bindButtons();
		bindRefreshControls();
		bindAddressWizard();
		bindCopyControls();
		bindDomainMarkers();
		fetchStatus();
	}

	window.CastPublish = {
		init: init,
		fetchStatus: fetchStatus,
		refreshStatus: refreshStatus,
		applyStatus: applyStatus,
		perform: perform,
		can: can,
		canCancel: canCancel,
		canEscape: canEscape,
		primaryActionFor: primaryActionFor,
		runStateOf: runStateOf,
		runLabelOf: runLabelOf,
		stateLabelOf: stateLabelOf,
		stageLabelOf: stageLabelOf,
		progressPercentOf: progressPercentOf,
		readinessOf: readinessOf,
		readinessLabelOf: readinessLabelOf,
		modeLabelOf: modeLabelOf,
		modeNameOf: modeNameOf,
		modeHelpOf: modeHelpOf,
		contextOf: contextOf,
		progressCountLabelOf: progressCountLabelOf,
		queuedElapsedSeconds: queuedElapsedSeconds,
		queuedEtaSecondsLeft: queuedEtaSecondsLeft,
		formatElapsed: formatElapsed,
		formatRemaining: formatRemaining,
		startWithinSeconds: startWithinSeconds,
		START_NOW_WAIT_SECONDS: START_NOW_WAIT_SECONDS,
		isLiveRun: isLiveRun,
		fingerprint: fingerprint,
		workingMessage: workingMessage,
		syncActions: syncActions,
		domainValidate: domainValidate,
		dnsLabelFor: dnsLabelFor,
		domainDns: domainDns,
		domainSsl: domainSsl,
		// The "Your site address" wizard (S5) surface.
		renderAddressCard: renderAddressCard,
		destinationLifecycleOf: destinationLifecycleOf,
		openAddressWizard: openAddressWizard,
		closeAddressWizard: closeAddressWizard,
		buildAddressPayload: buildAddressPayload,
		addressPayloadValid: addressPayloadValid,
		updateAddressNamespaceNote: updateAddressNamespaceNote,
		refreshExistingPicker: refreshExistingPicker,
		confirmAddress: confirmAddress,
		destinationRefusalCopy: destinationRefusalCopy,
		destinationRouteAvailable: destinationRouteAvailable
	};

	window.CastPublish.init();
} )( window, document );
