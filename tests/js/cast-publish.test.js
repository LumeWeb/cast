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

function defaultDomainList() {
	return {
		listed: true,
		status: 'ok',
		domains: [defaultDomainRow()],
		refusal: null,
	};
}

function defaultDomainBind() {
	return {
		bound: true,
		status: 'bound',
		domain: defaultDomainRow(),
		refusal: null,
	};
}

function defaultDomainDns() {
	return {
		ok: true,
		status: 'ok',
		domain: defaultDomainRow({ domain: 'name/', namespace: 'hns' }),
		refusal: null,
	};
}

function defaultDomainVerify() {
	return {
		verified: true,
		status: 'verified',
		domain: defaultDomainRow({ status: 'active' }),
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

function defaultDomainDelete() {
	return { deleted: true, status: 'deleted', domain_id: '99', refusal: null };
}

function defaultDomainPlatform() {
	return {
		ok: true,
		status: 'ok',
		platform_domains: [{ id: 3, domain: 'pinner.xyz', namespace: 'icann', zone_id: 101, enabled: true }],
		refusal: null,
	};
}

function defaultDomainAvailability() {
	return {
		ok: true,
		status: 'ok',
		availability: {
			label: 'my-site',
			results: [{ platform_domain: 'pinner.xyz', namespace: 'icann', available: true }],
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

/** A guided website-card marker ([data-website-action]) double. */
function makeWebsiteMarker(action, attrs) {
	return makeDomainMarker(action, Object.assign({ 'data-website-action': action }, attrs || {}));
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
		} else if (url.indexOf('/domains/list') !== -1) {
			descriptor = pickDescriptor(script.domain_list, defaultDomainList(), calls);
		} else if (url.indexOf('/domains/bind') !== -1) {
			descriptor = pickDescriptor(script.domain_bind, defaultDomainBind(), calls);
		} else if (url.indexOf('/domains/dns') !== -1) {
			descriptor = pickDescriptor(script.domain_dns, defaultDomainDns(), calls);
		} else if (url.indexOf('/domains/verify') !== -1) {
			descriptor = pickDescriptor(script.domain_verify, defaultDomainVerify(), calls);
		} else if (url.indexOf('/domains/validate') !== -1) {
			descriptor = pickDescriptor(script.domain_validate, defaultDomainValidate(), calls);
		} else if (url.indexOf('/domains/delete') !== -1) {
			descriptor = pickDescriptor(script.domain_delete, defaultDomainDelete(), calls);
		} else if (url.indexOf('/domains/platform') !== -1) {
			descriptor = pickDescriptor(script.domain_platform, defaultDomainPlatform(), calls);
		} else if (url.indexOf('/domains/availability') !== -1) {
			descriptor = pickDescriptor(script.domain_availability, defaultDomainAvailability(), calls);
		} else if (url.indexOf('/domains/ssl') !== -1) {
			descriptor = pickDescriptor(script.domain_ssl, defaultDomainSsl(), calls);
		} else if (url.indexOf('/website/available') !== -1) {
			descriptor = pickDescriptor(script.website_available, defaultWebsiteAvailable(), calls);
		} else if (url.indexOf('/website/link') !== -1) {
			descriptor = script.website_link || { linked: true, status: 'linked', website_id: '66', refusal: null };
		} else if (url.indexOf('/website') !== -1) {
			descriptor = script.website_create || { created: true, status: 'created', website_id: '77', website_name: 'sub.example.com', domain: 'sub.example.com', refusal: null };
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
	// The choose-a-domain panel nodes the domain orchestrator updates.
	'.cast-domain-panel',
	'.cast-domain-state',
	'.cast-domain-list',
	'.cast-domain-list-empty',
	'.cast-domain-list-refusal',
	'.cast-domain-dns-label',
	'.cast-domain-dns-copy',
	'.cast-domain-ssl-label',
	// The guided website card (S1) nodes the awaiting-website orchestrator
	// updates: the root placeholder, the card itself, the preserved-CID line,
	// the two paths (create hostname/confirmation; link picker) and the error/
	// result lines. There is no manual "handle it in Pinner / Dismiss" path.
	'.cast-website-root',
	'.cast-website-card',
	'.cast-website-cid',
	'.cast-website-hostname',
	'.cast-website-create',
	'.cast-website-create-confirm',
	'.cast-website-create-confirm-btn',
	'.cast-website-picker',
	'.cast-website-link-empty',
	'.cast-website-error',
	'.cast-website-result',
];

const DEFAULT_ENDPOINTS = {
	status: 'http://example.test/wp-json/cast/v1/publish/status',
	start: 'http://example.test/wp-json/cast/v1/publish/start',
	now: 'http://example.test/wp-json/cast/v1/publish/now',
	mode: 'http://example.test/wp-json/cast/v1/publish/mode',
	cancel: 'http://example.test/wp-json/cast/v1/publish/cancel',
	artifact: 'http://example.test/wp-json/cast/v1/publish/artifact',
	domain_list: 'http://example.test/wp-json/cast/v1/domains/list',
	domain_bind: 'http://example.test/wp-json/cast/v1/domains/bind',
	domain_dns: 'http://example.test/wp-json/cast/v1/domains/dns',
	domain_verify: 'http://example.test/wp-json/cast/v1/domains/verify',
	domain_validate: 'http://example.test/wp-json/cast/v1/domains/validate',
	domain_delete: 'http://example.test/wp-json/cast/v1/domains/delete',
	domain_platform: 'http://example.test/wp-json/cast/v1/domains/platform',
	domain_availability: 'http://example.test/wp-json/cast/v1/domains/availability',
	domain_ssl: 'http://example.test/wp-json/cast/v1/domains/ssl',
	website_create: 'http://example.test/wp-json/cast/v1/website',
	website_available: 'http://example.test/wp-json/cast/v1/website/available',
	website_link: 'http://example.test/wp-json/cast/v1/website/link',
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
	const websiteMarkers = opts.websiteMarkers || [];
	const refreshControls = opts.refreshControls || [];

	const nodes = {};
	DISPLAY_SELECTORS.forEach((sel) => {
		nodes[sel] = makeNode(sel.slice(1));
	});

	// The empty-hostname confirmation copy the server template pins into the
	// card (templates/admin/publish.php, cast-website-create-confirm /
	// -confirm-btn). The orchestrator only reveals/hides those nodes — it never
	// writes their text — so the harness must carry the same pinned copy a real
	// server render would, or the confirm assertions would see an empty node.
	nodes['.cast-website-create-confirm'].textContent = 'Platform domain will be auto-generated — continue?';
	nodes['.cast-website-create-confirm-btn'].textContent = 'Yes, auto-generate';

	const registry = { objects: {}, created: [] };
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
			if (sel === '[data-website-action]') {
				return websiteMarkers;
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
				'domain_list',
				'domain_bind',
				'domain_dns',
				'domain_verify',
				'domain_validate',
				'domain_delete',
				'domain_platform',
				'domain_availability',
				'domain_ssl',
			],
			website_actions: ['website_create', 'website_available', 'website_link'],
			verify_poll: { intervalMs: 50, maxAttempts: 3 },
		},
		opts.config || {}
	);
	Object.assign(config, { fetch: fetchDouble });

	const window = {
		castPublish: config,
		fetch: fetchDouble,
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
		websiteMarkers,
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

	// The live report's mode marks the matching option (on_update) active and
	// non-actionable even mid-run; the alternative (manual) stays clear and
	// selectable.
	assert.doesNotMatch(manual.className, /is-active/);
	assert.match(onUpdate.className, /is-active/);
	assert.equal(manual['aria-pressed'], 'false');
	assert.equal(manual.disabled, false);
	assert.equal(onUpdate['aria-pressed'], 'true', 'the report mode is pressed');
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
	assert.match(manual.className, /is-active/);
	assert.doesNotMatch(onUpdate.className, /is-active/);
	// The active mode is visibly selected AND non-actionable; the alternatives
	// become actionable again.
	assert.equal(manual['aria-pressed'], 'true', 'the active mode is pressed');
	assert.equal(manual.disabled, true, 'the active mode is disabled/non-actionable');
	assert.equal(onUpdate['aria-pressed'], 'false');
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

/* ------------------------ domain surface ------------------------ */

/**
 * The choose-a-domain step mirrors the DomainDashboardView mappings
 * server-side: the client lists the bound domains, binds an ICANN/HNS domain,
 * (re)verifies a binding with a BOUNDED verify poll over the domain list,
 * deletes a binding, reads the selected domain's DNS delegation requirements
 * and SSL status, and reads the pre-bind platform catalog. Every domain route
 * is localized under castPublish.endpoints.domain_* and each operation is
 * guarded by the same X-WP-Nonce header + the localized domain_actions
 * allowlist; every DOM write goes through textContent.
 */

test('domainList fetches the list with the nonce and renders rows via textContent', async () => {
	const { client, calls, nodes } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const result = await client.domainList();

	assert.equal(result, true, 'a successful list fetch resolves true');
	const req = calls.find((c) => c.url === DEFAULT_ENDPOINTS.domain_list);
	assert.ok(req, 'the domain list route is requested');
	assert.equal(req.options.method, 'GET');
	assert.equal(req.options.headers['X-WP-Nonce'], 'wp-rest-nonce');
	assert.equal(req.options.credentials, 'same-origin');

	assert.equal(nodes['.cast-domain-state'].textContent, 'Choose and manage your domain');
	assert.equal(nodes['.cast-domain-list'].children.length, 1, 'one domain row is rendered');
	assert.equal(nodes['.cast-domain-list'].children[0].children[0].textContent, 'site.example.test');
	assert.equal(nodes['.cast-domain-list'].hidden, false);
	assert.equal(nodes['.cast-domain-list-empty'].hidden, true);
	assert.equal(nodes['.cast-domain-list-refusal'].hidden, true);

	assertNoInnerHTML(Object.values(nodes));
});

test('domainList renders the empty-list state for a bound-free website', async () => {
	const { client, nodes } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: { domain_list: { listed: true, status: 'ok', domains: [], refusal: null } },
	});

	const result = await client.domainList();

	assert.equal(result, true);
	// The state line and the empty-list line name different things, so the
	// empty state must not write the same string to both nodes (no visible
	// duplicate line).
	assert.equal(nodes['.cast-domain-state'].textContent, 'Choose and manage your domain');
	assert.equal(nodes['.cast-domain-list'].children.length, 0);
	assert.equal(nodes['.cast-domain-list'].hidden, true);
	assert.equal(nodes['.cast-domain-list-empty'].hidden, false);
	assert.equal(nodes['.cast-domain-list-empty'].textContent, 'No domains bound yet.');

	assertNoInnerHTML(Object.values(nodes));
});

test('domainList renders the refusal copy when the server refuses the list', async () => {
	const { client, nodes } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: { domain_list: { listed: false, status: 'refused', domains: [], refusal: 'identity_missing' } },
	});

	const result = await client.domainList();

	assert.equal(result, true);
	assert.equal(nodes['.cast-domain-state'].textContent, 'Domain data unavailable');
	assert.equal(nodes['.cast-domain-list-refusal'].hidden, false);
	assert.equal(nodes['.cast-domain-list-refusal'].textContent, 'identity_missing');
	assert.equal(nodes['.cast-domain-list'].hidden, true);

	assertNoInnerHTML(Object.values(nodes));
});

test('domainBind posts domain + namespace and re-fetches the list on success', async () => {
	const { client, calls, liveRegion } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const result = await client.domainBind('blog.example.test', 'icann');

	assert.equal(result, true, 'a successful bind resolves true');
	const post = calls.find((c) => c.url === DEFAULT_ENDPOINTS.domain_bind);
	assert.ok(post, 'the bind route is requested');
	assert.equal(post.options.method, 'POST');
	assert.equal(post.options.headers['X-WP-Nonce'], 'wp-rest-nonce');
	assert.equal(post.options.headers['Content-Type'], 'application/json');
	assert.deepEqual(JSON.parse(post.options.body), { domain: 'blog.example.test', namespace: 'icann' });
	assert.match(liveRegion.textContent, /bound/i);
	// A successful bind re-fetches the list so the panel resyncs.
	assert.ok(
		calls.some((c) => c.url === DEFAULT_ENDPOINTS.domain_list),
		'the domain list re-fetches after a bind'
	);
});

test('domainBind refuses an empty domain without touching a route', async () => {
	const { client, calls } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const result = await client.domainBind('   ', 'icann');

	assert.equal(result, false, 'an empty domain is refused client-side');
	assert.equal(
		calls.some((c) => c.url.indexOf('/domains/bind') !== -1),
		false,
		'a refused bind never reaches the bind route'
	);
});

test('domainVerify posts once then bounded-polls the list until the binding is active', async () => {
	const { client, calls, timers, liveRegion } = load({
		config: {
			poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 },
			verify_poll: { intervalMs: 50, maxAttempts: 3 },
		},
		script: {
			domain_list: [
				{ listed: true, status: 'ok', domains: [defaultDomainRow()], refusal: null },
				{ listed: true, status: 'ok', domains: [defaultDomainRow({ status: 'active' })], refusal: null },
			],
		},
	});

	const pending = client.domainVerify('99');
	await tick();
	timers.runNext();
	await tick();

	const result = await pending;

	assert.equal(result, true, 'a verified binding resolves true');
	assert.equal(
		calls.filter((c) => c.url === DEFAULT_ENDPOINTS.domain_verify).length,
		1,
		'only a single verify POST is sent; confirmation comes from the list poll'
	);
	assert.equal(
		calls.filter((c) => c.url === DEFAULT_ENDPOINTS.domain_list).length,
		2,
		'the list is polled until the binding flips to active'
	);
	assert.match(liveRegion.textContent, /verified/i);
});

