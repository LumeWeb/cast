'use strict';

/**
 * Dependency-free Node tests for the Cast Publish dashboard card orchestrator
 * (assets/js/cast-publish.js).
 *
 * The production asset is a browser IIFE (no module system, no jQuery, no
 * wp.*). The harness evals it with injected window/document/fetch/timer
 * doubles that mirror the WordPress admin surface it talks to:
 *
 *   - the cast/v1 publish REST routes (status GET + start/now/mode/cancel/
 *     artifact POST), every one of which the server guards with the manage_options
 *     capability AND a valid wp_rest nonce (sent as the X-WP-Nonce header);
 *   - the server-rendered publish card DOM (the .cast-publish-* nodes that
 *     templates/admin/publish.php emits) plus an optional #cast-publish-live
 *     aria-live region.
 *
 * The suite pins, in one place, the mapping the server pins in
 * PublishDashboardView (runState/runLabel/stageLabel/progress/readiness/mode)
 * so the no-refresh card can never drift from the server-rendered one, plus
 * the bounded-poll and action-client guarantees: status is fetched against the
 * localized route with the localized nonce, polling stops once the run is
 * terminal / the budget is exhausted / the fetch fails, and every DOM update
 * goes through textContent — never innerHTML.
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// RED: this file must fail while assets/js/cast-publish.js does not exist.
const ASSET = path.join(__dirname, '..', '..', 'assets', 'js', 'cast-publish.js');
const source = fs.readFileSync(ASSET, 'utf8');

// A microtask + macrotask flush so promise chains (fetch then .json then
// applyStatus, poll-scheduling) resolve before assertions run.
const tick = () => new Promise((resolve) => setImmediate(resolve));

function makeNode(className, id) {
	return {
		id: id || '',
		className: className || '',
		textContent: '',
		hidden: false,
		disabled: false,
		style: {},
		listeners: {},
		children: [],
		setAttribute(name, value) {
			this[name] = value;
		},
		getAttribute(name) {
			return this[name] === undefined || this[name] === null ? null : String(this[name]);
		},
		addEventListener(type, cb) {
			this.listeners[type] = cb;
		},
		click() {
			if (this.listeners.click) {
				this.listeners.click();
			}
		},
		appendChild(child) {
			this.children.push(child);
		},
		replaceChildren(...children) {
			this.children = children;
		},
	};
}

function makeButton(action, value, disabled, className) {
	return {
		disabled: !!disabled,
		className: className || '',
		listeners: {},
		getAttribute(attr) {
			if (attr === 'data-cast-publish-action') {
				// setAttribute() (e.g. the primary-action reconciliation)
				// overrides the initial action, matching real DOM semantics.
				if (this[attr] !== undefined && this[attr] !== null) {
					return this[attr] === '' ? '' : String(this[attr]);
				}
				return action;
			}
			if (attr === 'data-cast-publish-value') {
				return value === undefined || value === null ? null : String(value);
			}
			return this[attr] === undefined || this[attr] === null ? null : String(this[attr]);
		},
		setAttribute(name, attrValue) {
			this[name] = attrValue;
		},
		addEventListener(type, cb) {
			this.listeners[type] = cb;
		},
		click() {
			if (this.listeners.click) {
				this.listeners.click();
			}
		},
	};
}

/**
 * A manual timer queue so polling is fully deterministic. Timers fire only when
 * the test flushes them; nothing auto-fires during eval.
 */
function makeTimerQueue() {
	const queue = [];
	let nextId = 1;
	return {
		queue,
		setTimeout(fn, ms) {
			const id = nextId++;
			queue.push({ id, fn, ms });
			return id;
		},
		clearTimeout(id) {
			for (let i = 0; i < queue.length; i++) {
				if (queue[i].id === id) {
					queue.splice(i, 1);
					return;
				}
			}
		},
		runNext() {
			const item = queue.shift();
			if (item) {
				item.fn();
				return true;
			}
			return false;
		},
		count() {
			return queue.length;
		},
	};
}

/** The default ready-but-untouched status, mirroring PublishSetupService. */
function defaultStatus() {
	return {
		bootstrap_identity_complete: true,
		env_problems: [],
		onboarding_complete: true,
		has_eligible_content: true,
		mode: 'manual',
		auto_active: false,
		run_status: 'not_started',
		run_stage: 'idle',
		progress_count: 0,
		pipeline_stage: null,
		progress_total: null,
		capture_done: null,
		rewrite_done: null,
		queued_at: null,
		run_active: false,
		dirty: false,
		superseded: false,
		publish_cid: null,
		last_error: null,
		identity: null,
	};
}

/**
 * Resolve a scripted descriptor for one route: a function (indexed by call),
 * a consumed array (last repeats), or a static object falling back to the
 * fixture so the domain surface always answers deterministically.
 */
function pickDescriptor(value, fallback, calls) {
	if (typeof value === 'function') {
		return value(calls.length - 1);
	}
	if (Array.isArray(value)) {
		return value.length > 1 ? value.shift() : value[0];
	}
	return value || fallback;
}

/**
 * Collect the textContent of every descendant (or self) whose className
 * matches, walking the makeNode() children tree. The rendered guidance nests
 * summary dd/dt rows inside a <dl> (and check rows inside their own dl), so
 * assertions need to read through the whole subtree, not just the direct
 * children of the copy container.
 */
function collectClassText(root, className) {
	const out = [];
	function walk(node) {
		if (node && node.className === className) {
			out.push(node.textContent);
		}
		(node.children || []).forEach(walk);
	}
	walk(root);
	return out;
}

/**
 * Collect the textContent of every node in a subtree (self + descendants),
 * flattening the created DOM so guidance copy (paragraphs, headings, cells,
 * dd/dt rows) can be asserted regardless of nesting.
 */
function collectAllText(root) {
	const out = [];
	function walk(node) {
		if (node && typeof node.textContent === 'string') {
			out.push(node.textContent);
		}
		(node.children || []).forEach(walk);
	}
	walk(root);
	return out;
}

/** A bound-domain row echoing the DomainListResult serialization. */
function defaultDomainRow(overrides) {
	return Object.assign(
		{
			id: '99',
			domain: 'site.example.test',
			namespace: 'icann',
			dns_hosting_enabled: true,
			status: 'waiting_delegation',
			gateway_host: 'gw.example.com',
		},
		overrides || {}
	);
}

/** The delegation-records read (the GET /domains/dns payload). */
function defaultDomainDns() {
	return {
		ok: true,
		status: 'ok',
		domain: defaultDomainRow({ domain: 'name/', namespace: 'hns' }),
		refusal: null,
	};
}

/** A serialized WebsiteValidateResult (the POST /domains/validate payload). */
function defaultDomainValidate() {
	return {
		ok: true,
		status: 'ok',
		validation: {
			id: 42,
			domain: 'site.example.test',
			valid: false,
			message: 'DNS records not yet published.',
			reason: 'dns_validation_failed',
			checks: [
				{
					name: 'dnslink',
					ok: false,
					message: '',
					expected: 'dnslink=/ipns/k-ipns-7',
					found: 'dnslink=/ipfs/QmWrong',
				},
			],
		},
		refusal: null,
	};
}

function defaultDomainSsl() {
	return {
		ok: true,
		status: 'ok',
		ssl: {
			status: 'ready',
			issued_at: '2026-01-02T00:00:00Z',
			last_updated_at: '2026-01-02T00:00:00Z',
			error: null,
		},
		refusal: null,
	};
}

/** A picker row echoing the PublishWebsiteListResult row serialization. */
function defaultWebsiteRow(overrides) {
	return Object.assign(
		{
			website_id: '66',
			domain: 'sub.example.com',
			status: 'active',
			target_hash: 'QmOther',
			target_type: 'ipfs',
		},
		overrides || {}
	);
}

function defaultWebsiteAvailable() {
	return {
		listed: true,
		status: 'ok',
		websites: [defaultWebsiteRow()],
		refusal: null,
	};
}

/** A server-rendered domain action marker ([data-domain-action]) double. */
function makeDomainMarker(action, attrs) {
	return {
		attributes: Object.assign({ 'data-domain-action': action }, attrs || {}),
		listeners: {},
		getAttribute(name) {
			return this.attributes[name] === undefined || this.attributes[name] === null
				? null
				: String(this.attributes[name]);
		},
		// The validate flow toggles aria-busy through setAttribute, so the
		// double must record it like real DOM would (a plain property).
		setAttribute(name, attrValue) {
			this.attributes[name] = attrValue;
		},
		addEventListener(type, cb) {
			this.listeners[type] = cb;
		},
		click() {
			if (this.listeners.click) {
				this.listeners.click();
			}
		},
	};
}

/**
 * A scripted fetch double. `script.status` may be an object, an array of
 * objects (consumed in order, the last repeats), or a function
 * `(fetchCallIndex) => descriptor`. Action endpoints return the matching
 * scripted result (or a synthetic success). A descriptor carrying
 * `{ __httpError: true, status }` resolves to a non-ok Response.
 */
function makeFetch(script, calls) {
	return (url, options) => {
		calls.push({ url, options });
		const status = script.status;
		let descriptor;
		if (url.indexOf('/publish/status') !== -1) {
			if (typeof status === 'function') {
				descriptor = status(calls.length - 1);
			} else if (Array.isArray(status)) {
				descriptor = status.length > 1 ? status.shift() : status[0];
			} else if (status) {
				descriptor = status;
			} else {
				descriptor = defaultStatus();
			}
		} else if (url.indexOf('/publish/mode') !== -1) {
			descriptor = script.mode || { updated: true, status: 'updated', mode: 'on_update', auto_active: true };
		} else if (url.indexOf('/publish/cancel') !== -1) {
			descriptor = script.cancel || { cancelled: true, status: 'cancelled', run_id: 'r-cancel' };
		} else if (url.indexOf('/publish/artifact') !== -1) {
			descriptor = script.artifact || { queued: true, status: 'queued', run_id: 'r-artifact' };
		} else if (url.indexOf('/publish/now') !== -1) {
			descriptor = script.now || { queued: true, status: 'queued', run_id: 'r-now' };
		} else if (url.indexOf('/domains/dns') !== -1) {
			descriptor = pickDescriptor(script.domain_dns, defaultDomainDns(), calls);
		} else if (url.indexOf('/domains/validate') !== -1) {
			descriptor = pickDescriptor(script.domain_validate, defaultDomainValidate(), calls);
		} else if (url.indexOf('/domains/ssl') !== -1) {
			descriptor = pickDescriptor(script.domain_ssl, defaultDomainSsl(), calls);
		} else if (url.indexOf('/website/available') !== -1) {
			descriptor = pickDescriptor(script.website_available, defaultWebsiteAvailable(), calls);
		} else if (url.indexOf('/publish/destination/confirm') !== -1) {
			descriptor = script.destination_confirm || { confirmed: true, status: 'confirmed', refusal: null, lifecycle: 'confirmed' };
		} else if (url.indexOf('/publish/destination') !== -1) {
			if (options && options.method === 'GET') {
				descriptor = script.destination_get || { lifecycle: null, destination: null };
			} else {
				descriptor = script.destination_save || { saved: true, status: 'saved', refusal: null, lifecycle: 'draft' };
			}
		} else {
			descriptor = script.start || { queued: true, status: 'queued', run_id: 'r-start' };
		}

		if (descriptor && descriptor.__httpError) {
			return Promise.resolve({
				ok: false,
				status: descriptor.status || 0,
				json: async () => ({}),
			});
		}

		return Promise.resolve({ ok: true, status: 200, json: async () => descriptor });
	};
}

const DISPLAY_SELECTORS = [
	'.cast-publish-card',
	'.cast-publish-wrap',
	'.cast-publish-state',
	'.cast-publish-state-chip',
	'.cast-publish-run-label',
	'.cast-publish-stage',
	'.cast-publish-last-checked',
	'.cast-publish-last-checked-label',
	'.cast-publish-last-checked-time',
	'.cast-publish-refresh',
	'.cast-publish-progress-value',
	'.cast-publish-progress-count',
	'.cast-publish-progress-track',
	'.cast-publish-progress-fill',
	'.cast-publish-site',
	'.cast-publish-cid',
	'.cast-publish-dirty',
	'.cast-publish-superseded',
	'.cast-publish-error',
	'.cast-publish-readiness-level',
	'.cast-publish-mode',
	// The workflow additions: the "why publish" copy and the result line.
	'.cast-publish-context',
	'.cast-publish-result',
	'.cast-publish-mode-help',
	// The queued recovery/start-now escape (revealed after a wait while queued).
	'.cast-publish-escape',
	// The "Connect your domain" card (custom destinations only) nodes the
	// domain orchestrator updates: the DNS label/copy area and the SSL label.
	'.cast-domain-dns-label',
	'.cast-domain-dns-copy',
	'.cast-domain-ssl-label',
	// The "Your site address" card + inline wizard nodes the address
	// orchestrator updates: the summary (source label + value + durable note +
	// review prompt), the hidden wizard (three native source radios, the three
	// branch field groups, the plain review read-out, the error/result lines
	// and the confirm button).
	'.cast-address-card',
	'.cast-address-prompt',
	'.cast-address-choose',
	'.cast-address-state',
	'.cast-address-source',
	'.cast-address-value',
	'.cast-address-durable',
	'.cast-address-review',
	'.cast-address-wizard',
	// The branches are keyed by the template's data-cast-address-branch
	// attribute (the orchestrator reveals exactly the selected source's).
	'[data-cast-address-branch="platform"]',
	'[data-cast-address-branch="custom"]',
	'[data-cast-address-branch="existing"]',
	'.cast-address-review-box',
	'.cast-address-review-copy',
	'.cast-address-existing-empty',
	// The existing-site picker's distinct loading / error states.
	'.cast-address-existing-loading',
	'.cast-address-existing-error',
	'.cast-address-wizard-error',
	'.cast-address-wizard-result',
	'.cast-address-confirm',
	// The "Connect your domain" card root + the selected-domain line.
	'.cast-domain-setup-card',
	'.cast-domain-setup-domain',
];

const DEFAULT_ENDPOINTS = {
	status: 'http://example.test/wp-json/cast/v1/publish/status',
	start: 'http://example.test/wp-json/cast/v1/publish/start',
	now: 'http://example.test/wp-json/cast/v1/publish/now',
	mode: 'http://example.test/wp-json/cast/v1/publish/mode',
	cancel: 'http://example.test/wp-json/cast/v1/publish/cancel',
	artifact: 'http://example.test/wp-json/cast/v1/publish/artifact',
	domain_dns: 'http://example.test/wp-json/cast/v1/domains/dns',
	domain_validate: 'http://example.test/wp-json/cast/v1/domains/validate',
	domain_ssl: 'http://example.test/wp-json/cast/v1/domains/ssl',
	destination_get: 'http://example.test/wp-json/cast/v1/publish/destination',
	destination_save: 'http://example.test/wp-json/cast/v1/publish/destination',
	destination_confirm: 'http://example.test/wp-json/cast/v1/publish/destination/confirm',
	website_available: 'http://example.test/wp-json/cast/v1/website/available',
};

/**
 * Eval the production asset in a sandbox driven by the options:
 *   - config: localized window.castPublish payload overrides
 *   - script: scripted fetch responses
 *   - buttons: action buttons present in the DOM at init
 *   - liveRegion: pass null to model a template without #cast-publish-live
 *   - fetch: a fully custom fetch double (takes precedence over script)
 */
