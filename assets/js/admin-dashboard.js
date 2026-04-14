/**
 * Albert Dashboard Scripts
 *
 * Wires the "copy connection details" buttons on the dashboard. Shared
 * clipboard + live-region helpers come from `albert-admin-utils.js`.
 *
 * @package Albert
 * @since 1.0.0
 */

( function () {
	'use strict';

	function init() {
		Albert.liveRegion.ensure();

		document.querySelectorAll( '.albert-copy-btn' ).forEach( ( button ) => {
			button.addEventListener( 'click', async ( e ) => {
				e.preventDefault();

				const target = document.querySelector(
					button.getAttribute( 'data-clipboard-target' )
				);
				if ( ! target ) {
					return;
				}

				const success = await Albert.clipboard.copy( target.value );

				if ( success ) {
					Albert.clipboard.flashButton( button, {
						label: 'Copied!',
						className: 'albert-copy-success',
						swap: true,
						disable: true,
					} );
				} else {
					Albert.clipboard.flashButton( button, {
						label: 'Failed!',
						className: 'albert-copy-error',
						swap: true,
						disable: true,
					} );
				}
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
