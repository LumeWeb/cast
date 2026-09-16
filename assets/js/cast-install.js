/**
 * Cast Getting Started installer orchestration (no page refresh).
 *
 * Depends on the WordPress core `updates` script: it drives wp.updates
 * install-plugin / activate-plugin admin-ajax calls directly, so the server
 * checks the core `updates` nonce (injected by _wpUpdatesSettings.ajax_nonce)
 * and the install_plugins / activate_plugin capabilities. This file adds no
 * parallel endpoint and weakens nothing.
 *
 * WP 7.1 source facts this relies on (see vendor/roots/wordpress-no-content):
 *   - wp-admin/includes/ajax-actions.php::wp_ajax_install_plugin returns
 *     { install, slug, pluginName, activateUrl? } — there is NO standalone
 *     basename; the basename surfaces only inside activateUrl as the `plugin`
 *     query parameter.
 *   - wp-admin/includes/ajax-actions.php::wp_ajax_activate_plugin requires
 *     POST name + slug + plugin (basename).
 *   - wp-admin/js/updates.js::wp.updates.ajax attaches action + _ajax_nonce.
 *
 * The only slugs this script will ever act on are those the server localized
 * into `castInstall.rows` (the PageBuilderInstaller allowlist). A forged DOM
 * button whose slug is not in that allowlist is inert.
 *
 * Browser IIFE — no module system. The Node test harness evals this file with
 * injected window/document/wp doubles (see tests/js/cast-install.test.js).
 */