function load(options) {
	const opts = options || {};
	const calls = [];
	const timers = makeTimerQueue();
	const script = opts.script || {};
	const fetchDouble = opts.fetch || makeFetch(script, calls);
	const buttons = opts.buttons || [];
	const markers = opts.markers || [];
	const addressControls = opts.addressControls || [];
	const copyControls = opts.copyControls || [];
	const refreshControls = opts.refreshControls || [];

	const nodes = {};
	DISPLAY_SELECTORS.forEach((sel) => {
		// The data-attribute branch selectors map onto the template's shared
		// .cast-address-branch class (one class, keyed by the attribute).
		const className = sel.startsWith('[data-') ? 'cast-address-branch' : sel.slice(1);
		nodes[sel] = makeNode(className);
	});

	// Model the server paint: nodes the template renders with the `hidden`
	// attribute start hidden (the orchestrator reveals them).
	(opts.initialHidden || []).forEach((sel) => {
		if (nodes[sel]) {
			nodes[sel].hidden = true;
		}
	});

	const registry = { objects: {}, created: [] };
	// Wizard field nodes addressed by id (the template emits id="cast-address-…"
	// inputs the orchestrator reads through getElementById).
	Object.keys(opts.idNodes || {}).forEach((id) => {
		registry.objects[id] = opts.idNodes[id];
	});
	const liveRegion = opts.liveRegion === undefined ? makeNode('cast-publish-live', 'cast-publish-live') : opts.liveRegion;

	const document = {
		getElementById(id) {
			if (id === 'cast-publish-live' && liveRegion) {
				return liveRegion;
			}
			return registry.objects[id] || null;
		},
		querySelector(sel) {
			return nodes[sel] || registry.objects[sel] || null;
		},
		querySelectorAll(sel) {
			if (sel === '[data-cast-publish-action]') {
				return buttons;
			}
			if (sel === '[data-domain-action]') {
				return markers;
			}
			if (sel === '[data-cast-address-action]') {
				return addressControls;
			}
			if (sel === '[data-cast-copy]') {
				return copyControls;
			}
			if (sel === 'input[name="cast-address-source"]') {
				return opts.addressSourceRadios || [];
			}
			if (sel === 'input[name="cast-address-dns"]') {
				return opts.addressDnsRadios || [];
			}
			if (sel === '[data-cast-namespace-note]') {
				return opts.addressNamespaceNotes || [];
			}
			if (sel === '[data-cast-publish-refresh]') {
				return refreshControls;
			}
			return [];
		},
		createElement(tag) {
			const node = makeNode(tag === 'div' ? 'cast-publish-live' : tag);
			registry.created.push(node);
			return node;
		},
	};

	const config = Object.assign(
		{
			endpoints: DEFAULT_ENDPOINTS,
			nonce: 'wp-rest-nonce',
			actions: ['start', 'now', 'mode', 'cancel', 'artifact'],
			poll: { intervalMs: 50, maxAttempts: 4, backoffMs: 0 },
			domain_actions: [
				'domain_dns',
				'domain_validate',
				'domain_ssl',
			],
			destination_actions: [
				'destination_get',
				'destination_save',
				'destination_confirm',
				'website_available',
			],
		},
		opts.config || {}
	);
	Object.assign(config, { fetch: fetchDouble });

	const window = {
		castPublish: config,
		fetch: fetchDouble,
		navigator: opts.navigator,
		setTimeout: timers.setTimeout,
		clearTimeout: timers.clearTimeout,
	};

	// Execute the real asset; a missing file / broken reference here is a real
	// RED and a broken asset cannot silently pass.
	// eslint-disable-next-line no-new-func
	new Function('window', 'document', source)(window, document);

	return {
		client: window.CastPublish,
		calls,
		timers,
		nodes,
		buttons,
		markers,
		addressControls,
		copyControls,
		refreshControls,
		liveRegion,
		created: registry.created,
		document,
	};
}

/** Every retained node must never have gained an innerHTML property. */
function assertNoInnerHTML(nodes) {
	nodes.forEach((node) => {
		assert.equal(
			Object.prototype.hasOwnProperty.call(node, 'innerHTML'),
			false,
			'the publish orchestrator must never build UI through innerHTML'
		);
	});
}

function statusWith(overrides) {
	return Object.assign(defaultStatus(), overrides || {});
}

/* ------------------------ display mapping (mirror) ------------------------ */

test('runStateOf/runLabelOf mirror the PublishDashboardView run states', () => {
	const { client } = load();
	statusWith;

	const idle = statusWith({ run_status: 'not_started', run_active: false, run_stage: 'idle' });
	assert.equal(client.runStateOf(idle), 'idle');
	assert.equal(client.runLabelOf(idle), 'No publish yet');

	const queued = statusWith({ run_status: 'not_started', run_active: true, run_stage: 'idle' });
	assert.equal(client.runStateOf(queued), 'queued');
	assert.equal(client.runLabelOf(queued), 'Queued to publish');

	assert.equal(client.runLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting' })), 'Exporting content');
	assert.equal(client.runLabelOf(statusWith({ run_status: 'running', run_stage: 'uploading' })), 'Uploading to Pinner');
	assert.equal(client.runLabelOf(statusWith({ run_status: 'running', run_stage: 'publishing' })), 'Publishing');
	// A running run with no meaningful stage falls back to the ellipsis label.
	assert.equal(client.runLabelOf(statusWith({ run_status: 'running', run_stage: 'idle' })), 'Publishing…');

	assert.equal(client.runLabelOf(statusWith({ run_status: 'paused' })), 'Paused');
	assert.equal(client.runLabelOf(statusWith({ run_status: 'completed' })), 'Published');
	assert.equal(client.runLabelOf(statusWith({ run_status: 'completed_with_warnings' })), 'Published with warnings');
	assert.equal(client.runLabelOf(statusWith({ run_status: 'failed' })), 'Publish failed');
	assert.equal(client.runLabelOf(statusWith({ run_status: 'cancelled' })), 'Cancelled');
});

test('stageLabelOf and progressPercentOf mirror the stage table', () => {
	const { client } = load();

	assert.equal(client.stageLabelOf(statusWith({ run_stage: 'exporting' })), 'Exporting content');
	assert.equal(client.stageLabelOf(statusWith({ run_stage: 'uploading' })), 'Uploading to Pinner');
	assert.equal(client.stageLabelOf(statusWith({ run_stage: 'publishing' })), 'Publishing');
	assert.equal(client.stageLabelOf(statusWith({ run_stage: 'idle' })), null);
	assert.equal(client.stageLabelOf(statusWith({ run_stage: 'finished' })), null);

	assert.equal(client.progressPercentOf(statusWith({ run_stage: 'idle' })), 0);
	assert.equal(client.progressPercentOf(statusWith({ run_stage: 'exporting' })), 25);
	assert.equal(client.progressPercentOf(statusWith({ run_stage: 'uploading' })), 55);
	assert.equal(client.progressPercentOf(statusWith({ run_stage: 'publishing' })), 85);
	assert.equal(client.progressPercentOf(statusWith({ run_stage: 'finished' })), 100);
});

test('readinessOf/readinessLabelOf and modeLabelOf mirror the view model', () => {
	const { client } = load();

	assert.deepEqual(client.readinessOf(statusWith({ env_problems: [{ variable: 'PORTAL_API_KEY', kind: 'missing', message: '…' }] })), {
		readiness: 'config',
		level: 'error',
	});
	assert.deepEqual(client.readinessOf(statusWith({ bootstrap_identity_complete: false })), { readiness: 'config', level: 'error' });
	assert.deepEqual(client.readinessOf(statusWith({ onboarding_complete: false })), { readiness: 'setup', level: 'warning' });
	assert.deepEqual(client.readinessOf(statusWith({ has_eligible_content: false })), { readiness: 'no_content', level: 'note' });
	assert.deepEqual(client.readinessOf(statusWith({})), { readiness: 'ready', level: 'ok' });

	assert.equal(client.readinessLabelOf(statusWith({ env_problems: [{ variable: 'X', kind: 'empty', message: '…' }] })), 'Configuration required');
	assert.equal(client.readinessLabelOf(statusWith({ onboarding_complete: false })), 'Finish onboarding to publish');
	assert.equal(client.readinessLabelOf(statusWith({ has_eligible_content: false })), 'No publishable content yet');
	assert.equal(client.readinessLabelOf(statusWith({})), 'Ready to publish');

	assert.equal(client.modeLabelOf(statusWith({ mode: 'manual' })), 'Manual publishing');
	assert.equal(client.modeLabelOf(statusWith({ mode: 'on_update' })), 'Publishes automatically when you update content');

	// Per-option help names the exact scheduler semantics — a click queues a
	// background publish (never synchronous), and eligible content changes
	// queue a debounced/coalesced background publish — and the short mode name
	// feeds the mode-change confirmation. Only Manual and On update exist; the
	// server normalizes a legacy 'scheduled' value to On-update, so the client
	// never labels it.
	assert.equal(client.modeNameOf('manual'), 'Manual');
	assert.equal(client.modeNameOf('on_update'), 'On update');
	assert.equal(
		client.modeHelpOf('manual'),
		'Publish only when you choose. Clicking Publish to Pinner queues a background publish — content edits wait until then and nothing publishes on its own.'
	);
	assert.equal(
		client.modeHelpOf('on_update'),
		'Publish automatically. Eligible content changes queue a background publish for you — rapid edits are combined into one debounced publish instead of one per save.'
	);

	// The explicit queue/run status chip labels mirror the view-model mapping.
	assert.equal(client.stateLabelOf(statusWith({})), 'Ready');
	assert.equal(client.stateLabelOf(statusWith({ run_status: 'not_started', run_active: true })), 'Queued — starting shortly');
	assert.equal(client.stateLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting' })), 'Publishing');
	assert.equal(client.stateLabelOf(statusWith({ run_status: 'paused' })), 'Paused');
	assert.equal(client.stateLabelOf(statusWith({ run_status: 'completed' })), 'Published');
	assert.equal(client.stateLabelOf(statusWith({ run_status: 'completed_with_warnings' })), 'Published with warnings');
	assert.equal(client.stateLabelOf(statusWith({ run_status: 'failed' })), 'Failed — retry available');
	assert.equal(client.stateLabelOf(statusWith({ run_status: 'cancelled' })), 'Cancelled — retry available');
});

test('fingerprint folds progress and update fields into change detection', () => {
	const { client } = load();

	// The same stage but a ticked item counter / new CID / new error / drift
	// toggle is a CHANGE — a live run on the fast cadence never looks stale.
	assert.notEqual(
		client.fingerprint(statusWith({ run_status: 'running', run_stage: 'exporting', progress_count: 3 })),
		client.fingerprint(statusWith({ run_status: 'running', run_stage: 'exporting', progress_count: 4 }))
	);
	assert.notEqual(
		client.fingerprint(statusWith({ run_status: 'completed', run_stage: 'finished', publish_cid: null })),
		client.fingerprint(statusWith({ run_status: 'completed', run_stage: 'finished', publish_cid: 'QmA' }))
	);
	assert.notEqual(
		client.fingerprint(statusWith({ run_status: 'failed', run_stage: 'finished', last_error: null })),
		client.fingerprint(statusWith({ run_status: 'failed', run_stage: 'finished', last_error: 'boom' }))
	);

	// Identical reports share one fingerprint.
	assert.equal(
		client.fingerprint(statusWith({ run_status: 'running', run_stage: 'uploading', progress_count: 9 })),
		client.fingerprint(statusWith({ run_status: 'running', run_stage: 'uploading', progress_count: 9 }))
	);
});

test('contextOf mirrors the PublishDashboardView "why publish" copy', () => {
	const { client } = load();

	// First publish: no identity yet, content ready.
	assert.equal(
		client.contextOf(statusWith({ identity: null, dirty: true })),
		'Your workspace has content ready — publish it to Pinner for the first time.'
	);
	assert.equal(
		client.contextOf(statusWith({ identity: null, dirty: false })),
		'Your site is ready — publish it to Pinner to make it live.'
	);

	// Re-publish: an identity exists, with and without unpublished changes.
	assert.equal(
		client.contextOf(statusWith({ identity: { website_id: 'w-1' }, dirty: true })),
		'You have unpublished changes ready to go live.'
	);
	assert.equal(
		client.contextOf(statusWith({ identity: { website_id: 'w-1' }, dirty: false })),
		'Your published site is up to date.'
	);

	// A live run owns the copy — the button is disabled, so no "publish it"
	// wording ever appears mid-run.
	assert.equal(
		client.contextOf(statusWith({ run_status: 'running', run_stage: 'exporting', run_active: true })),
		'Publishing is in progress — progress updates live below.'
	);
	assert.equal(
		client.contextOf(statusWith({ run_status: 'not_started', run_active: true })),
		'Your publish is queued and will start automatically. Keep working — it runs in the background and this page tracks the progress.'
	);

	// Terminal retry states are honest: they name the failure/cancel and the
	// retry (never dressed up as a first-time publish).
	assert.equal(
		client.contextOf(statusWith({ run_status: 'failed', run_stage: 'finished', identity: null })),
		'Your last publish failed. Publish to Pinner to retry.'
	);
	assert.equal(
		client.contextOf(statusWith({ run_status: 'cancelled', run_stage: 'finished', identity: null })),
		'Your last publish was cancelled. Publish to Pinner to start again.'
	);
	assert.equal(
		client.contextOf(statusWith({ run_status: 'failed', run_stage: 'finished', identity: { website_id: 'w-1' } })),
		'Your last publish failed. Publish again to retry.'
	);

	// Not-ready surfaces explain why publishing is unavailable.
	assert.equal(
		client.contextOf(statusWith({ bootstrap_identity_complete: false })),
		'Publishing is unavailable until the configuration below is resolved.'
	);
	assert.equal(
		client.contextOf(statusWith({ onboarding_complete: false })),
		'Finish onboarding to publish your site.'
	);
	assert.equal(
		client.contextOf(statusWith({ has_eligible_content: false })),
		'Add publishable content, then publish your site to Pinner.'
	);
});

test('the onboarding instruction distinguishes skipped from incomplete', () => {
	const { client } = load();

	// Incomplete (never finished): the honest instruction is to finish it.
	assert.equal(
		client.contextOf(statusWith({ onboarding_complete: false })),
		'Finish onboarding to publish your site.'
	);
	assert.equal(client.readinessLabelOf(statusWith({ onboarding_complete: false })), 'Finish onboarding to publish');

	// Skipped: it must NOT instruct finishing something the user deliberately
	// skipped — the truthful next step is publishing.
	const skipped = statusWith({ onboarding_complete: false, onboarding_skipped: true });
	assert.equal(
		client.contextOf(skipped),
		'You skipped onboarding, so there is nothing to finish — publish your site whenever you are ready.'
	);
	assert.doesNotMatch(client.contextOf(skipped), /finish onboarding/i);
	assert.equal(client.readinessLabelOf(skipped), 'Onboarding skipped — publish when ready');
});

test('queuedElapsedSeconds/formatElapsed time the wait from the server timestamp', () => {
	const { client } = load();

	// queued_at is server unix seconds; the wall clock is injected ms.
	assert.equal(client.queuedElapsedSeconds(statusWith({ queued_at: 1000 }), 1065 * 1000), 65);
	assert.equal(client.queuedElapsedSeconds(statusWith({ queued_at: 2000 }), 1500 * 1000), 0, 'never negative');
	assert.equal(client.queuedElapsedSeconds(statusWith({ queued_at: null }), 5000 * 1000), null);
	assert.equal(client.queuedElapsedSeconds(statusWith({}), 5000 * 1000), null, 'missing field → null');

	assert.equal(client.formatElapsed(0), '0 sec');
	assert.equal(client.formatElapsed(45), '45 sec');
	assert.equal(client.formatElapsed(125), '2 min 5 sec');
});

test('progressCountLabelOf is an honest waiting line for a queued run, not a 0-count', () => {
	const { client } = load({ config: { nowMs: () => 1_700_000_000_000 } });

	// No queue timestamp → the static honest caption only.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'not_started', run_active: true, queued_at: null, progress_count: 0 })),
		'Waiting to begin'
	);

	// With a queue timestamp the live elapsed context is appended — never "0
	// items processed" while the run has processed nothing.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'not_started', run_active: true, queued_at: 1_700_000_000 - 95, progress_count: 0 })),
		'Waiting to begin — in the queue for 1 min 35 sec'
	);

	// Once the run starts the honest per-stage copy replaces the count (no
	// discover total yet → preparing).
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting', run_active: true, queued_at: 999, progress_count: 7 })),
		'Preparing to export…'
	);
});

