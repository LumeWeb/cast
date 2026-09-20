'use strict';

/**
 * Dependency-free Node tests for the Cast no-refresh installer orchestration
 * (assets/js/cast-install.js).
 *
 * The production asset is a browser IIFE (no module system). The harness evals
 * it with injected window/document/wp doubles that mirror the WordPress core
 * `updates` contract this module depends on:
 *
 *   - wp.updates.installPlugin({ slug, success, error })
 *   - wp.updates.activatePlugin({ slug, name, plugin, success, error })
 *
 * WP 7.1 facts pinned here (inspected in vendor/roots/wordpress-no-content):
 *   - install-plugin success has NO standalone basename; the basename lives
 *     only in response.activateUrl as the `plugin` query parameter.
 *   - activate-plugin requires slug + name + plugin (basename) and is refused
 *     on the server without the `updates` nonce + activate_plugin capability,
 *     both of which core's wp.updates.ajax attaches for us.
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ASSET = path.join(__dirname, '..', '..', 'assets', 'js', 'cast-install.js');
const source = fs.readFileSync(ASSET, 'utf8');

function makeButton(slug, disabled) {
	return {
		disabled: !!disabled,
		textContent: '',
		listeners: {},
		getAttribute(attr) {
			return attr === 'data-cast-slug' ? slug : null;
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

function makeUpdates() {
	const resources = {
		installCalls: [],
		activateCalls: [],
		installSuccess: null,
		installError: null,
		activateSuccess: null,
		activateError: null,
	};
	const updates = {
		installPlugin(args) {
			resources.installCalls.push(args);
			if (resources.installSuccess) {
				args.success(resources.installSuccess);
			} else if (resources.installError) {
				args.error(resources.installError);
			}
		},
		activatePlugin(args) {
			resources.activateCalls.push(args);
			if (resources.activateSuccess) {
				args.success(resources.activateSuccess);
			} else if (resources.activateError) {
				args.error(resources.activateError);
			}
		},
	};
	return { updates, resources };
}

function makeDocument(buttons, liveRegion) {
	return {
		getElementById(id) {
			return id === 'cast-install-live' ? liveRegion : null;
		},
		querySelectorAll(selector) {
			return selector === '.cast-install-button' ? buttons : [];
		},
	};
}

/**
 * Eval the production asset in a sandbox and return the constructed
 * window.CastInstall instance plus the injected doubles.
 */
function load(rows, updates, buttons, config) {
	const liveRegion = { textContent: '' };
	const document = makeDocument(buttons, liveRegion);
	const window = {
		castInstall: Object.assign({ rows }, config || {}),
		wp: { updates },
	};

	if (config && config.fetch) {
		window.fetch = config.fetch;
	}

	// Execute the real asset; a missing file / broken reference here is a real
	// RED and a broken asset cannot silently pass.
	// eslint-disable-next-line no-new-func
	new Function('window', 'document', source)(window, document);

	// Re-bind the buttons the test created after eval (eval-time init saw none).
	window.CastInstall.init();

	return {
		installer: window.CastInstall,
		liveRegion,
		buttons,
		updates,
	};
}

test('basenameFromActivateUrl derives the basename core hides in activateUrl', () => {
	const { installer } = load([], makeUpdates().updates, []);

	assert.equal(
		installer.basenameFromActivateUrl(
			'http://example.test/wp-admin/plugins.php?action=activate&plugin=brizy%2Fbrizy.php&_wpnonce=abc'
		),
		'brizy/brizy.php'
	);
	assert.equal(installer.basenameFromActivateUrl('http://example.test/wp-admin/plugins.php'), null);
	assert.equal(installer.basenameFromActivateUrl(''), null);
	assert.equal(installer.basenameFromActivateUrl(null), null);
});

test('install then activate flows to core with the derived basename', () => {
	const row = { slug: 'brizy', name: 'Brizy (Free)', basename: null, alreadyInstalled: false, alreadyActive: false };
	const button = makeButton('brizy');
	const { updates, resources } = makeUpdates();
	resources.installSuccess = {
		slug: 'brizy',
		pluginName: 'Brizy (Free)',
		activateUrl: 'http://example.test/wp-admin/plugins.php?action=activate&plugin=brizy%2Fbrizy.php&_wpnonce=abc',
	};
	resources.activateSuccess = {};
	const { installer, liveRegion } = load([row], updates, [button]);

	button.click();

	assert.equal(resources.installCalls.length, 1);
	assert.deepEqual(resources.installCalls[0].slug, 'brizy');
	assert.equal(resources.activateCalls.length, 1);
	assert.equal(resources.activateCalls[0].plugin, 'brizy/brizy.php');
	assert.equal(resources.activateCalls[0].name, 'Brizy (Free)');
	assert.equal(button.textContent, 'Active');
	assert.equal(button.disabled, true);
	assert.equal(liveRegion.textContent, 'Brizy (Free) is active.');
});

