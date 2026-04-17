/**
 * Albert Admin Utilities
 *
 * Shared helpers exposed on `window.Albert` for the plugin's admin-side
 * scripts. Classic IIFE + namespace (no build step) so every admin script
 * can depend on it via `wp_enqueue_script()` and use the helpers directly.
 *
 * Modules:
 *   - Albert.liveRegion  — single shared aria-live announcer
 *   - Albert.clipboard   — copy-to-clipboard with fallback + button flash
 *   - Albert.ajax        — admin-ajax.php POST helper
 *
 * @package Albert
 * @since   1.1.0
 */

( function () {
	'use strict';

	const Albert = ( window.Albert = window.Albert || {} );

	/**
	 * Shared live region for screen-reader announcements.
	 *
	 * Uses a single `#albert-copy-status` element appended to <body>. Scripts
	 * call `ensure()` once (idempotent) and `announce()` whenever they need
	 * to surface a status update to assistive tech.
	 */
	Albert.liveRegion = {
		id: 'albert-copy-status',

		ensure() {
			let el = document.getElementById( this.id );
			if ( el ) {
				return el;
			}
			el = document.createElement( 'div' );
			el.setAttribute( 'aria-live', 'polite' );
			el.setAttribute( 'aria-atomic', 'true' );
			el.setAttribute( 'role', 'status' );
			el.className = 'screen-reader-text';
			el.id = this.id;
			document.body.appendChild( el );
			return el;
		},

		announce( message ) {
			this.ensure().textContent = message;
		},
	};

	/**
	 * Clipboard helpers.
	 *
	 * `copy()` prefers the async Clipboard API and falls back to a hidden
	 * textarea + `execCommand('copy')` for browsers without clipboard
	 * permission (or over insecure origins in development).
	 *
	 * `flashButton()` gives a 2-second visual confirmation with a live-region
	 * announcement. Callers configure the class, label, and whether the
	 * element's textContent is swapped (for buttons) or a `data-copied`
	 * attribute is added (for inline text, driven by CSS tooltips).
	 */
	Albert.clipboard = {
		async copy( text ) {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				try {
					await navigator.clipboard.writeText( text );
					return true;
				} catch {
					// Fall through to the legacy path.
				}
			}

			const textarea = document.createElement( 'textarea' );
			textarea.value = text;
			textarea.style.position = 'fixed';
			textarea.style.left = '-9999px';
			textarea.style.top = '-9999px';
			textarea.style.opacity = '0';
			document.body.appendChild( textarea );
			textarea.focus();
			textarea.select();

			try {
				return document.execCommand( 'copy' );
			} catch {
				return false;
			} finally {
				document.body.removeChild( textarea );
			}
		},

		/**
		 * Flash success feedback on an element for a fixed duration.
		 *
		 * @param {HTMLElement} el                Element to decorate.
		 * @param {Object}      [options]
		 * @param {string}      [options.label]     Text announced + optionally swapped in. Defaults to "Copied!".
		 * @param {string}      [options.className] Class toggled during the flash. Defaults to "copied".
		 * @param {boolean}     [options.swap]      Replace `textContent` with `label` (and restore after). Defaults to false.
		 * @param {boolean}     [options.disable]   Disable the element while the flash is active. Defaults to false.
		 * @param {number}      [options.duration]  Milliseconds to keep the flash visible. Defaults to 2000.
		 */
		flashButton( el, options = {} ) {
			const {
				label = 'Copied!',
				className = 'copied',
				swap = false,
				disable = false,
				duration = 2000,
			} = options;

			Albert.liveRegion.announce( label );
			el.classList.add( className );

			let originalText;
			if ( swap ) {
				originalText = el.textContent;
				el.textContent = label;
			} else {
				el.setAttribute( 'data-copied', label );
			}

			if ( disable ) {
				el.disabled = true;
			}

			setTimeout( () => {
				el.classList.remove( className );
				if ( swap ) {
					el.textContent = originalText;
				} else {
					el.removeAttribute( 'data-copied' );
				}
				if ( disable ) {
					el.disabled = false;
				}
			}, duration );
		},
	};

	/**
	 * Minimal POST wrapper for admin-ajax.php endpoints.
	 *
	 * Returns the native Response so each caller decides whether to read
	 * `.json()` or inspect `.ok` / `.status` directly.
	 *
	 * @param {string}                url  Endpoint URL.
	 * @param {Object<string,string>} data Form fields. Values are coerced to strings; null/undefined become "".
	 * @return {Promise<Response>}
	 */
	Albert.ajax = {
		post( url, data ) {
			const body = new URLSearchParams();
			Object.entries( data ).forEach( ( [ key, value ] ) => {
				body.set( key, value == null ? '' : String( value ) );
			} );

			return fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body,
			} );
		},
	};
} )();