test('queuedEtaSecondsLeft reconciles the queued ETA against the tick cadence', () => {
	const { client } = load();

	// queuedAt (server unix seconds) + the localized tick-delivery cadence − the
	// wall clock (ms / 1000) = whole seconds until the expected start.
	assert.equal(client.queuedEtaSecondsLeft(statusWith({ queued_at: 1_700_000_000 - 7 }), 1_700_000_000_000, 30), 23);
	assert.equal(client.queuedEtaSecondsLeft(statusWith({ queued_at: 1_700_000_000 - 30 }), 1_700_000_000_000, 30), 0, 'window exactly passed → 0, never a negative promise');
	assert.equal(client.queuedEtaSecondsLeft(statusWith({ queued_at: 1_700_000_000 - 40 }), 1_700_000_000_000, 30), -10, 'a past window reads non-positive');

	// No queue-entry timestamp, no cadence, or a sub-second cadence → null, so
	// callers fall back to the honest waiting copy (never a guessed number).
	assert.equal(client.queuedEtaSecondsLeft(statusWith({ queued_at: null }), 1_700_000_000_000, 30), null);
	assert.equal(client.queuedEtaSecondsLeft(statusWith({}), 5_000 * 1000, 30), null, 'missing field → null');
	assert.equal(client.queuedEtaSecondsLeft(statusWith({ queued_at: 1000 }), 5_000 * 1000, null), null, 'no cadence → null');
	assert.equal(client.queuedEtaSecondsLeft(statusWith({ queued_at: 1000 }), 5_000 * 1000, 0), null, 'sub-second cadence → null');

	assert.equal(client.formatRemaining(1), '1 second');
	assert.equal(client.formatRemaining(23), '23 seconds');
	assert.equal(client.formatRemaining(65), '1 min 5 sec');
});

test('progressCountLabelOf leads with a live countdown while the start window is ahead', () => {
	const { client } = load({ config: { nowMs: () => 1_700_000_000_000, startWithinSeconds: 30 } });

	// Still inside the start window (queuedAt + cadence is ahead of now) the
	// honest line leads with the live countdown — never a misleading 0-count.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'not_started', run_active: true, queued_at: 1_700_000_000 - 7, progress_count: 0 })),
		'Starting in 23 seconds — waiting to begin'
	);

	// Once the window passes it hands the line back to the honest elapsed
	// waiting copy.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'not_started', run_active: true, queued_at: 1_700_000_000 - 95, progress_count: 0 })),
		'Waiting to begin — in the queue for 1 min 35 sec'
	);
});

test('progressCountLabelOf never guesses a countdown without a localized cadence', () => {
	const { client } = load({ config: { nowMs: () => 1_700_000_000_000 } });

	// startWithinSeconds absent (an unlocalized embedded surface) → the
	// countdown is skipped entirely and the server base copy stands: never a
	// made-up number.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'not_started', run_active: true, queued_at: 1_700_000_000 - 7, progress_count: 0 })),
		'Waiting to begin — in the queue for 7 sec'
	);
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'not_started', run_active: true, queued_at: null, progress_count: 0 })),
		'Waiting to begin'
	);
});

test('progressCountLabelOf mirrors the per-stage export copy before a total exists', () => {
	const { client } = load();

	// Probe/setup — and a freshly started run with no pinned cursor — are
	// "preparing", never a misleading cumulative count.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'probe' })),
		'Preparing to export…'
	);
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'setup' })),
		'Preparing to export…'
	);
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting' })),
		'Preparing to export…'
	);

	// The discover stage runs before any total has landed → discovering.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'discover' })),
		'Discovering content…'
	);
});

test('progressCountLabelOf mirrors the stage verb and terminal x-of-M once a total exists', () => {
	const { client } = load();

	// While capture runs its summary has not landed → the stage verb + total.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'capture', progress_total: 120 })),
		'Capturing content… (120 URLs)'
	);
	// The terminal capture summary → the honest cap-sum copy.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'capture', progress_total: 120, capture_done: 118 })),
		'Captured 118 of 120 URLs'
	);
	// A live 0 is honest and moving — never the frozen stage verb.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'capture', progress_total: 120, capture_done: 0 })),
		'Captured 0 of 120 URLs'
	);

	// Same restraint on rewrite, with its terminal numerator.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'rewrite', progress_total: 120 })),
		'Rewriting content… (120 URLs)'
	);
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'rewrite', progress_total: 120, rewrite_done: 90 })),
		'Rewrote 90 of 120'
	);
	// The live rewrite numerator (Rewritten rows so far) can legitimately be 0.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'rewrite', progress_total: 120, rewrite_done: 0 })),
		'Rewrote 0 of 120'
	);

	// Pack/wrapup name the build step with the same denominator.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'uploading', pipeline_stage: 'pack', progress_total: 120 })),
		'Building the export (120 URLs)'
	);
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'publishing', pipeline_stage: 'wrapup', progress_total: 120 })),
		'Building the export (120 URLs)'
	);

	// The coarse upload/publishing tail.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'uploading', pipeline_stage: 'publish', progress_total: 120 })),
		'Uploading… (120 URLs)'
	);
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'publishing', pipeline_stage: 'publish', progress_total: 120 })),
		'Publishing…'
	);

	// Terminal states keep the legacy count line, never the stage mapping.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'completed', run_stage: 'finished', progress_count: 12 })),
		'12 items processed'
	);
});

test('progressCountLabelOf suppresses discovery for a publish-only re-run', () => {
	const { client } = load();

	// A publish-only re-run owns no discover total: it says "republishing"
	// instead of pretending discovery is happening.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'publishing', pipeline_stage: 'publish', progress_total: null })),
		'Republishing existing build…'
	);

	// A full run at the same boundary WITH a total is a normal publish.
	assert.equal(
		client.progressCountLabelOf(statusWith({ run_status: 'running', run_stage: 'publishing', pipeline_stage: 'publish', progress_total: 85 })),
		'Publishing…'
	);
});

test('fingerprint folds the fine stage, total and stage numerators', () => {
	const { client } = load();

	// Advancing the fine stage without a coarse-stage change must count as a
	// visual change (the progress line re-renders), even when progress_count
	// is identical.
	assert.notEqual(
		client.fingerprint(statusWith({ run_status: 'running', run_stage: 'exporting', progress_count: 3, pipeline_stage: 'discover' })),
		client.fingerprint(statusWith({ run_status: 'running', run_stage: 'exporting', progress_count: 3, pipeline_stage: 'capture' }))
	);

	// The discover total landing is a change too.
	assert.notEqual(
		client.fingerprint(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'discover' })),
		client.fingerprint(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'discover', progress_total: 120 }))
	);

	// The terminal stage numerators folding in.
	assert.notEqual(
		client.fingerprint(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'capture', progress_total: 120 })),
		client.fingerprint(statusWith({ run_status: 'running', run_stage: 'exporting', pipeline_stage: 'capture', progress_total: 120, capture_done: 118 }))
	);

	// Identical reports still share one fingerprint.
	assert.equal(
		client.fingerprint(statusWith({ run_status: 'running', run_stage: 'uploading', progress_count: 9 })),
		client.fingerprint(statusWith({ run_status: 'running', run_stage: 'uploading', progress_count: 9 }))
	);
});

test('the queued countdown re-renders the ETA live and never outlives the queue', async () => {
	let now = 1_700_000_000_000;

	const { client, timers, nodes } = load({
		// Polling disabled so the timer budget isolates the countdown itself:
		// the init fetch lands on the queued report, then only the per-second
		// countdown timer is armed.
		config: {
			nowMs: () => now,
			startWithinSeconds: 30,
			poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 },
		},
		script: {
			status: statusWith({ run_status: 'not_started', run_active: true, queued_at: 1_700_000_000 - 7 }),
		},
	});

	await tick();
	assert.equal(nodes['.cast-publish-progress-count'].textContent, 'Starting in 23 seconds — waiting to begin');
	assert.equal(timers.count(), 1, 'a timestamped queued run arms exactly one countdown timer');

	// One second later the countdown ticks WITHOUT a fetch (pure re-render from
	// the wall clock + the last report) and re-arms a single timer — no stack.
	now += 1000;
	assert.ok(timers.runNext(), 'the per-second countdown fires');
	assert.equal(nodes['.cast-publish-progress-count'].textContent, 'Starting in 22 seconds — waiting to begin');
	assert.equal(timers.count(), 1, 'the countdown re-arms itself, never stacking');

	// Leaving the queue (the run starts) stops the countdown entirely and the
	// line returns to the honest per-stage copy (no discover total yet).
	client.applyStatus(statusWith({ run_status: 'running', run_stage: 'exporting', run_active: true, queued_at: 1_700_000_000 - 7, progress_count: 2 }));
	assert.equal(timers.count(), 0, 'a running run stops the countdown — no timer outlives the queue');
	assert.equal(nodes['.cast-publish-progress-count'].textContent, 'Preparing to export…');
});

test('a queued report without a queue timestamp never arms a countdown timer', async () => {
	let now = 1_700_000_000_000;

	const { timers, nodes } = load({
		config: {
			nowMs: () => now,
			startWithinSeconds: 30,
			poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 },
		},
		script: {
			status: statusWith({ run_status: 'not_started', run_active: true, queued_at: null, progress_count: 0 }),
		},
	});

	await tick();
	// A static waiting line has nothing that changes per second, so there is
	// nothing to re-render — and the timer budget stays at zero.
	assert.equal(nodes['.cast-publish-progress-count'].textContent, 'Waiting to begin');
	assert.equal(timers.count(), 0, 'no per-second timer for a queued report without a queue-entry time');
});

test('applyStatus reveals the start-now escape only after a reasonable wait while queued', () => {
	const { client, nodes } = load({ config: { nowMs: () => 1_700_000_000_000 } });

	// A briefly-queued run (not yet past the threshold, incl. the normal quiet
	// period) keeps the escape hidden.
	client.applyStatus(statusWith({ run_status: 'not_started', run_active: true, queued_at: 1_700_000_000 - 10 }));
	assert.equal(nodes['.cast-publish-escape'].hidden, true, 'a briefly-queued run keeps the escape hidden');

	// Once it has honestly waited at least START_NOW_WAIT_SECONDS the escape
	// appears as the recovery/start-now way out.
	client.applyStatus(statusWith({ run_status: 'not_started', run_active: true, queued_at: 1_700_000_000 - 120 }));
	assert.equal(nodes['.cast-publish-escape'].hidden, false, 'a genuinely-stalled queued run reveals the escape');

	// As soon as the run starts (or any non-queued state) the escape disappears.
	client.applyStatus(statusWith({ run_status: 'running', run_stage: 'exporting', run_active: true, queued_at: 1_700_000_000 - 120 }));
	assert.equal(nodes['.cast-publish-escape'].hidden, true, 'a running run hides the escape');

	// An idle run never shows it either.
	client.applyStatus(statusWith({ run_status: 'not_started', run_active: false, queued_at: 1_700_000_000 - 120 }));
	assert.equal(nodes['.cast-publish-escape'].hidden, true, 'an idle run hides the escape');
});

test('canEscape() routes the queued start-now escape through start/now only', () => {
	const { client } = load();

	// First publish (no identity) queued → the escape funnels through 'start'.
	assert.equal(client.canEscape('start', statusWith({ run_status: 'not_started', run_active: true, identity: null })), true);
	assert.equal(client.canEscape('now', statusWith({ run_status: 'not_started', run_active: true, identity: null })), false);

	// Re-publish (identity exists) queued → the escape funnels through 'now'.
	const republish = statusWith({ run_status: 'not_started', run_active: true, identity: { website_id: 'w-1' }, has_eligible_content: true });
	assert.equal(client.canEscape('now', republish), true);
	assert.equal(client.canEscape('start', republish), false);

	// The escape needs the same ready surface as the routes it funnels through.
	assert.equal(
		client.canEscape('start', statusWith({ run_status: 'not_started', run_active: true, identity: null, bootstrap_identity_complete: false })),
		false,
		'no escape with an unready environment'
	);
	assert.equal(
		client.canEscape('start', statusWith({ run_status: 'not_started', run_active: true, identity: null, superseded: true })),
		false,
		'no escape for a superseded queued run'
	);

	// A non-queued (idle/running) status never counts as an escape.
	assert.equal(client.canEscape('start', statusWith({ run_status: 'not_started', run_active: false, identity: null })), false);
	assert.equal(client.canEscape('start', statusWith({ run_status: 'running', run_stage: 'exporting', run_active: true, identity: null })), false);
});

test('can() stays strict while queued and syncActions only re-enables the escape button', () => {
	const start = makeButton('start', null, false, 'cast-publish-primary');
	const escape = makeButton('start', null, false, 'cast-publish-start-now');
	const { client, buttons } = load({
		config: { nowMs: () => 1_700_000_000_000 },
		buttons: [start, escape],
	});

	// The normal 'start' guard must NOT open for a queued run — otherwise the
	// primary Publish button would come back to life mid-queue.
	assert.equal(client.can('start', statusWith({ run_status: 'not_started', run_active: true, identity: null })), false);

	// syncActions: the primary stays disabled while queued, the escape enables.
	client.applyStatus(statusWith({ run_status: 'not_started', run_active: true, identity: null, queued_at: 1_700_000_000 - 120 }));
	assert.equal(start.disabled, true, 'the primary stays disabled mid-queue');
	assert.equal(escape.disabled, false, 'the escape becomes clickable');

	// Once the run starts, the escape is disabled again (and hidden).
	client.applyStatus(statusWith({ run_status: 'running', run_stage: 'exporting', run_active: true, identity: null, queued_at: 1_700_000_000 - 120 }));
	assert.equal(escape.disabled, true, 'no escape once the run is under way');
});

test('perform() fires the start-now escape for a queued run against the start route', async () => {
	const { client, calls } = load({
		config: { nowMs: () => 1_700_000_000_000 },
		buttons: [makeButton('start', undefined, false, 'cast-publish-start-now')],
	});

	client.applyStatus(statusWith({ run_status: 'not_started', run_active: true, identity: null, queued_at: 1_700_000_000 - 120 }));
	const started = await client.perform('start');

	assert.equal(started, true);
	assert.ok(
		calls.some((c) => c.url.indexOf('/publish/start') !== -1 && c.options.method === 'POST'),
		'the escape posts to the first-publish start route'
	);
});

/* ------------------------- status fetch + apply --------------------------- */

test('status is fetched with the nonce header and renders the card through textContent', async () => {
	const status = statusWith({
		run_status: 'running',
		run_stage: 'exporting',
		progress_count: 42,
		publish_cid: 'QmExample',
		identity: { website_id: 'w-1', website_name: 'Example <Site>' },
		mode: 'on_update',
	});
	const { client, calls, nodes, liveRegion } = load({ script: { status } });

	assert.equal(calls.length, 1, 'init fetches status once immediately');
	assert.equal(calls[0].url, DEFAULT_ENDPOINTS.status);
	assert.equal(calls[0].options.method, 'GET');
	assert.equal(calls[0].options.headers['X-WP-Nonce'], 'wp-rest-nonce');
	assert.equal(calls[0].options.credentials, 'same-origin');

	await tick();

	assert.equal(nodes['.cast-publish-run-label'].textContent, 'Exporting content');
	assert.equal(nodes['.cast-publish-stage'].textContent, 'Exporting content');
	assert.equal(nodes['.cast-publish-progress-value'].textContent, '25%');
	assert.equal(nodes['.cast-publish-progress-count'].textContent, 'Preparing to export…');
	assert.equal(nodes['.cast-publish-cid'].textContent, 'Published CID: QmExample');
	// The connected website renders as a labelled, escaped line — the clear
	// "Website: <domain>" display the refresh path must always show.
	assert.equal(nodes['.cast-publish-site'].textContent, 'Website: Example <Site>');
	assert.equal(nodes['.cast-publish-mode'].textContent, 'Publishes automatically when you update content');
	assert.equal(nodes['.cast-publish-readiness-level'].className, 'cast-publish-readiness-level cast-publish-level-ready');
	assert.equal(liveRegion.textContent, '');

	assertNoInnerHTML([nodes['.cast-publish-cid'], nodes['.cast-publish-site'], nodes['.cast-publish-run-label']]);
});

