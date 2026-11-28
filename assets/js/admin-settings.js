/**
 * Albert Admin Settings Scripts
 *
 * @package Albert
 * @since   1.0.0
 */

/**
 * Clipboard bindings for inline copy-text spans and explicit copy buttons.
 *
 * Both flows share `Albert.clipboard` (copy + flashButton). Inline text
 * uses a `data-copied` attribute (CSS renders a tooltip); buttons swap
 * their label for the duration of the feedback.
 */
const ClipboardModule = {
	init() {
		document.addEventListener( 'click', async ( e ) => {
			const copyText = e.target.closest( '.albert-copy-text' );
			if ( copyText ) {
				const ok = await Albert.clipboard.copy( copyText.textContent.trim() );
				if ( ok ) {
					Albert.clipboard.flashButton( copyText, { label: ClipboardModule.label() } );
				}
				return;
			}

			const button = e.target.closest( '.albert-copy-button' );
			if ( ! button ) {
				return;
			}

			// Either the text itself, or the element holding it.
			let text = button.dataset.copyText;
			if ( text === undefined ) {
				const target = document.getElementById( button.dataset.copyTarget );
				if ( ! target ) {
					return;
				}
				text = target.value !== undefined && null !== target.value
					? target.value
					: target.textContent.trim();
			}

			const ok = await Albert.clipboard.copy( text );
			if ( ok ) {
				Albert.clipboard.flashButton( button, { label: ClipboardModule.label(), swap: true } );
			}
		} );
	},

	label() {
		return window.albertAdmin?.i18n?.copied || 'Copied!';
	},
};

/**
 * Disconnect dialog: populates and shows a native dialog for disconnect actions.
 */
const DisconnectModule = {
	init() {
		this.dialog = document.getElementById( 'albert-disconnect-dialog' );
		if ( ! this.dialog ) {
			return;
		}

		this.title = document.getElementById( 'albert-disconnect-dialog-title' );
		this.connLink = document.getElementById( 'albert-disconnect-connection' );
		this.sessLink = document.getElementById( 'albert-disconnect-session' );

		document.addEventListener( 'click', ( e ) => {
			const trigger = e.target.closest( '.albert-disconnect-trigger' );
			if ( ! trigger ) {
				return;
			}

			e.preventDefault();

			// Remember who opened it: a native dialog traps focus while it is
			// open but does not put focus back when it closes.
			this.opener = trigger;

			const template =
				window.albertAdmin?.i18n?.disconnectTitle || 'Disconnect %s?';
			this.title.textContent = template.replace(
				'%s',
				trigger.dataset.clientName || ''
			);
			this.connLink.href = trigger.dataset.revokeUrl;
			this.sessLink.href = trigger.dataset.revokeFullUrl;

			this.dialog.showModal();
		} );

		this.dialog.addEventListener( 'close', () => {
			if ( this.opener && document.contains( this.opener ) ) {
				this.opener.focus();
			}
			this.opener = null;
		} );

		this.dialog.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( '.albert-disconnect-dialog-close' ) || e.target.closest( '.albert-disconnect-cancel' ) ) {
				this.dialog.close();
			}
		} );

		this.dialog.addEventListener( 'click', ( e ) => {
			if ( e.target === this.dialog ) {
				this.dialog.close();
			}
		} );
	},
};

/**
 * Initialize all modules when DOM is ready.
 */
