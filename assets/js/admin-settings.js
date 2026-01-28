/**
 * Albert Admin Settings Scripts
 *
 * @package Albert
 * @since   1.0.0
 */

/**
 * Dirty state tracking — warns users about unsaved changes.
 */
const DirtyStateModule = {
	isDirty: false,

	init() {
		this.form = document.getElementById( 'albert-form' );
		if ( ! this.form ) {
			return;
		}

		this.saveButtons = document.querySelectorAll( '#submit, #submit-mobile' );

		// Listen for any checkbox change inside the form.
		this.form.addEventListener( 'change', () => {
			this.markDirty();
		} );

		// Clear dirty state on form submit.
		this.form.addEventListener( 'submit', () => {
			this.isDirty = false;
		} );

		// Warn on navigation away.
		window.addEventListener( 'beforeunload', ( e ) => {
			if ( this.isDirty ) {
				e.preventDefault();
			}
		} );
	},

	markDirty() {
		if ( this.isDirty ) {
			return;
		}
		this.isDirty = true;

		this.saveButtons.forEach( ( btn ) => {
			btn.classList.add( 'albert-save-dirty' );
		} );
	},
};

/**
 * Toggle functionality for ability categories (simplified UI with grouped toggles).
 */
const ToggleModule = {
	init() {
		this.handleGroupCheckboxes();
		this.handleIndividualCheckboxes();
		this.handleCategoryToggleAll();
		this.handleContentTypeExpand();
		this.initializeToggleStates();
	},

	/**
	 * Handle group checkbox changes (Read/Write toggles per content type).
	 * Each group checkbox controls multiple abilities via data-abilities attribute.
	 */
	handleGroupCheckboxes() {
		document.querySelectorAll( '.ability-group-checkbox' ).forEach( ( checkbox ) => {
			checkbox.addEventListener( 'change', ( e ) => {
				const abilities = JSON.parse( e.target.dataset.abilities || '[]' );
				const isChecked = e.target.checked;
				const category = e.target.dataset.category;

				// Sync individual checkboxes if details panel is expanded.
				this.syncIndividualCheckboxes( checkbox.id, abilities, isChecked );

				// Sync hidden inputs for abilities not shown in expanded panel.
				this.syncHiddenInputsForGroup( checkbox, abilities, isChecked );

				this.updateCategoryToggleState( category );
			} );
		} );
	},

	/**
	 * Handle individual ability checkbox changes within expanded panels.
	 */
	handleIndividualCheckboxes() {
		document.addEventListener( 'change', ( e ) => {
			if ( ! e.target.classList.contains( 'ability-item-checkbox' ) ) {
				return;
			}

			const groupCheckboxId = e.target.dataset.groupCheckbox;
			if ( ! groupCheckboxId ) {
				return;
			}

			// Update the group checkbox state based on individual checkboxes.
			this.updateGroupCheckboxState( groupCheckboxId );
		} );
	},

	/**
	 * Sync individual checkboxes when group checkbox changes.
	 */
	syncIndividualCheckboxes( groupCheckboxId, abilities, isChecked ) {
		abilities.forEach( ( abilityName ) => {
			const checkbox = document.querySelector(
				`.ability-item-checkbox[data-group-checkbox="${ groupCheckboxId }"][value="${ abilityName }"]`
			);
			if ( checkbox && checkbox.checked !== isChecked ) {
				checkbox.checked = isChecked;
			}
		} );
	},

	/**
	 * Sync hidden inputs for abilities when a group is toggled.
	 * Only needed for single-ability types without expanded panel.
	 */
	syncHiddenInputsForGroup( checkbox, abilities, isChecked ) {
		const form = document.getElementById( 'albert-form' );
		if ( ! form ) {
			return;
		}

		abilities.forEach( ( abilityName ) => {
			// Skip if there's already a visible checkbox for this ability.
			const visibleCheckbox = form.querySelector(
				`.ability-item-checkbox[value="${ abilityName }"]`
			);
			if ( visibleCheckbox ) {
				return;
			}

			// Find or create the hidden input for this ability.
			let input = form.querySelector(
				`input[type="hidden"][name="albert_enabled_on_page[]"][value="${ abilityName }"]`
			);

			if ( isChecked && ! input ) {
				// Create hidden input for enabled ability.
				input = document.createElement( 'input' );
				input.type = 'hidden';
				input.name = 'albert_enabled_on_page[]';
				input.value = abilityName;
				input.dataset.groupCheckbox = checkbox.id;
				form.appendChild( input );
			} else if ( ! isChecked && input && input.type === 'hidden' ) {
				// Remove hidden input for disabled ability.
				input.remove();
			}
		} );
	},

	/**
	 * Update group checkbox state based on individual checkboxes.
	 * Sets indeterminate state when some (but not all) are checked.
	 */
	updateGroupCheckboxState( groupCheckboxId ) {
		const groupCheckbox = document.getElementById( groupCheckboxId );
		if ( ! groupCheckbox ) {
			return;
		}

		const abilities = JSON.parse( groupCheckbox.dataset.abilities || '[]' );
		const individualCheckboxes = abilities.map( ( name ) =>
			document.querySelector( `.ability-item-checkbox[value="${ name }"]` )
		).filter( Boolean );

		if ( individualCheckboxes.length === 0 ) {
			return;
		}

		const checkedCount = individualCheckboxes.filter( ( cb ) => cb.checked ).length;
		const allChecked = checkedCount === individualCheckboxes.length;
		const noneChecked = checkedCount === 0;

		// Set indeterminate state for partial selection.
		groupCheckbox.indeterminate = ! allChecked && ! noneChecked;
		groupCheckbox.checked = allChecked;

		// Update category toggle.
		const category = groupCheckbox.dataset.category;
		if ( category ) {
			this.updateCategoryToggleState( category );
		}
	},

	/**
	 * Handle category "Enable All" toggle.
	 */
	handleCategoryToggleAll() {
		document.querySelectorAll( '.toggle-category-abilities' ).forEach( ( toggle ) => {
			toggle.addEventListener( 'change', ( e ) => {
				const category = e.target.dataset.category;
				const isChecked = e.target.checked;

				// If enabling, check for unchecked write abilities and confirm.
				if ( isChecked ) {
					const writeCheckboxes = document.querySelectorAll(
						`.ability-group-checkbox[data-category="${ category }"][data-mode="write"]:not(:checked)`
					);

					if ( writeCheckboxes.length > 0 ) {
						const i18n = window.albertAdmin?.i18n || {};
						const msg = i18n.enableAllWriteConfirm ||
							`This will enable ${ writeCheckboxes.length } write ability group(s) (create, update, delete). Continue?`;

						if ( ! window.confirm( msg ) ) {
							e.target.checked = false;
							return;
						}
					}
				}

				// Toggle all group checkboxes in this category.
				document.querySelectorAll( `.ability-group-checkbox[data-category="${ category }"]` ).forEach( ( checkbox ) => {
					if ( checkbox.checked !== isChecked ) {
						checkbox.checked = isChecked;
						checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					}
				} );
			} );
		} );
	},

	/**
	 * Handle expand/collapse of content type rows.
	 */
	handleContentTypeExpand() {
		document.querySelectorAll( '.ability-type-expand' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				const isExpanded = button.getAttribute( 'aria-expanded' ) === 'true';
				const detailsId = button.getAttribute( 'aria-controls' );
				const details = document.getElementById( detailsId );

				button.setAttribute( 'aria-expanded', String( ! isExpanded ) );

				if ( details ) {
					details.hidden = isExpanded;
				}
			} );
		} );
	},

	/**
	 * Initialize toggle states on page load.
	 */
	initializeToggleStates() {
		// Sync hidden inputs for checked group checkboxes without expanded panels.
		document.querySelectorAll( '.ability-group-checkbox:checked' ).forEach( ( checkbox ) => {
			const abilities = JSON.parse( checkbox.dataset.abilities || '[]' );
			this.syncHiddenInputsForGroup( checkbox, abilities, true );
		} );

		// Initialize indeterminate states for group checkboxes based on individual abilities.
		this.initializeIndeterminateStates();

		// Update category toggle states.
		const processedCategories = new Set();
		document.querySelectorAll( '.toggle-category-abilities' ).forEach( ( toggle ) => {
			const category = toggle.dataset.category;
			if ( ! processedCategories.has( category ) ) {
				processedCategories.add( category );
				this.updateCategoryToggleState( category );
			}
		} );
	},

	/**
	 * Initialize indeterminate states for group checkboxes.
	 * Checks if individual ability checkboxes have mixed states.
	 */
	initializeIndeterminateStates() {
		document.querySelectorAll( '.ability-group-checkbox' ).forEach( ( groupCheckbox ) => {
			const abilities = JSON.parse( groupCheckbox.dataset.abilities || '[]' );

			// Check if there are individual checkboxes for these abilities.
			const individualCheckboxes = abilities.map( ( name ) =>
				document.querySelector( `.ability-item-checkbox[value="${ name }"]` )
			).filter( Boolean );

			// If no individual checkboxes exist, check hidden presented inputs vs enabled.
			if ( individualCheckboxes.length === 0 ) {
				const form = document.getElementById( 'albert-form' );
				if ( ! form ) {
					return;
				}

				const enabledCount = abilities.filter( ( name ) =>
					form.querySelector( `input[name="albert_enabled_on_page[]"][value="${ name }"]` )
				).length;

				const allEnabled = enabledCount === abilities.length;
				const noneEnabled = enabledCount === 0;

				groupCheckbox.indeterminate = ! allEnabled && ! noneEnabled;
				// Note: checked state is already set from PHP.
			} else {
				// Use individual checkboxes to determine state.
				const checkedCount = individualCheckboxes.filter( ( cb ) => cb.checked ).length;
				const allChecked = checkedCount === individualCheckboxes.length;
				const noneChecked = checkedCount === 0;

				groupCheckbox.indeterminate = ! allChecked && ! noneChecked;
				groupCheckbox.checked = allChecked;
			}
		} );
	},

	/**
	 * Update category toggle state based on group checkboxes.
	 * Sets indeterminate state when some (but not all) groups are fully enabled.
	 */
	updateCategoryToggleState( category ) {
		const checkboxes = document.querySelectorAll( `.ability-group-checkbox[data-category="${ category }"]` );
		const toggleAll = document.querySelector( `.toggle-category-abilities[data-category="${ category }"]` );

		if ( checkboxes.length === 0 || ! toggleAll ) {
			return;
		}

		const checkedCount = Array.from( checkboxes ).filter( ( cb ) => cb.checked ).length;
		const hasIndeterminate = Array.from( checkboxes ).some( ( cb ) => cb.indeterminate );
		const allChecked = checkedCount === checkboxes.length;
		const noneChecked = checkedCount === 0;

		// Indeterminate if any group is indeterminate, or if some groups are checked.
		toggleAll.indeterminate = hasIndeterminate || ( ! allChecked && ! noneChecked );
		toggleAll.checked = allChecked && ! hasIndeterminate;
	},
};