test('the setup readiness line is hidden so the onboarding instruction is stated once', async () => {
	const { nodes, client } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: { status: statusWith({ onboarding_complete: false }) },
	});

	await tick();

	assert.equal(nodes['.cast-publish-readiness-level'].hidden, true, 'the readiness line does not repeat the onboarding instruction');
	assert.equal(nodes['.cast-publish-context'].textContent, 'Finish onboarding to publish your site.');

	// …and a later poll completing onboarding reveals it again.
	client.applyStatus(statusWith({}));
	await tick();
	assert.equal(nodes['.cast-publish-readiness-level'].hidden, false, 'the readiness line returns once onboarding is complete');
	assert.equal(nodes['.cast-publish-readiness-level'].textContent, 'Ready to publish');
});

test('a skipped-onboarding report renders the truthful next step, not the finish instruction', async () => {
	const { nodes } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: { status: statusWith({ onboarding_complete: false, onboarding_skipped: true }) },
	});

	await tick();

	assert.equal(
		nodes['.cast-publish-context'].textContent,
		'You skipped onboarding, so there is nothing to finish — publish your site whenever you are ready.'
	);
	assert.doesNotMatch(nodes['.cast-publish-context'].textContent, /finish onboarding/i);
	assert.equal(nodes['.cast-publish-readiness-level'].hidden, true, 'the readiness line never repeats the onboarding instruction');
});

test('hidden conditional nodes toggle as the status drifts', async () => {
	const script = {
		status: [
			statusWith({ run_status: 'completed', run_stage: 'finished', dirty: false, superseded: false, publish_cid: null }),
			statusWith({ run_status: 'failed', run_stage: 'finished', dirty: false, superseded: false, last_error: 'Disk full' }),
		],
	};
	const { client, nodes } = load({ script });

	await tick();
	assert.equal(nodes['.cast-publish-dirty'].hidden, true);
	assert.equal(nodes['.cast-publish-superseded'].hidden, true);
	assert.equal(nodes['.cast-publish-cid'].hidden, true);

	client.timers && undefined;
});

test('applyStatus writes the workflow progress bar and context copy', async () => {
	const status = statusWith({
		run_status: 'running',
		run_stage: 'exporting',
		progress_count: 7,
		identity: { website_id: 'w-1', website_name: 'Blog' },
		dirty: true,
	});
	const { client, nodes } = load({ script: { status } });

	await tick();

	// The action card's "why" copy reflects the live report.
	assert.equal(nodes['.cast-publish-context'].textContent, 'You have unpublished changes ready to go live.');

	// The visual progress bar: value/count text, the fill width and the
	// progressbar aria-valuenow all agree with the stage mapping (25%).
	assert.equal(nodes['.cast-publish-progress-value'].textContent, '25%');
	assert.equal(nodes['.cast-publish-progress-count'].textContent, 'Preparing to export…');
	assert.equal(nodes['.cast-publish-progress-fill'].style.width, '25%');
	assert.equal(nodes['.cast-publish-progress-track']['aria-valuenow'], '25');

	// A finished run drives the bar to 100% through the same path.
	client.applyStatus(statusWith({ run_status: 'completed', run_stage: 'finished', progress_count: 123 }));
	assert.equal(nodes['.cast-publish-progress-value'].textContent, '100%');
	assert.equal(nodes['.cast-publish-progress-fill'].style.width, '100%');
	assert.equal(nodes['.cast-publish-progress-track']['aria-valuenow'], '100');

	assertNoInnerHTML([nodes['.cast-publish-context'], nodes['.cast-publish-progress-value']]);
});

test('applyStatus livens the accessible state chip and the last-checked time', async () => {
	const start = makeButton('start', null, false, 'cast-publish-primary');
	const { client, nodes } = load({
		buttons: [start],
		script: {
			status: statusWith({ run_status: 'running', run_stage: 'exporting', run_active: true }),
		},
	});

	await tick();

	// The explicit queue/run state chip is live (text + per-state class) and
	// never built from markup.
	assert.equal(nodes['.cast-publish-state-chip'].textContent, 'Publishing');
	assert.match(nodes['.cast-publish-state-chip'].className, /cast-publish-state-running/);
	assert.notEqual(nodes['.cast-publish-last-checked-time'].textContent, '', 'a successful report records the last-checked time');
	assertNoInnerHTML([nodes['.cast-publish-state-chip'], nodes['.cast-publish-last-checked-time']]);

	// A queued (worker-waiting) transition re-labels the chip.
	client.applyStatus(statusWith({ run_status: 'not_started', run_active: true }));
	assert.equal(nodes['.cast-publish-state-chip'].textContent, 'Queued — starting shortly');
	assert.match(nodes['.cast-publish-state-chip'].className, /cast-publish-state-queued/);

	// A terminal success stops the chip on "Published".
	client.applyStatus(statusWith({ run_status: 'completed', run_stage: 'finished', run_active: false }));
	assert.equal(nodes['.cast-publish-state-chip'].textContent, 'Published');
	assert.match(nodes['.cast-publish-state-chip'].className, /cast-publish-state-completed/);
});

test('perform() represents the queued/working state immediately and blocks duplicate clicks', async () => {
	const start = makeButton('start', null, false, 'cast-publish-primary');
	const calls = [];
	let statusCalls = 0;
	const { client, nodes, liveRegion, buttons } = load({
		buttons: [start],
		fetch: (url, options) => {
			calls.push({ url, options });
			if (url.indexOf('/publish/status') !== -1) {
				// The init fetch is a ready/idle surface (start allowed); after
				// the action the run is queued, exactly as the server reports.
				statusCalls = statusCalls + 1;
				const fresh = statusCalls === 1
					? statusWith({})
					: statusWith({ run_status: 'not_started', run_active: true });
				return Promise.resolve({ ok: true, status: 200, json: async () => fresh });
			}
			// A deliberately slow action: signals the request is still in
			// flight while a duplicate click is attempted.
			return new Promise((resolve) => {
				setImmediate(() => resolve({ ok: true, status: 200, json: async () => ({ queued: true, status: 'queued' }) }));
			});
		},
	});

	await tick();

	const first = client.perform('start');
	// Before the POST answers, the fired action is already represented as
	// queued/working AND the button is disabled, so a second click is inert.
	assert.match(nodes['.cast-publish-result'].textContent, /Queueing your publish/i);
	assert.equal(start.disabled, true, 'the fired action is disabled while its request is in flight');
	assert.match(liveRegion.textContent, /Queueing your publish/i);

	// A duplicate perform() while busy is refused — no second POST fires.
	const second = client.perform('start');
	assert.equal(await second, false, 'a duplicate action while in flight is refused');
	assert.equal(
		calls.filter((c) => c.url === DEFAULT_ENDPOINTS.start).length,
		1,
		'exactly one start POST fires despite the duplicate click'
	);

	await first;
	await tick();
	// Once settled, the queued state keeps the button disabled via the status
	// check (the run is now queued), so the safety is not lost after settle.
	assert.equal(start.disabled, true, 'the queued run keeps the primary disabled after the action settles');
});

test('applyStatus reconciles the single primary button and marks the active mode', async () => {
	const primary = makeButton('start', null, false, 'cast-publish-primary');
	const manual = makeButton('mode', 'manual', false, 'cast-publish-mode-option is-active');
	const onUpdate = makeButton('mode', 'on_update', false, 'cast-publish-mode-option');
	const { client, buttons } = load({
		buttons: [primary, manual, onUpdate],
		script: {
			status: statusWith({ run_status: 'running', run_stage: 'exporting', run_active: true, mode: 'on_update' }),
		},
	});

	await tick();

	// Mid-run: the live report clears + disables the one prominent Publish
	// button so no publish action is offered while the run is underway.
	assert.equal(primary.disabled, true, 'the primary is disabled while a run is live');
	assert.equal(primary.getAttribute('data-cast-publish-action'), '', 'a live run clears the primary action');

	// The live report's mode checks the matching radio (on_update) and makes it
	// non-actionable even mid-run; the alternative (manual) stays unchecked
	// and selectable.
	assert.equal(manual.checked, false, 'the non-active mode radio is unchecked');
	assert.equal(manual.disabled, false);
	assert.equal(onUpdate.checked, true, 'the report mode radio is checked');
	assert.equal(onUpdate.disabled, true, 'the report mode is non-actionable');

	// A ready, resumable state re-arms the SAME primary button with the
	// re-publish action and re-marks the active mode — exactly like a fresh
	// server render whose one primary button carries the current action.
	client.applyStatus(statusWith({
		run_status: 'completed',
		run_stage: 'finished',
		run_active: false,
		dirty: true,
		identity: { website_id: 'w-1' },
		mode: 'manual',
	}));

	assert.equal(primary.disabled, false, 'the primary is re-enabled for a ready re-publish');
	assert.equal(primary.getAttribute('data-cast-publish-action'), 'now', 'the ready re-publish re-arms the primary to the now route');
	// The can()/primaryActionFor() mapping keeps the routes honest: once an
	// identity exists the first-publish start guard stays shut.
	assert.equal(client.can('start', statusWith({ run_status: 'completed', identity: { website_id: 'w-1' } })), false);
	assert.equal(client.can('now', statusWith({ run_status: 'completed', identity: { website_id: 'w-1' } })), true);
	assert.equal(client.primaryActionFor(statusWith({ run_status: 'completed', identity: { website_id: 'w-1' } })), 'now');
	// A completed-without-identity record is an anomaly (completing requires an
	// identity), so the first-publish start guard stays shut — mirrors the view
	// model's canStart.
	assert.equal(client.primaryActionFor(statusWith({ run_status: 'completed', identity: null })), null);
	// The active mode is visibly selected (checked) AND non-actionable; the
	// alternatives become actionable again (unchecked).
	assert.equal(manual.checked, true, 'the active mode radio is checked');
	assert.equal(manual.disabled, true, 'the active mode is disabled/non-actionable');
	assert.equal(onUpdate.checked, false, 'the non-active mode radio is unchecked');
	assert.equal(onUpdate.disabled, false);
});

test('a failed first publish restarts the primary start action and help copy', async () => {
	const start = makeButton('start', null, false, 'cast-publish-primary');
	const { client, nodes } = load({
		buttons: [start],
		script: {
			status: statusWith({ run_status: 'failed', run_stage: 'finished', identity: null, last_error: 'boom' }),
		},
	});

	await tick();

	// Terminal retry: the start action is available again (not the idle-only
	// guard), the context names the failure, and the help line renders.
	assert.equal(start.disabled, false, 'the terminal retry stays clickable');
	assert.equal(client.can('start', statusWith({ run_status: 'failed', run_stage: 'finished', identity: null })), true);
	assert.equal(client.can('start', statusWith({ run_status: 'cancelled', run_stage: 'finished', identity: null })), true);
	assert.equal(client.can('start', statusWith({ run_status: 'failed', run_stage: 'finished', identity: { website_id: 'w-1' } })), false);
	assert.equal(
		nodes['.cast-publish-context'].textContent,
		'Your last publish failed. Publish to Pinner to retry.'
	);
	assert.equal(
		nodes['.cast-publish-mode-help'].textContent,
		'Publish only when you choose. Clicking Publish to Pinner queues a background publish — content edits wait until then and nothing publishes on its own.'
	);
});

test('an active run still disables start regardless of terminal history', async () => {
	const start = makeButton('start', null, false, 'cast-publish-primary');
	const { client, buttons } = load({
		buttons: [start],
		script: {
			status: statusWith({ run_status: 'running', run_stage: 'exporting', run_active: true, identity: null }),
		},
	});

	await tick();

	assert.equal(start.disabled, true, 'a live run keeps start disabled (no deadlock reintroduction)');
	assert.equal(client.can('start', statusWith({ run_status: 'running', run_active: true, identity: null })), false);
});

test('cancel visibility mirrors canCancel: only a live run may offer Cancel', () => {
	const cancel = makeButton('cancel', null, false, 'cast-publish-cancel');
	const { client, buttons } = load({ buttons: [cancel] });

	// Idle (no run at all) and every terminal state hide + disable cancel —
	// the button is reconciled to disappear exactly like a fresh render that
	// omits it entirely once the run is no longer cancellable.
	client.applyStatus(statusWith({ run_status: 'not_started', run_active: false }));
	assert.equal(cancel.hidden, true, 'an idle surface hides Cancel');
	assert.equal(cancel.disabled, true, 'an idle surface disables Cancel');

	client.applyStatus(statusWith({ run_status: 'cancelled', run_stage: 'finished', run_active: false }));
	assert.equal(cancel.hidden, true, 'a terminal cancelled run hides Cancel');
	assert.equal(cancel.disabled, true, 'a terminal cancelled run disables Cancel');

	client.applyStatus(statusWith({ run_status: 'failed', run_stage: 'finished', run_active: false }));
	assert.equal(cancel.hidden, true, 'a terminal failed run hides Cancel');

	client.applyStatus(statusWith({ run_status: 'completed', run_stage: 'finished', run_active: false }));
	assert.equal(cancel.hidden, true, 'a terminal completed run hides Cancel');

	// A live run (queued/running/paused) shows + enables the cancel it may use.
	client.applyStatus(statusWith({ run_status: 'not_started', run_active: true }));
	assert.equal(cancel.hidden, false, 'a queued run shows Cancel');
	assert.equal(cancel.disabled, false, 'a queued run enables Cancel');

	client.applyStatus(statusWith({ run_status: 'running', run_stage: 'exporting', run_active: true }));
	assert.equal(cancel.hidden, false, 'a running run shows Cancel');
	assert.equal(cancel.disabled, false, 'a running run enables Cancel');

	assert.equal(client.canCancel(statusWith({ run_status: 'paused', run_active: true })), true);
	assert.equal(client.canCancel(statusWith({ run_status: 'cancelled', run_active: false })), false);
	assert.equal(client.canCancel(statusWith({ run_status: 'not_started', run_active: false })), false);
});

test('updateEscape mirrors canStartNowEscape across state, superseded and readiness', () => {
	const { client, nodes } = load({ config: { nowMs: () => 1_700_000_000_000 } });

	// A genuinely-stalled queued run (ready, not superseded) reveals the escape.
	client.applyStatus(statusWith({ run_status: 'not_started', run_active: true, queued_at: 1_700_000_000 - 120 }));
	assert.equal(nodes['.cast-publish-escape'].hidden, false, 'a ready stalled queued run reveals the escape');

	// Every terminal state hides it, exactly like a fresh render without one.
	client.applyStatus(statusWith({ run_status: 'cancelled', run_stage: 'finished', queued_at: 1_700_000_000 - 120 }));
	assert.equal(nodes['.cast-publish-escape'].hidden, true, 'a cancelled run hides the escape');

	client.applyStatus(statusWith({ run_status: 'failed', run_stage: 'finished', queued_at: 1_700_000_000 - 120 }));
	assert.equal(nodes['.cast-publish-escape'].hidden, true, 'a failed run hides the escape');

	client.applyStatus(statusWith({ run_status: 'completed', run_stage: 'finished', queued_at: 1_700_000_000 - 120 }));
	assert.equal(nodes['.cast-publish-escape'].hidden, true, 'a completed run hides the escape');

	// A queued run the server would NOT offer an escape for (superseded, or an
	// unready surface) must hide it too — otherwise the line shows with Start now
	// disabled, which is exactly the stale-escape artifact reported.
	client.applyStatus(statusWith({ run_status: 'not_started', run_active: true, superseded: true, queued_at: 1_700_000_000 - 120 }));
	assert.equal(nodes['.cast-publish-escape'].hidden, true, 'a superseded queued run hides the escape');

	client.applyStatus(statusWith({ run_status: 'not_started', run_active: true, bootstrap_identity_complete: false, env_problems: [{}], queued_at: 1_700_000_000 - 120 }));
	assert.equal(nodes['.cast-publish-escape'].hidden, true, 'a queued run on an unready surface hides the escape');

	// Below the wait threshold it stays hidden (the quiet-period debounce).
	client.applyStatus(statusWith({ run_status: 'not_started', run_active: true, queued_at: 1_700_000_000 - 10 }));
	assert.equal(nodes['.cast-publish-escape'].hidden, true, 'a briefly-queued run keeps the escape hidden');
});