test('domainVerify gives up once its bounded poll budget is exhausted', async () => {
	const { client, calls, timers, liveRegion } = load({
		config: {
			poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 },
			verify_poll: { intervalMs: 25, maxAttempts: 3 },
		},
		script: { domain_list: defaultDomainList() },
	});

	const pending = client.domainVerify('99');
	await tick();
	timers.runNext();
	await tick();
	timers.runNext();
	await tick();
	timers.runNext();
	await tick();

	const result = await pending;

	assert.equal(result, false, 'the bounded poll resolves false once the budget is gone');
	assert.equal(
		calls.filter((c) => c.url === DEFAULT_ENDPOINTS.domain_list).length,
		3,
		'the list is polled exactly maxAttempts times'
	);
	assert.match(liveRegion.textContent, /pending/i);
});

test('domainDelete posts the domain id and re-fetches the list on success', async () => {
	const { client, calls, liveRegion } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const result = await client.domainDelete('99');

	assert.equal(result, true, 'a successful delete resolves true');
	const post = calls.find((c) => c.url === DEFAULT_ENDPOINTS.domain_delete);
	assert.ok(post, 'the delete route is requested');
	assert.equal(post.options.method, 'POST');
	assert.equal(post.options.headers['X-WP-Nonce'], 'wp-rest-nonce');
	assert.deepEqual(JSON.parse(post.options.body), { domain_id: '99' });
	assert.match(liveRegion.textContent, /deleted/i);
	assert.ok(
		calls.some((c) => c.url === DEFAULT_ENDPOINTS.domain_list),
		'the domain list re-fetches after a delete'
	);
});

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
	assert.equal(markers[0].textContent, 'Validate DNS', 'the button label is restored');
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

