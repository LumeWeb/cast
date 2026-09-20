'use strict';

/**
 * Dependency-free Node tests for the Cast admin-bar publish shortcut
 * orchestrator (assets/js/cast-adminbar.js).
 *
 * The production asset is a browser IIFE (no module system, no jQuery, no
 * wp.*). The harness evals it with injected window/document/fetch doubles that
 * mirror the WordPress admin surface it talks to:
 *
 *   - the tight cast/v1 publish routes it may reach (start/now/cancel POST),
 *     every one guarded server-side by manage_options AND a valid wp_rest nonce
 *     (sent as the X-WP-Nonce header);
 *   - the admin-bar control anchors the server renders
 *     (PublishAdminSubscriber::registerAdminBar) carrying
 *     data-cast-adminbar-action.
 *
 * The suite pins the security contract the server pins when it localizes
 * castPublishAdminBar: only start/now/cancel may ever fire, a forged action is
 * inert, and a missing nonce/endpoint makes the whole toolbar inert so the
 * default anchor href (the Publish page) is used instead. It also pins the
 * progressive-enhancement handoff — a fired action lands the user on the
 * Publish page where the full card orchestrator shows progress/result.
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// RED: this file must fail while assets/js/cast-adminbar.js does not exist.
const ASSET = path.join(__dirname, '..', '..', 'assets', 'js', 'cast-adminbar.js');
const source = fs.readFileSync(ASSET, 'utf8');

const tick = () => new Promise((resolve) => setImmediate(resolve));

/**
 * A server-rendered admin-bar control anchor double: an <a> carrying the
 * data the toolbar script binds (action) plus an href fallback to the Publish
 * page for the default-degradation path.
 */
function makeControl(action, overrides) {
	return Object.assign(
		{
			action,
			href: '',
			prevented: 0,
			listeners: {},
			getAttribute(name) {
				return name === 'data-cast-adminbar-action' ? this.action : null;
			},
			addEventListener(type, cb) {
				this.listeners[type] = cb;
			},
			click(event) {
				if (this.listeners.click) {
					this.listeners.click(event || { preventDefault: () => this.prevented++ });
				}
			},
		},
		overrides || {}
	);
}

/**
 * A scripted fetch double that records every request and answers the action
 * routes with `ok` (or the configured failure) like the REST surface would.
 */
function makeFetch(calls, ok) {
	return (url, options) => {
		calls.push({ url, options });
		return Promise.resolve(ok === false
			? { ok: false, status: 403, json: async () => ({}) }
			: { ok: true, status: 200, json: async () => ({ queued: true, status: 'queued' }) });
	};
}

const DEFAULT_ENDPOINTS = {
	start: 'http://example.test/wp-json/cast/v1/publish/start',
	now: 'http://example.test/wp-json/cast/v1/publish/now',
	cancel: 'http://example.test/wp-json/cast/v1/publish/cancel',
};

/**
 * Eval the production asset in a sandbox driven by the options:
 *   - config: localized window.castPublishAdminBar payload overrides
 *   - controls: data-cast-adminbar-action anchors present in the DOM at init
 *   - fetch: a fully custom fetch double (records + answers routes)
 *   - locations: an array the window.location.assign() call records into
 */
function load(options) {
	const opts = options || {};
	const calls = [];
	const fetchDouble = opts.fetch || makeFetch(calls, opts.ok === undefined ? true : opts.ok);
	const controls = opts.controls || [];
	const locations = [];

	const document = {
		querySelectorAll(sel) {
			return sel === '[data-cast-adminbar-action]' ? controls : [];
		},
	};

	const config = Object.assign(
		{
			endpoints: DEFAULT_ENDPOINTS,
			nonce: 'wp-rest-nonce',
			actions: ['start', 'now', 'cancel'],
			publishPageUrl: 'http://example.test/wp-admin/admin.php?page=workspace-publish',
		},
		opts.config || {}
	);
	Object.assign(config, { fetch: fetchDouble });

	const window = {
		castPublishAdminBar: config,
		fetch: fetchDouble,
		location: {
			assign(url) {
				locations.push(url);
			},
		},
	};

	// eslint-disable-next-line no-new-func
	new Function('window', 'document', source)(window, document);

	return {
		client: window.CastPublishAdminBar,
		calls,
		controls,
		locations,
	};
}

/* ------------------------- gating + progressive enhancement --------------- */

test('exposes a public fire()/navigateToPublishPage() surface and binds controls', () => {
	const { client } = load();
	assert.equal(typeof client.fire, 'function');
	assert.equal(typeof client.navigateToPublishPage, 'function');
});

test('fire("start") POSTs to the start route with the nonce and navigates to the Publish page', async () => {
	const { client, calls, locations } = load();

	const fired = client.fire('start');

	assert.equal(fired, true, 'an allowlisted action fires');
	await tick();

	const post = calls.find((c) => c.url === DEFAULT_ENDPOINTS.start);
	assert.ok(post, 'a start request reaches the start route');
	assert.equal(post.options.method, 'POST');
	assert.equal(post.options.headers['X-WP-Nonce'], 'wp-rest-nonce');
	assert.equal(post.options.headers['Content-Type'], 'application/json');
	assert.equal(post.options.credentials, 'same-origin');
	assert.equal(post.options.body, '{}');

	assert.deepEqual(
		locations,
		['http://example.test/wp-admin/admin.php?page=workspace-publish'],
		'a fired action lands the user on the Publish page'
	);
});

test('a clicked control fires its action and prevents the default link', async () => {
	const start = makeControl('start');
	const { calls, locations } = load({ controls: [start] });

	start.click();
	await tick();

	assert.equal(calls.some((c) => c.url === DEFAULT_ENDPOINTS.start), true, 'the control reaches the start route');
	assert.equal(start.prevented, 1, 'the fired control owns the navigation');
	assert.equal(locations.length, 1, 'the toolbar navigates to the Publish page');
});

test('an action missing from the localized allowlist is inert — a forged control never fires', async () => {
	const forged = makeControl('mode'); // the toolbar never handles the mode write
	const { client, calls } = load({
		controls: [forged],
		config: { actions: ['start', 'now'] }, // no 'mode'
	});

	const fired = client.fire('mode');

	assert.equal(fired, false, 'an unallowlisted control is refused client-side');
	await tick();
	assert.equal(calls.length, 0, 'a forged control fires no request at all');
});

test('a missing nonce makes the whole toolbar inert (default href wins)', async () => {
	const { client, calls } = load({ config: { nonce: null } });

	const fired = client.fire('start');

	assert.equal(fired, false, 'without the localized nonce the control is inert');
	await tick();
	assert.equal(calls.length, 0, 'nothing is ever requested without the nonce');
});

test('a missing endpoint for the action makes it inert', async () => {
	const { client, calls } = load({
		config: { endpoints: { start: DEFAULT_ENDPOINTS.start } }, // no 'cancel'
	});

	const fired = client.fire('cancel');

	assert.equal(fired, false, 'an action without a localized endpoint is refused');
	await tick();
	assert.equal(calls.length, 0, 'no unknown route is ever requested');
});

test('even a refused/errored action still lands on the Publish page', async () => {
	const { client, locations } = load({ ok: false });

	const fired = client.fire('start');

	assert.equal(fired, true, 'the request is still attempted');
	await tick();
	assert.equal(locations.length, 1, 'a refused action falls back to the Publish page, where the status card reflects reality');
});
