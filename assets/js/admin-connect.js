/**
 * The guided connect dialog: where is this site, which app, do this, then
 * wait until Albert sees the connection.
 *
 * Every step's markup is rendered by PHP; this only shows, hides and asks
 * the server. "Connected" is shown only when the status route reports a new
 * connection from this user: the steps happen in other apps, which this page
 * cannot see.
 *
 * @package Albert
 * @since 1.5.0
 */
( function () {
	'use strict';

	const config = window.albertConnect || {};
	const i18n = config.i18n || {};

	// Ask every 3 seconds, slowing to 10; offer "Not connected yet?" after two
	// minutes and stop asking after ten, so a forgotten tab goes quiet.
	const POLL_MS = 3000;
	const POLL_MAX_MS = 10000;
	const STUCK_MS = 120000;
	const GIVE_UP_MS = 600000;

	const format = ( text, ...values ) => {
		let index = 0;
		return String( text ).replace( /%(\d+\$)?[sd]/g, ( match, position ) => {
			const value = position ? values[ parseInt( position, 10 ) - 1 ] : values[ index++ ];
			return value === undefined ? match : String( value );
		} );
	};

	const ConnectDialog = {
		dialog: null,
		location: 'public',
		step: 'location',
		app: '',
		opener: null,
		pollTimer: 0,
		stuckTimer: 0,
		polling: false,
		inFlight: false,
		pollDelay: POLL_MS,
		pollStarted: 0,
		waitingFor: '',
		since: 0,
		clockOffset: 0,
		channel: null,
		connection: null,
		downOnBackdrop: false,

		init() {
			this.dialog = document.querySelector( '[data-albert-connect]' );
			if ( ! this.dialog ) {
				return;
			}

			this.location = this.dialog.dataset.location || 'public';
			// Server seconds minus browser seconds, so "since" is the server's clock.
			this.clockOffset = parseInt( this.dialog.dataset.serverTime, 10 ) - Math.floor( Date.now() / 1000 );

			document.addEventListener( 'click', ( event ) => {
				const opener = event.target.closest( '[data-albert-open-connect]' );
				if ( opener ) {
					event.preventDefault();
					this.open( opener );
				}
			} );

			// A click on the backdrop closes, as people expect of a dialog. It
			// must start there too, or selecting text and releasing outside
			// would close it. `closedby="any"` does this natively, not yet in Safari.
			this.dialog.addEventListener( 'pointerdown', ( event ) => {
				this.downOnBackdrop = this.isBackdrop( event );
			} );
			this.dialog.addEventListener( 'click', ( event ) => this.onClick( event ) );
			this.dialog.addEventListener( 'change', ( event ) => this.onChange( event ) );
			this.dialog.addEventListener( 'close', () => this.onClose() );

			if ( 'BroadcastChannel' in window ) {
				// The approval page says so the moment someone approves.
				this.channel = new BroadcastChannel( 'albert-connect' );
				this.channel.addEventListener( 'message', () => this.poll() );
			}

			// Coming back from the app is the likeliest moment it just connected.
			window.addEventListener( 'focus', () => this.poll() );
			document.addEventListener( 'visibilitychange', () => {
				if ( document.visibilityState === 'visible' ) {
					this.poll();
				}
			} );
		},

		open( opener ) {
			this.opener = opener;
			this.connection = null;
			this.since = Math.floor( Date.now() / 1000 ) + this.clockOffset - 5;

			const app = opener.dataset.app || '';
			const guess = this.dialog.dataset.guess === '1';

			if ( opener.dataset.step === 'location' || ( guess && ! app ) ) {
				this.show( 'location' );
			} else if ( app ) {
				this.chooseApp( app );
			} else {
				this.show( 'app' );
			}

			this.dialog.showModal();
			this.focusTitle();
		},

		onClose() {
			this.stopPolling();

			if ( this.connection ) {
				// The lists and the checklist are drawn on the server; a reload shows
				// them as they are now, with the new row marked.
				const url = new URL( window.location.href );
				url.searchParams.set( 'albert_new', this.connection.client );
				window.location.assign( url.toString() );
				return;
			}

			if ( this.opener && document.contains( this.opener ) ) {
				this.opener.focus();
			}
		},

		isBackdrop( event ) {
			if ( event.target !== this.dialog ) {
				return false;
			}

			const rect = this.dialog.getBoundingClientRect();

			return event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom;
		},

		onClick( event ) {
			const target = event.target;

			const fromBackdrop = this.downOnBackdrop;
			this.downOnBackdrop = false;

			if ( fromBackdrop && this.isBackdrop( event ) ) {
				this.dialog.close();
				return;
			}

			if ( target.closest( '[data-albert-connect-close]' ) || target.closest( '[data-albert-connect-done]' ) ) {
				this.dialog.close();
				return;
			}

			if ( target.closest( '[data-albert-connect-next]' ) ) {
				this.next();
				return;
			}

			if ( target.closest( '[data-albert-connect-back]' ) ) {
				this.back();
				return;
			}

			if ( target.closest( '[data-albert-connect-change]' ) ) {
				this.show( 'location' );
				this.focusTitle();
				return;
			}

			const appLink = target.closest( '[data-albert-app-link]' );
			if ( appLink ) {
				this.watchAppLink( appLink );
				return;
			}

			const markPublic = target.closest( '[data-albert-mark-public]' );
			if ( markPublic ) {
				this.location = 'public';
				this.saveLocation();
				this.chooseApp( markPublic.dataset.app );
				this.focusTitle();
				return;
			}

			if ( target.closest( '[data-albert-connect-manual]' ) ) {
				const panel = this.dialog.querySelector( '[data-albert-connect-panel]:not([hidden])' );
				const manual = panel ? panel.querySelector( 'details.albert-guide-panel__manual, details.albert-guide-panel__fold' ) : null;
				if ( manual ) {
					manual.open = true;
					manual.querySelector( 'summary' ).focus();
				}
				return;
			}

			const show = target.closest( '[data-albert-show-guide]' );
			if ( show ) {
				this.chooseApp( show.dataset.albertShowGuide );
				this.focusTitle();
			}
		},

		onChange( event ) {
			if ( event.target.name === 'albert-connect-location' ) {
				this.location = event.target.value;
			}

			if ( event.target.name.startsWith( 'albert-connect-app-' ) ) {
				this.showAppError( false );
			}
		},

		next() {
			if ( this.step === 'location' ) {
				const chosen = this.dialog.querySelector( 'input[name="albert-connect-location"]:checked' );
				this.location = chosen ? chosen.value : this.location;
				this.saveLocation();
				this.show( 'app' );
			} else if ( this.step === 'app' ) {
				const chosen = this.dialog.querySelector( `input[name="albert-connect-app-${ this.location }"]:checked` );
				if ( ! chosen ) {
					this.showAppError( true );
					return;
				}
				this.chooseApp( chosen.value );
			}

			this.focusTitle();
		},

		back() {
			this.stopPolling();
			this.show( this.step === 'steps' ? 'app' : 'location' );
			this.focusTitle();
		},

		chooseApp( app ) {
			this.app = app;
			const radio = this.dialog.querySelector( `input[name="albert-connect-app-${ this.location }"][value="${ CSS.escape( app ) }"]` );
			if ( radio ) {
				radio.checked = true;
			}
			this.show( 'steps' );
		},

		/**
		 * Say why Continue did nothing, and move to the choices.
		 *
		 * @param {boolean} visible Whether to show the message.
		 */
		showAppError( visible ) {
			const message = this.dialog.querySelector( '[data-albert-connect-app-error]' );
			const list = this.dialog.querySelector( `[data-albert-connect-apps="${ CSS.escape( this.location ) }"]` );

			message.hidden = ! visible;
			if ( list ) {
				if ( visible ) {
					list.setAttribute( 'aria-describedby', 'albert-connect-app-error' );
				} else {
					list.removeAttribute( 'aria-describedby' );
				}
			}

			if ( visible ) {
				announce( i18n.chooseApp );
				const first = list ? list.querySelector( 'input' ) : null;
				if ( first ) {
					first.focus();
				}
			}
		},

		show( step ) {
			this.step = step;
			this.showAppError( false );

			this.dialog.querySelectorAll( '[data-albert-connect-step]' ).forEach( ( section ) => {
				section.hidden = section.dataset.albertConnectStep !== step;
			} );

			this.dialog.querySelectorAll( '[data-albert-connect-apps]' ).forEach( ( list ) => {
				list.hidden = list.dataset.albertConnectApps !== this.location;
			} );

			this.dialog.querySelectorAll( '[data-albert-connect-where]' ).forEach( ( line ) => {
				line.textContent = this.location === 'local' ? i18n.local : i18n.public;
			} );
			this.updateIcons();

			let title = '';
			let panel = null;

			if ( step === 'steps' ) {
				this.dialog.querySelectorAll( '[data-albert-connect-panel]' ).forEach( ( candidate ) => {
					const match = candidate.dataset.albertConnectPanel === `${ this.location }:${ this.app }`;
					candidate.hidden = ! match;
					if ( match ) {
						panel = candidate;
					}
				} );
				title = panel ? panel.dataset.title : '';
			} else if ( step !== 'done' ) {
				title = this.dialog.querySelector( `[data-albert-connect-step="${ step }"]` ).dataset.title;
			}

			if ( step !== 'done' ) {
				this.dialog.querySelector( '[data-albert-connect-title]' ).textContent = title;
			}

			const number = { location: 1, app: 2, steps: 3 }[ step ];
			this.dialog.querySelector( '[data-albert-connect-progress]' ).textContent = number ? format( i18n.progress, number, 3 ) : '';

			this.dialog.querySelector( '[data-albert-connect-back]' ).hidden = step === 'location' || step === 'done';
			this.dialog.querySelector( '[data-albert-connect-next]' ).hidden = step === 'steps' || step === 'done';
			this.dialog.querySelector( '[data-albert-connect-done]' ).hidden = step !== 'done';

			const wait = this.dialog.querySelector( '[data-albert-connect-wait]' );
			if ( step === 'steps' && panel && panel.dataset.waits === '1' ) {
				wait.hidden = false;
				this.dialog.querySelector( '[data-albert-connect-stuck]' ).hidden = true;
				// Filled after it is shown: a status region that appears already
				// filled is often not read.
				const text = this.dialog.querySelector( '[data-albert-connect-wait-text]' );
				text.textContent = '';
				window.requestAnimationFrame( () => {
					text.textContent = format( i18n.waiting, panel.dataset.appName );
				} );
				// Only where the panel has a by-hand route to point at.
				this.dialog.querySelector( '[data-albert-connect-manual]' ).hidden = ! panel.querySelector( 'details.albert-guide-panel__manual, details.albert-guide-panel__fold' );
				this.startPolling( panel.dataset.appName );
			} else {
				wait.hidden = true;
				this.stopPolling();
			}
		},

		/**
		 * A computer for a local site, a globe for a public one, wherever the
		 * location is shown.
		 */
		updateIcons() {
			document.querySelectorAll( '[data-albert-location-icon]' ).forEach( ( icon ) => {
				icon.classList.remove( 'dashicons-desktop', 'dashicons-admin-site-alt3' );
				icon.classList.add( this.location === 'local' ? 'dashicons-desktop' : 'dashicons-admin-site-alt3' );
			} );
		},

		/**
		 * An app's own link (cursor:, vscode:) does nothing at all when the
		 * app is missing. If the page still has focus a moment later, nothing
		 * opened, so say what to do.
		 *
		 * @param {HTMLElement} link The link that was clicked.
		 */
		watchAppLink( link ) {
			const step = link.closest( '.albert-guide-panel__step' );
			const hint = step ? step.querySelector( '[data-albert-link-missing]' ) : null;
			if ( ! hint ) {
				return;
			}

			let left = false;
			const onBlur = () => {
				left = true;
			};
			window.addEventListener( 'blur', onBlur, { once: true } );
			window.setTimeout( () => {
				window.removeEventListener( 'blur', onBlur );
				if ( ! left && document.hasFocus() ) {
					hint.hidden = false;
				}
			}, 1500 );
		},

		focusTitle() {
			this.dialog.querySelector( '[data-albert-connect-title]' ).focus();
		},

		startPolling( appName ) {
			this.stopPolling();
			this.polling = true;
			this.waitingFor = appName;
			this.pollDelay = POLL_MS;
			this.pollStarted = Date.now();
			this.schedulePoll();
			this.stuckTimer = window.setTimeout( () => {
				this.dialog.querySelector( '[data-albert-connect-stuck]' ).hidden = false;
				this.setWaitText( format( i18n.stillWait, appName ) );
			}, STUCK_MS );
		},

		schedulePoll() {
			window.clearTimeout( this.pollTimer );
			this.pollTimer = window.setTimeout( () => this.poll(), this.pollDelay );
			this.pollDelay = Math.min( POLL_MAX_MS, Math.round( this.pollDelay * 1.25 ) );
		},

		stopPolling() {
			window.clearTimeout( this.pollTimer );
			window.clearTimeout( this.stuckTimer );
			this.pollTimer = 0;
			this.polling = false;
		},

		/**
		 * Stop waiting and say why, leaving the dialog open.
		 *
		 * @param {string} message What to show.
		 */
		giveUp( message ) {
			this.stopPolling();
			this.dialog.querySelector( '[data-albert-connect-stuck]' ).hidden = false;
			this.setWaitText( message );
		},

		setWaitText( text ) {
			this.dialog.querySelector( '[data-albert-connect-wait-text]' ).textContent = text;
		},

		async poll() {
			if ( ! this.polling || this.inFlight || ! this.dialog.open || this.step !== 'steps' ) {
				return;
			}

			if ( Date.now() - this.pollStarted > GIVE_UP_MS ) {
				this.giveUp( format( i18n.gaveUp, this.waitingFor ) );
				return;
			}

			this.inFlight = true;
			try {
				const url = new URL( config.statusUrl );
				url.searchParams.set( 'since', String( this.since ) );
				const response = await window.fetch( url, {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': config.nonce },
				} );

				// An expired login or nonce never recovers by asking again.
				if ( response.status === 401 || response.status === 403 ) {
					this.giveUp( i18n.expired );
					return;
				}

				const data = response.ok ? await response.json() : null;
				if ( data && data.connected && this.polling ) {
					this.succeed( data );
					return;
				}
			} catch ( error ) {
				// A network blip: the next tick asks again.
			} finally {
				this.inFlight = false;
			}

			if ( this.polling ) {
				this.schedulePoll();
			}
		},

		succeed( data ) {
			this.stopPolling();
			this.connection = data;
			this.show( 'done' );

			const title = this.dialog.querySelector( '[data-albert-connect-title]' );
			title.textContent = format( i18n.connected, data.name );
			this.dialog.querySelector( '[data-albert-connect-signed-in]' ).textContent = format( i18n.signedIn, data.user );

			announce( `${ title.textContent }. ${ format( i18n.signedIn, data.user ) }` );

			this.dialog.querySelector( '[data-albert-connect-done]' ).focus();
		},

		async saveLocation() {
			this.dialog.dataset.guess = '0';

			const line = document.querySelector( '[data-albert-location-text]' );
			if ( line ) {
				line.textContent = this.location === 'local' ? i18n.local : i18n.public;
			}
			this.updateIcons();

			try {
				const response = await window.fetch( config.locationUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
					body: JSON.stringify( { location: this.location } ),
				} );
				if ( ! response.ok ) {
					throw new Error( String( response.status ) );
				}
			} catch ( error ) {
				announce( i18n.saveError );
			}
		},
	};

	/**
	 * Read a message out through the shared live region.
	 *
	 * @param {string} message The message.
	 */
	function announce( message ) {
		if ( message && window.Albert && window.Albert.liveRegion ) {
			window.Albert.liveRegion.announce( message );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => ConnectDialog.init() );
	} else {
		ConnectDialog.init();
	}
} )();