test('domainPlatform and domainAvailability fetch the catalog routes', async () => {
	const { client, calls } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const platform = await client.domainPlatform();
	const availability = await client.domainAvailability('my-site');

	assert.equal(platform, true, 'the platform catalog read resolves true');
	assert.equal(availability, true, 'the availability read resolves true');

	const platformReq = calls.find((c) => c.url === DEFAULT_ENDPOINTS.domain_platform);
	assert.ok(platformReq, 'the platform route is requested');
	assert.equal(platformReq.options.method, 'GET');

	const availabilityReq = calls.find((c) => c.url === DEFAULT_ENDPOINTS.domain_availability);
	assert.ok(availabilityReq, 'the availability route is requested');
	assert.equal(availabilityReq.options.method, 'GET');
	assert.equal(availabilityReq.options.headers['X-WP-Nonce'], 'wp-rest-nonce');
});

test('a data-domain-action marker fires its mapped domain route', async () => {
	const markers = [
		makeDomainMarker('bind', { 'data-domain': 'new.example.test', 'data-domain-namespace': 'icann' }),
	];
	const { calls } = load({
		markers,
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	markers[0].click();
	await tick();

	const post = calls.find((c) => c.url === DEFAULT_ENDPOINTS.domain_bind);
	assert.ok(post, 'the bind route is requested through the marker');
	assert.deepEqual(JSON.parse(post.options.body), { domain: 'new.example.test', namespace: 'icann' });
});

test('a forged data-domain-action marker is inert when its action is unknown', async () => {
	const forged = makeDomainMarker('wreck');
	const { calls } = load({
		markers: [forged],
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	forged.click();
	await tick();

	assert.equal(
		calls.some((c) => c.url.indexOf('/domains/') !== -1),
		false,
		'a forged domain marker never reaches any domain route'
	);
});

test('domain operations are inert without the localized nonce', async () => {
	const { client, calls } = load({
		config: { nonce: null, poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const listed = await client.domainList();
	const bound = await client.domainBind('a.example.test', 'icann');

	assert.equal(listed, false, 'a missing nonce refuses the list');
	assert.equal(bound, false, 'a missing nonce refuses the bind');
	assert.equal(calls.length, 0, 'nothing is ever requested without the localized nonce');
});

test('domain operations are inert when the route endpoint is missing', async () => {
	const endpoints = Object.assign({}, DEFAULT_ENDPOINTS);
	delete endpoints.domain_list;
	delete endpoints.domain_bind;
	const { client, calls } = load({
		config: { endpoints, poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	const listed = await client.domainList();
	const bound = await client.domainBind('a.example.test', 'icann');

	assert.equal(listed, false, 'a missing endpoint refuses the list');
	assert.equal(bound, false, 'a missing endpoint refuses the bind');
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

test('renderWebsiteCard reveals the card and quotes the preserved CID while awaiting', async () => {
	// Auto-poll is off AND the init status fetch is scripted to the same
	// awaiting status the test renders, so the asset's one-shot init read can
	// never race in a non-awaiting default that hides the card under test.
	const { client, nodes } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: { status: awaitingStatus() },
	});

	// Awaiting: card visible, CID quoted, picker fetched and rendered.
	client.renderWebsiteCard(awaitingStatus());
	await tick();

	assert.equal(nodes['.cast-website-card'].hidden, false, 'the card is revealed while awaiting');
	assert.equal(nodes['.cast-website-cid'].textContent, 'Preserved CID: QmParkedCid');
	assert.equal(nodes['.cast-website-picker'].children.length, 1, 'the picker renders rows from the list');
	assert.equal(nodes['.cast-website-link-empty'].hidden, true, 'a populated list hides the empty state');

	// A status that un-parks hides the card.
	client.renderWebsiteCard(statusWith({ run_status: 'not_started', run_active: false, awaiting_website: false }));
	assert.equal(nodes['.cast-website-card'].hidden, true, 'the card hides once the run un-parks');
});

test('renderWebsiteCard renders the empty-state for a bound-free website list', async () => {
	const { client, nodes } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: { website_available: { listed: true, status: 'ok', websites: [], refusal: null } },
	});

	client.renderWebsiteCard(awaitingStatus());
	await tick();

	assert.equal(nodes['.cast-website-picker'].children.length, 0);
	assert.equal(nodes['.cast-website-link-empty'].hidden, false, 'an empty list shows the empty state');
});

test('an empty hostname requires the explicit auto-generate confirmation before any create POST', async () => {
	const { client, nodes, websiteMarkers, calls } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		websiteMarkers: [makeWebsiteMarker('create'), makeWebsiteMarker('create-confirm')],
	});

	client.renderWebsiteCard(awaitingStatus());
	await tick();

	// Empty hostname: first click only reveals the confirmation — no POST.
	// The match is the exact create route: renderWebsiteCard already fetched
	// the /website/available picker (a GET), which a broad '/website' URL
	// substring would wrongly count as a POST.
	assert.equal(Boolean(nodes['.cast-website-hostname'].value), false, 'the hostname input is empty');
	websiteMarkers[0].click();
	assert.equal(nodes['.cast-website-create-confirm'].hidden, false, 'the confirmation is revealed');
	assert.equal(nodes['.cast-website-create-confirm'].textContent, 'Platform domain will be auto-generated — continue?');
	assert.equal(
		calls.filter((c) => c.url === DEFAULT_ENDPOINTS.website_create).length,
		0,
		'nothing was POSTed yet'
	);

	// The explicit confirmation fires the create with the empty hostname.
	websiteMarkers[1].click();
	assert.equal(nodes['.cast-website-create-confirm'].hidden, true, 'the confirmation closes after accepting');
	assert.equal(
		JSON.parse(calls[calls.length - 1].options.body).hostname,
		'',
		'the confirmed create carries the empty hostname'
	);
});

test('a named hostname creates immediately without an extra confirmation', async () => {
	const { client, nodes, websiteMarkers, calls } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		websiteMarkers: [makeWebsiteMarker('create')],
	});

	client.renderWebsiteCard(awaitingStatus());
	await tick();

	nodes['.cast-website-hostname'].value = 'shop.example.com';
	websiteMarkers[0].click();

	assert.equal(nodes['.cast-website-create-confirm'].hidden, true, 'a named hostname never asks for confirmation');
	assert.equal(JSON.parse(calls[calls.length - 1].options.body).hostname, 'shop.example.com');
});

test('a refused create surfaces the refusal on the card error line, not a success', async () => {
	const { client, nodes, websiteMarkers } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: { website_create: { created: false, status: 'refused', refusal: 'create_failed' } },
		websiteMarkers: [makeWebsiteMarker('create-confirm')],
	});

	client.renderWebsiteCard(awaitingStatus());
	await tick();

	websiteMarkers[0].click();
	await tick();

	assert.equal(nodes['.cast-website-error'].hidden, false, 'the refusal reveals the error line');
	assert.equal(
		nodes['.cast-website-error'].textContent,
		'The website could not be created. Try again, or handle it in Pinner.'
	);
});

test('a picker row link posts the website id and a refusal surfaces cleanly', async () => {
	const { client, nodes, calls } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: { website_link: { linked: false, status: 'refused', refusal: 'link_failed' } },
	});

	client.renderWebsiteCard(awaitingStatus());
	await tick();

	// The picker built a row button carrying the website id; click it.
	const row = nodes['.cast-website-picker'].children[0].children[0];
	row.click();
	await tick();

	const linkCall = calls.find((c) => c.url.indexOf('/website/link') !== -1);
	assert.ok(linkCall, 'the link route was requested');
	assert.equal(JSON.parse(linkCall.options.body).website_id, '66');
	assert.equal(
		nodes['.cast-website-error'].textContent,
		'That website could not be linked — it may already belong to another workspace. Pick another, or handle it in Pinner.'
	);
});