( function ( window, document ) {
	'use strict';

	function CastInstaller( config ) {
		var rows = ( config && config.rows ) || [];
		var updates = ( config && config.updates ) || ( window.wp && window.wp.updates );
		var liveRegion = ( config && config.liveRegion ) || document.getElementById( 'cast-install-live' );

		// Result-recording endpoint, nonce and protected action names localized
		// by the server. The wizard only advances (to Complete) after the backend
		// records an install/activate outcome through these admin-post handlers.
		var adminPostUrl = ( config && config.adminPostUrl ) || ( window.castInstall && window.castInstall.adminPostUrl ) || null;
		var nonce = ( config && config.nonce ) || ( window.castInstall && window.castInstall.nonce ) || null;
		var reportActions = ( config && config.actions ) || ( window.castInstall && window.castInstall.actions ) || null;
		var post = ( config && config.fetch ) || ( window.fetch && window.fetch.bind( window ) ) || null;

		// A filesystem failure is almost always a server permissions/config
		// problem, not a credential typo. Surface something the user (or their
		// site admin) can actually act on instead of WP core's confusing
		// "Please confirm your credentials" when the real issue is that the
		// server cannot write to the filesystem at all.
		function filesystemErrorMessage() {
			return 'The installer could not write to your site\'s filesystem. ' +
				'Your site administrator needs to make the wp-content/plugins directory writable ' +
				'(or the server needs direct filesystem access). Nothing was installed or changed. ' +
				'You can retry, or choose a different builder.';
		}

		/**
		 * WP 7.1's core updates script leaves ajaxLocked=true (and the queue
		 * backed up) on a filesystem-credential error — its ajaxAlways
		 * intentionally does NOT unlock for that errorCode — and its
		 * queueChecker has NO 'activate-plugin' case, so a queued activation
		 * would be dropped forever. We defensively clear that pending core
		 * state so a Retry (and the install->activation hand-off) always
		 * issues a fresh request instead of hanging on the second attempt.
		 */
		function resetCoreState() {
			if ( updates ) {
				updates.ajaxLocked = false;
				updates.queue = [];
			}
		}

		function announce( message ) {
			if ( liveRegion ) {
				liveRegion.textContent = message;
			}
		}

		/**
		 * Record a structured install/activate outcome with the backend.
		 *
		 * The recordInstall/recordActivate admin-post handlers are capability +
		 * nonce gated like every other wizard mutation, so this only fires when
		 * the server localized the endpoint, a fresh nonce and the protected
		 * action names; a partial/forged payload is inert. A failed report never
		 * blocks the in-page flow (core already installed/activated), so the
		 * response (and redirect) is deliberately not awaited or surfaced.
		 */
		function reportResult( action, success ) {
			if ( ! post || ! adminPostUrl || ! nonce || ! reportActions ) {
				return;
			}

			var actionName = reportActions[action];

			if ( ! actionName ) {
				return;
			}

			var body = 'action=' + encodeURIComponent( actionName ) +
				'&_wpnonce=' + encodeURIComponent( nonce ) +
				'&success=' + ( success ? '1' : '0' );

			try {
				post( adminPostUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
					body: body,
					credentials: 'same-origin',
					// admin-post always ends in a 3xx (wp_safe_redirect), which is
					// the endpoint *accepting* the record — not an error to chase.
					// Do not auto-follow a stray redirect (e.g. a session-expired
					// hop to wp-login.php) as a fresh credential-less POST, and do
					// not swallow an HTML login page as a success result.
					redirect: 'manual'
				} ).catch( function () {} );
			} catch ( e ) {
				// Never let a reporting failure interrupt the visible flow.
			}
		}

		function findBySlug( slug ) {
			for ( var i = 0; i < rows.length; i++ ) {
				if ( rows[i].slug === slug ) {
					return rows[i];
				}
			}

			return null;
		}

		/**
		 * The server-side allowlist gate: a slug this script was not given in
		 * the localized payload is never acted on, even if someone injects a
		 * matching button into the DOM.
		 */
		function allowed( slug ) {
			return findBySlug( slug ) !== null;
		}

		/**
		 * Derive the plugin basename from the core install response. WP 7.1
		 * install-plugin success carries no basename field; it is only present
		 * in activateUrl's `plugin` query parameter (unencoded basename).
		 */
		function basenameFromActivateUrl( activateUrl ) {
			if ( ! activateUrl ) {
				return null;
			}

			var match = /(?:^|[?&])plugin=([^&]+)/.exec( activateUrl );

			if ( ! match ) {
				return null;
			}

			try {
				return decodeURIComponent( match[1] );
			} catch ( e ) {
				return null;
			}
		}

		function setLabel( button, text, disabled ) {
			button.disabled = !! disabled;
			button.textContent = text;
		}

		function fail( button, row, response ) {
			var message;

			if ( response && response.errorCode === 'unable_to_connect_to_filesystem' ) {
				message = filesystemErrorMessage();
			} else {
				message = response && response.errorMessage
					? response.errorMessage
					: 'The request could not be completed. Please retry.';
			}

			announce( message );

			// Always clear any pending core lock/queue so a subsequent click
			// issues a fresh install/activate instead of being silently queued
			// (the "second attempt hang"). This covers install, activation,
			// filesystem, network, 3xx, malformed and unauthorized failures.
			resetCoreState();

			// Return the button to a retryable state, keeping any state the
			// server reported (an installed-but-inactive plugin stays Activate).
			setLabel(
				button,
				row.buttonLabel || ( row.alreadyInstalled ? 'Activate' : 'Install' ),
				row.alreadyActive
			);
		}

		function activate( button, row, pluginName ) {
			announce( 'Activating ' + pluginName + '...' );

			updates.activatePlugin( {
				slug: row.slug,
				name: pluginName,
				plugin: row.basename,
				success: function () {
					row.alreadyActive = true;
					setLabel( button, 'Active', true );
					announce( row.name + ' is active.' );
					reportResult( 'activateResult', true );
				},
				error: function ( response ) {
					reportResult( 'activateResult', false );
					fail( button, row, response );
				}
			} );
		}

		function install( button, row ) {
			announce( 'Installing ' + row.name + '...' );

			updates.installPlugin( {
				slug: row.slug,
				success: function ( response ) {
					var basename = basenameFromActivateUrl( response.activateUrl );

					// The install itself succeeded as soon as core says so.
					reportResult( 'installResult', true );

					if ( ! basename ) {
						// Core gave us no activateUrl: the installing user cannot
						// (or need not) activate — do not attempt it. Installing
						// succeeded; leave the button in a completed state.
						row.alreadyInstalled = true;
						setLabel( button, 'Installed', true );
						announce( row.name + ' installed.' );
						return;
					}

					row.basename = basename;
					row.alreadyInstalled = true;

					// Core's install request finished, but its ajaxLocked may
					// still be set (ajaxAlways runs just after this success
					// handler). Firing activation now would queue the job, and
					// WP 7.1's queueChecker drops 'activate-plugin' forever —
					// the reported "Activating…" hang. Clear the lock first so
					// activation actually executes.
					resetCoreState();
					activate( button, row, response.pluginName || row.name );
				},
				error: function ( response ) {
					reportResult( 'installResult', false );
					fail( button, row, response );
				}
			} );
		}

		/**
		 * Act on one install button. Inert unless the slug is in the localized
		 * allowlist and the core updates API is present.
		 */
		function run( button ) {
			var slug = button.getAttribute( 'data-cast-slug' );
			var row = findBySlug( slug );

			if ( ! allowed( slug ) || ! updates ) {
				return;
			}

			if ( row.alreadyActive ) {
				announce( row.name + ' is already active.' );
				return;
			}

			button.disabled = true;

			if ( row.alreadyInstalled && row.basename ) {
				activate( button, row, row.name );
			} else {
				install( button, row );
			}
		}

		function init() {
			var buttons = document.querySelectorAll( '.cast-install-button' );
			var i;

			for ( i = 0; i < buttons.length; i++ ) {
				( function ( button ) {
					button.addEventListener( 'click', function () {
						run( button );
					} );
				} )( buttons[i] );
			}
		}

		return {
			init: init,
			run: run,
			allowed: allowed,
			findBySlug: findBySlug,
			basenameFromActivateUrl: basenameFromActivateUrl
		};
	}

	window.CastInstall = new CastInstaller( {
		rows: ( window.castInstall && window.castInstall.rows ) || [],
		updates: window.wp && window.wp.updates,
		liveRegion: document.getElementById( 'cast-install-live' )
	} );
	window.CastInstall.init();
} )( window, document );
