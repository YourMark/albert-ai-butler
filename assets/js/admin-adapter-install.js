/**
 * One-click install and activation of the MCP Adapter plugin.
 *
 * Enhances any link carrying `data-albert-adapter-install` with core's own
 * `install-plugin` and `activate-plugin` actions. The link already points at
 * core's installer or activation screen, so without this script, or when a step
 * fails (for example a host that needs FTP credentials), the owner lands there
 * instead.
 *
 * @package Albert
 * @since   1.5.0
 */

( function () {
	'use strict';

	const config = window.albertAdapterInstall;

	if ( ! config ) {
		return;
	}

	const announce = ( message ) => {
		if ( window.Albert && window.Albert.liveRegion ) {
			window.Albert.liveRegion.announce( message );
		}
	};

	/**
	 * POST one of core's plugin actions to admin-ajax.php.
	 *
	 * @param {Object} fields Form fields, besides the nonce.
	 * @return {Promise<Object>} The response data, once WordPress reports success.
	 */
	const post = async ( fields ) => {
		const body = new URLSearchParams( { _ajax_nonce: config.nonce, ...fields } );
		const response = await fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} );
		const json = await response.json();

		if ( ! response.ok || ! json || ! json.success ) {
			throw new Error( 'request failed' );
		}

		return json.data || {};
	};

	const install = () => post( { action: 'install-plugin', slug: config.slug } );

	const activate = () =>
		post( {
			action: 'activate-plugin',
			slug: config.slug,
			plugin: config.plugin,
			name: config.name,
		} );

	document.addEventListener( 'click', async ( event ) => {
		const link = event.target.closest( '[data-albert-adapter-install]' );

		if ( ! link ) {
			return;
		}

		event.preventDefault();

		if ( link.getAttribute( 'aria-disabled' ) === 'true' ) {
			return;
		}

		const mode = link.dataset.albertAdapterInstall;
		const busyText = mode === 'install' ? config.i18n.installing : config.i18n.activating;

		link.setAttribute( 'aria-disabled', 'true' );
		link.classList.add( 'disabled' );
		link.textContent = busyText;
		announce( busyText );

		// Once installed, the installer would only say the folder exists, so a
		// failed activation falls back to core's activation link instead.
		let fallbackUrl = link.href;

		try {
			if ( mode === 'install' ) {
				const installed = await install();
				fallbackUrl = installed.activateUrl || fallbackUrl;
				announce( config.i18n.activating );
			}

			await activate();

			announce( config.i18n.done );

			// Reloads with a marker the server answers with a confirmation, and
			// core strips from the address bar again.
			const url = new URL( window.location.href );
			url.searchParams.set( config.doneArg, '1' );
			url.hash = '';
			window.location.assign( url.toString() );
		} catch ( error ) {
			announce( config.i18n.fallback );
			window.location.assign( fallbackUrl );
		}
	} );
}() );