test('a link 409 conflict surfaces the friendly already-used copy, not a raw error', async () => {
	const { client, nodes, websiteMarkers } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: { website_link: { linked: false, status: 'refused', refusal: 'website_already_linked' } },
		websiteMarkers: [makeWebsiteMarker('link', { 'data-website-id': '66' })],
	});

	client.renderWebsiteCard(awaitingStatus());
	await tick();

	websiteMarkers[0].click();
	await tick();

	assert.equal(nodes['.cast-website-error'].hidden, false, 'the conflict reveals the error line');
	assert.equal(
		nodes['.cast-website-error'].textContent,
		'This website is already used by another workspace. Pick another, or handle it in Pinner.'
	);
});

test('a create 409 workspace conflict surfaces the friendly already-linked copy', async () => {
	const { client, nodes, websiteMarkers } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: { website_create: { created: false, status: 'refused', refusal: 'workspace_already_linked' } },
		websiteMarkers: [makeWebsiteMarker('create-confirm')],
	});

	client.renderWebsiteCard(awaitingStatus());
	await tick();

	websiteMarkers[0].click();
	await tick();

	assert.equal(nodes['.cast-website-error'].hidden, false, 'the conflict reveals the error line');
	assert.equal(
		nodes['.cast-website-error'].textContent,
		'Your workspace already has a website linked. Handle it in Pinner, then try again.'
	);
});