/**
 * Collapse/Expand functionality for ability groups with localStorage persistence.
 */
const CollapseModule = {
	storageKey: 'albert_collapsed_categories',

	init() {
		this.restoreCollapsedState();
		this.removePreloadStyle();
		this.handleGroupCollapse();
		this.handleExpandCollapseAll();
	},

	removePreloadStyle() {
		const preload = document.getElementById( 'albert-collapse-preload' );
		if ( preload ) {
			preload.remove();
		}
	},

	getCollapsedCategories() {
		try {
			const stored = localStorage.getItem( this.storageKey );
			return stored ? JSON.parse( stored ) : [];
		} catch {
			return [];
		}
	},

	saveCollapsedCategories( collapsed ) {
		try {
			localStorage.setItem( this.storageKey, JSON.stringify( collapsed ) );
		} catch {
			// Silently fail if localStorage is unavailable.
		}
	},

	restoreCollapsedState() {
		const collapsed = this.getCollapsedCategories();

		collapsed.forEach( ( categoryId ) => {
			const group = document.getElementById( categoryId );
			if ( ! group ) {
				return;
			}

			const button = group.querySelector( '.ability-group-collapse-toggle' );
			const items = group.querySelector( '.ability-group-items' );

			if ( button && items ) {
				button.setAttribute( 'aria-expanded', 'false' );
				items.classList.add( 'collapsed' );
				group.classList.add( 'is-collapsed' );
			}
		} );
	},

	handleGroupCollapse() {
		document.querySelectorAll( '.ability-group-collapse-toggle' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				const group = button.closest( '.ability-group' );
				const targetId = button.getAttribute( 'aria-controls' );
				const target = document.getElementById( targetId );
				const isExpanded = button.getAttribute( 'aria-expanded' ) === 'true';

				button.setAttribute( 'aria-expanded', String( ! isExpanded ) );
				target.classList.toggle( 'collapsed' );
				group.classList.toggle( 'is-collapsed' );

				// Persist state.
				this.persistCollapseState();
			} );
		} );
	},

	persistCollapseState() {
		const collapsed = [];
		document.querySelectorAll( '.ability-group.is-collapsed' ).forEach( ( group ) => {
			if ( group.id ) {
				collapsed.push( group.id );
			}
		} );
		this.saveCollapsedCategories( collapsed );
	},

	handleExpandCollapseAll() {
		const expandAllBtn = document.getElementById( 'albert-expand-all' );
		const collapseAllBtn = document.getElementById( 'albert-collapse-all' );

		if ( expandAllBtn ) {
			expandAllBtn.addEventListener( 'click', () => {
				document.querySelectorAll( '.ability-group' ).forEach( ( group ) => {
					const button = group.querySelector( '.ability-group-collapse-toggle' );
					const items = group.querySelector( '.ability-group-items' );

					if ( button && items ) {
						button.setAttribute( 'aria-expanded', 'true' );
						items.classList.remove( 'collapsed' );
						group.classList.remove( 'is-collapsed' );
					}
				} );
				this.persistCollapseState();
			} );
		}

		if ( collapseAllBtn ) {
			collapseAllBtn.addEventListener( 'click', () => {
				document.querySelectorAll( '.ability-group' ).forEach( ( group ) => {
					const button = group.querySelector( '.ability-group-collapse-toggle' );
					const items = group.querySelector( '.ability-group-items' );

					if ( button && items ) {
						button.setAttribute( 'aria-expanded', 'false' );
						items.classList.add( 'collapsed' );
						group.classList.add( 'is-collapsed' );
					}
				} );
				this.persistCollapseState();
			} );
		}
	},
};