test('already installed but inactive activates directly without re-installing', () => {
	const row = { slug: 'generateblocks', name: 'GenerateBlocks (Free)', basename: 'generateblocks/generateblocks.php', alreadyInstalled: true, alreadyActive: false };
	const button = makeButton('generateblocks');
	const { updates, resources } = makeUpdates();
	resources.activateSuccess = {};
	const { liveRegion } = load([row], updates, [button]);

	button.click();

	assert.equal(resources.installCalls.length, 0);
	assert.equal(resources.activateCalls.length, 1);
	assert.equal(resources.activateCalls[0].plugin, 'generateblocks/generateblocks.php');
	assert.equal(liveRegion.textContent, 'GenerateBlocks (Free) is active.');
});

test('install success without an activateUrl does not attempt activation', () => {
	const row = { slug: 'brizy', name: 'Brizy (Free)', basename: null, alreadyInstalled: false, alreadyActive: false };
	const button = makeButton('brizy');
	const { updates, resources } = makeUpdates();
	resources.installSuccess = { slug: 'brizy', pluginName: 'Brizy (Free)' }; // no activateUrl
	const { liveRegion } = load([row], updates, [button]);

	button.click();

	assert.equal(resources.installCalls.length, 1);
	assert.equal(resources.activateCalls.length, 0);
	assert.equal(button.textContent, 'Installed');
	assert.equal(button.disabled, true);
	assert.match(liveRegion.textContent, /installed/);
});

test('install error is announced and the button becomes retryable', () => {
	const row = { slug: 'brizy', name: 'Brizy (Free)', basename: null, alreadyInstalled: false, alreadyActive: false };
	const button = makeButton('brizy');
	const { updates, resources } = makeUpdates();
	resources.installError = { slug: 'brizy', errorMessage: 'Install failed: disk full' };
	const { liveRegion } = load([row], updates, [button]);

	button.click();

	assert.equal(liveRegion.textContent, 'Install failed: disk full');
	assert.equal(button.textContent, 'Install');
	assert.equal(button.disabled, false, 'user can retry after an install error');
});

test('a slug that is not in the localized allowlist is inert even when forged in the DOM', () => {
	const row = { slug: 'brizy', name: 'Brizy (Free)', basename: null, alreadyInstalled: false, alreadyActive: false };
	const forgeButton = makeButton('elementor-pro'); // not localized, not allowlisted
	const { updates, resources } = makeUpdates();
	const { liveRegion } = load([row], updates, [forgeButton]);

	forgeButton.click();

	assert.equal(resources.installCalls.length, 0, 'forged slug must never reach wp.updates');
	assert.equal(forgeButton.disabled, false, 'forged button is never engaged');
	assert.equal(liveRegion.textContent, '');
});

test('an already active candidate does not re-run install or activation', () => {
	const row = { slug: 'brizy', name: 'Brizy (Free)', basename: 'brizy/brizy.php', alreadyInstalled: true, alreadyActive: true };
	const button = makeButton('brizy', true);
	const { updates, resources } = makeUpdates();
	const { liveRegion } = load([row], updates, [button]);

	button.click();

	assert.equal(resources.installCalls.length, 0);
	assert.equal(resources.activateCalls.length, 0);
	assert.match(liveRegion.textContent, /already active/);
});

test('a new catalog candidate drives install generically with no hardcoded builder name', () => {
	// The orchestrator must be driven entirely by the localized row data, so a
	// brand-new catalog slug (any future candidate) flows through the exact same
	// install -> activate path with zero script changes.
	const row = {
		slug: 'some-future-builder',
		name: 'Some Future Builder',
		basename: null,
		alreadyInstalled: false,
		alreadyActive: false,
	};
	const button = makeButton('some-future-builder');
	const { updates, resources } = makeUpdates();
	resources.installSuccess = {
		slug: 'some-future-builder',
		pluginName: 'Some Future Builder',
		activateUrl: 'http://example.test/wp-admin/plugins.php?action=activate&plugin=some-future-builder%2Fsome-future-builder.php&_wpnonce=abc',
	};
	resources.activateSuccess = {};
	const { liveRegion } = load([row], updates, [button]);

	button.click();

	assert.equal(resources.installCalls.length, 1);
	assert.equal(resources.installCalls[0].slug, 'some-future-builder');
	assert.equal(resources.activateCalls.length, 1);
	assert.equal(resources.activateCalls[0].plugin, 'some-future-builder/some-future-builder.php');
	assert.equal(button.textContent, 'Active');
	assert.equal(button.disabled, true);
	assert.equal(liveRegion.textContent, 'Some Future Builder is active.');
});