test('the guided card offers exactly the two paths — create and link — with no manual Dismiss', () => {
	const { client, created } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
	});

	// Build the client-side card (mid-poll park) and pin its structure: exactly
	// the create + link paths, never a manual "handle it in Pinner / Dismiss"
	// path.
	const card = client.buildWebsiteCard();
	const paths = card.children.filter(
		(el) => el.className && el.className.split(' ').indexOf('cast-website-path') !== -1
	);

	assert.equal(paths.length, 2, 'the card has exactly two paths');
	assert.equal(paths[0].className, 'cast-website-path cast-website-path-create');
	assert.equal(paths[1].className, 'cast-website-path cast-website-path-link');

	// No dismiss action survives anywhere in the built card.
	const dismissMarkers = created.filter(
		(el) => el.getAttribute && el.getAttribute('data-website-action') === 'dismiss'
	);
	assert.equal(dismissMarkers.length, 0, 'no dismiss action exists on the card');
	assert.equal(card.hidden, false, 'the card is revealed while awaiting');
});

test('the website card build never uses innerHTML and honors the nonce/allowlist', async () => {
	const { client, created, calls, nodes } = load({ config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } } });

	// Build the client-side card (mid-poll park) into the root placeholder.
	client.renderWebsiteCard(awaitingStatus());
	await tick();

	assertNoInnerHTML(created);
	assertNoInnerHTML(Object.values(nodes));

	// The picker read flew with the nonce header against the allowlisted route.
	const availCall = calls.find((c) => c.url.indexOf('/website/available') !== -1);
	assert.ok(availCall, 'the website picker read is requested while awaiting');
	assert.equal(availCall.options.headers['X-WP-Nonce'], 'wp-rest-nonce');

	// Without the allowlist the picker read is inert.
	const client2load = load({ config: { website_actions: [] } });
	client2load.client.renderWebsiteCard(awaitingStatus());
	await tick();
	assert.equal(
		client2load.calls.filter((c) => c.url.indexOf('/website') !== -1).length,
		0,
		'no allowlisted action means no website request'
	);
});

