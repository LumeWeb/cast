'use strict';

/**
 * TDD regression tests for the two reported Brizy onboarding bugs:
 *
 *   1. A filesystem-credential install failure left the WordPress core
 *      `updates` script with ajaxLocked=true (its ajaxAlways intentionally
 *      does NOT unlock for `unable_to_connect_to_filesystem`), so a Retry was
 *      silently queued forever — the "second attempt hang".
 *   2. The install-success handler fired activation while core's ajaxLocked
 *      was still held; `wp.updates.ajax` then queued the activate job, and WP
 *      7.1's queueChecker has no `activate-plugin` case, so it was dropped — a
 *      permanent hang at "Activating…".
 *
 * These tests drive the real asset (assets/js/cast-install.js) with a
 * wp.updates double that reproduces the exact core lock/queue semantics
 * (verified against wordpress/wp-admin/js/updates.js). They fail on the
 * unpatched asset and pass after the fix.
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
	new Function('window', 'document', source)(window, document);
	window.CastInstall.init();
	return { installer: window.CastInstall, liveRegion, buttons, updates };
}

/**
 * A wp.updates double that mirrors the WP 7.1 core mechanics behind the
 * reported retry hang (inspected in wordpress/wp-admin/js/updates.js):
 *
 *   - wp.updates.ajax() sets ajaxLocked=true while a request is in flight; a
 *     call made while locked is pushed to the queue and its success/error
 *     callbacks NEVER fire.
 *   - ajaxAlways() unlocks (and drains the queue) on success, but NOT for the
 *     `unable_to_connect_to_filesystem` errorCode — the lock stays stuck.
 *   - queueChecker() handles 'install-plugin' but has NO 'activate-plugin'
 *     case, so a queued activate job is silently dropped forever.
 *
 * The supplied installResult/activateResult fns return the response shape the
 * server sent (attempt index starts at 1).
 */
function makeLockingUpdates(outcomes) {
	const opts = outcomes || {};
	const resources = { installCalls: [], activateCalls: [] };
	const updates = {
		ajaxLocked: false,
		queue: [],
		queueChecker() {
			// Core WP 7.1 drains install/update/delete jobs; 'activate-plugin'
			// is NOT a case, so those queued jobs are dropped.
			while (this.queue.length && !this.ajaxLocked) {
				const job = this.queue.shift();
				if (job.action === 'install-plugin') {
					this.installPlugin(job.data);
				}
			}
		},
		installPlugin(args) {
			if (this.ajaxLocked) {
				this.queue.push({ action: 'install-plugin', data: args });
				return;
			}
			this.ajaxLocked = true;
			resources.installCalls.push(args);
			const outcome = opts.installResult;
			if (!outcome) {
				return;
			}
			const response = outcome(resources.installCalls.length);
			if (args.success && response && response.success) {
				args.success(response);
			} else if (args.error) {
				args.error(response);
			}
			// Simulate ajaxAlways(): unlock unless this was a filesystem error
			// (or the caller asked to model a stuck lock — e.g. a network / 3xx /
			// malformed response where core never gets a chance to unlock).
			if (
				!opts.stuckInstallError &&
				(!response || response.errorCode !== 'unable_to_connect_to_filesystem')
			) {
				this.ajaxLocked = false;
				this.queueChecker();
			}
		},
		activatePlugin(args) {
			if (this.ajaxLocked) {
				this.queue.push({ action: 'activate-plugin', data: args });
				return;
			}
			this.ajaxLocked = true;
			resources.activateCalls.push(args);
			const outcome = opts.activateResult;
			if (!outcome) {
				return;
			}
			const response = outcome(resources.activateCalls.length);
			if (args.success && response && response.success) {
				args.success(response);
			} else if (args.error) {
				args.error(response);
			}
			this.ajaxLocked = false;
			this.queueChecker();
		},
	};
	return { updates, resources };
}

// Each test needs a FRESH row: the installer mutates the row object in place
// (alreadyInstalled/alreadyActive/basename), so a shared module-level row would
// leak state between tests and mask the bugs under test.
function freshBrizyRow() {
	return { slug: 'brizy', name: 'Brizy (Free)', basename: null, alreadyInstalled: false, alreadyActive: false };
}
const INSTALL_OK = {
	slug: 'brizy',
	pluginName: 'Brizy (Free)',
	activateUrl: 'http://example.test/wp-admin/plugins.php?action=activate&plugin=brizy%2Fbrizy.php&_wpnonce=abc',
	success: true,
};