/* ------------------------ backend result recording ------------------------ */

function makeFetchRecorder(calls) {
	return (url, options) => {
		calls.push({ url, options });
		return Promise.resolve({ ok: true, status: 200 });
	};
}

function resultConfig(calls) {
	return {
		adminPostUrl: 'http://example.test/wp-admin/admin-post.php',
		nonce: 'abc123',
		actions: {
			installResult: 'cast_onboarding_install_result',
			activateResult: 'cast_onboarding_activate_result',
		},
		fetch: makeFetchRecorder(calls),
	};
}

function installThenActivateHarness() {
	const row = { slug: 'brizy', name: 'Brizy (Free)', basename: null, alreadyInstalled: false, alreadyActive: false };
	const button = makeButton('brizy');
	const { updates, resources } = makeUpdates();
	resources.installSuccess = {
		slug: 'brizy',
		pluginName: 'Brizy (Free)',
		activateUrl: 'http://example.test/wp-admin/plugins.php?action=activate&plugin=brizy%2Fbrizy.php&_wpnonce=abc',
	};
	resources.activateSuccess = {};
	const calls = [];
	return { row, button, updates, resources, calls };
}

test('install then activate report both results to the backend endpoint', () => {
	const { button, updates, resources, calls } = installThenActivateHarness();
	const config = resultConfig(calls);
	load([{ slug: 'brizy', name: 'Brizy (Free)', basename: null, alreadyInstalled: false, alreadyActive: false }], updates, [button], config);

	button.click();

	assert.equal(calls.length, 2, 'one install result + one activate result');
	assert.equal(calls[0].url, 'http://example.test/wp-admin/admin-post.php');
	assert.match(calls[0].options.body, /^action=cast_onboarding_install_result&/);
	assert.match(calls[0].options.body, /_wpnonce=abc123/);
	assert.match(calls[0].options.body, /success=1/);
	assert.equal(calls[1].url, 'http://example.test/wp-admin/admin-post.php');
	assert.match(calls[1].options.body, /^action=cast_onboarding_activate_result&/);
	assert.match(calls[1].options.body, /_wpnonce=abc123/);
	assert.match(calls[1].options.body, /success=1/);
});

test('install failure reports failure back to the backend', () => {
	const row = { slug: 'brizy', name: 'Brizy (Free)', basename: null, alreadyInstalled: false, alreadyActive: false };
	const button = makeButton('brizy');
	const { updates, resources } = makeUpdates();
	resources.installError = { slug: 'brizy', errorMessage: 'Install failed: disk full' };
	const calls = [];
	load([row], updates, [button], resultConfig(calls));

	button.click();

	assert.equal(calls.length, 1);
	assert.match(calls[0].options.body, /^action=cast_onboarding_install_result&/);
	assert.match(calls[0].options.body, /success=0/);
});

test('activate failure reports failure back to the backend', () => {
	const row = { slug: 'generateblocks', name: 'GenerateBlocks (Free)', basename: 'generateblocks/generateblocks.php', alreadyInstalled: true, alreadyActive: false };
	const button = makeButton('generateblocks');
	const { updates, resources } = makeUpdates();
	resources.activateError = { slug: 'generateblocks', errorMessage: 'Activation failed' };
	const calls = [];
	load([row], updates, [button], resultConfig(calls));

	button.click();

	assert.equal(calls.length, 1);
	assert.match(calls[0].options.body, /^action=cast_onboarding_activate_result&/);
	assert.match(calls[0].options.body, /success=0/);
});

test('no reporting endpoint means a forged or partial payload is inert', () => {
	// Without localized adminPostUrl/nonce/actions the asset must still drive
	// core install/activate unchanged and never attempt a result POST.
	const { button, updates, resources } = installThenActivateHarness();
	load([{ slug: 'brizy', name: 'Brizy (Free)', basename: null, alreadyInstalled: false, alreadyActive: false }], updates, [button]);

	button.click();

	assert.equal(resources.installCalls.length, 1);
	assert.equal(resources.activateCalls.length, 1);
	assert.equal(resources.activateCalls[0].plugin, 'brizy/brizy.php');
});