test('a successful create re-fetches status AND the available list and hides the awaiting card', async () => {
	const { client, nodes, websiteMarkers, calls } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		// The init read reports the parked wait; the post-create refresh
		// reports the run un-parked (identity written through server-side).
		script: {
			status: [
				awaitingStatus(),
				statusWith({ run_status: 'not_started', run_active: false, awaiting_website: false }),
			],
			website_create: {
				created: true,
				status: 'created',
				website_id: '77',
				website_name: 'sub.example.com',
				domain: 'sub.example.com',
				refusal: null,
			},
		},
		websiteMarkers: [makeWebsiteMarker('create-confirm')],
	});

	client.renderWebsiteCard(awaitingStatus());
	await tick();

	const statusBefore = calls.filter((c) => c.url.indexOf('/publish/status') !== -1).length;
	const availBefore = calls.filter((c) => c.url.indexOf('/website/available') !== -1).length;

	websiteMarkers[0].click();
	await tick();

	// Status AND the available list were both re-fetched right after the
	// create — the card never waits for the next poll tick.
	assert.equal(
		calls.filter((c) => c.url.indexOf('/publish/status') !== -1).length,
		statusBefore + 1,
		'status is re-fetched immediately after a successful create'
	);
	assert.equal(
		calls.filter((c) => c.url.indexOf('/website/available') !== -1).length,
		availBefore + 1,
		'the available list is re-fetched immediately after a successful create'
	);
	// The re-render hides the awaiting card as soon as the fresh status
	// un-parks — the just-created website means the wait is over.
	assert.equal(nodes['.cast-website-card'].hidden, true, 'the awaiting card hides once the run un-parks');
	assert.equal(nodes['.cast-website-result'].textContent, 'Website created — resuming your publish.');
});