/**
 * Content type row click-to-toggle functionality.
 */
const ContentTypeRowModule = {
	init() {
		document.querySelectorAll( '.ability-type-row' ).forEach( ( row ) => {
			row.addEventListener( 'click', ( e ) => {
				// Let native label/input behavior handle clicks on those elements.
				if ( e.target.closest( 'label' ) || e.target.closest( 'input' ) ) {
					return;
				}

				// Don't toggle on row click - let users click specific toggles.
			} );
		} );
	},
};

/**
 * Clipboard functionality for copy buttons and text.
 */
const ClipboardModule = {
	init() {
		this.handleCopyText();
		this.handleCopyButton();
	},

	async copyToClipboard( text ) {
		try {
			await navigator.clipboard.writeText( text );
			return true;
		} catch {
			// Fallback for older browsers.
			const textarea = document.createElement( 'textarea' );
			textarea.value = text;
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';
			document.body.appendChild( textarea );
			textarea.select();
			document.execCommand( 'copy' );
			document.body.removeChild( textarea );
			return true;
		}
	},

	showCopiedFeedback( element, originalText = null ) {
		const i18n = window.albertAdmin?.i18n || { copied: 'Copied!' };

		// Announce to screen readers.
		const liveRegion = document.getElementById( 'albert-copy-status' );
		if ( liveRegion ) {
			liveRegion.textContent = i18n.copied;
		}

		if ( originalText !== null ) {
			element.textContent = i18n.copied;
			element.classList.add( 'copied' );

			setTimeout( () => {
				element.textContent = originalText;
				element.classList.remove( 'copied' );
			}, 2000 );
		} else {
			element.classList.add( 'copied' );
			element.setAttribute( 'data-copied', i18n.copied );

			setTimeout( () => {
				element.classList.remove( 'copied' );
				element.removeAttribute( 'data-copied' );
			}, 2000 );
		}
	},

	handleCopyText() {
		document.addEventListener( 'click', async ( e ) => {
			const copyText = e.target.closest( '.albert-copy-text' );
			if ( ! copyText ) {
				return;
			}

			const text = copyText.textContent.trim();
			await this.copyToClipboard( text );
			this.showCopiedFeedback( copyText );
		} );
	},

	handleCopyButton() {
		document.addEventListener( 'click', async ( e ) => {
			const button = e.target.closest( '.albert-copy-button' );
			if ( ! button ) {
				return;
			}

			const targetId = button.dataset.copyTarget;
			const target = document.getElementById( targetId );
			if ( ! target ) {
				return;
			}

			const text = target.value !== undefined ? target.value : target.textContent.trim();
			const originalText = button.textContent;

			await this.copyToClipboard( text );
			this.showCopiedFeedback( button, originalText );
		} );
	},
};

