/**
 * Albert → Connections.
 *
 * Five modules, all of which no-op when their markup is absent, because this
 * file also loads on the Dashboard for the shared allowed-users picker:
 *
 *   - ConnectionList: filter and selection mode.
 *   - ConnectionNaming: the inline "+ Name this connection" / "Edit" editor.
 *   - AllowedUserFilter: filter the allowed-users list already on screen.
 *   - AllowedUserRemoval: removing an allowed user in the background.
 *   - UserPicker: the "who may approve an assistant" modal, opened from the
 *     Connections screen and from the Dashboard onboarding checklist.
 *
 * Everything else on the screen is server-rendered HTML plus the shared
 * clipboard helpers in admin-settings.js, and the setup accordion is a native
 * <details> so it needs no script at all.
 *
 * @package Albert
 * @since   1.4.0
 */

( function () {
	'use strict';

	const config = window.albertConnections || {};
	const i18n = config.i18n || {};
	const DEBOUNCE_MS = 250;
	// "5 or 10" was the ask; 10 gives a fuller list without turning the dialog
	// into a second Users screen.
	const DEFAULT_SUGGESTION_COUNT = 10;

	/**
	 * Fill %s / %d / %1$s style placeholders, in order.
	 *
	 * @param {string} template The translated string.
	 * @param {...*}   values   The values to substitute.
	 * @return {string} The filled string.
	 */
	function format( template, ...values ) {
		let index = 0;

		return String( template || '' ).replace(
			/%(\d+\$)?[sd]/g,
			( match, position ) => {
				if ( position ) {
					return String( values[ parseInt( position, 10 ) - 1 ] );
				}

				return String( values[ index++ ] );
			}
		);
	}

	/**
	 * The connected-assistants list: filtering and selection mode.
	 *
	 * Selection is kept as a set of client ids rather than as checkbox state,
	 * since a row leaving the current filter must also leave the selection:
	 * tracking a Set makes that a lookup rather than a re-scan of the DOM.
	 */
	const ConnectionList = {
		init() {
			this.root = document.querySelector( '[data-albert-connections]' );

			if ( ! this.root ) {
				return;
			}

			this.filter = this.root.querySelector( '[data-albert-filter]' );
			this.selectToggle = this.root.querySelector(
				'[data-albert-select-toggle]'
			);
			this.bulkBar = this.root.querySelector( '[data-albert-bulkbar]' );
			this.bulkAll = this.root.querySelector( '[data-albert-bulk-all]' );
			this.bulkCount = this.root.querySelector(
				'[data-albert-bulk-count]'
			);
			this.selectFiltered = this.root.querySelector(
				'[data-albert-select-filtered]'
			);
			this.bulkRevoke = this.root.querySelector(
				'[data-albert-bulk-revoke]'
			);
			this.done = this.root.querySelector( '[data-albert-select-done]' );
			this.status = this.root.querySelector(
				'[data-albert-selection-status]'
			);
			this.bulkForm = this.root.querySelector(
				'[data-albert-bulk-form]'
			);

			this.selected = new Set();
			this.selectMode = false;

			this.bind();
			this.update();
		},

		bind() {
			if ( this.filter ) {
				this.filter.addEventListener( 'input', () =>
					this.applyFilter()
				);
			}

			if ( this.selectToggle ) {
				this.selectToggle.addEventListener( 'click', () =>
					this.setSelectMode( ! this.selectMode )
				);
			}

			if ( this.done ) {
				this.done.addEventListener( 'click', () =>
					this.setSelectMode( false )
				);
			}

			if ( this.bulkAll ) {
				this.bulkAll.addEventListener( 'change', () => {
					const rows = this.filteredRows();
					const shouldSelect = this.bulkAll.checked;

					rows.forEach( ( row ) => {
						this.setRowSelected(
							row.dataset.clientId,
							shouldSelect
						);
					} );

					this.update();
				} );
			}

			if ( this.selectFiltered ) {
				this.selectFiltered.addEventListener( 'click', () => {
					this.filteredRows().forEach( ( row ) => {
						this.setRowSelected( row.dataset.clientId, true );
					} );

					this.update();
				} );
			}

			this.root.addEventListener( 'change', ( e ) => {
				const check = e.target.closest( '[data-albert-row-check]' );

				if ( ! check ) {
					return;
				}

				this.setRowSelected( check.value, check.checked );
				this.update();
			} );

			if ( this.bulkForm && this.bulkRevoke ) {
				this.bulkForm.addEventListener( 'submit', ( e ) => {
					const message =
						this.bulkRevoke.dataset.albertConfirm || '';

					if ( message && ! window.confirm( message ) ) {
						e.preventDefault();
					}
				} );
			}
		},

		/** Every row element on the list. */
		allRows() {
			return Array.from(
				this.root.querySelectorAll( '[data-albert-row]' )
			);
		},

		/** The rows currently on screen that match the filter. */
		filteredRows() {
			return this.allRows().filter( ( row ) => ! row.hidden );
		},

		/**
		 * Narrow the rows to the typed term.
		 *
		 * A row that leaves the filter also leaves the selection. Otherwise
		 * "Revoke selected" would act on connections nobody can see, which is
		 * the one thing a destructive bulk action must never do.
		 */
		applyFilter() {
			const term = ( this.filter.value || '' ).trim().toLowerCase();

			this.allRows().forEach( ( row ) => {
				const haystack = row.dataset.search || '';
				const matches = term === '' || haystack.indexOf( term ) !== -1;

				row.hidden = ! matches;

				if ( ! matches && this.selected.has( row.dataset.clientId ) ) {
					this.setRowSelected( row.dataset.clientId, false );
				}
			} );

			this.update();
		},

		setRowSelected( clientId, selected ) {
			if ( ! clientId ) {
				return;
			}

			if ( selected ) {
				this.selected.add( clientId );
			} else {
				this.selected.delete( clientId );
			}
		},

		setSelectMode( on ) {
			this.selectMode = on;

			if ( ! on ) {
				this.selected.clear();
			}

			this.allRows().forEach( ( row ) => {
				const check = row.querySelector( '[data-albert-row-check]' );

				if ( check ) {
					check.disabled = ! on;
				}
			} );

			if ( this.bulkBar ) {
				this.bulkBar.hidden = ! on;
			}

			if ( this.selectToggle ) {
				this.selectToggle.setAttribute(
					'aria-pressed',
					on ? 'true' : 'false'
				);
			}

			this.say(
				on
					? i18n.selectModeOn || 'Selection mode on, nothing selected'
					: i18n.selectModeOff || 'Selection mode off'
			);

			this.update( true );
		},

		/**
		 * Push the selection back onto the checkboxes and the bulk bar.
		 *
		 * @param {boolean} [quiet] Skip the live-region announcement.
		 */
		update( quiet ) {
			const rows = this.filteredRows();
			const count = this.selected.size;

			this.allRows().forEach( ( row ) => {
				const check = row.querySelector( '[data-albert-row-check]' );

				if ( check ) {
					check.checked = this.selected.has( row.dataset.clientId );
				}
			} );

			const visibleIds = new Set(
				rows.map( ( row ) => row.dataset.clientId )
			);
			let selectedVisible = 0;

			visibleIds.forEach( ( id ) => {
				if ( this.selected.has( id ) ) {
					selectedVisible += 1;
				}
			} );

			if ( this.bulkAll ) {
				const all =
					visibleIds.size > 0 && selectedVisible === visibleIds.size;

				this.bulkAll.checked = all;
				// `indeterminate` is a property with no HTML attribute behind
				// it, so it can only be set here.
				this.bulkAll.indeterminate =
					! all && selectedVisible > 0;
			}

			if ( this.bulkCount ) {
				this.bulkCount.textContent =
					count === 0
						? i18n.selectedNone || 'Nothing selected'
						: format( i18n.selectedCount || '%d selected', count );
			}

			if ( this.selectFiltered ) {
				this.selectFiltered.textContent = format(
					i18n.selectAllFiltered ||
						'Select all %d that match this filter',
					visibleIds.size
				);
			}

			if ( this.bulkRevoke ) {
				this.bulkRevoke.disabled = count === 0;
			}

			if ( ! quiet && this.selectMode ) {
				this.say(
					format(
						i18n.selectionStatus || '%1$d of %2$d selected',
						count,
						visibleIds.size
					)
				);
			}
		},

		say( message ) {
			if ( this.status ) {
				this.status.textContent = message;
			}
		},
	};

	/**
	 * The inline "+ Name this connection" / "Edit" editor.
	 *
	 * The trigger and the form occupy the same slots in the row's flex layout,
	 * so opening the editor swaps the title text for an input in place rather
	 * than unfolding a block underneath it. Delegated on `document` rather than
	 * scoped to a root element: every row carries its own trigger, form and
	 * cancel button, and there is nothing else to look up once first.
	 */
	const ConnectionNaming = {
		init() {
			document.addEventListener( 'click', ( e ) => {
				const trigger = e.target.closest(
					'[data-albert-naming-trigger]'
				);

				if ( trigger ) {
					this.open( trigger.closest( '[data-albert-row]' ) );
					return;
				}

				const cancel = e.target.closest(
					'[data-albert-naming-cancel]'
				);

				if ( cancel ) {
					e.preventDefault();
					this.close( cancel.closest( '[data-albert-row]' ) );
				}
			} );

			document.addEventListener( 'keydown', ( e ) => {
				if ( e.key !== 'Escape' ) {
					return;
				}

				const form = e.target.closest(
					'[data-albert-naming-form]'
				);

				if ( form ) {
					this.close( form.closest( '[data-albert-row]' ) );
				}
			} );
		},

		open( row ) {
			if ( ! row ) {
				return;
			}

			const form = row.querySelector( '[data-albert-naming-form]' );
			const input = row.querySelector( '[data-albert-naming-input]' );

			row.querySelectorAll( '[data-albert-naming-display]' ).forEach(
				( el ) => {
					el.hidden = true;
				}
			);

			if ( form ) {
				form.hidden = false;
			}

			if ( input ) {
				input.focus();
				input.select();
			}
		},

		close( row ) {
			if ( ! row ) {
				return;
			}

			const form = row.querySelector( '[data-albert-naming-form]' );
			const input = row.querySelector( '[data-albert-naming-input]' );
			const trigger = row.querySelector(
				'[data-albert-naming-trigger]'
			);

			if ( input ) {
				input.value = input.defaultValue;
			}

			if ( form ) {
				form.hidden = true;
			}

			row.querySelectorAll( '[data-albert-naming-display]' ).forEach(
				( el ) => {
					el.hidden = false;
				}
			);

			if ( trigger ) {
				trigger.focus();
			}
		},
	};

	/**
	 * The filter over the allowed-users list.
	 *
	 * It narrows what is already on the screen. Finding somebody who is *not*
	 * on it is the picker's job, and the two are deliberately different
	 * controls in different places.
	 */
	const AllowedUserFilter = {
		init() {
			this.input = document.querySelector( '[data-albert-user-filter]' );

			if ( ! this.input ) {
				return;
			}

			this.refresh();

			this.input.addEventListener( 'input', () => this.apply() );
		},

		/**
		 * Re-read the row list from the DOM. Needed after the picker inserts
		 * new rows: the set captured at `init()` would otherwise not include
		 * them, and they'd never be hidden by a later filter term.
		 */
		refresh() {
			if ( ! this.input ) {
				return;
			}

			this.rows = Array.from(
				document.querySelectorAll( '[data-albert-user-row]' )
			);

			this.apply();
		},

		apply() {
			if ( ! this.input ) {
				return;
			}

			const term = ( this.input.value || '' ).trim().toLowerCase();

			this.rows.forEach( ( row ) => {
				const haystack = row.dataset.search || '';
				row.hidden = term !== '' && haystack.indexOf( term ) === -1;
			} );
		},
	};

	/**
	 * Removing an allowed user, without leaving the page.
	 *
	 * Unlike naming a connection or adding a user, this can change the
	 * Connected assistants card too: removing someone revokes every token
	 * they hold, on every client, so the response carries a fresh copy of
	 * both cards rather than just the one the "Remove" link lives on.
	 */
	const AllowedUserRemoval = {
		init() {
			document.addEventListener( 'click', ( e ) => {
				const link = e.target.closest( '[data-albert-remove-user]' );

				if ( link ) {
					e.preventDefault();
					this.remove( link );
				}
			} );
		},

		async remove( link ) {
			const message = link.dataset.albertConfirm || '';

			if ( message && ! window.confirm( message ) ) {
				return;
			}

			let payload;

			try {
				const response = await fetch( link.href, {
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' },
				} );

				payload = await response.json();
			} catch ( error ) {
				// The confirm already happened; finish the same way this
				// link always worked rather than leaving the click stuck.
				window.location.href = link.href;
				return;
			}

			if ( ! payload || ! payload.success ) {
				window.location.href = link.href;
				return;
			}

			this.apply( payload.data || {} );
		},

		apply( data ) {
			const usersTarget = document.querySelector(
				'[data-albert-userlist-body]'
			);

			if ( usersTarget && typeof data.usersBodyHtml === 'string' ) {
				usersTarget.innerHTML = data.usersBodyHtml;
			}

			// Keep the picker's "already allowed" set in sync: without this, a
			// user removed here still shows as already-allowed if the picker is
			// reopened in the same page load, since that set is otherwise only
			// ever added to (see UserPicker.applyAdd()).
			if ( UserPicker.allowed && data.removedId !== undefined ) {
				UserPicker.allowed.delete( Number( data.removedId ) );
			}

			AllowedUserFilter.refresh();

			const connectionsTarget = document.querySelector(
				'[data-albert-connections-body]'
			);

			if (
				connectionsTarget &&
				typeof data.connectionsHtml === 'string'
			) {
				connectionsTarget.innerHTML = data.connectionsHtml;
			}

			const countTarget = document.querySelector(
				'[data-albert-connections-count]'
			);

			if ( countTarget && typeof data.connectionsCountHtml === 'string' ) {
				countTarget.innerHTML = data.connectionsCountHtml;
			}

			const disconnectAll = document.querySelector(
				'[data-albert-disconnect-all]'
			);

			if ( disconnectAll ) {
				disconnectAll.hidden = ! data.hasConnections;
			}

			// A revoked connection's row, and any selection it was part of,
			// no longer exists; recompute rather than leave the bulk bar
			// showing a stale count.
			ConnectionList.update( true );

			if ( window.Albert && window.Albert.liveRegion ) {
				window.Albert.liveRegion.announce( data.message || '' );
			}
		},
	};

	/**
	 * The allowed-users picker.
	 *
	 * Queries core's own /wp/v2/users route rather than one of Albert's,
	 * because core already has one and a second endpoint answering the same
	 * question is a second thing to keep permission-correct.
	 */
	const UserPicker = {
		init() {
			this.dialog = document.querySelector( '[data-albert-userpicker]' );

			if ( ! this.dialog ) {
				return;
			}

			this.form = this.dialog.querySelector( 'form' );
			this.search = this.dialog.querySelector(
				'[data-albert-picker-search]'
			);
			this.chips = this.dialog.querySelector(
				'[data-albert-picker-chips]'
			);
			this.count = this.dialog.querySelector(
				'[data-albert-picker-count]'
			);
			this.selectedCount = this.dialog.querySelector(
				'[data-albert-picker-selected-count]'
			);
			this.results = this.dialog.querySelector(
				'[data-albert-picker-results]'
			);
			this.ids = this.dialog.querySelector( '[data-albert-picker-ids]' );
			this.confirm = this.dialog.querySelector(
				'[data-albert-picker-confirm]'
			);

			this.chosen = new Map();
			this.allowed = new Set(
				( config.allowed || [] ).map( ( id ) => Number( id ) )
			);
			this.timer = null;
			this.requestId = 0;
			this.opener = null;

			document.addEventListener( 'click', ( e ) => {
				const opener = e.target.closest(
					'[data-albert-open-userpicker]'
				);

				if ( opener ) {
					e.preventDefault();
					this.open( opener );
					return;
				}

				if ( e.target.closest( '[data-albert-picker-close]' ) ) {
					e.preventDefault();
					this.dialog.close();
				}
			} );

			this.dialog.addEventListener( 'click', ( e ) => {
				if ( e.target === this.dialog ) {
					this.dialog.close();
				}
			} );

			// A native dialog traps focus while it is open but does not put
			// focus back when it closes.
			this.dialog.addEventListener( 'close', () => {
				if ( this.opener && document.contains( this.opener ) ) {
					this.opener.focus();
				}

				this.opener = null;
			} );

			if ( this.search ) {
				this.search.addEventListener( 'input', () => this.onInput() );
			}

			if ( this.results ) {
				this.results.addEventListener( 'change', ( e ) => {
					const check = e.target.closest(
						'[data-albert-picker-check]'
					);

					if ( check ) {
						this.toggle( check );
					}
				} );
			}

			if ( this.chips ) {
				this.chips.addEventListener( 'click', ( e ) => {
					const remove = e.target.closest( '.albert-chip__remove' );

					if ( remove ) {
						e.preventDefault();
						this.deselect( remove.dataset.userId );
					}
				} );
			}

			if ( this.form ) {
				this.form.addEventListener( 'submit', ( e ) =>
					this.onSubmit( e )
				);
			}
		},

		/**
		 * Open the dialog already showing its default suggestions, rather
		 * than opening empty and popping them in a moment later: the second
		 * render was a visible reflow every single time, not just on a slow
		 * connection. `this.opening` guards against a second click landing
		 * mid-fetch and calling `showModal()` on a dialog that is already
		 * open (which throws).
		 *
		 * @param {HTMLElement} opener The button that opened the dialog.
		 */
		async open( opener ) {
			if ( this.opening ) {
				return;
			}

			this.opening = true;
			this.opener = opener;

			if ( opener ) {
				opener.disabled = true;
			}

			this.chosen.clear();
			this.renderChips();

			if ( this.search ) {
				this.search.value = '';
			}

			await this.loadDefaults();

			this.opening = false;

			if ( opener ) {
				opener.disabled = false;
			}

			this.dialog.showModal();

			if ( this.search ) {
				this.search.focus();
			}
		},

		onInput() {
			const term = ( this.search.value || '' ).trim();

			window.clearTimeout( this.timer );

			if ( term === '' ) {
				this.loadDefaults();
				return;
			}

			// Two characters before searching, except for a user ID: ids start
			// at 1, and refusing to look up "1" would break searching by id on
			// every site with fewer than ten users.
			const isId = /^\d+$/.test( term );

			if ( term.length < 2 && ! isId ) {
				this.renderResults( [] );
				this.setCount( i18n.prompt || '' );
				return;
			}

			this.setCount( i18n.searching || '' );
			this.timer = window.setTimeout( () => this.query( term ), DEBOUNCE_MS );
		},

		/**
		 * Fetch a page of users from core's own route.
		 *
		 * `search_columns[]` is passed explicitly whenever `params.search` is
		 * set. Left to itself WP_User_Query picks columns from what the term
		 * *looks like* (a term with an "@" searches email only, a numeric term
		 * searches login and ID only), so the same person is found or not found
		 * depending on how you typed their name.
		 *
		 * @param {Object} params search/orderby/order/roles/per_page.
		 * @return {Promise<Array>} The matching users, or [] on failure.
		 */
		async fetchUsers( params ) {
			const query = new URLSearchParams();

			query.set( 'per_page', String( params.per_page || 20 ) );
			query.set( 'context', 'edit' );
			query.set( '_fields', 'id,name,email,roles' );

			if ( params.search ) {
				query.set( 'search', params.search );

				[ 'name', 'username', 'email', 'id' ].forEach( ( column ) => {
					query.append( 'search_columns[]', column );
				} );
			}

			if ( params.orderby ) {
				query.set( 'orderby', params.orderby );
			}

			if ( params.order ) {
				query.set( 'order', params.order );
			}

			( params.roles || [] ).forEach( ( role ) => {
				query.append( 'roles[]', role );
			} );

			if ( config.capability ) {
				query.append( 'capabilities[]', config.capability );
			}

			const response = await fetch(
				config.usersUrl + '?' + query.toString(),
				{
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': config.nonce },
				}
			);

			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}

			const users = await response.json();

			return Array.isArray( users ) ? users : [];
		},

		/**
		 * Search core's users route for the typed term.
		 *
		 * @param {string} term The search term.
		 */
		async query( term ) {
			const id = ++this.requestId;
			let users;

			try {
				users = await this.fetchUsers( { search: term, per_page: 20 } );
			} catch ( error ) {
				if ( id === this.requestId ) {
					this.renderResults( [] );
					this.setCount( i18n.failed || '' );
				}

				return;
			}

			// A slower earlier request must not overwrite a faster later one.
			if ( id !== this.requestId ) {
				return;
			}

			this.renderResults( users );

			if ( ! users.length ) {
				this.setCount( i18n.noResults || '' );
			} else if ( users.length === 1 ) {
				this.setCount( format( i18n.matchesOne || '', term ) );
			} else {
				this.setCount( format( i18n.matchesMany || '', users.length, term ) );
			}
		},

		/**
		 * Suggest some people before anybody has typed anything.
		 *
		 * Administrators first (the likeliest people to approve an assistant),
		 * then alphabetically, with anyone already allowed left out: showing
		 * them here would just be "Already allowed" badges crowding out the
		 * people actually worth suggesting.
		 */
		async loadDefaults() {
			const id = ++this.requestId;

			this.renderResults( [] );
			this.setCount( i18n.loadingDefaults || '' );

			let admins;
			let rest;

			try {
				// In parallel, not admins-then-rest: since open() now awaits
				// this before showing the dialog at all, two sequential
				// round trips would mean twice the delay before anything
				// appears. The REST route's `orderby` enum has no
				// `display_name`; its `name` value is what maps to that
				// column server-side.
				[ admins, rest ] = await Promise.all( [
					this.fetchUsers( {
						roles: [ 'administrator' ],
						per_page: DEFAULT_SUGGESTION_COUNT,
						orderby: 'name',
						order: 'asc',
					} ),
					this.fetchUsers( {
						per_page: DEFAULT_SUGGESTION_COUNT,
						orderby: 'name',
						order: 'asc',
					} ),
				] );
			} catch ( error ) {
				if ( id === this.requestId ) {
					this.renderResults( [] );
					this.setCount( i18n.failed || '' );
				}

				return;
			}

			if ( id !== this.requestId ) {
				return;
			}

			const seenIds = new Set( admins.map( ( user ) => Number( user.id ) ) );
			const suggestions = admins
				.concat(
					rest.filter( ( user ) => ! seenIds.has( Number( user.id ) ) )
				)
				.filter( ( user ) => ! this.allowed.has( Number( user.id ) ) )
				.slice( 0, DEFAULT_SUGGESTION_COUNT );

			this.renderResults( suggestions );

			if ( ! suggestions.length ) {
				this.setCount( i18n.defaultSuggestionsEmpty || '' );
			} else if ( suggestions.length === 1 ) {
				this.setCount( i18n.defaultSuggestionOne || '' );
			} else {
				this.setCount(
					format( i18n.defaultSuggestionsMany || '', suggestions.length )
				);
			}
		},

		/**
		 * The one string a result row shows: everything searchable, in order.
		 *
		 * @param {Object} user A user object from /wp/v2/users.
		 * @return {string} The label.
		 */
		labelFor( user ) {
			const role = ( user.roles && user.roles[ 0 ] ) || '';
			const parts = [ user.name || '' ];

			if ( user.email ) {
				parts.push( user.email );
			}

			parts.push(
				( config.roles && config.roles[ role ] ) ||
					i18n.noRole ||
					''
			);

			// The id rides along so somebody who searched by it can see which
			// row answered, and so two people sharing a display name stay
			// distinguishable in the list.
			parts.push( '#' + user.id );

			return parts.filter( Boolean ).join( ' · ' );
		},

		/**
		 * Render a set of result rows. Counts and messaging are the caller's
		 * job: a search and the default suggestions mean different things by
		 * an empty or a full list, and only the caller knows which this is.
		 *
		 * @param {Array} users The users to render.
		 */
		renderResults( users ) {
			if ( ! this.results ) {
				return;
			}

			this.results.innerHTML = '';

			users.forEach( ( user ) => {
				this.results.appendChild( this.resultRow( user ) );
			} );
		},

		resultRow( user ) {
			const item = document.createElement( 'li' );
			const label = this.labelFor( user );

			item.className = 'albert-picker__result';

			// Somebody already on the list is shown, not hidden, and cannot be
			// added twice: hiding them reads as "not found" for a person who
			// is plainly there.
			if ( this.allowed.has( Number( user.id ) ) ) {
				item.classList.add( 'albert-picker__result--allowed' );

				const name = document.createElement( 'span' );
				name.className = 'albert-picker__name';
				name.textContent = label;

				const badge = document.createElement( 'span' );
				badge.className = 'albert-badge';
				badge.textContent = i18n.alreadyAllowed || '';

				item.appendChild( name );
				item.appendChild( badge );

				return item;
			}

			const check = document.createElement( 'input' );
			check.type = 'checkbox';
			check.id = 'albert-picker-user-' + user.id;
			check.value = String( user.id );
			check.checked = this.chosen.has( String( user.id ) );
			check.dataset.albertPickerCheck = '';
			check.dataset.userName = user.name || '';

			const name = document.createElement( 'label' );
			name.className = 'albert-picker__name';
			name.setAttribute( 'for', check.id );
			name.textContent = label;

			item.appendChild( check );
			item.appendChild( name );

			return item;
		},

		toggle( check ) {
			const id = String( check.value );

			if ( check.checked ) {
				this.chosen.set( id, check.dataset.userName || id );
				this.announce( format( i18n.chosen || '', this.chosen.get( id ) ) );
			} else {
				const name = this.chosen.get( id ) || id;
				this.chosen.delete( id );
				this.announce( format( i18n.unchosen || '', name ) );
			}

			this.renderChips();
		},

		deselect( id ) {
			const name = this.chosen.get( String( id ) ) || String( id );

			this.chosen.delete( String( id ) );

			const check = this.results
				? this.results.querySelector(
						'[data-albert-picker-check][value="' + id + '"]'
				  )
				: null;

			if ( check ) {
				check.checked = false;
			}

			this.announce( format( i18n.unchosen || '', name ) );
			this.renderChips();

			if ( this.search ) {
				this.search.focus();
			}
		},

		renderChips() {
			if ( this.chips ) {
				this.chips.innerHTML = '';

				this.chosen.forEach( ( name, id ) => {
					const chip = document.createElement( 'li' );
					chip.className = 'albert-chip';

					const text = document.createElement( 'span' );
					text.textContent = name;

					const remove = document.createElement( 'button' );
					remove.type = 'button';
					remove.className = 'albert-chip__remove';
					remove.dataset.userId = id;
					remove.setAttribute(
						'aria-label',
						format( i18n.removeChip || '', name )
					);

					const icon = document.createElement( 'span' );
					icon.className = 'dashicons dashicons-no-alt';
					icon.setAttribute( 'aria-hidden', 'true' );

					remove.appendChild( icon );
					chip.appendChild( text );
					chip.appendChild( remove );
					this.chips.appendChild( chip );
				} );
			}

			const ids = Array.from( this.chosen.keys() );

			if ( this.ids ) {
				this.ids.value = ids.join( ',' );
			}

			if ( this.selectedCount ) {
				this.selectedCount.hidden = ids.length === 0;
				this.selectedCount.textContent = format(
					i18n.selectedCount || '',
					ids.length
				);
			}

			if ( this.confirm ) {
				this.confirm.disabled = ids.length === 0;

				if ( ids.length === 0 ) {
					this.confirm.textContent = i18n.addNone || 'Add user';
				} else if ( ids.length === 1 ) {
					this.confirm.textContent = i18n.addOne || 'Add 1 user';
				} else {
					this.confirm.textContent = format(
						i18n.addMany || '',
						ids.length
					);
				}
			}
		},

		/**
		 * Whether there is an allowed-users list on this page to update in
		 * place. True on the Connections screen, false on the Dashboard,
		 * which opens the same dialog but has nowhere to insert a new row.
		 *
		 * @return {boolean}
		 */
		canInsertInline() {
			return !! document.querySelector( '[data-albert-userlist-body]' );
		},

		/**
		 * Submit the chosen ids. On the Connections screen this posts in the
		 * background and updates the list without leaving the page; anywhere
		 * else (the Dashboard checklist) it falls through to a normal form
		 * submission, since there is no list on that page to update.
		 *
		 * @param {SubmitEvent} e The form's submit event.
		 */
		async onSubmit( e ) {
			if ( ! this.form || ! this.canInsertInline() ) {
				return;
			}

			e.preventDefault();

			if ( this.confirm ) {
				this.confirm.disabled = true;
			}

			let payload;

			try {
				// Not `this.form.action`: a form has a hidden field named
				// "action" (WordPress's own admin-post.php routing convention),
				// and HTMLFormElement's [OverrideBuiltins] means that field
				// shadows the built-in `.action` property, silently turning it
				// into the input element itself rather than the submit URL.
				// The attribute is unaffected and is already an absolute URL.
				const response = await fetch( this.form.getAttribute( 'action' ), {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' },
					body: new FormData( this.form ),
				} );

				payload = await response.json();
			} catch ( error ) {
				this.setCount( i18n.addFailed || '' );

				if ( this.confirm ) {
					this.confirm.disabled = this.chosen.size === 0;
				}

				return;
			}

			if ( ! payload || ! payload.success ) {
				this.setCount(
					( payload && payload.data && payload.data.message ) ||
						i18n.addFailed ||
						''
				);

				if ( this.confirm ) {
					this.confirm.disabled = this.chosen.size === 0;
				}

				return;
			}

			this.applyAdd( payload.data || {} );
			this.dialog.close();
		},

		/**
		 * Apply a successful add: swap in the freshly rendered list body (the
		 * exact markup the page itself would render, from the server, not a
		 * second copy of it built here), mark the new rows so they can be
		 * un-hidden by a stale filter and briefly highlighted, and keep this
		 * session's "already allowed" set in sync so a dialog reopened later
		 * does not offer the same person again.
		 *
		 * @param {Object} data `{ message, addedIds, bodyHtml }` from the server.
		 */
		applyAdd( data ) {
			const target = document.querySelector(
				'[data-albert-userlist-body]'
			);

			if ( target && typeof data.bodyHtml === 'string' ) {
				target.innerHTML = data.bodyHtml;
			}

			( data.addedIds || [] ).forEach( ( id ) => {
				this.allowed.add( Number( id ) );

				const row = target
					? target.querySelector(
							'[data-albert-user-row][data-user-id="' +
								Number( id ) +
								'"]'
					  )
					: null;

				if ( row ) {
					row.classList.add( 'albert-user-item--new' );
				}
			} );

			// Cleared before refreshing, not after: a leftover filter term
			// could otherwise hide the very person who was just added.
			if ( AllowedUserFilter.input ) {
				AllowedUserFilter.input.value = '';
			}

			AllowedUserFilter.refresh();

			this.announce( data.message || '' );
		},

		setCount( message ) {
			if ( this.count ) {
				this.count.textContent = message;
			}
		},

		announce( message ) {
			if ( window.Albert && window.Albert.liveRegion ) {
				window.Albert.liveRegion.announce( message );
			}
		},
	};

	function init() {
		ConnectionList.init();
		ConnectionNaming.init();
		AllowedUserFilter.init();
		AllowedUserRemoval.init();
		UserPicker.init();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