test('filesystem error shows an actionable message (not credential spam) and a retry issues a fresh install instead of hanging', () => {
	// Reproduces bug 1: core leaves ajaxLocked stuck after a filesystem error,
	// so a naive retry queues forever and never reaches the server.
	const sticker = makeLockingUpdates({
		installResult(attempt) {
			if (attempt === 1) {
				return {
					slug: 'brizy',
					errorCode: 'unable_to_connect_to_filesystem',
					errorMessage: 'Unable to connect to the filesystem. Please confirm your credentials.',
					success: false,
				};
			}
			return INSTALL_OK;
		},
		activateResult() {
			return { success: true };
		},
	});
	const button = makeButton('brizy');
	const { liveRegion } = load([freshBrizyRow()], sticker.updates, [button]);

	button.click(); // attempt 1 -> filesystem error
	assert.equal(sticker.resources.installCalls.length, 1);
	assert.equal(button.disabled, false, 'button must be re-enabled after the error');
	assert.equal(sticker.updates.ajaxLocked, false, 'the installer must clear the stuck core lock');
	// The live region must be actionable, not a dead-end "confirm credentials" prompt.
	assert.doesNotMatch(liveRegion.textContent, /credentials/i, 'do not tell the user to enter irrelevant FTP credentials');
	assert.match(liveRegion.textContent, /filesystem|write|permissions/i, 'message must point at the real filesystem problem');

	button.click(); // attempt 2 -> retry actually runs (attach attempt)
	assert.equal(sticker.resources.installCalls.length, 2, 'a retry after a filesystem error must issue a fresh install (no second-attempt hang)');
});

test('install success leaves core locked, yet activation still runs instead of being queued-and-dropped', () => {
	// Reproduces bug 2: the install success handler fires while core's
	// ajaxLocked is STILL true (its ajaxAlways unlocks right after). Calling
	// activatePlugin synchronously there queues the job, and WP 7.1's
	// queueChecker drops 'activate-plugin' forever — a permanent hang.
	const sticker = makeLockingUpdates({
		installResult() {
			return INSTALL_OK;
		},
		activateResult() {
			return { success: true };
		},
	});
	const button = makeButton('brizy');
	const { liveRegion } = load([freshBrizyRow()], sticker.updates, [button]);

	button.click();

	assert.equal(sticker.resources.installCalls.length, 1);
	assert.equal(sticker.resources.activateCalls.length, 1, 'activation must execute even while the core lock was held on install completion');
	assert.equal(button.textContent, 'Active');
	assert.equal(button.disabled, true);
	assert.equal(liveRegion.textContent, 'Brizy (Free) is active.');
});

test('a malformed/unauthorized install response is not swallowed as success and a retry still works', () => {
	// A bad nonce / 403 / malformed payload arrives as a non-object (e.g. '-1')
	// or a non-HTTP error. The installer must surface a generic failure, clear
	// any stuck core lock and allow a retry — never treat it as success or hang.
	// Worst case: core never unlocks (a network/3xx/malformed body that never
	// reaches a clean ajaxAlways). The installer must not depend on core to
	// recover — it clears the pending lock itself so the Retry button works.
	const sticker = makeLockingUpdates({
		stuckInstallError: true,
		installResult(attempt) {
			return attempt === 1 ? '-1' : INSTALL_OK;
		},
		activateResult() {
			return { success: true };
		},
	});
	const button = makeButton('brizy');
	const { liveRegion } = load([freshBrizyRow()], sticker.updates, [button]);

	button.click();
	assert.equal(sticker.resources.installCalls.length, 1);
	assert.equal(button.textContent, 'Install');
	assert.equal(button.disabled, false, 'a malformed/unauthorized failure must still re-enable the retry button');
	assert.equal(sticker.updates.ajaxLocked, false, 'a malformed/unauthorized failure must clear any pending core lock');
	assert.match(liveRegion.textContent, /retry|could not be completed/i, 'a generic actionable message is shown, not a success');

	button.click();
	assert.equal(sticker.resources.installCalls.length, 2, 'a retry after a malformed/unauthorized response must issue a fresh install');
});

test('result reporting does not silently follow a redirect to the login page', () => {
	// admin-post always ends in a 3xx redirect (wp_safe_redirect), which is the
	// endpoint *accepting* the record — not an auth failure to follow. The
	// fetch must use redirect:'manual' so a stray 302 to wp-login.php is never
	// followed as a fresh (credential-less) POST, and an HTML login page must
	// never be swallowed as a success result.
	const calls = [];
	const button = makeButton('brizy');
	const sticker = makeLockingUpdates({
		installResult() {
			return INSTALL_OK;
		},
		activateResult() {
			return { success: true };
		},
	});
	const fetchSpy = (url, options) => {
		calls.push({ url, options });
		return Promise.resolve({
			ok: false,
			status: 302,
			redirected: false,
			type: 'basic',
			url: 'http://example.test/wp-admin/admin-post.php',
			headers: { get: () => 'http://example.test/wp-login.php' },
		});
	};
	const config = {
		adminPostUrl: 'http://example.test/wp-admin/admin-post.php',
		nonce: 'abc123',
		actions: {
			installResult: 'cast_onboarding_install_result',
			activateResult: 'cast_onboarding_activate_result',
		},
		fetch: fetchSpy,
	};
	load([freshBrizyRow()], sticker.updates, [button], config);

	button.click();

	assert.equal(sticker.resources.activateCalls.length, 1, 'activation proceeded despite the redirecting report');
	assert.ok(calls.length >= 1);
	calls.forEach((c) => {
		assert.equal(c.url, 'http://example.test/wp-admin/admin-post.php', 'no follow-up POST to a redirected location');
		assert.equal(c.options.redirect, 'manual', 'admin-post reporting must not auto-follow its redirect');
		assert.equal(c.options.credentials, 'same-origin', 'same-origin cookies are kept for the report');
	});
});