/**
 * OAuth client modal functionality.
 */
const ModalModule = {
	init() {
		const modal = document.getElementById( 'albert-oauth-client-modal' );
		if ( ! modal ) {
			return;
		}

		this.modal = modal;
		this.form = document.getElementById( 'albert-client-form' );
		this.created = document.getElementById( 'albert-client-created' );
		this.spinner = modal.querySelector( '.spinner' );

		this.bindEvents();
	},

	bindEvents() {
		// Open modal.
		document.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( '#albert-add-client' ) ) {
				e.preventDefault();
				this.openModal();
			}
		} );

		// Close modal.
		document.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( '.albert-modal-close' ) || e.target.closest( '#albert-close-modal-btn' ) ) {
				this.closeModal();
			}
		} );

		// Close on backdrop click.
		this.modal.addEventListener( 'click', ( e ) => {
			if ( e.target.classList.contains( 'albert-modal' ) ) {
				this.closeModal();
			}
		} );

		// Close on ESC key.
		document.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Escape' && this.modal.style.display !== 'none' ) {
				this.closeModal();
			}
		} );

		// Create client.
		document.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( '#albert-create-client-btn' ) ) {
				this.createClient();
			}
		} );
	},

	openModal() {
		if ( this.form ) {
			this.form.style.display = '';
		}
		if ( this.created ) {
			this.created.style.display = 'none';
		}

		const userSelect = document.getElementById( 'albert-client-user' );
		const nameInput = document.getElementById( 'albert-client-name' );

		if ( userSelect ) {
			userSelect.value = '';
		}
		if ( nameInput ) {
			nameInput.value = '';
		}

		this.modal.style.display = '';
		userSelect?.focus();
	},

	closeModal() {
		this.modal.style.display = 'none';

		if ( this.created && this.created.style.display !== 'none' ) {
			window.location.reload();
		}
	},

	async createClient() {
		const userSelect = document.getElementById( 'albert-client-user' );
		const nameInput = document.getElementById( 'albert-client-name' );
		const userId = userSelect?.value;
		const name = nameInput?.value.trim();

		if ( ! userId ) {
			userSelect?.focus();
			return;
		}

		if ( ! name ) {
			nameInput?.focus();
			return;
		}

		if ( this.spinner ) {
			this.spinner.classList.add( 'is-active' );
		}

		const adminData = window.albertAdmin || {};
		const i18n = adminData.i18n || { createError: 'Error creating client' };

		try {
			const formData = new FormData();
			formData.append( 'action', 'ea_create_oauth_client' );
			formData.append( 'nonce', adminData.nonce || '' );
			formData.append( 'user_id', userId );
			formData.append( 'name', name );

			const response = await fetch( adminData.ajaxUrl || '/wp-admin/admin-ajax.php', {
				method: 'POST',
				body: formData,
			} );

			const data = await response.json();

			if ( this.spinner ) {
				this.spinner.classList.remove( 'is-active' );
			}

			if ( data.success ) {
				const clientIdEl = document.getElementById( 'albert-new-client-id' );
				const clientSecretEl = document.getElementById( 'albert-new-client-secret' );

				if ( clientIdEl ) {
					clientIdEl.textContent = data.data.client_id;
				}
				if ( clientSecretEl ) {
					clientSecretEl.textContent = data.data.client_secret;
				}

				if ( this.form ) {
					this.form.style.display = 'none';
				}
				if ( this.created ) {
					this.created.style.display = '';
				}
			} else {
				alert( data.data?.message || i18n.createError );
			}
		} catch {
			if ( this.spinner ) {
				this.spinner.classList.remove( 'is-active' );
			}
			alert( i18n.createError );
		}
	},
};

/**
 * Initialize all modules when DOM is ready.
 */
function init() {
	initLiveRegion();
	ToggleModule.init();
	CollapseModule.init();
	ContentTypeRowModule.init();
	ClipboardModule.init();
	ModalModule.init();
	DirtyStateModule.init();
}

/**
 * Initialize a live region for screen reader announcements.
 */
function initLiveRegion() {
	if ( document.getElementById( 'albert-copy-status' ) ) {
		return;
	}
	const liveRegion = document.createElement( 'div' );
	liveRegion.setAttribute( 'aria-live', 'polite' );
	liveRegion.setAttribute( 'aria-atomic', 'true' );
	liveRegion.setAttribute( 'role', 'status' );
	liveRegion.className = 'screen-reader-text';
	liveRegion.id = 'albert-copy-status';
	document.body.appendChild( liveRegion );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