test('a successful link re-fetches status AND the available list and hides the awaiting card', async () => {
	const { client, nodes, calls } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		script: {
			status: [
				awaitingStatus(),
				statusWith({ run_status: 'not_started', run_active: false, awaiting_website: false }),
			],
		},
	});

	client.renderWebsiteCard(awaitingStatus());
	await tick();

	const statusBefore = calls.filter((c) => c.url.indexOf('/publish/status') !== -1).length;
	const availBefore = calls.filter((c) => c.url.indexOf('/website/available') !== -1).length;

	// The picker built a row button carrying the website id; click it to link.
	const row = nodes['.cast-website-picker'].children[0].children[0];
	row.click();
	await tick();

	assert.equal(
		calls.filter((c) => c.url.indexOf('/publish/status') !== -1).length,
		statusBefore + 1,
		'status is re-fetched immediately after a successful link'
	);
	assert.equal(
		calls.filter((c) => c.url.indexOf('/website/available') !== -1).length,
		availBefore + 1,
		'the available list is re-fetched immediately after a successful link'
	);
	assert.equal(nodes['.cast-website-card'].hidden, true, 'the awaiting card hides once the run un-parks');
	// The "Linking your website…" spinner never wedges: it is replaced on
	// completion.
	assert.equal(nodes['.cast-website-result'].textContent, 'Website linked — resuming your publish.');
});