test('a terminal cancelled run reconciles the card exactly like a fresh render', () => {
	const primary = makeButton('start', null, false, 'cast-publish-primary');
	const escape = makeButton('start', null, false, 'cast-publish-start-now');
	const cancel = makeButton('cancel', null, false, 'cast-publish-cancel');
	const { client, buttons, nodes } = load({
		config: { nowMs: () => 1_700_000_000_000 },
		buttons: [primary, escape, cancel],
	});

	// First the card reflects a queued first-publish (as the server rendered
	// it): primary disabled, escape revealed after a real wait, cancel live.
	client.applyStatus(statusWith({ run_status: 'not_started', run_active: true, identity: null, queued_at: 1_700_000_000 - 120 }));
	assert.equal(primary.disabled, true, 'the queued run keeps the primary disabled');
	assert.equal(escape.disabled, false, 'the queued run enables the start-now escape');
	assert.equal(cancel.disabled, false, 'the queued run enables Cancel');

	// The run is then cancelled: the poll must reconcile the WHOLE card to the
	// fresh-render equivalent — escape line gone, Cancel gone, primary Publish
	// re-armed as the retry.
	client.applyStatus(statusWith({ run_status: 'cancelled', run_stage: 'finished', run_active: false, identity: null, queued_at: null }));
	assert.equal(nodes['.cast-publish-escape'].hidden, true, 'the escape container hides for the cancelled run');
	assert.equal(escape.disabled, true, 'the start-now button disables for the cancelled run');
	assert.equal(cancel.hidden, true, 'Cancel hides once nothing is active or queued');
	assert.equal(cancel.disabled, true, 'Cancel disables once nothing is active or queued');
	assert.equal(primary.disabled, false, 'the terminal retry re-enables the primary Publish button');
	assert.equal(primary.getAttribute('data-cast-publish-action'), 'start', 'the terminal retry re-arms the primary to the start route');
});

test('terminal status stops the poll after a single fetch', async () => {
	const { client, calls, timers } = load({
		script: { status: statusWith({ run_status: 'completed', run_stage: 'finished' }) },
	});

	await tick();

	assert.equal(calls.length, 1, 'only the init status fetch happened');
	assert.equal(timers.count(), 0, 'a completed run schedules no further polls');
});

test('a retry after a terminal run re-arms the poll (terminal stop is not sticky)', async () => {
	const { client, timers } = load({
		script: {
			// Init lands on a terminal failed first publish (poll stops), then a
			// retry action re-queues the run and the follow-up fetch sees it.
			status: [
				statusWith({ run_status: 'failed', run_stage: 'finished', identity: null }),
				statusWith({ run_status: 'not_started', run_active: true, identity: null }),
			],
		},
	});

	await tick();
	assert.equal(timers.count(), 0, 'a terminal run left the poll stopped');

	// The honest retry: start is available over the terminal failed slot.
	await client.perform('start');
	await tick();

	assert.ok(timers.count() >= 1, 'the re-queued run re-arms the poll after a retry');
	assert.equal(timers.count() === 1, true, 'still exactly one poll is armed (no leak)');
});

test('polling is continuous while a run is queued or active — no attempt budget', async () => {
	const { calls, timers } = load({
		config: { poll: { intervalMs: 50, maxAttempts: 4, backoffMs: 0 } },
		script: { status: statusWith({ run_status: 'running', run_stage: 'exporting' }) },
	});

	await tick();
	assert.equal(calls.length, 1, 'init fetch happens immediately');
	assert.equal(timers.count(), 1, 'one follow-up poll is armed');

	// Keep flushing far past the old maxAttempts=4 ceiling: a live run is
	// followed continuously, never stopped on a short fixed budget.
	for (let i = 0; i < 8; i++) {
		assert.ok(timers.runNext(), `poll step ${i + 1} fires`);
		await tick();
	}
	assert.equal(calls.length, 9, 'the poll outlives the old attempt budget while the run is live');
	assert.ok(timers.count() >= 1, 'the loop stays armed for the live run');
});

test('a non-positive maxAttempts opts out of automatic polling', async () => {
	const { calls, timers } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: {
			status: [
				statusWith({ run_status: 'running', run_stage: 'exporting' }),
				statusWith({ run_status: 'running', run_stage: 'uploading' }),
			],
		},
	});

	await tick();
	assert.equal(calls.length, 1, 'only the init fetch happens');
	assert.equal(timers.count(), 0, 'a non-positive maxAttempts disables auto-polling');
});

test('a live run stays on the fast interval when progress advances', async () => {
	const { timers } = load({
		config: { poll: { intervalMs: 100, maxAttempts: 6, backoffMs: 500 } },
		script: {
			// The item counter ticks without a stage change — the fingerprint
			// includes progress/update fields, so this counts as CHANGED and
			// the card stays on the fast cadence mid-run.
			status: [
				statusWith({ run_status: 'running', run_stage: 'exporting', progress_count: 0 }),
				statusWith({ run_status: 'running', run_stage: 'exporting', progress_count: 42 }),
			],
		},
	});

	await tick();
	assert.equal(timers.queue[0].ms, 100, 'the initial (changed) status schedules at the interval');

	timers.runNext();
	await tick();
	assert.equal(timers.queue[0].ms, 100, 'a progress-count-only change still counts as changed (no backoff)');
});

test('an unchanged status backs off to the longer delay only when configured', async () => {
	const { timers } = load({
		config: { poll: { intervalMs: 100, maxAttempts: 6, backoffMs: 500 } },
		script: {
			// changed -> unchanged -> changed, so only the middle step backs off.
			status: [
				statusWith({ run_status: 'running', run_stage: 'exporting', progress_count: 0 }),
				statusWith({ run_status: 'running', run_stage: 'exporting', progress_count: 0 }),
				statusWith({ run_status: 'running', run_stage: 'uploading', progress_count: 2 }),
			],
		},
	});

	await tick();
	assert.equal(timers.queue[0].ms, 100, 'first (changed) status schedules at the interval');

	timers.runNext();
	await tick();
	assert.equal(timers.queue[0].ms, 500, 'an unchanged status backs off to the longer delay');

	timers.runNext();
	await tick();
	assert.equal(timers.queue[0].ms, 100, 'a changed status returns to the interval');
});

test('a failed status fetch announces, surfaces the failure and keeps polling', async () => {
	const calls = [];
	const { timers, liveRegion, nodes } = load({
		fetch: (url, options) => {
			calls.push({ url, options });
			if (calls.length === 1) {
				return Promise.resolve({ ok: false, status: 503, json: async () => ({}) });
			}
			return Promise.resolve({ ok: true, status: 200, json: async () => statusWith({}) });
		},
	});

	await tick();
	assert.equal(calls.length, 1, 'the first fetch failed');
	assert.ok(timers.count() >= 1, 'a transient failure keeps the poll armed (it does not quit)');
	assert.equal(nodes['.cast-publish-last-checked-time'].textContent, 'Unavailable — retrying');
	assert.match(liveRegion.textContent, /retrying/i);

	// The loop recovers once the server answers again.
	timers.runNext();
	await tick();
	assert.equal(calls.length, 2, 'the poll retried after the failure');
	assert.notEqual(nodes['.cast-publish-last-checked-time'].textContent, 'Unavailable — retrying', 'a successful report restores the live "last checked" time');
});

test('the manual Refresh control re-fetches status immediately', async () => {
	const refresh = {
		attributes: { 'data-cast-publish-refresh': '' },
		listeners: {},
		addEventListener(type, cb) {
			this.listeners[type] = cb;
		},
		click() {
			if (this.listeners.click) {
				this.listeners.click();
			}
		},
	};
	const { calls, timers, refreshControls } = load({
		refreshControls: [refresh],
		script: { status: statusWith({ run_status: 'running', run_stage: 'exporting' }) },
	});

	await tick();
	assert.equal(refreshControls.length, 1, 'the Refresh control is bound at init');
	const before = calls.filter((c) => c.url === DEFAULT_ENDPOINTS.status).length;
	const pendingTimer = timers.count();

	refresh.click();
	await tick();

	const after = calls.filter((c) => c.url === DEFAULT_ENDPOINTS.status).length;
	assert.ok(after >= before + 1, 'Refresh triggers an immediate status fetch');
	assert.equal(timers.count() <= pendingTimer + 1, true, 'Refresh keeps exactly one poll armed (no timer pile-up)');
});

test('the aria-live region is created when the template does not render one', async () => {
	const { created } = load({
		liveRegion: null,
		script: { status: { __httpError: true, status: 0 } },
	});

	await tick();

	const region = created.find((node) => node.id === 'cast-publish-live');
	assert.ok(region, 'the orchestrator creates #cast-publish-live itself');
	assert.equal(region.className, 'cast-publish-live');
	assert.equal(region['role'], 'status');
	assert.equal(region['aria-live'], 'polite');
	assert.match(region.textContent, /could not be refreshed/i);
	assertNoInnerHTML(created);
});

/* ------------------------------ actions ----------------------------------- */

test('perform("start") POSTs to the start route and announces the queue', async () => {
	const { client, calls, nodes, liveRegion } = load({ script: { status: statusWith({}) } });

	await tick();
	await client.perform('start');

	const post = calls.find((c) => c.url === DEFAULT_ENDPOINTS.start);
	assert.ok(post, 'a start request reaches the start route');
	assert.equal(post.options.method, 'POST');
	assert.equal(post.options.headers['X-WP-Nonce'], 'wp-rest-nonce');
	assert.equal(post.options.headers['Content-Type'], 'application/json');
	assert.equal(post.options.credentials, 'same-origin');
	assert.equal(post.options.body, '{}');
	assert.equal(liveRegion.textContent, 'Publish queued.');

	// A successful action always re-fetches status so the card resyncs.
	assert.ok(
		calls.filter((c) => c.url === DEFAULT_ENDPOINTS.status).length >= 2,
		'status is re-fetched after the action'
	);
	assertNoInnerHTML(nodes ? Object.values(nodes) : [nodes]);
});

test('perform("mode") sends the selected mode as JSON and announces the update', async () => {
	const { client, calls, liveRegion } = load({ script: { status: statusWith({}) } });

	await tick();
	await client.perform('mode', 'on_update');

	const post = calls.find((c) => c.url === DEFAULT_ENDPOINTS.mode);
	assert.ok(post, 'a mode request reaches the mode route');
	assert.deepEqual(JSON.parse(post.options.body), { mode: 'on_update' });
	assert.equal(liveRegion.textContent, 'Mode updated to On update.');
});

test('the already-active mode is non-actionable even when perform() is forced', async () => {
	const { client, calls, liveRegion } = load({ script: { status: statusWith({ mode: 'on_update' }) } });

	await tick();
	// The height of the "non-actionable" contract: re-picking the CURRENT mode
	// is refused client-side, so no mode POST fires.
	const result = await client.perform('mode', 'on_update');

	assert.equal(result, false, 're-selecting the active mode is refused');
	assert.equal(calls.filter((c) => c.url === DEFAULT_ENDPOINTS.mode).length, 0, 'no mode request fires for the active mode');
	assert.match(liveRegion.textContent, /not available right now/i);
});

test('perform("cancel") announces the cancellation', async () => {
	const { client, calls, liveRegion } = load({ script: { status: statusWith({}) } });

	await tick();
	await client.perform('cancel');

	const post = calls.find((c) => c.url === DEFAULT_ENDPOINTS.cancel);
	assert.ok(post, 'a cancel request reaches the cancel route');
	assert.equal(liveRegion.textContent, 'Publish cancelled.');
});

test('an action missing from the localized allowlist is inert — a forged request never fires', async () => {
	const calls = [];
	const { client } = load({
		config: { actions: ['start', 'now', 'mode', 'artifact'] }, // no 'cancel'
		fetch: (url, options) => {
			calls.push({ url, options });
			return Promise.resolve({ ok: true, status: 200, json: async () => ({}) });
		},
	});

	const before = calls.length; // the init status fetch the card always performs
	const result = await client.perform('cancel');

	assert.equal(result, false, 'an unallowlisted action is refused client-side');
	assert.equal(calls.length, before, 'a refused action fires no request at all (not even a status re-fetch)');
	assert.equal(
		calls.filter((c) => c.url === DEFAULT_ENDPOINTS.cancel).length,
		0,
		'an unallowlisted action never reaches an action route'
	);
});

test('a missing nonce makes both the status fetch and every action inert', async () => {
	const calls = [];
	const { client } = load({
		config: { nonce: null },
		fetch: (url, options) => {
			calls.push({ url, options });
			return Promise.resolve({ ok: true, status: 200, json: async () => defaultStatus() });
		},
	});

	await tick();
	await client.perform('start');

	assert.equal(calls.length, 0, 'without the localized nonce nothing is ever requested');
});

test('a failed action writes the failure copy to the result line instead of announcing success', async () => {
	const { client, nodes, liveRegion } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		fetch: (url, options) => {
			if (url.indexOf('/publish/status') !== -1) {
				return Promise.resolve({ ok: true, status: 200, json: async () => statusWith({}) });
			}
			return Promise.resolve({ ok: false, status: 403, json: async () => ({}) });
		},
	});

	await tick();
	const result = await client.perform('start');

	assert.equal(result, false, 'a non-ok action result is a failure');
	assert.match(nodes['.cast-publish-result'].textContent, /could not be completed/i);
	assert.match(liveRegion.textContent, /could not be completed/i);
	assertNoInnerHTML([nodes['.cast-publish-result']]);
});

test('a currently-disallowed action is refused with an announcement', async () => {
	const { client, calls, liveRegion } = load({
		script: { status: statusWith({ run_status: 'running', run_stage: 'uploading' }) },
	});

	await tick();
	const result = await client.perform('start'); // start is not allowed mid-run

	assert.equal(result, false, 'the live run-state gate refuses the start');
	assert.equal(calls.filter((c) => c.url === DEFAULT_ENDPOINTS.start).length, 0, 'no start request is sent');
	assert.match(liveRegion.textContent, /not available right now/i);
});

test('a forged action button in the DOM is inert when its action is unknown', async () => {
	const forged = makeButton('cancel'); // not in the localized allowlist
	const { calls } = load({
		buttons: [forged],
		config: { actions: ['start', 'now', 'mode', 'artifact'] },
		script: { status: statusWith({}) },
	});

	forged.click();
	await tick();

	const posts = calls.filter((c) => c.url === DEFAULT_ENDPOINTS.cancel);
	assert.equal(posts.length, 0, 'a forged button must never reach an action route');
});

/* ------------------------ connect your domain --------------------------- */

/**
 * The "Connect your domain" card (custom destinations only) mirrors the
 * DomainDashboardView mappings server-side: the client re-reads the selected
 * domain's DNS delegation requirements and SSL status and re-runs the DNS
 * validation check. Every domain route is localized under
 * castPublish.endpoints.domain_* and each operation is guarded by the same
 * X-WP-Nonce header + the localized domain_actions allowlist; every DOM write
 * goes through textContent.
 */

test('domainDns fetches the delegation records and renders accessible copy', async () => {
	const { client, calls, nodes, liveRegion } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const result = await client.domainDns('99');

	assert.equal(result, true, 'a successful DNS read resolves true');
	const req = calls.find((c) => c.url === DEFAULT_ENDPOINTS.domain_dns);
	assert.ok(req, 'the DNS route is requested');
	assert.equal(req.options.method, 'GET');
	assert.equal(req.options.headers['X-WP-Nonce'], 'wp-rest-nonce');

	assert.equal(nodes['.cast-domain-dns-label'].textContent, 'Publish the delegation records below');
	// The summary rows live inside the nested <dl>; the guidance walk reads the
	// whole subtree so the delegated HNS name, namespace, status and gateway
	// all surface.
	const ddTexts = collectClassText(nodes['.cast-domain-dns-copy'], 'dd');
	assert.ok(ddTexts.includes('name/'), 'the delegated HNS name is rendered');
	assert.ok(ddTexts.includes('hns'), 'the namespace is rendered');
	assert.ok(ddTexts.includes('waiting_delegation'), 'the delegation status is rendered');
	assert.ok(ddTexts.includes('gw.example.com'), 'the gateway is rendered');
	assert.match(liveRegion.textContent, /delegation/is);

	assertNoInnerHTML(Object.values(nodes));
});