function init() {
	Albert.liveRegion.ensure();
	ClipboardModule.init();
	DisconnectModule.init();

	// A form that undoes something ("Revoke") asks first.
	document.addEventListener( 'submit', ( event ) => {
		const form = event.target.closest( 'form[data-albert-confirm]' );
		if ( form && ! window.confirm( form.dataset.albertConfirm ) ) {
			event.preventDefault();
		}
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}

/**
 * Dismissing an attention item on the Dashboard.
 *
 * The row is removed optimistically and put back if the request fails, because
 * the alternative is a button that appears to do nothing for a round trip. When
 * the last item goes, the card swaps to its empty state rather than emptying
 * out: a card with a heading and nothing under it reads as broken.
 *
 * The swap hides, it never destroys. A first version replaced the card body's
 * innerHTML, which detached the list the rollback still held a reference to,
 * so a failed request put the row back into an orphaned node and the item
 * vanished for good while the server still had it undismissed. Anything the
 * optimistic path touches has to be restorable, because the whole point of the
 * rollback is that the page goes on describing the site truthfully.
 */
( () => {
	'use strict';

	const card = document.querySelector( '.albert-attention' );

	if ( ! card || typeof window.albertAdmin === 'undefined' ) {
		return;
	}

	const list = card.querySelector( '.albert-attention__list' );
	const description = card.querySelector( '.albert-card__description' );

	/**
	 * Show or hide the "nothing right now" state.
	 *
	 * Built once, on first need, and thereafter only toggled, so the list, the
	 * count line and the empty state all survive a rollback.
	 *
	 * @param {boolean} isEmpty Whether the card has no items left.
	 */
	const setEmpty = ( isEmpty ) => {
		const body = card.querySelector( '.albert-card__body' );

		if ( ! body ) {
			return;
		}

		let empty = card.querySelector( '.albert-attention__empty' );

		if ( ! empty && isEmpty ) {
			empty = document.createElement( 'div' );
			empty.className = 'albert-card__body albert-attention__empty';

			const icon = document.createElement( 'span' );
			icon.className = 'dashicons dashicons-yes-alt';
			icon.setAttribute( 'aria-hidden', 'true' );

			const text = document.createElement( 'p' );
			text.textContent = card.getAttribute( 'data-empty-text' ) || '';

			empty.append( icon, text );
			body.after( empty );
		}

		if ( empty ) {
			empty.hidden = ! isEmpty;
		}

		body.hidden = isEmpty;

		if ( description ) {
			description.hidden = isEmpty;
		}
	};

	/**
	 * Rewrite the count sentence to match the list under it.
	 *
	 * Hiding the sentence when the list empties was never the whole job: after
	 * dismissing one of three, the heading still claimed three while the list
	 * showed two, and only the list was right.
	 */
	const syncCount = () => {
		if ( ! description || ! list ) {
			return;
		}

		const remaining = list.querySelectorAll( '.albert-attention__item' ).length;

		if ( remaining === 0 ) {
			return;
		}

		const template =
			remaining === 1
				? card.getAttribute( 'data-count-one' )
				: card.getAttribute( 'data-count-many' );

		if ( template ) {
			description.textContent = template.replace( '%d', String( remaining ) );
		}
	};

	card.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-albert-dismiss-attention]' );

		if ( ! button || ! list ) {
			return;
		}

		const item = button.closest( '.albert-attention__item' );
		const id = button.getAttribute( 'data-albert-dismiss-attention' );

		if ( ! item || ! id ) {
			return;
		}

		const next = item.nextElementSibling;

		// Where focus goes once this row is gone. Removing the element that
		// currently holds focus drops it to <body>, which sends a keyboard user
		// back to the top of the admin and tells a screen-reader user nothing.
		const successor =
			( next && next.querySelector( '[data-albert-dismiss-attention]' ) ) ||
			( item.previousElementSibling &&
				item.previousElementSibling.querySelector(
					'[data-albert-dismiss-attention]'
				) ) ||
			card.querySelector( '.albert-card__title' );

		button.disabled = true;
		item.remove();

		if ( ! list.querySelector( '.albert-attention__item' ) ) {
			setEmpty( true );
		}

		syncCount();

		if ( successor ) {
			// The heading is not focusable by default; make it so only for as
			// long as it needs to receive focus.
			if ( ! successor.hasAttribute( 'tabindex' ) && successor.tagName === 'H2' ) {
				successor.setAttribute( 'tabindex', '-1' );
			}

			successor.focus();
		}

		if ( window.Albert && window.Albert.liveRegion ) {
			window.Albert.liveRegion.announce(
				card.getAttribute( 'data-dismissed-text' ) || ''
			);
		}

		const body = new FormData();
		body.append( 'action', 'albert_dismiss_attention' );
		body.append( 'nonce', window.albertAdmin.dismissNonce );
		body.append( 'id', id );

		window
			.fetch( window.albertAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} )
			.then( ( response ) =>
				response.ok ? response.json() : { success: false }
			)
			.then( ( result ) => {
				if ( ! result || ! result.success ) {
					throw new Error( 'dismiss failed' );
				}
			} )
			.catch( () => {
				// Put it back exactly where it was, so the page still describes
				// the site truthfully.
				//
				// `next` was captured before the removal and may itself have
				// been dismissed since, in which case it is no longer a child
				// of the list and insertBefore throws NotFoundError — inside
				// the catch, so the rest of the rollback would never run and
				// the item would stay gone from a page the server still has it
				// on. Falling back to the end of the list is the wrong order
				// but the right content.
				button.disabled = false;
				list.insertBefore( item, next?.isConnected ? next : null );
				setEmpty( false );
				syncCount();
			} );
	} );
} )();

/**
 * Keep a locked radio group readable without letting it be changed.
 *
 * HTML radios have no `readonly`, and `disabled` takes the whole group out of
 * the tab order — which loses the two things a locked field most needs to
 * convey: which value is in force, and what owns it. So the radios stay
 * focusable and carry `aria-disabled`, and this puts back any change.
 */
( function () {
	'use strict';

	document.addEventListener( 'change', ( event ) => {
		const input = event.target;

		if ( ! input || input.type !== 'radio' || ! input.dataset.albertLocked ) {
			return;
		}

		const group = document.querySelectorAll(
			'input[type="radio"][name="' + CSS.escape( input.name ) + '"]'
		);

		group.forEach( ( radio ) => {
			// defaultChecked is the value the server rendered, which is the one
			// actually in force — restoring from it means a second attempt
			// cannot drift further from the truth.
			radio.checked = radio.defaultChecked;
		} );
	} );
} )();