test('a link 409 refusal shows the copy, clears the spinner, refreshes status+list and reveals the real linked state', async () => {
	const { client, nodes, websiteMarkers, calls } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		// The real state is already linked: the fresh status un-parks the run.
		script: {
			status: [
				awaitingStatus(),
				statusWith({ run_status: 'not_started', run_active: false, awaiting_website: false }),
			],
			website_link: { linked: false, status: 'refused', refusal: 'website_already_linked' },
		},
		websiteMarkers: [makeWebsiteMarker('link', { 'data-website-id': '66' })],
	});

	client.renderWebsiteCard(awaitingStatus());
	await tick();

	const statusBefore = calls.filter((c) => c.url.indexOf('/publish/status') !== -1).length;
	const availBefore = calls.filter((c) => c.url.indexOf('/website/available') !== -1).length;

	assert.equal(nodes['.cast-website-result'].textContent, '', 'the card starts with no spinner line');

	websiteMarkers[0].click();
	assert.equal(nodes['.cast-website-result'].textContent, 'Linking your website…', 'the spinner shows while the link is in flight');
	await tick();

	// The friendly 409-refusal copy is shown...
	assert.equal(nodes['.cast-website-error'].hidden, false, 'the 409 refusal reveals the error line');
	assert.equal(
		nodes['.cast-website-error'].textContent,
		'This website is already used by another workspace. Pick another, or handle it in Pinner.'
	);
	// ...AND status + available list were re-fetched, so the card shows the
	// real (already linked) state instead of a dead awaiting card.
	assert.equal(
		calls.filter((c) => c.url.indexOf('/publish/status') !== -1).length,
		statusBefore + 1,
		'status is re-fetched after a link refusal'
	);
	assert.equal(
		calls.filter((c) => c.url.indexOf('/website/available') !== -1).length,
		availBefore + 1,
		'the available list is re-fetched after a link refusal'
	);
	// The "Linking…" spinner never wedges: it is cleared once the refusal lands.
	assert.equal(nodes['.cast-website-result'].textContent, '', 'the Linking… spinner is cleared on refusal');
	// The fresh status shows the workspace is already linked.
	assert.equal(nodes['.cast-website-card'].hidden, true, 'the refreshed status un-parks the run (real state is linked)');
});

test('a refused create clears the spinner and re-fetches status+list while the card stays awaiting', async () => {
	const { client, nodes, websiteMarkers, calls } = load({
		config: { poll: { intervalMs: 0, maxAttempts: 0, backoffMs: 0 } },
		// The create was refused, so the fresh status still reports the wait.
		script: {
			status: awaitingStatus(),
			website_create: { created: false, status: 'refused', refusal: 'create_failed' },
		},
		websiteMarkers: [makeWebsiteMarker('create-confirm')],
	});

	client.renderWebsiteCard(awaitingStatus());
	await tick();

	const statusBefore = calls.filter((c) => c.url.indexOf('/publish/status') !== -1).length;
	const availBefore = calls.filter((c) => c.url.indexOf('/website/available') !== -1).length;

	websiteMarkers[0].click();
	await tick();

	// The refusal lands on the error line...
	assert.equal(nodes['.cast-website-error'].hidden, false, 'the refusal reveals the error line');
	assert.equal(
		nodes['.cast-website-error'].textContent,
		'The website could not be created. Try again, or handle it in Pinner.'
	);
	// ...the "Creating…" spinner never wedges: it is cleared...
	assert.equal(nodes['.cast-website-result'].textContent, '', 'the Creating… spinner is cleared on refusal');
	// ...and status + available were re-fetched so the next render is fresh.
	assert.equal(
		calls.filter((c) => c.url.indexOf('/publish/status') !== -1).length,
		statusBefore + 1,
		'status is re-fetched after a refused create'
	);
	// The card stays awaiting, so the refresh re-renders it AND the render
	// refreshes the picker again — the list is deterministically fresh twice.
	assert.equal(
		calls.filter((c) => c.url.indexOf('/website/available') !== -1).length,
		availBefore + 2,
		'the available list is re-fetched (twice: the mutation refresh + the still-awaiting card render) after a refused create'
	);
	assert.equal(nodes['.cast-website-card'].hidden, false, 'the card stays awaiting after a refused create');
});