test('dnsLabelFor treats onchain_managed as a fully confirmed binding', () => {
	const { client } = load();

	assert.equal(client.dnsLabelFor({ status: 'active' }), 'DNS delegation confirmed');
	assert.equal(client.dnsLabelFor({ status: 'onchain_managed' }), 'DNS delegation confirmed');
	assert.equal(client.dnsLabelFor({ status: 'waiting_delegation' }), 'Publish the delegation records below');
	assert.equal(client.dnsLabelFor({ status: 'pending' }), 'DNS delegation records');
	assert.equal(client.dnsLabelFor({}), 'DNS delegation records');
});

test('renderDomainDns renders managed ICANN guidance with the nameserver table', async () => {
	const domain_dns = {
		ok: true,
		status: 'ok',
		domain: Object.assign(
			defaultDomainRow({ domain: 'site.example.test', namespace: 'icann', status: 'waiting_delegation' }),
			{
				delegation: {
					mode: null,
					nameservers: ['ns1.pinner.xyz', 'ns2.pinner.xyz'],
					dnssec: 'secure',
					dnssec_error: null,
					parent_records: [
						{ type: 'NS', value: 'ns1.pinner.xyz,ns2.pinner.xyz' },
						{ type: 'DS', value: '12345 8 2 ABCDEF' },
					],
					authoritative_records: [],
				},
				checks: [],
			}
		),
		refusal: null,
	};
	const { client, nodes } = load({
		script: { domain_dns },
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	await client.domainDns('99');

	const texts = collectAllText(nodes['.cast-domain-dns-copy']);
	assert.ok(texts.includes(`Update your domain's nameservers at your registrar.`), 'the managed ICANN instruction is rendered');
	assert.ok(texts.includes(`Point your registrar's nameservers to the records below.`), 'the point-your-registrar line is rendered');
	assert.ok(texts.includes('Pinner manages your DNS, so the authoritative side is handled for you.'), 'the managed-authoritative line is rendered');
	assert.ok(texts.includes('Parent records (configure at your registrar)'), 'the parent-records heading is rendered');

	const tdTexts = collectClassText(nodes['.cast-domain-dns-copy'], 'td');
	assert.ok(tdTexts.includes('site.example.test'), 'the named-row cell carries the domain');
	assert.ok(tdTexts.includes('NS'), 'the type cell says NS');
	assert.ok(tdTexts.includes('ns1.pinner.xyz'), 'the first nameserver value renders');
	assert.ok(tdTexts.includes('ns2.pinner.xyz'), 'the second nameserver value renders');
	assert.ok(tdTexts.includes('12345 8 2 ABCDEF'), 'the DS parent record value renders verbatim');

	assert.ok(texts.includes('DNSSEC: secure'), 'the DNSSEC state renders verbatim');
	assert.equal(texts.some((t) => t.indexOf('DNSSEC error') !== -1), false, 'no DNSSEC error when none is present');
	assert.equal(
		texts.includes('Add the DNS records shown above at your registrar, then validate.'),
		false,
		'managed DNS never asks the operator to add/validate the records'
	);

	assertNoInnerHTML(Object.values(nodes));
});

test('renderDomainDns renders managed HNS on-chain guidance with nameservers and DNSSEC error', async () => {
	const domain_dns = {
		ok: true,
		status: 'ok',
		domain: Object.assign(
			defaultDomainRow({ domain: 'name/', namespace: 'hns', status: 'waiting_delegation' }),
			{
				delegation: {
					mode: 'inline',
					nameservers: ['ns1.pinner.xyz', 'ns2.pinner.xyz'],
					dnssec: 'secure',
					dnssec_error: 'dnssec-broken',
					parent_records: [
						{ type: 'NS', value: 'ns1.pinner.xyz,ns2.pinner.xyz' },
						{ type: 'DS', value: '12345 8 2 ABCDEF' },
					],
					authoritative_records: [],
				},
				checks: [],
			}
		),
		refusal: null,
	};
	const { client, nodes } = load({
		script: { domain_dns },
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	await client.domainDns('99');

	const texts = collectAllText(nodes['.cast-domain-dns-copy']);
	assert.ok(
		texts.includes('Publish the records below in the DNS/records area of your HNS wallet (on-chain).'),
		'the HNS on-chain instruction is rendered'
	);
	assert.ok(texts.includes(`The authoritative side is served via Pinner's synthetic nameservers.`), 'the inline authoritative line is rendered');
	assert.ok(texts.includes('Parent records (publish in your HNS wallet)'), 'the HNS parent-records heading is rendered');

	// Comma-joined parent NS values split onto their own rows.
	const tdTexts = collectClassText(nodes['.cast-domain-dns-copy'], 'td');
	assert.ok(tdTexts.includes('ns1.pinner.xyz'), 'the first split parent NS value renders');
	assert.ok(tdTexts.includes('ns2.pinner.xyz'), 'the second split parent NS value renders');

	// The nameservers list renders as a list (no NAME/TYPE/VALUE table for HNS).
	const liTexts = collectClassText(nodes['.cast-domain-dns-copy'], 'li');
	assert.ok(liTexts.includes('ns1.pinner.xyz'), 'the nameservers list carries ns1');
	assert.ok(liTexts.includes('ns2.pinner.xyz'), 'the nameservers list carries ns2');

	assert.ok(texts.includes('DNSSEC: secure'), 'the DNSSEC state renders verbatim');
	assert.ok(texts.includes('DNSSEC error: dnssec-broken'), 'the DNSSEC error renders verbatim');
	assert.equal(texts.includes(`Update your domain's nameservers at your registrar.`), false, 'HNS does not show the ICANN registrar copy');

	assertNoInnerHTML(Object.values(nodes));
});

test('renderDomainDns renders HNS on-chain-managed guidance from server-returned records only', async () => {
	// An HNS binding the server reports as onchain_managed: its DNS is served
	// by an external on-chain contract, so there is no Pinner-managed zone to
	// point at. The card must never claim Pinner manages the DNS and must show
	// only the server-returned DNSLink/TLSA guidance.
	const domain_dns = {
		ok: true,
		status: 'ok',
		domain: Object.assign(
			defaultDomainRow({ domain: 'name/', namespace: 'hns', dns_hosting_enabled: true, status: 'onchain_managed' }),
			{
				delegation: null,
				checks: [
					{ name: 'dnslink', ok: false, message: '', expected: 'dnslink=/ipns/k-ipns-7', found: '' },
					{ name: 'tlsa', ok: false, message: '', expected: '_443._tcp.name/ TLSA 3 1 1 abcdef', found: '' },
				],
			},
		),
		refusal: null,
	};
	const { client, nodes } = load({
		script: { domain_dns },
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	await client.domainDns('99');

	const texts = collectAllText(nodes['.cast-domain-dns-copy']);
	assert.ok(
		texts.includes('This domain is managed on-chain, so its DNS records are set on-chain, not by Pinner.'),
		'the on-chain-managed explanation renders'
	);
	assert.ok(texts.includes('dnslink=/ipns/k-ipns-7'), 'the server-returned DNSLink value renders');
	assert.ok(texts.includes('_443._tcp.name/ TLSA 3 1 1 abcdef'), 'the server-returned TLSA value renders');
	assert.equal(texts.includes('No delegation records are available for name/.'), false, 'the nil-delegation miss is not shown for an on-chain binding');
	assert.equal(texts.includes('Pinner manages your DNS, so the authoritative side is handled for you.'), false, 'on-chain-managed never claims Pinner manages DNS');
	assert.equal(texts.includes('Publish the records below in the DNS/records area of your HNS wallet (on-chain).'), false, 'the managed-HNS framing is absent for an on-chain binding');

	assertNoInnerHTML(Object.values(nodes));
});

test('renderDomainDns renders self-managed guidance with checks rows', async () => {
	const domain_dns = {
		ok: true,
		status: 'ok',
		domain: Object.assign(
			defaultDomainRow({ domain: 'site.example.test', namespace: 'icann', dns_hosting_enabled: false, status: 'waiting_delegation' }),
			{
				delegation: {
					mode: null,
					nameservers: [],
					dnssec: '',
					dnssec_error: null,
					parent_records: [],
					authoritative_records: [{ type: 'TXT', value: 'pinner-verify=AbCdEf123456' }],
				},
				checks: [
					{ name: 'pinner-verify', ok: false, message: '', expected: 'pinner-verify=AbCdEf123456', found: '' },
					{ name: 'dnslink', ok: false, message: '', expected: 'dnslink=/ipns/k-ipns-7', found: 'dnslink=/ipfs/QmWrong' },
				],
			}
		),
		refusal: null,
	};
	const { client, nodes } = load({
		script: { domain_dns },
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	await client.domainDns('99');

	const texts = collectAllText(nodes['.cast-domain-dns-copy']);
	assert.ok(
		texts.includes('Configure the parent records at your registrar, then point your DNS server at the authoritative records below.'),
		'the self-managed parent-records instruction renders'
	);
	assert.ok(texts.includes('Authoritative records (configure on your DNS server)'), 'the authoritative-records heading renders');
	assert.ok(texts.includes('pinner-verify=AbCdEf123456'), 'the authoritative TXT value renders');
	assert.ok(texts.includes('Add the DNS records shown above at your registrar, then validate.'), 'the self-managed validate callout renders');

	assert.ok(texts.includes('Validation checks'), 'the validation-checks heading renders');
	assert.ok(texts.includes('Publish this record:'), 'the publish-this-record label renders');
	assert.ok(texts.includes('dnslink=/ipns/k-ipns-7'), 'the expected value renders verbatim');
	assert.ok(texts.includes('Found instead:'), 'the found-instead label renders');
	assert.ok(texts.includes('dnslink=/ipfs/QmWrong'), 'the found value renders verbatim');
	assert.equal(texts.includes('Nameserver changes can take time to propagate. Try again in a few minutes.'), false, 'the guidance block alone does not force the retry copy');

	assertNoInnerHTML(Object.values(nodes));
});

test('domainValidate posts once and renders the server-computed checks with retry copy', async () => {
	const { client, calls, nodes } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const result = await client.domainValidate('99');

	assert.equal(result, true, 'a successful validate resolves true');
	const req = calls.find((c) => c.url === DEFAULT_ENDPOINTS.domain_validate);
	assert.ok(req, 'the validate route is requested');
	assert.equal(req.options.method, 'POST');
	assert.equal(req.options.headers['X-WP-Nonce'], 'wp-rest-nonce');
	assert.deepEqual(JSON.parse(req.options.body), { domain_id: '99' });

	assert.equal(nodes['.cast-domain-dns-label'].textContent, 'Publish the delegation records below');
	const texts = collectAllText(nodes['.cast-domain-dns-copy']);
	assert.ok(texts.includes('DNS validation is incomplete.'), 'the incomplete-result line renders');
	assert.ok(texts.includes('DNS records not yet published.'), 'the server message renders verbatim');
	assert.ok(texts.includes('Validation checks'), 'the validation-checks heading renders');
	assert.ok(texts.includes('Publish this record:'), 'the publish-this-record label renders');
	assert.ok(texts.includes('dnslink=/ipns/k-ipns-7'), 'the expected value renders verbatim');
	assert.ok(texts.includes('Found instead:'), 'the found-instead label renders');
	assert.ok(texts.includes('dnslink=/ipfs/QmWrong'), 'the found value renders verbatim');
	assert.ok(
		texts.includes('Nameserver changes can take time to propagate. Try again in a few minutes.'),
		'while the DNS is still invalid the propagation retry copy renders'
	);

	assertNoInnerHTML(Object.values(nodes));
});

test('domainValidate confirms successful DNS without the retry copy', async () => {
	const { client, nodes } = load({
		script: {
			domain_validate: {
				ok: true,
				status: 'ok',
				validation: {
					id: 42,
					domain: 'site.example.test',
					valid: true,
					message: 'DNS records validated.',
					reason: '',
					checks: [
						{ name: 'dnslink', ok: true, message: '', expected: 'dnslink=/ipns/k-ipns-7', found: '' },
					],
				},
				refusal: null,
			},
		},
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const result = await client.domainValidate('99');

	assert.equal(result, true, 'a valid validate resolves true');
	assert.equal(nodes['.cast-domain-dns-label'].textContent, 'DNS delegation confirmed');
	const texts = collectAllText(nodes['.cast-domain-dns-copy']);
	assert.ok(texts.includes('DNS records validated.'), 'the validated-result line renders');
	assert.ok(texts.includes('DNS records validated.'), 'the server message renders verbatim');
	assert.equal(
		texts.includes('Nameserver changes can take time to propagate. Try again in a few minutes.'),
		false,
		'valid DNS drops the propagation retry copy'
	);
});

test('domainValidate failure shows the propagation retry copy', async () => {
	const { client, nodes } = load({
		script: { domain_validate: { __httpError: true, status: 500 } },
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const result = await client.domainValidate('99');

	assert.equal(result, false, 'a failed validate resolves false');
	assert.equal(nodes['.cast-domain-dns-label'].textContent, 'DNS validation could not be completed');
	const texts = collectAllText(nodes['.cast-domain-dns-copy']);
	assert.ok(
		texts.includes('Nameserver changes can take time to propagate. Try again in a few minutes.'),
		'the propagation retry copy renders on failure'
	);
});

test('domainValidate shows Validating… while in flight and blocks a second call', async () => {
	let resolveFetch;
	const fetchDouble = () => new Promise((resolve) => { resolveFetch = resolve; });
	const markers = [makeDomainMarker('validate')];
	const { client, nodes } = load({
		markers,
		fetch: fetchDouble,
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const first = client.domainValidate('99');
	await tick();

	assert.equal(markers[0].textContent, 'Validating...', 'the button reads Validating... while in flight');
	assert.equal(markers[0].disabled, true, 'the button is disabled while in flight');
	assert.equal(nodes['.cast-domain-dns-label'].textContent, 'Validating DNS records...', 'the label swaps to the working line');

	// A second click while in flight never stacks a second request.
	const second = await client.domainValidate('99');
	assert.equal(second, false, 'a concurrent validate is refused');

	resolveFetch({ ok: true, status: 200, json: async () => defaultDomainValidate() });
	const result = await first;
	assert.equal(result, true, 'the first validate completes once the fetch resolves');
	assert.equal(markers[0].textContent, 'I made the changes — check again', 'the button label is restored');
	assert.equal(markers[0].disabled, false, 'the button is re-enabled');
});

test('domainValidate is inert without the localized nonce', async () => {
	const { client, calls } = load({
		config: { nonce: null, poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const result = await client.domainValidate('99');

	assert.equal(result, false, 'a missing nonce refuses the validate');
	assert.equal(calls.length, 0, 'nothing is ever requested without the localized nonce');
});

test('domainValidate is inert when the allowlist omits it', async () => {
	const { client, calls } = load({
		config: {
			endpoints: DEFAULT_ENDPOINTS,
			domain_actions: ['domain_list', 'domain_dns'],
			poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 },
		},
	});

	const result = await client.domainValidate('99');

	assert.equal(result, false, 'an allowlisted-out validate is refused');
	assert.equal(
		calls.some((c) => c.url.indexOf('/domains/validate') !== -1),
		false,
		'no validate request ever fires'
	);
});

test('a data-domain-action validate marker fires the validate route', async () => {
	const markers = [makeDomainMarker('validate', { 'data-domain-id': '99' })];
	const { calls } = load({
		markers,
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	markers[0].click();
	await tick();

	const post = calls.find((c) => c.url === DEFAULT_ENDPOINTS.domain_validate);
	assert.ok(post, 'the validate route is requested through the marker');
	assert.deepEqual(JSON.parse(post.options.body), { domain_id: '99' });
});

test('domainSsl fetches the SSL status and renders the SSL copy', async () => {
	const { client, calls, nodes } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const result = await client.domainSsl('site.example.test');

	assert.equal(result, true, 'a successful SSL read resolves true');
	const req = calls.find((c) => c.url === DEFAULT_ENDPOINTS.domain_ssl);
	assert.ok(req, 'the SSL route is requested');
	assert.equal(req.options.method, 'GET');
	assert.equal(req.options.headers['X-WP-Nonce'], 'wp-rest-nonce');
	assert.equal(nodes['.cast-domain-ssl-label'].textContent, 'SSL active');
});

test('domain operations are inert without the localized nonce', async () => {
	const { client, calls } = load({
		config: { nonce: null, poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const dns = await client.domainDns('site.example.test');
	const validated = await client.domainValidate('99');

	assert.equal(dns, false, 'a missing nonce refuses the DNS read');
	assert.equal(validated, false, 'a missing nonce refuses the validation');
	assert.equal(calls.length, 0, 'nothing is ever requested without the localized nonce');
});

test('domain operations are inert when the route endpoint is missing', async () => {
	const endpoints = Object.assign({}, DEFAULT_ENDPOINTS);
	delete endpoints.domain_dns;
	delete endpoints.domain_validate;
	const { client, calls } = load({
		config: { endpoints, poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const dns = await client.domainDns('site.example.test');
	const validated = await client.domainValidate('99');

	assert.equal(dns, false, 'a missing endpoint refuses the DNS read');
	assert.equal(validated, false, 'a missing endpoint refuses the validation');
	assert.equal(
		calls.some((c) => c.url.indexOf('/domains/') !== -1),
		false,
		'a missing endpoint is never requested'
	);
});

/* ------------------------ awaiting website (S1) ---------------------------- */

/** A parked run awaiting a website, mirroring the PublishSetupService report. */
function awaitingStatus(overrides) {
	return statusWith(
		Object.assign(
			{
				run_status: 'paused',
				run_stage: 'idle',
				run_active: true,
				awaiting_website: true,
				awaiting_cid: 'QmParkedCid',
			},
			overrides || {}
		)
	);
}

test('stateLabelOf/contextOf/progressCountLabelOf mirror the parked awaiting copy', () => {
	const { client } = load();

	const awaiting = awaitingStatus();

	// The chip swaps to the design-doc S1 stage label while awaiting.
	assert.equal(client.stateLabelOf(awaiting), 'Sent — waiting for a website');
	// An ordinary paused run keeps the plain chip.
	assert.equal(client.stateLabelOf(statusWith({ run_status: 'paused' })), 'Paused');

	// The "why" copy leads with the parked wait (doc path-c), never the plain
	// "Publishing is paused." line.
	assert.equal(
		client.contextOf(awaiting),
		'Your upload was sent. The run is waiting for a website — open Pinner and create one or attach one to this workspace, then come back.'
	);

	// The progress line names the wait — no fabricated percentage or count.
	assert.equal(client.progressCountLabelOf(awaiting), 'Waiting for a website — the run is paused.');
});

test('can("artifact") permits the resume-with-same-CID for an awaiting run', () => {
	const { client } = load();

	// A parked awaiting run (no identity, paused) may resume through the
	// artifact/publishExisting route with its preserved CID.
	assert.equal(client.can('artifact', awaitingStatus()), true, 'awaiting resume is allowed');
	assert.equal(client.can('artifact', statusWith({ run_status: 'paused', identity: null })), false, 'a plain paused run cannot');
});


/* ------------------------ address wizard (S5) ---------------------------- */

/** A status carrying a persisted first-publish destination. */
function destinationStatus(destination, overrides) {
	return statusWith(
		Object.assign(
			{
				destination: {
					lifecycle: 'confirmed',
					destination:
						destination || {
							source: 'custom',
							domain: 'site.example.test',
							namespace: 'icann',
							dns_hosting_enabled: true,
							platform_domain: null,
							platform_namespace: null,
							generate: null,
							label: null,
							website_id: null,
						},
				},
			},
			overrides || {}
		)
	);
}

/** A native source radio double the wizard orchestrator toggles. */
function makeAddressRadio(value, checked) {
	const node = makeNode('cast-address-source-input');
	node.value = value;
	node.checked = !!checked;
	node.focus = function () {
		this.focused = true;
	};
	return node;
}

test('applyStatus renders the address summary without force-closing the wizard', async () => {
	const draft = destinationStatus(null, {
		destination: {
			lifecycle: 'draft',
			destination: {
				source: 'custom',
				domain: 'site.example.test',
				namespace: 'icann',
				dns_hosting_enabled: true,
				platform_domain: null,
				platform_namespace: null,
				generate: null,
				label: null,
				website_id: null,
			},
		},
	});
	const { nodes } = load({
		script: { status: draft },
	});

	await tick();

	assert.equal(nodes['.cast-address-source'].textContent, 'Your own domain');
	assert.equal(nodes['.cast-address-value'].textContent, 'site.example.test');
	assert.equal(nodes['.cast-address-wizard'].hidden, false, 'a status paint never force-closes the wizard while the choice is a draft');
});

test('a confirmed status never opens the mutation wizard', async () => {
	const review = Object.assign(makeNode('cast-address-review'), { 'data-cast-address-action': 'review' });
	const { nodes } = load({
		addressControls: [review],
		initialHidden: ['.cast-address-wizard'],
		script: { status: destinationStatus() }, // lifecycle 'confirmed'
	});

	await tick();
	review.click();
	await tick();

	assert.equal(nodes['.cast-address-wizard'].hidden, true, 'a stale confirmed status never opens the wizard');
});

test('a polled confirmed status closes an open wizard and blocks the confirm submit', async () => {
	const confirm = Object.assign(makeNode('cast-address-confirm'), { 'data-cast-address-action': 'confirm' });
	const draft = destinationStatus(null, {
		destination: {
			lifecycle: 'draft',
			destination: {
				source: 'custom',
				domain: 'site.example.test',
				namespace: 'icann',
				dns_hosting_enabled: true,
				platform_domain: null,
				platform_namespace: null,
				generate: null,
				label: null,
				website_id: null,
			},
		},
	});
	const { calls, nodes, client } = load({
		addressControls: [confirm],
		addressSourceRadios: [makeAddressRadio('custom', true)],
		idNodes: {
			'cast-address-custom-domain': Object.assign(makeNode('cast-address-custom-domain'), { value: 'site.example.test' }),
			'cast-address-custom-namespace': Object.assign(makeNode('cast-address-custom-namespace'), { value: 'icann' }),
		},
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: { status: draft },
	});

	await tick();
	client.openAddressWizard();
	await tick();
	assert.equal(nodes['.cast-address-wizard'].hidden, false, 'the wizard opens while the choice is a draft');

	// A later poll reports the choice confirmed elsewhere: the mutation
	// surface freezes.
	client.applyStatus(destinationStatus());
	await tick();
	assert.equal(nodes['.cast-address-wizard'].hidden, true, 'the polled confirmed status closes the wizard');

	confirm.click();
	await tick();

	assert.equal(
		calls.some((c) => c.url === DEFAULT_ENDPOINTS.destination_save || c.url === DEFAULT_ENDPOINTS.destination_confirm),
		false,
		'no destination mutation is submitted for a frozen address'
	);
	assert.match(nodes['.cast-address-wizard-error'].textContent, /can no longer be changed/i, 'the frozen refusal is stated');
});

test('applyStatus leaves the choose prompt when no destination exists', async () => {
	const { nodes } = load({
		// The server paint renders the wizard hidden; the poll must not reveal it.
		initialHidden: ['.cast-address-wizard'],
		script: { status: statusWith({}) },
	});

	await tick();

	assert.equal(nodes['.cast-address-wizard'].hidden, true, 'no wizard revealed without a destination');
	assert.equal(nodes['.cast-address-prompt'].hidden, false, 'the choose prompt stays the entry point');
});

test('the choose-address control opens the wizard and focuses the first choice', async () => {
	const choose = Object.assign(makeNode('cast-address-choose'), { 'data-cast-address-action': 'choose' });
	const radios = [makeAddressRadio('platform', false), makeAddressRadio('custom', false), makeAddressRadio('existing', false)];
	const { nodes } = load({
		addressControls: [choose],
		addressSourceRadios: radios,
		script: { status: statusWith({}) },
	});

	await tick();
	choose.click();
	await tick();

	assert.equal(nodes['.cast-address-wizard'].hidden, false, 'the wizard is revealed');
	assert.equal(radios[0].focused, true, 'focus moves to the first choice');
});

test('switching the source radio reveals only that data-cast-address-branch', async () => {
	const radios = [makeAddressRadio('platform', true), makeAddressRadio('custom', false), makeAddressRadio('existing', false)];
	// Simulate the browser: checking a radio unchecks the others, then the
	// wizard's own change handler (bound at init) runs.
	const pick = (radio) => {
		radios.forEach((other) => {
			other.checked = other === radio;
		});
		if (radio.listeners.change) {
			radio.listeners.change();
		}
	};
	const { nodes } = load({
		addressSourceRadios: radios,
		// The server paint hides the non-checked source's branches.
		initialHidden: ['[data-cast-address-branch="custom"]', '[data-cast-address-branch="existing"]'],
		script: { status: statusWith({}) },
	});

	await tick();
	pick(radios[1]); // user picks "use a domain you own"

	assert.equal(nodes['[data-cast-address-branch="custom"]'].hidden, false, 'the custom branch is revealed');
	assert.equal(nodes['[data-cast-address-branch="platform"]'].hidden, true, 'the platform branch is hidden');
	assert.equal(nodes['[data-cast-address-branch="existing"]'].hidden, true, 'the existing branch is hidden');
});

test('selecting the existing branch fetches the account sites into the picker', async () => {
	const radios = [makeAddressRadio('platform', false), makeAddressRadio('custom', false), makeAddressRadio('existing', false)];
	const select = Object.assign(makeNode('cast-address-existing-website'), { value: '' });
	const { calls, nodes } = load({
		addressSourceRadios: radios,
		idNodes: { 'cast-address-existing-website': select },
		initialHidden: ['.cast-address-existing-loading', '.cast-address-existing-error'],
		script: {
			status: statusWith({}),
			website_available: {
				listed: true,
				status: 'ok',
				websites: [{ website_id: '66', domain: 'blog.example.com', status: 'active', target_hash: 'QmA', target_type: 'ipfs' }],
				refusal: null,
			},
		},
	});

	await tick();
	radios[2].checked = true;
	radios[2].listeners.change();

	assert.equal(nodes['.cast-address-existing-loading'].hidden, false, 'the loading state shows while the picker fetch is in flight');
	await tick();

	assert.equal(calls.some((c) => c.url === DEFAULT_ENDPOINTS.website_available), true, 'the picker fetch fires when the branch is chosen');
	assert.equal(nodes['.cast-address-existing-loading'].hidden, true, 'the loading state clears when the list lands');
	assert.equal(nodes['.cast-address-existing-error'].hidden, true, 'no error state on success');
	assert.equal(select.children.length, 1, 'the fetched site is listed');
	assert.equal(select.children[0].value, '66');
});

test('the existing-site picker shows a distinct error state when the fetch fails', async () => {
	const radios = [makeAddressRadio('platform', false), makeAddressRadio('custom', false), makeAddressRadio('existing', false)];
	const select = Object.assign(makeNode('cast-address-existing-website'), { value: '' });
	const { nodes } = load({
		addressSourceRadios: radios,
		idNodes: { 'cast-address-existing-website': select },
		initialHidden: ['.cast-address-existing-loading', '.cast-address-existing-error'],
		script: {
			status: statusWith({}),
			website_available: { __httpError: true, status: 500 },
		},
	});

	await tick();
	radios[2].checked = true;
	radios[2].listeners.change();
	await tick();

	assert.equal(nodes['.cast-address-existing-error'].hidden, false, 'the error state is visible after a failed fetch');
	assert.match(nodes['.cast-address-existing-error'].textContent, /could not load/i, 'the error names the failure');
	assert.equal(nodes['.cast-address-existing-loading'].hidden, true, 'the loading state clears on failure');
	assert.equal(select.children.length, 0, 'a failed fetch lists nothing');
});

test('the existing-site picker shows the empty state when the account has no sites', async () => {
	const radios = [makeAddressRadio('platform', false), makeAddressRadio('custom', false), makeAddressRadio('existing', false)];
	const select = Object.assign(makeNode('cast-address-existing-website'), { value: '' });
	const { nodes } = load({
		addressSourceRadios: radios,
		idNodes: { 'cast-address-existing-website': select },
		initialHidden: ['.cast-address-existing-loading', '.cast-address-existing-error', '.cast-address-existing-empty'],
		script: {
			status: statusWith({}),
			website_available: { listed: true, status: 'ok', websites: [], refusal: null },
		},
	});

	await tick();
	radios[2].checked = true;
	radios[2].listeners.change();
	await tick();

	assert.equal(nodes['.cast-address-existing-empty'].hidden, false, 'the empty note shows when no sites exist');
	assert.equal(nodes['.cast-address-existing-error'].hidden, true, 'an empty list is not an error');
	assert.equal(nodes['.cast-address-existing-loading'].hidden, true, 'the loading state clears');
});

test('a normal status poll does not hide an open unconfirmed wizard or discard its fields', async () => {
	const choose = Object.assign(makeNode('cast-address-choose'), { 'data-cast-address-action': 'choose' });
	const radios = [makeAddressRadio('platform', false), makeAddressRadio('custom', true), makeAddressRadio('existing', false)];
	const domain = Object.assign(makeNode('cast-address-custom-domain'), { value: 'shop.example.com' });
	const { nodes, client } = load({
		addressControls: [choose],
		addressSourceRadios: radios,
		idNodes: { 'cast-address-custom-domain': domain },
		script: { status: statusWith({}) },
	});

	await tick();
	choose.click();
	await tick();
	assert.equal(nodes['.cast-address-wizard'].hidden, false, 'the wizard is open');

	// A routine poll lands while the user is mid-wizard.
	client.applyStatus(statusWith({}));

	assert.equal(nodes['.cast-address-wizard'].hidden, false, 'the poll must not hide the open wizard');
	assert.equal(domain.value, 'shop.example.com', 'the poll must not discard field selections');
});

test('the platform review says Pinner will create a free address', async () => {
	const radios = [makeAddressRadio('platform', true), makeAddressRadio('custom', false), makeAddressRadio('existing', false)];
	const { nodes } = load({
		addressSourceRadios: radios,
		script: { status: statusWith({}) },
	});

	await tick();
	radios[0].listeners.change();
	await tick();

	assert.equal(nodes['.cast-address-review-box'].hidden, false, 'the review is shown for a valid platform choice');
	assert.match(nodes['.cast-address-review-copy'].textContent, /Pinner will create a free address/i);
	assert.doesNotMatch(nodes['.cast-address-review-copy'].textContent, /available at/i, 'a generated address is never phrased as "available at …"');
});

test('the custom review names the domain the user typed', async () => {
	const radios = [makeAddressRadio('platform', false), makeAddressRadio('custom', true), makeAddressRadio('existing', false)];
	const idNodes = {
		'cast-address-custom-domain': Object.assign(makeNode('cast-address-custom-domain'), { value: 'shop.example.com' }),
		'cast-address-custom-namespace': Object.assign(makeNode('cast-address-custom-namespace'), { value: 'icann' }),
	};
	const { nodes } = load({
		addressSourceRadios: radios,
		addressDnsRadios: [Object.assign(makeAddressRadio('managed', true), { className: 'cast-address-dns-input' })],
		idNodes,
		script: { status: statusWith({}) },
	});

	await tick();
	radios[1].listeners.change();
	await tick();

	assert.equal(nodes['.cast-address-review-box'].hidden, false);
	assert.equal(
		nodes['.cast-address-review-copy'].textContent,
		'Your site will be available at shop.example.com. You cannot change this address after your first publish.'
	);
});

test('the custom wizard defaults to Pinner-managed DNS when no choice is made', async () => {
	const radios = [makeAddressRadio('platform', false), makeAddressRadio('custom', true), makeAddressRadio('existing', false)];
	const idNodes = {
		'cast-address-custom-domain': Object.assign(makeNode('cast-address-custom-domain'), { value: 'shop.example.com' }),
		'cast-address-custom-namespace': Object.assign(makeNode('cast-address-custom-namespace'), { value: 'icann' }),
	};
	const { client } = load({
		addressSourceRadios: radios,
		idNodes,
		script: { status: statusWith({}) },
	});

	await tick();
	const payload = client.buildAddressPayload();

	assert.equal(payload.dns_hosting_enabled, true, 'dns_hosting_enabled defaults to managed (true)');
});

test('picking self-managed DNS in the advanced disclosure sends dns_hosting_enabled false', async () => {
	const radios = [makeAddressRadio('platform', false), makeAddressRadio('custom', true), makeAddressRadio('existing', false)];
	const dnsRadios = [
		Object.assign(makeAddressRadio('managed', false), { className: 'cast-address-dns-input' }),
		Object.assign(makeAddressRadio('self', true), { className: 'cast-address-dns-input' }),
	];
	const idNodes = {
		'cast-address-custom-domain': Object.assign(makeNode('cast-address-custom-domain'), { value: 'shop.example.com' }),
		'cast-address-custom-namespace': Object.assign(makeNode('cast-address-custom-namespace'), { value: 'hns' }),
	};
	const { client } = load({
		addressSourceRadios: radios,
		addressDnsRadios: dnsRadios,
		idNodes,
		script: { status: statusWith({}) },
	});

	await tick();
	const payload = client.buildAddressPayload();

	assert.equal(payload.dns_hosting_enabled, false, 'the explicit self-managed choice is honored');
});

test('the namespace note follows the chosen domain type', async () => {
	const radios = [makeAddressRadio('platform', false), makeAddressRadio('custom', true), makeAddressRadio('existing', false)];
	const namespaceSelect = Object.assign(makeNode('cast-address-custom-namespace'), { value: 'icann' });
	const icannNote = Object.assign(makeNode('cast-address-namespace-note'), {
		'data-cast-namespace-note': 'icann',
		textContent: 'Pinner creates and manages the DNS records for this domain.',
	});
	const hnsNote = Object.assign(makeNode('cast-address-namespace-note'), {
		'data-cast-namespace-note': 'hns',
		textContent: 'For Handshake (HNS) names, the DNS records are held on-chain at the name’s parent. Pinner manages this for you, and the exact nameserver values may appear here later.',
	});
	const { client } = load({
		addressSourceRadios: radios,
		addressNamespaceNotes: [icannNote, hnsNote],
		idNodes: {
			'cast-address-custom-domain': Object.assign(makeNode('cast-address-custom-domain'), { value: 'shop.example.com' }),
			'cast-address-custom-namespace': namespaceSelect,
		},
		script: { status: statusWith({}) },
	});

	await tick();
	client.openAddressWizard();
	await tick();
	assert.equal(icannNote.hidden, false, 'the ICANN note is shown for the default namespace');
	assert.equal(hnsNote.hidden, true, 'the HNS note is hidden until the namespace is HNS');

	namespaceSelect.value = 'hns';
	namespaceSelect.listeners.change();
	await tick();
	assert.equal(hnsNote.hidden, false, 'switching to HNS reveals the HNS copy');
	assert.equal(icannNote.hidden, true, '…and hides the ICANN copy');

	namespaceSelect.value = 'icann';
	namespaceSelect.listeners.change();
	await tick();
	assert.equal(icannNote.hidden, false, 'switching back restores the ICANN copy');
	assert.equal(hnsNote.hidden, true, '…and hides the HNS copy again');
});

test('the pre-create payload never carries a HIP-5 choice', async () => {
	const radios = [makeAddressRadio('platform', false), makeAddressRadio('custom', true), makeAddressRadio('existing', false)];
	const dnsRadios = [Object.assign(makeAddressRadio('managed', true), { className: 'cast-address-dns-input' })];
	const idNodes = {
		'cast-address-custom-domain': Object.assign(makeNode('cast-address-custom-domain'), { value: 'acme.example' }),
		'cast-address-custom-namespace': Object.assign(makeNode('cast-address-custom-namespace'), { value: 'hns' }),
	};
	const { client } = load({
		addressSourceRadios: radios,
		addressDnsRadios: dnsRadios,
		idNodes,
		script: { status: statusWith({}) },
	});

	await tick();
	const payload = client.buildAddressPayload();

	assert.ok(
		!JSON.stringify(payload).toLowerCase().includes('hip-5'),
		'HIP-5 is post-binding server state — never a pre-create field',
	);
	assert.deepEqual(
		Object.keys(payload).sort(),
		['dns_hosting_enabled', 'domain', 'namespace', 'source'],
		'only the allowlisted pre-create fields are sent',
	);
});

test('the existing review names the picked site', async () => {
	const radios = [makeAddressRadio('platform', false), makeAddressRadio('custom', false), makeAddressRadio('existing', true)];
	const select = Object.assign(makeNode('cast-address-existing-website'), { value: '66' });
	const { nodes } = load({
		addressSourceRadios: radios,
		idNodes: { 'cast-address-existing-website': select },
		script: {
			status: statusWith({}),
			website_available: {
				listed: true,
				status: 'ok',
				websites: [{ website_id: '66', domain: 'blog.example.com', status: 'active', target_hash: 'QmA', target_type: 'ipfs' }],
				refusal: null,
			},
		},
	});

	await tick();
	radios[2].listeners.change(); // fires the picker fetch
	await tick(); // the list lands
	select.value = '66';
	select.listeners.change();

	assert.equal(nodes['.cast-address-review-box'].hidden, false);
	assert.match(nodes['.cast-address-review-copy'].textContent, /available at blog\.example\.com/);
});

test('confirming a custom address saves, confirms, then starts the first publish', async () => {
	const confirm = Object.assign(makeNode('cast-address-confirm'), { 'data-cast-address-action': 'confirm' });
	const idNodes = {
		'cast-address-custom-domain': Object.assign(makeNode('cast-address-custom-domain'), { value: 'site.example.test' }),
		'cast-address-custom-namespace': Object.assign(makeNode('cast-address-custom-namespace'), { value: 'icann' }),
	};
	const dnsRadios = [
		Object.assign(makeAddressRadio('managed', true), { className: 'cast-address-dns-input' }),
		Object.assign(makeAddressRadio('self', false), { className: 'cast-address-dns-input' }),
	];
	const { calls, nodes } = load({
		addressControls: [confirm],
		addressSourceRadios: [makeAddressRadio('platform', false), makeAddressRadio('custom', true), makeAddressRadio('existing', false)],
		addressDnsRadios: dnsRadios,
		idNodes,
		script: {
			status: statusWith({}),
			start: { queued: true, status: 'queued', run_id: 'r-first' },
		},
	});

	await tick();
	confirm.click();
	await tick();
	await tick();

	const urls = calls.map((c) => c.url);
	const saveIndex = urls.indexOf(DEFAULT_ENDPOINTS.destination_save);
	const confirmIndex = urls.indexOf(DEFAULT_ENDPOINTS.destination_confirm);
	const startIndex = urls.indexOf(DEFAULT_ENDPOINTS.start);
	assert.ok(saveIndex !== -1, 'the destination save route is posted');
	assert.ok(confirmIndex !== -1 && confirmIndex > saveIndex, 'the confirm route is posted after the save');
	assert.ok(startIndex !== -1 && startIndex > confirmIndex, 'the first publish starts after the confirm');
	const save = calls[saveIndex];
	assert.equal(save.options.method, 'POST');
	assert.deepEqual(JSON.parse(save.options.body), {
		source: 'custom',
		domain: 'site.example.test',
		namespace: 'icann',
		dns_hosting_enabled: true,
	});
	assert.equal(nodes['.cast-address-wizard'].hidden, true, 'the wizard closes after a confirmed address');
});

test('a refused save surfaces friendly copy and stays in the wizard', async () => {
	const confirm = Object.assign(makeNode('cast-address-confirm'), { 'data-cast-address-action': 'confirm' });
	const idNodes = {
		'cast-address-custom-domain': Object.assign(makeNode('cast-address-custom-domain'), { value: 'site.example.test' }),
		'cast-address-custom-namespace': Object.assign(makeNode('cast-address-custom-namespace'), { value: 'icann' }),
	};
	const { calls, nodes, client } = load({
		addressControls: [confirm],
		addressSourceRadios: [makeAddressRadio('platform', false), makeAddressRadio('custom', true), makeAddressRadio('existing', false)],
		addressDnsRadios: [Object.assign(makeAddressRadio('managed', true), { className: 'cast-address-dns-input' })],
		idNodes,
		script: {
			status: statusWith({}),
			destination_save: { saved: false, status: 'refused', refusal: 'confirmed_cannot_change', lifecycle: 'confirmed' },
		},
	});

	await tick();
	client.openAddressWizard(); // the user is looking at the wizard
	confirm.click();
	await tick();

	assert.match(nodes['.cast-address-wizard-error'].textContent, /can no longer be changed/i);
	assert.equal(nodes['.cast-address-wizard'].hidden, false, 'the wizard stays open on a refusal');
	assert.equal(
		calls.some((c) => c.url === DEFAULT_ENDPOINTS.destination_confirm || c.url === DEFAULT_ENDPOINTS.start),
		false,
		'no confirm or start fires after a refused save'
	);
});

test('the confirm button reads "Create address" while the site is not publish-ready', async () => {
	const { nodes, client } = load({
		addressSourceRadios: [makeAddressRadio('platform', true)],
		script: { status: statusWith({ has_eligible_content: false }) },
	});

	await tick();
	client.openAddressWizard();
	await tick();

	assert.equal(nodes['.cast-address-confirm'].textContent, 'Create address', 'no publish is promised while the server will refuse a start');
	assert.doesNotMatch(nodes['.cast-address-confirm'].textContent, /publish/i, 'the button never claims a publish it will not attempt');
});

test('the confirm button reads "Create address and publish" when the site is publish-ready', async () => {
	const { nodes, client } = load({
		addressSourceRadios: [makeAddressRadio('platform', true)],
		script: { status: statusWith({}) },
	});

	await tick();
	client.openAddressWizard();
	await tick();

	assert.equal(nodes['.cast-address-confirm'].textContent, 'Create address and publish', 'a ready site keeps the create-and-publish promise');
});

test('confirming while not publish-ready saves and confirms the address without starting a publish', async () => {
	const confirm = Object.assign(makeNode('cast-address-confirm'), { 'data-cast-address-action': 'confirm' });
	const idNodes = {
		'cast-address-custom-domain': Object.assign(makeNode('cast-address-custom-domain'), { value: 'site.example.test' }),
		'cast-address-custom-namespace': Object.assign(makeNode('cast-address-custom-namespace'), { value: 'icann' }),
	};
	const { calls, nodes } = load({
		addressControls: [confirm],
		addressSourceRadios: [makeAddressRadio('platform', false), makeAddressRadio('custom', true), makeAddressRadio('existing', false)],
		addressDnsRadios: [Object.assign(makeAddressRadio('managed', true), { className: 'cast-address-dns-input' })],
		idNodes,
		script: { status: statusWith({ has_eligible_content: false }) },
	});

	await tick();
	confirm.click();
	await tick();
	await tick();

	const urls = calls.map((c) => c.url);
	assert.ok(urls.includes(DEFAULT_ENDPOINTS.destination_save), 'the address is saved');
	assert.ok(urls.includes(DEFAULT_ENDPOINTS.destination_confirm), 'the address is confirmed');
	assert.equal(urls.includes(DEFAULT_ENDPOINTS.start), false, 'no publish is attempted while the site is not publish-ready');
	assert.match(nodes['.cast-address-wizard-result'].textContent, /address is confirmed/i, 'the result says the address is confirmed');
	assert.match(nodes['.cast-address-wizard-result'].textContent, /publishable content/i, '…and truthfully names what unblocks publishing');
	assert.doesNotMatch(nodes['.cast-address-wizard-result'].textContent, /queued/i, 'no publish is claimed as queued');
});

test('a no_eligible_content start refusal after a race surfaces clear copy', async () => {
	const confirm = Object.assign(makeNode('cast-address-confirm'), { 'data-cast-address-action': 'confirm' });
	const idNodes = {
		'cast-address-custom-domain': Object.assign(makeNode('cast-address-custom-domain'), { value: 'site.example.test' }),
		'cast-address-custom-namespace': Object.assign(makeNode('cast-address-custom-namespace'), { value: 'icann' }),
	};
	const { calls, nodes } = load({
		addressControls: [confirm],
		addressSourceRadios: [makeAddressRadio('platform', false), makeAddressRadio('custom', true), makeAddressRadio('existing', false)],
		addressDnsRadios: [Object.assign(makeAddressRadio('managed', true), { className: 'cast-address-dns-input' })],
		idNodes,
		script: {
			// The client saw a ready report and attempts the start…
			status: statusWith({}),
			// …but the server's first-publish gate refuses (content vanished in the race).
			start: { queued: false, status: 'refused', refusal: 'no_eligible_content' },
		},
	});

	await tick();
	confirm.click();
	await tick();
	await tick();

	assert.ok(calls.some((c) => c.url === DEFAULT_ENDPOINTS.start), 'the start was attempted from a ready report');
	const spoken = nodes['.cast-address-wizard-result'].textContent + ' ' + nodes['.cast-address-wizard-error'].textContent;
	assert.match(spoken, /address is confirmed/i, 'the confirmed address is acknowledged');
	assert.match(spoken, /no publishable content/i, 'the refusal explains why nothing published');
	assert.doesNotMatch(spoken, /could not be saved/i, 'the generic save-failure copy is not shown for a start refusal');
});

test('the existing branch lists the Pinner sites from the account', async () => {
	const radios = [makeAddressRadio('platform', false), makeAddressRadio('custom', false), makeAddressRadio('existing', false)];
	const select = Object.assign(makeNode('cast-address-existing-website'), { value: '' });
	const { nodes } = load({
		addressSourceRadios: radios,
		idNodes: { 'cast-address-existing-website': select },
		script: {
			status: statusWith({}),
			website_available: {
				listed: true,
				status: 'ok',
				websites: [
					{ website_id: '66', domain: 'blog.example.com', status: 'active', target_hash: 'QmA', target_type: 'ipfs' },
					{ website_id: '77', domain: 'shop.example.com', status: 'active', target_hash: 'QmB', target_type: 'ipfs' },
				],
				refusal: null,
			},
		},
	});

	await tick();
	// Picking the existing branch lists the account's sites on demand.
	radios[2].checked = true;
	radios[2].listeners.change();
	await tick();

	assert.equal(select.children.length, 2, 'both account sites are listed');
	assert.equal(select.children[0].value, '66');
	assert.equal(nodes['.cast-address-existing-empty'].hidden, true, 'the empty note hides when sites exist');
});

test('the copy control copies the value pinned in its data-cast-copy attribute', async () => {
	const copied = [];
	const copy = Object.assign(makeNode('cast-domain-copy'), { 'data-cast-copy': 'site.example.test' });
	load({
		copyControls: [copy],
		navigator: { clipboard: { writeText: (value) => copied.push(value) } },
		script: { status: statusWith({}) },
	});

	await tick();
	copy.click();
	await tick();

	assert.deepEqual(copied, ['site.example.test'], 'the pinned value is copied, never a re-parsed one');
});

test('the connect-card validate marker fires the validate route with the domain id', async () => {
	const marker = makeDomainMarker('validate', { 'data-domain-id': '99' });
	const { calls } = load({
		markers: [marker],
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: { status: statusWith({}) },
	});

	await tick();
	marker.click();
	await tick();

	const validate = calls.find((c) => c.url === DEFAULT_ENDPOINTS.domain_validate);
	assert.ok(validate, 'the validate route is requested');
	assert.deepEqual(JSON.parse(validate.options.body), { domain_id: '99' });
});

test('a forged address control is inert when its action is unknown', async () => {
	const forged = Object.assign(makeNode('cast-address-forged'), { 'data-cast-address-action': 'publish' });
	const { calls } = load({
		addressControls: [forged],
		script: { status: statusWith({}) },
	});

	await tick();
	forged.click();
	await tick();

	assert.equal(
		calls.some((c) => c.url === DEFAULT_ENDPOINTS.destination_save || c.url === DEFAULT_ENDPOINTS.destination_confirm),
		false,
		'an unknown address action never reaches a destination route'
	);
});
