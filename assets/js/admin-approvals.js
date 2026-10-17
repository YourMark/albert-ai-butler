/**
 * Albert → Approvals dialogs.
 *
 * Each held action carries its own native <dialog> holding the confirmation
 * prompt. This opens the right one from its row's Review button, restores focus
 * to that button on close (a native dialog traps focus while open but does not
 * put it back), and closes on the backdrop, the close button or Escape (Escape
 * is native). It also auto-opens the deep-linked action, since arriving from the
 * assistant's link is itself the request to decide that one.
 *
 * The decisions themselves are ordinary form submits; nothing here posts.
 *
 * @package Albert
 * @since   1.5.0
 */
( function () {
	'use strict';

	/**
	 * Open a dialog, remembering the element that opened it.
	 *
	 * @param {HTMLDialogElement} dialog The dialog to open.
	 * @param {HTMLElement|null}  opener The element to return focus to on close.
	 */
	function open( dialog, opener ) {
		if ( ! dialog || typeof dialog.showModal !== 'function' ) {
			return;
		}

		dialog.__albertOpener = opener || null;
		dialog.showModal();
	}

	function init() {
		document.addEventListener( 'click', ( event ) => {
			const trigger = event.target.closest( '[data-albert-review]' );

			if ( ! trigger ) {
				return;
			}

			event.preventDefault();
			open( document.getElementById( trigger.dataset.albertDialog ), trigger );
		} );

		document.querySelectorAll( '.albert-approvals__dialog' ).forEach( ( dialog ) => {
			dialog.addEventListener( 'close', () => {
				const opener = dialog.__albertOpener;

				if ( opener && document.contains( opener ) ) {
					opener.focus();
				}

				dialog.__albertOpener = null;
			} );

			dialog.addEventListener( 'click', ( event ) => {
				// The backdrop is the dialog element itself; a click on the
				// close button is delegated here too.
				if ( event.target === dialog || event.target.closest( '[data-albert-dialog-close]' ) ) {
					dialog.close();
				}
			} );
		} );

		// The deep-linked action opens straight into its prompt.
		const focus = document.querySelector( '.albert-approvals__item--focus [data-albert-review]' );

		if ( focus ) {
			open( document.getElementById( focus.dataset.albertDialog ), focus );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
