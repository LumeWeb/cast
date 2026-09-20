/**
 * Cast admin-bar publish shortcut orchestrator (no full page refresh).
 *
 * A dependency-free browser IIFE that wires the admin bar's "Publish to Pinner"
 * / "Cancel publish" controls (rendered by PublishAdminSubscriber::registerAdminBar
 * as anchors carrying data-cast-adminbar-action) to the cast/v1 REST surface:
 *
 *   - ACTIONS — clicking an actionable control POSTs start / now / cancel to
 *     the matching localized route with the wp_rest nonce header and a JSON
 *     body, then lands the user on the Publish page where the full card
 *     orchestrator shows live progress/result feedback.
 *   - PROGRESSIVE ENHANCEMENT — without this script a control anchor is still a
 *     plain link to the Publish page, so a user on a screen that does not load
 *     the script (e.g. the front end) is still taken to the page where the
 *     action lives.
 *
 * Security contract (mirrored by tests/js/cast-adminbar.test.js):
 *
 *   - The only actions this script will ever fire are the names the server
 *     localized into `castPublishAdminBar.actions` (a tight start/now/cancel
 *     allowlist); a forged data-cast-adminbar-action for anything else is inert.
 *   - Without the localized nonce (or endpoints) nothing is requested at all,
 *     and the click is left to the default href.
 *
 * The server only renders the controls (and therefore only localizes the
 * payload) when the current publish state can actually act, so the toolbar
 * never exposes an unusable shortcut.
 */
( function ( window, document ) {
	'use strict';

	var config = ( window && window.castPublishAdminBar ) || {};
	var nonce = config.nonce || null;
	var endpoints = config.endpoints || null;
	var actions = config.actions || [];
	var publishPageUrl = config.publishPageUrl || '';
	var post = ( config && config.fetch ) || ( window && window.fetch && window.fetch.bind( window ) ) || null;

	/**
	 * Fire one protected toolbar action: POST to the matching localized route
	 * with the nonce header, then navigate to the Publish page so the card
	 * orchestrator can show the live progress. Inert (returns false, fires
	 * nothing) when the action is not allowlisted or the nonce/endpoint is
	 * missing, so the default href navigates instead.
	 *
	 * @param {string} action
	 * @return {boolean} whether the action was fired
	 */
	function fire( action ) {
		if ( ! nonce || ! post || ! endpoints || ! endpoints[ action ] ) {
			return false;
		}

		// The server-side action allowlist, mirrored client-side: a forged or
		// unknown action is refused before anything is requested.
		if ( actions.indexOf( action ) === -1 ) {
			return false;
		}

		post( endpoints[ action ], {
			method: 'POST',
			headers: {
				'X-WP-Nonce': nonce,
				'Content-Type': 'application/json'
			},
			credentials: 'same-origin',
			body: '{}'
		} ).then( function () {
			navigateToPublishPage();
		} ).catch( function () {
			// Even a refused/errored action lands on the Publish page, where
			// the status card reflects the true state.
			navigateToPublishPage();
		} );

		return true;
	}

	/**
	 * Move the user to the Publish page (the default action landing spot).
	 */
	function navigateToPublishPage() {
		if ( publishPageUrl ) {
			window.location.assign( publishPageUrl );
		}
	}

	/**
	 * Bind the server-rendered admin-bar controls. A control's action is read
	 * from data-cast-adminbar-action and validated by the localized allowlist,
	 * so a forged control can never reach a publish route.
	 */
	function bind() {
		var items = document.querySelectorAll( '[data-cast-adminbar-action]' );
		var i;

		for ( i = 0; i < items.length; i++ ) {
			( function ( item ) {
				item.addEventListener( 'click', function ( event ) {
					var action = item.getAttribute( 'data-cast-adminbar-action' );

					if ( ! fire( action ) ) {
						return;
					}

					// Fired: own the navigation (default href would jump to the
					// Publish page anyway, but the REST result decides).
					if ( event && event.preventDefault ) {
						event.preventDefault();
					}
				} );
			} )( items[ i ] );
		}
	}

	/**
	 * Start the toolbar orchestrator: bind the controls. Fully inert (no
	 * bindings) without a nonce or endpoint, so a partial/forged localized
	 * payload can never talk to the REST surface.
	 */
	function init() {
		if ( ! nonce || ! post || ! endpoints ) {
			return;
		}

		bind();
	}

	window.CastPublishAdminBar = {
		init: init,
		fire: fire,
		navigateToPublishPage: navigateToPublishPage
	};

	window.CastPublishAdminBar.init();
} )( window, document );
