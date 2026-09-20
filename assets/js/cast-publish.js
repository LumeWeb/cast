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
 *   - DOMAIN — the W3 "choose a domain" panel read: domainList() renders the
 *     bound domains, domainBind()/domainDelete() POST to the domain routes and
 *     re-fetch the list, domainVerify() re-checks a binding with a BOUNDED
 *     verification poll over the domain list, and domainDns()/domainSsl()/
 *     domainPlatform()/domainAvailability() read the delegation/catalog
 *     routes. Every domain call is gated by the localized domain-endpoint
 *     allowlist + nonce and lands through textContent.
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
			return 'Finish onboarding to publish';
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
				return 'Finish onboarding to publish your site.';
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
			level.hidden = false;
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

		// The guided website card mirrors the awaiting flag: show/build while
		// the run waits for a website, hide the moment it un-parks.
		renderWebsiteCard( status );

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
				// The current mode's button is marked active AND non-actionable
				// (aria-pressed=true + disabled); every other option is clear
				// and selectable again. Any in-flight request holds the whole
				// group until it settles.
				if ( value === ( status ? status.mode : '' ) ) {
					button.className = button.className.replace( /\s*is-active/, '' ) + ' is-active';
					button.setAttribute( 'aria-pressed', 'true' );
					button.disabled = true;
				} else {
					button.className = ( button.className || '' ).replace( /\s*is-active/, '' );
					button.setAttribute( 'aria-pressed', 'false' );
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

	/* ---------------------------- website card (S1) ------------------------ */

	// The guided "sent — waiting for a website" choice card: only live while a
	// parked first publish awaits a website, and only when the server localized
	// the website routes + tight website_actions allowlist (nonce + endpoint
	// required). The card renders server-side for an awaiting page load; a
	// parked run caught mid-poll is built client-side into the root placeholder
	// hosting the exact pinned copy. The card offers exactly two paths (create
	// or link) — there is no "handle it in Pinner" escape. The resume-with-same-
	// CID affordance lives in the action card so the parked operator always has
	// that way out.
	var websiteActions = config.website_actions || [];

	/**
	 * Whether a website client route may be used: the localized nonce, the
	 * fetch surface, the route endpoint and the website-action allowlist must
	 * all exist — a forged or partial localized payload can never reach a
	 * website route.
	 *
	 * @param {string} routeName
	 * @return {boolean}
	 */
	function websiteRouteAvailable( routeName ) {
		return !!( nonce && post && endpoints && endpoints[ routeName ] && websiteActions.indexOf( routeName ) !== -1 );
	}

	/**
	 * Attach a click listener to one website marker. Called for server-rendered
	 * markers at init and for client-built markers as they are created.
	 *
	 * @param {Element} marker
	 */
	function bindWebsiteMarker( marker ) {
		marker.addEventListener( 'click', function () {
			fireWebsiteMarker( marker );
		} );
	}

	/**
	 * Build the guided website card client-side (document.createElement +
	 * textContent only — never innerHTML) for the no-refresh case where the
	 * server had nothing to render because the page loaded before the run
	 * parked. Mirrors the server-pinned copy in templates/admin/publish.php
	 * exactly.
	 *
	 * @return {Element}
	 */
	function buildWebsiteCard() {
		var section = document.createElement( 'section' );
		section.className = 'card cast-website-card';

		var heading = document.createElement( 'h2' );
		heading.textContent = 'Publish to Pinner needs a website.';
		section.appendChild( heading );

		var subline = document.createElement( 'p' );
		subline.className = 'cast-website-subline';
		subline.textContent = 'A website tells Pinner where to serve your upload. Create one, or link one you already own.';
		section.appendChild( subline );

		var cid = document.createElement( 'p' );
		cid.className = 'cast-website-cid';
		section.appendChild( cid );

		// Path (a): create a new website. Hostname optional; when empty the
		// platform domain would be auto-generated, so the card requires the
		// explicit confirmation below before any create POST (no silent
		// auto-create anywhere in the no-refresh path).
		var create = document.createElement( 'div' );
		create.className = 'cast-website-path cast-website-path-create';

		var createHeading = document.createElement( 'h3' );
		createHeading.textContent = 'Create a new website';
		create.appendChild( createHeading );

		var createLabel = document.createElement( 'label' );
		createLabel.className = 'cast-website-hostname-label';
		createLabel.textContent = 'Web address (optional)';
		create.appendChild( createLabel );

		var hostname = document.createElement( 'input' );
		hostname.className = 'cast-website-hostname';
		hostname.setAttribute( 'type', 'text' );
		hostname.setAttribute( 'autocomplete', 'off' );
		hostname.setAttribute( 'placeholder', 'e.g. mysite.com' );
		create.appendChild( hostname );

		var createButton = document.createElement( 'button' );
		createButton.className = 'button button-primary cast-website-create';
		createButton.setAttribute( 'data-website-action', 'create' );
		createButton.textContent = 'Create website';
		create.appendChild( createButton );
		bindWebsiteMarker( createButton );

		var confirmText = document.createElement( 'p' );
		confirmText.className = 'cast-website-create-confirm';
		confirmText.hidden = true;
		confirmText.textContent = 'Platform domain will be auto-generated — continue?';
		create.appendChild( confirmText );

		var confirmButton = document.createElement( 'button' );
		confirmButton.className = 'button cast-website-create-confirm-btn';
		confirmButton.setAttribute( 'data-website-action', 'create-confirm' );
		confirmButton.hidden = true;
		confirmButton.textContent = 'Yes, auto-generate';
		create.appendChild( confirmButton );
		bindWebsiteMarker( confirmButton );

		section.appendChild( create );

		// Path (b): link an existing website the account owns. The list
		// payload carries no "linked to another workspace" marker, so every
		// row is offered; an attach conflict surfaces as a typed refusal once
		// the link action runs.
		var link = document.createElement( 'div' );
		link.className = 'cast-website-path cast-website-path-link';

		var linkHeading = document.createElement( 'h3' );
		linkHeading.textContent = 'Link a website you already have';
		link.appendChild( linkHeading );

		var picker = document.createElement( 'ul' );
		picker.className = 'cast-website-picker';
		link.appendChild( picker );

		var empty = document.createElement( 'p' );
		empty.className = 'cast-website-link-empty';
		empty.hidden = true;
		empty.textContent = 'No websites available to link. Create one, or handle it in Pinner.';
		link.appendChild( empty );

		section.appendChild( link );

		var error = document.createElement( 'p' );
		error.className = 'cast-website-error notice notice-error';
		error.hidden = true;
		section.appendChild( error );

		var result = document.createElement( 'p' );
		result.className = 'cast-website-result';
		section.appendChild( result );

		return section;
	}

	/**
	 * Render the guided website card from one status report: reveal it while
	 * the run awaits a website, build it client-side when a park was caught
	 * mid-poll, quote the preserved CID, refresh the link picker and reset the
	 * create confirmation. Every write goes through textContent / hidden —
	 * never innerHTML.
	 *
	 * @param {object} status
	 */
	function renderWebsiteCard( status ) {
		status = status || {};

		var root = el( '.cast-website-root' );
		var card = el( '.cast-website-card' );

		if ( ! status.awaiting_website ) {
			if ( root && card ) {
				card.hidden = true;
			}
			if ( root && document.createElement ) {
				root.replaceChildren();
			}
			return;
		}

		// A park caught after this page loaded had no server-rendered card;
		// build the mirror into the root placeholder once.
		if ( ! card && root && document.createElement ) {
			card = buildWebsiteCard();
			root.appendChild( card );
			card = el( '.cast-website-card' );
		}

		if ( ! card ) {
			return;
		}

		card.hidden = false;

		// The preserved CID the operator matches in Pinner: the top-level
		// publish_cid is null for a resumable park, so this is the only quote.
		setText( '.cast-website-cid', status.awaiting_cid ? 'Preserved CID: ' + status.awaiting_cid : '' );

		resetCreateConfirm();
		refreshWebsitePicker();
		showWebsiteError( '' );
		setText( '.cast-website-result', '' );
	}

	/**
	 * Collapse the guided card back to its initial, pre-confirmation state —
	 * the empty-hostname confirm line/button only appear the moment they are
	 * needed, and a later status report must never leave a stale confirm
	 * visible.
	 */
	function resetCreateConfirm() {
		var confirmText = el( '.cast-website-create-confirm' );
		var confirmButton = el( '.cast-website-create-confirm-btn' );

		if ( confirmText ) {
			confirmText.hidden = true;
		}
		if ( confirmButton ) {
			confirmButton.hidden = true;
		}
	}

	/**
	 * Fetch the account's websites for the link picker and render them. A
	 * failed read surfaces through the card error line and the picker empty
	 * state — never a silent partial list.
	 */
	function refreshWebsitePicker() {
		if ( ! websiteRouteAvailable( 'website_available' ) ) {
			return;
		}

		post( endpoints.website_available, {
			method: 'GET',
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin'
		} ).then( function ( response ) {
			if ( ! response || ! response.ok ) {
				renderWebsitePicker( null );
				return null;
			}

			return response.json();
		} ).then( function ( data ) {
			if ( ! data ) {
				return null;
			}

			renderWebsitePicker( data );
			return null;
		} ).catch( function () {
			renderWebsitePicker( null );
		} );
	}

	/**
	 * Render one available-website picker payload (or null on a failed read)
	 * into the card: refused reads and empty lists share the empty state, a
	 * refusal is also surfaced on the card error line. Rows are rebuilt with
	 * createElement + textContent so no value can become markup.
	 *
	 * @param {?object} data
	 */
	function renderWebsitePicker( data ) {
		data = data || {};
		var rows = data.websites || [];
		var refused = ! data.listed;
		var picker = el( '.cast-website-picker' );
		var empty = el( '.cast-website-link-empty' );

		if ( ! picker ) {
			return;
		}

		// Rebuild the picker from scratch on every read; rows carry only
		// textContent, so no value can become markup.
		picker.replaceChildren ? picker.replaceChildren() : ( picker.children = [] );

		if ( refused ) {
			showWebsiteError( websiteRefusalCopy( data.refusal || 'list_failed' ) );
		}

		if ( refused || rows.length === 0 ) {
			if ( empty ) {
				empty.hidden = false;
			}
			return;
		}

		if ( empty ) {
			empty.hidden = true;
		}

		for ( var i = 0; i < rows.length; i++ ) {
			picker.appendChild( buildWebsitePickerRow( rows[ i ] ) );
		}
	}

	/**
	 * One picker row — a button carrying the website id so a click links that
	 * website. The domain names the row; the status is shown when present.
	 *
	 * @param {object} row
	 * @return {Element}
	 */
	function buildWebsitePickerRow( row ) {
		row = row || {};

		var item = document.createElement( 'li' );
		var button = document.createElement( 'button' );
		var name = document.createElement( 'span' );

		name.className = 'cast-website-picker-name';
		name.textContent = row.domain || ( 'Website ' + ( row.website_id || '' ) );
		button.className = 'cast-website-picker-row';
		button.setAttribute( 'data-website-action', 'link' );
		button.setAttribute( 'data-website-id', String( row.website_id || '' ) );
		button.appendChild( name );

		if ( row.status ) {
			var status = document.createElement( 'span' );
			status.className = 'cast-website-picker-status';
			status.textContent = String( row.status );
			button.appendChild( status );
		}

		item.appendChild( button );
		bindWebsiteMarker( button );

		return item;
	}

	/**
	 * Dispatch one website marker. Only the allowlisted marker actions are
	 * handled; an unknown marker is inert.
	 *
	 * @param {Element} marker
	 */
	function fireWebsiteMarker( marker ) {
		var action = marker.getAttribute( 'data-website-action' );

		switch ( action ) {
			case 'create':
				handleCreateRequest();
				break;
			case 'create-confirm':
				confirmCreateRequest();
				break;
			case 'link':
				websiteLinkAction( marker.getAttribute( 'data-website-id' ) || '' );
				break;
		}
	}

	/**
	 * The create path's explicit-confirmation gate. A named hostname is an
	 * explicit create and POSTs immediately; an EMPTY hostname means the
	 * platform domain would be auto-generated, so the card first reveals the
	 * confirmation line + "Yes, auto-generate" button — nothing is POSTed
	 * until the operator confirms (no silent auto-create).
	 */
	function handleCreateRequest() {
		var hostnameNode = el( '.cast-website-hostname' );
		var hostname = hostnameNode
			? String( hostnameNode.value || hostnameNode.getAttribute( 'value' ) || '' )
			: '';

		var confirmText = el( '.cast-website-create-confirm' );
		var confirmButton = el( '.cast-website-create-confirm-btn' );

		if ( hostname === '' ) {
			if ( confirmText ) {
				confirmText.hidden = false;
			}
			if ( confirmButton ) {
				confirmButton.hidden = false;
			}
			return;
		}

		if ( confirmText ) {
			confirmText.hidden = true;
		}
		if ( confirmButton ) {
			confirmButton.hidden = true;
		}

		websiteCreateAction( hostname );
	}

	/**
	 * The explicit empty-hostname confirmation: the operator accepted the
	 * auto-generated platform domain, so the create POST may fire.
	 */
	function confirmCreateRequest() {
		resetCreateConfirm();
		websiteCreateAction( '' );
	}

	/**
	 * Re-fetch everything after a website-card mutation (a create or link
	 * action, success OR refusal). The status report re-renders the card —
	 * hiding it the moment the run un-parks — and the available list re-fills
	 * the link picker, so a mutation never leaves a stale awaiting card, a
	 * stale picker, or a wedged spinner: the card always reflects the server's
	 * current reality instead of waiting for the next poll tick.
	 *
	 * An optional refusal copy is written only AFTER the refresh settles, so a
	 * still-awaiting card's render (which clears the error and result lines)
	 * cannot wipe the message a refusal just surfaced — the spinner is cleared
	 * first, then the copy lands on the error line.
	 *
	 * @param {?string} refusalCopy
	 */
	function refreshWebsiteState( refusalCopy ) {
		refreshWebsitePicker();
		fetchStatus().then( function () {
			if ( refusalCopy ) {
				showWebsiteError( refusalCopy );
			}
		} );
	}

	/**
	 * POST the explicit create to the localized route. On success the run
	 * un-parks on the next status fetch (the identity binding was recorded
	 * server-side); a refusal is surfaced on the card error line. Either way
	 * the card re-fetches status AND the available list and re-renders, so the
	 * awaiting card disappears as soon as the server reports the link and the
	 * picker always shows the freshly created website.
	 *
	 * @param {string} hostname
	 */
	function websiteCreateAction( hostname ) {
		if ( ! websiteRouteAvailable( 'website_create' ) ) {
			announce( 'This action is not available right now.' );
			return;
		}

		setText( '.cast-website-result', 'Creating your website…' );

		post( endpoints.website_create, {
			method: 'POST',
			headers: {
				'X-WP-Nonce': nonce,
				'Content-Type': 'application/json'
			},
			credentials: 'same-origin',
			body: JSON.stringify( { hostname: hostname || '' } )
		} ).then( function ( response ) {
			if ( ! response || ! response.ok ) {
				setText( '.cast-website-result', '' );
				refreshWebsiteState( websiteRefusalCopy( 'create_failed' ) );
				return null;
			}

			return response.json();
		} ).then( function ( data ) {
			if ( ! data ) {
				return null;
			}

			if ( data.created ) {
				setText( '.cast-website-result', 'Website created — resuming your publish.' );
				announce( 'Website created — resuming your publish.' );
				refreshWebsiteState();
				return null;
			}

			setText( '.cast-website-result', '' );
			refreshWebsiteState( websiteRefusalCopy( data.refusal || 'create_failed' ) );
			return null;
		} ).catch( function () {
			setText( '.cast-website-result', '' );
			refreshWebsiteState( websiteRefusalCopy( 'create_failed' ) );
		} );
	}

	/**
	 * POST the link action to the localized route. On success the run un-parks
	 * on the next status fetch; a refusal — including an "already linked to
	 * another workspace" conflict, which the list payload cannot express — is
	 * surfaced on the card error line. Either way the card re-fetches status
	 * AND the available list and re-renders, so the "Linking…" spinner always
	 * clears and a 409-refusal shows the copy while the refreshed status shows
	 * the real (already linked) state instead of a dead awaiting card.
	 *
	 * @param {string} websiteId
	 */
	function websiteLinkAction( websiteId ) {
		if ( ! websiteRouteAvailable( 'website_link' ) ) {
			announce( 'This action is not available right now.' );
			return;
		}

		setText( '.cast-website-result', 'Linking your website…' );

		post( endpoints.website_link, {
			method: 'POST',
			headers: {
				'X-WP-Nonce': nonce,
				'Content-Type': 'application/json'
			},
			credentials: 'same-origin',
			body: JSON.stringify( { website_id: websiteId } )
		} ).then( function ( response ) {
			if ( ! response || ! response.ok ) {
				setText( '.cast-website-result', '' );
				refreshWebsiteState( websiteRefusalCopy( 'link_failed' ) );
				return null;
			}

			return response.json();
		} ).then( function ( data ) {
			if ( ! data ) {
				return null;
			}

			if ( data.linked ) {
				setText( '.cast-website-result', 'Website linked — resuming your publish.' );
				announce( 'Website linked — resuming your publish.' );
				refreshWebsiteState();
				return null;
			}

			setText( '.cast-website-result', '' );
			refreshWebsiteState( websiteRefusalCopy( data.refusal || 'link_failed' ) );
			return null;
		} ).catch( function () {
			setText( '.cast-website-result', '' );
			refreshWebsiteState( websiteRefusalCopy( 'link_failed' ) );
		} );
	}

	/**
	 * The fixed end-user line for a website-card refusal. Only the typed
	 * refusal code is mapped; the server never surfaces the wrapped exception
	 * message, so no internal detail can leak through this copy.
	 *
	 * @param {?string} refusal
	 * @return {string}
	 */
	function websiteRefusalCopy( refusal ) {
		switch ( refusal ) {
			case 'website_already_linked':
				return 'This website is already used by another workspace. Pick another, or handle it in Pinner.';
			case 'workspace_already_linked':
				return 'Your workspace already has a website linked. Handle it in Pinner, then try again.';
			case 'link_failed':
				return 'That website could not be linked — it may already belong to another workspace. Pick another, or handle it in Pinner.';
			case 'create_failed':
				return 'The website could not be created. Try again, or handle it in Pinner.';
			case 'no_workspace':
				return 'The workspace could not be resolved. Check the connection, then try again.';
			case 'invalid_website_id':
				return 'That website could not be selected. Refresh and try again.';
			case 'unavailable':
				return 'Website setup is unavailable until the connection is complete.';
			case 'not_awaiting_website':
				return 'The run is no longer waiting for a website.';
			case 'list_failed':
				return 'The website list could not be loaded. Try again, or handle it in Pinner.';
			default:
				return 'That could not be completed right now. Try again, or handle it in Pinner.';
		}
	}

	/**
	 * Reveal the card's error line with the given copy (or hide it when the
	 * copy is empty).
	 *
	 * @param {string} message
	 */
	function showWebsiteError( message ) {
		var errorNode = el( '.cast-website-error' );

		setText( '.cast-website-error', message || '' );

		if ( errorNode ) {
			errorNode.hidden = ! message;
		}
	}

	/**
	 * Bind the server-rendered website card markers ([data-website-action]) at
	 * init; client-built cards bind their own markers as they are created.
	 */
	function bindWebsiteMarkers() {
		var markers = document.querySelectorAll( '[data-website-action]' );
		var i;

		for ( i = 0; i < markers.length; i++ ) {
			bindWebsiteMarker( markers[ i ] );
		}
	}

	/* --------------------------- domain surface (W3) ----------------------- */

	// The W3 "choose a domain" orchestrator: list/bind/verify/delete/DNS/SSL
	// plus the pre-bind platform catalog and availability reads. The panel is
	// only live when the server localized the domain endpoints + allowlist (a
	// complete portal identity); without them every domain call is inert, so a
	// partial or forged payload can never reach a domain route.
	var domainActions = config.domain_actions || [];
	var verifyPoll = config.verify_poll || {};
	var verifyIntervalMs = verifyPoll.intervalMs || 0;
	var verifyMaxAttempts = verifyPoll.maxAttempts || 0;

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
			case 'bind':
				return 'domain_bind';
			case 'verify':
				return 'domain_verify';
			case 'validate':
				return 'domain_validate';
			case 'delete':
				return 'domain_delete';
			case 'dns':
				return 'domain_dns';
			case 'ssl':
				return 'domain_ssl';
			default:
				return null;
		}
	}

	/**
	 * Fetch the bound-domain list and return the parsed payload (or null when
	 * the read fails / the surface is unavailable). Shared by the bounded
	 * verify poll (which inspects the active state), so the poll and the
	 * public domainList() always agree on one serialization.
	 *
	 * @return {Promise<?object>}
	 */
	function fetchDomainListPayload() {
		if ( ! domainRouteAvailable( 'domain_list' ) ) {
			return Promise.resolve( null );
		}

		return post( endpoints.domain_list, {
			method: 'GET',
			headers: { 'X-WP-Nonce': nonce },
			credentials: 'same-origin'
		} ).then( function ( response ) {
			if ( ! response || ! response.ok ) {
				return null;
			}

			return response.json();
		} ).catch( function () {
			return null;
		} );
	}

	/**
	 * One bound-domain row, rendered with document.createElement so no value
	 * can become markup — the first span carries the domain name.
	 *
	 * @param {object} row
	 * @return {Element}
	 */
	function renderDomainRow( row ) {
		row = row || {};

		var item = document.createElement( 'li' );
		var name = document.createElement( 'span' );

		name.className = 'cast-domain-name';
		name.textContent = row.domain || '';
		item.className = 'cast-domain-row';
		item.appendChild( name );

		if ( row.namespace ) {
			var ns = document.createElement( 'span' );
			ns.className = 'cast-domain-namespace';
			ns.textContent = row.namespace;
			item.appendChild( ns );
		}

		if ( row.status ) {
			var status = document.createElement( 'span' );
			status.className = 'cast-domain-status';
			status.textContent = row.status;
			item.appendChild( status );
		}

		if ( row.dns_hosting_enabled ) {
			var hosted = document.createElement( 'span' );
			hosted.className = 'cast-domain-hosted';
			hosted.textContent = 'DNS hosted';
			item.appendChild( hosted );
		}

		if ( row.gateway_host ) {
			var gateway = document.createElement( 'span' );
			gateway.className = 'cast-domain-gateway';
			gateway.textContent = row.gateway_host;
			item.appendChild( gateway );
		}

		return item;
	}

	/**
	 * Render a bound-domain list payload into the W3 panel: the state label,
	 * then one of the refused / empty / rows states. Every write goes through
	 * textContent or hidden toggling; rows are rebuilt through createElement.
	 *
	 * @param {object} data
	 */
	function renderDomainList( data ) {
		data = data || {};

		var rows = data.domains || [];
		var refusal = data.listed ? null : ( data.refusal || null );
		var listNode = el( '.cast-domain-list' );
		var rendered = [];
		var i;

		if ( refusal ) {
			setText( '.cast-domain-state', 'Domain data unavailable' );
			setTextAndToggle( '.cast-domain-list-refusal', refusal, true );
			setTextAndToggle( '.cast-domain-list-empty', '', false );
			setTextAndToggle( '.cast-domain-list', '', false );

			if ( listNode ) {
				listNode.replaceChildren();
			}

			return;
		}

		if ( rows.length === 0 ) {
			// The state line names the panel's overall state, the empty line names
			// the list itself — writing the same string to both would render a
			// visible duplicate line, so they stay distinct (mirroring the
			// server-rendered DomainDashboardView pair).
			setText( '.cast-domain-state', 'Choose and manage your domain' );
			setTextAndToggle( '.cast-domain-list-empty', 'No domains bound yet.', true );
			setTextAndToggle( '.cast-domain-list-refusal', '', false );
			setTextAndToggle( '.cast-domain-list', '', false );

			if ( listNode ) {
				listNode.replaceChildren();
			}

			return;
		}

		setText( '.cast-domain-state', 'Choose and manage your domain' );
		setTextAndToggle( '.cast-domain-list', '', true );
		setTextAndToggle( '.cast-domain-list-empty', '', false );
		setTextAndToggle( '.cast-domain-list-refusal', '', false );

		for ( i = 0; i < rows.length; i++ ) {
			rendered.push( renderDomainRow( rows[ i ] ) );
		}

		if ( listNode ) {
			listNode.replaceChildren.apply( listNode, rendered );
		}
	}

	/**
	 * Fetch the bound-domain list once and render the W3 panel. Resolves true
	 * when the server answered (including a typed refusal — itself a rendered
	 * state), false when the surface is unavailable or the fetch failed.
	 *
	 * @return {Promise<boolean>}
	 */
	function domainList() {
		return new Promise( function ( resolve ) {
			if ( ! domainRouteAvailable( 'domain_list' ) ) {
				resolve( false );
				return;
			}

			post( endpoints.domain_list, {
				method: 'GET',
				headers: { 'X-WP-Nonce': nonce },
				credentials: 'same-origin'
			} ).then( function ( response ) {
				if ( ! response || ! response.ok ) {
					announce( 'The domain list could not be refreshed.' );
					resolve( false );
					return null;
				}

				return response.json();
			} ).then( function ( data ) {
				if ( ! data ) {
					resolve( false );
					return;
				}

				renderDomainList( data );
				resolve( true );
			} ).catch( function () {
				announce( 'The domain list could not be refreshed.' );
				resolve( false );
			} );
		} );
	}

	/**
	 * Bind an ICANN/HNS domain to the registered website. POSTs the domain +
	 * namespace to the bind route with the nonce header, announces the result
	 * and re-fetches the list so the panel resyncs. An empty domain is refused
	 * client-side and never reaches the route.
	 *
	 * @param {string} domain
	 * @param {string} namespace
	 * @return {Promise<boolean>}
	 */
	function domainBind( domain, namespace ) {
		return new Promise( function ( resolve ) {
			domain = String( domain || '' ).trim();

			if ( ! domain || ! domainRouteAvailable( 'domain_bind' ) ) {
				resolve( false );
				return;
			}

			post( endpoints.domain_bind, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json'
				},
				credentials: 'same-origin',
				body: JSON.stringify( { domain: domain, namespace: String( namespace || '' ) } )
			} ).then( function ( response ) {
				if ( ! response || ! response.ok ) {
					announce( 'The domain could not be bound.' );
					resolve( false );
					return;
				}

				announce( 'Domain bound.' );
				resolve( true );
				domainList();
			} ).catch( function () {
				announce( 'The domain could not be bound.' );
				resolve( false );
			} );
		} );
	}

	/**
	 * (Re)verify a domain binding: POST the verify route once, then poll the
	 * domain list with a BOUNDED budget (verify_poll interval/maxAttempts)
	 * until the binding flips to 'active'. Confirmation never comes from a
	 * second verify POST — only from the list reflecting the verified state.
	 *
	 * @param {string} domainId
	 * @return {Promise<boolean>}
	 */
	function domainVerify( domainId ) {
		return new Promise( function ( resolve ) {
			if ( ! domainRouteAvailable( 'domain_verify' ) ) {
				resolve( false );
				return;
			}

			post( endpoints.domain_verify, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json'
				},
				credentials: 'same-origin',
				body: JSON.stringify( { domain_id: String( domainId || '' ) } )
			} ).then( function ( response ) {
				if ( ! response || ! response.ok ) {
					announce( 'The domain could not be verified.' );
					resolve( false );
					return;
				}

				pollDomainVerified( String( domainId || '' ), 0, resolve );
			} ).catch( function () {
				announce( 'The domain could not be verified.' );
				resolve( false );
			} );
		} );
	}

	/**
	 * One verification-list poll step. Attempt 0 runs immediately after the
	 * verify POST; each later attempt is spaced by the verify interval. An
	 * 'active' binding resolves true; exhausting the budget resolves false.
	 *
	 * @param {string} domainId
	 * @param {number} attempt
	 * @param {Function} resolve
	 */
	function pollDomainVerified( domainId, attempt, resolve ) {
		fetchDomainListPayload().then( function ( data ) {
			var active = false;
			var i;

			if ( data && data.listed && data.domains ) {
				for ( i = 0; i < data.domains.length; i++ ) {
					if ( String( data.domains[ i ].id ) === domainId && data.domains[ i ].status === 'active' ) {
						active = true;
						break;
					}
				}
			}

			if ( data ) {
				renderDomainList( data );
			}

			if ( active ) {
				announce( 'Domain verified.' );
				resolve( true );
				return;
			}

			if ( attempt + 1 < verifyMaxAttempts ) {
				window.setTimeout( function () {
					pollDomainVerified( domainId, attempt + 1, resolve );
				}, verifyIntervalMs );
				return;
			}

			announce( 'The domain is still pending verification.' );
			resolve( false );
		} );
	}

	/**
	 * Delete (unbind) a domain from the registered website. POSTs the domain
	 * id to the delete route, announces the result and re-fetches the list so
	 * the panel resyncs.
	 *
	 * @param {string} domainId
	 * @return {Promise<boolean>}
	 */
	function domainDelete( domainId ) {
		return new Promise( function ( resolve ) {
			if ( ! domainRouteAvailable( 'domain_delete' ) ) {
				resolve( false );
				return;
			}

			post( endpoints.domain_delete, {
				method: 'POST',
				headers: {
					'X-WP-Nonce': nonce,
					'Content-Type': 'application/json'
				},
				credentials: 'same-origin',
				body: JSON.stringify( { domain_id: String( domainId || '' ) } )
			} ).then( function ( response ) {
				if ( ! response || ! response.ok ) {
					announce( 'The domain could not be deleted.' );
					resolve( false );
					return;
				}

				announce( 'Domain deleted.' );
				resolve( true );
				domainList();
			} ).catch( function () {
				announce( 'The domain could not be deleted.' );
				resolve( false );
			} );
		} );
	}

	/**
	 * The DNS label copy mirroring DomainDashboardView::dnsLabelFor.
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
				markers[ i ].textContent = working ? 'Validating...' : 'Validate DNS';
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

	/**
	 * Read the pre-bind platform-domain catalog (the suffixes a domain may be
	 * bound under). Resolves true when the read succeeded.
	 *
	 * @return {Promise<boolean>}
	 */
	function domainPlatform() {
		return new Promise( function ( resolve ) {
			if ( ! domainRouteAvailable( 'domain_platform' ) ) {
				resolve( false );
				return;
			}

			post( endpoints.domain_platform, {
				method: 'GET',
				headers: { 'X-WP-Nonce': nonce },
				credentials: 'same-origin'
			} ).then( function ( response ) {
				resolve( !!( response && response.ok ) );
			} ).catch( function () {
				resolve( false );
			} );
		} );
	}

	/**
	 * Check platform availability for a chosen label. Resolves true when the
	 * read succeeded.
	 *
	 * @param {string} label
	 * @return {Promise<boolean>}
	 */
	function domainAvailability( label ) {
		return new Promise( function ( resolve ) {
			if ( ! domainRouteAvailable( 'domain_availability' ) ) {
				resolve( false );
				return;
			}

			post( endpoints.domain_availability, {
				method: 'GET',
				headers: { 'X-WP-Nonce': nonce },
				credentials: 'same-origin'
			} ).then( function ( response ) {
				resolve( !!( response && response.ok ) );
			} ).catch( function () {
				resolve( false );
			} );
		} );
	}

	/**
	 * Bind the server-rendered domain action markers. Each marker's action is
	 * read from data-domain-action and translated through the allowlist, so a
	 * forged marker can never reach a domain (or publish) route.
	 */
	function bindDomainMarkers() {
		var markers = document.querySelectorAll( '[data-domain-action]' );
		var i;

		for ( i = 0; i < markers.length; i++ ) {
			( function ( marker ) {
				marker.addEventListener( 'click', function () {
					fireDomainMarker( marker );
				} );
			} )( markers[ i ] );
		}
	}

	/**
	 * Fire one domain marker: map its short action to the allowlisted client
	 * route and pass the marker's data attributes through.
	 *
	 * @param {Element} marker
	 */
	function fireDomainMarker( marker ) {
		var action = domainActionForMarker( marker.getAttribute( 'data-domain-action' ) );

		if ( ! action || domainActions.indexOf( action ) === -1 ) {
			return;
		}

		switch ( action ) {
			case 'domain_bind':
				domainBind(
					marker.getAttribute( 'data-domain' ) || '',
					marker.getAttribute( 'data-domain-namespace' ) || ''
				);
				break;
			case 'domain_verify':
				domainVerify( marker.getAttribute( 'data-domain-id' ) || '' );
				break;
			case 'domain_validate':
				domainValidate( marker.getAttribute( 'data-domain-id' ) || '' );
				break;
			case 'domain_delete':
				domainDelete( marker.getAttribute( 'data-domain-id' ) || '' );
				break;
			case 'domain_dns':
				domainDns( marker.getAttribute( 'data-domain-id' ) || '' );
				break;
			case 'domain_ssl':
				domainSsl( marker.getAttribute( 'data-domain' ) || '' );
				break;
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
	 * Start the orchestrator: bind action buttons + the Refresh control +
	 * domain action markers and fetch status immediately. Fully inert (no
	 * requests, no bindings) without a nonce or endpoint, so a partial/forged
	 * localized payload can never talk to the REST surface.
	 */
	function init() {
		if ( ! nonce || ! post || ! endpoints ) {
			return;
		}

		bindButtons();
		bindRefreshControls();
		bindWebsiteMarkers();
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
		domainList: domainList,
		domainBind: domainBind,
		domainVerify: domainVerify,
		domainValidate: domainValidate,
		dnsLabelFor: dnsLabelFor,
		domainDelete: domainDelete,
		domainDns: domainDns,
		domainSsl: domainSsl,
		domainPlatform: domainPlatform,
		domainAvailability: domainAvailability,
		// The guided website card (S1) surface.
		renderWebsiteCard: renderWebsiteCard,
		buildWebsiteCard: buildWebsiteCard,
		refreshWebsitePicker: refreshWebsitePicker,
		renderWebsitePicker: renderWebsitePicker,
		handleCreateRequest: handleCreateRequest,
		confirmCreateRequest: confirmCreateRequest,
		websiteCreateAction: websiteCreateAction,
		websiteLinkAction: websiteLinkAction,
		websiteRefusalCopy: websiteRefusalCopy,
		websiteRouteAvailable: websiteRouteAvailable
	};

	window.CastPublish.init();
} )( window, document );
