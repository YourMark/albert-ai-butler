/**
 * Albert Admin Settings Scripts
 *
 * @package Albert
 * @since   1.0.0
 */

/**
 * Toggle functionality for ability categories.
 */
const ToggleModule = {
	init() {
		this.handleCategoryToggleAll();
		this.handleSubgroupToggleAll();
		this.handleIndividualToggles();
		this.initializeToggleStates();
	},

	handleCategoryToggleAll() {
		document.querySelectorAll( '.toggle-category-abilities' ).forEach( ( toggle ) => {
			toggle.addEventListener( 'change', ( e ) => {
				const category = e.target.dataset.category;
				const isChecked = e.target.checked;

				document.querySelectorAll( `.ability-checkbox-category[data-category="${ category }"]` ).forEach( ( checkbox ) => {
					checkbox.checked = isChecked;
				} );

				// Sync subgroup toggles.
				document.querySelectorAll( `.toggle-subgroup-abilities[data-category="${ category }"]` ).forEach( ( subToggle ) => {
					subToggle.checked = isChecked;
				} );
			} );
		} );
	},

	handleSubgroupToggleAll() {
		document.querySelectorAll( '.toggle-subgroup-abilities' ).forEach( ( toggle ) => {
			toggle.addEventListener( 'change', ( e ) => {
				const category = e.target.dataset.category;
				const subgroup = e.target.dataset.subgroup;
				const isChecked = e.target.checked;

				document.querySelectorAll( `.ability-checkbox-category[data-category="${ category }"][data-subgroup="${ subgroup }"]` ).forEach( ( checkbox ) => {
					checkbox.checked = isChecked;
				} );

				// Update the parent category toggle.
				this.updateCategoryToggleState( category );
			} );
		} );
	},

	handleIndividualToggles() {
		document.querySelectorAll( '.ability-checkbox-category' ).forEach( ( checkbox ) => {
			checkbox.addEventListener( 'change', ( e ) => {
				const category = e.target.dataset.category;
				const subgroup = e.target.dataset.subgroup;

				this.updateSubgroupToggleState( category, subgroup );
				this.updateCategoryToggleState( category );
			} );
		} );
	},

	initializeToggleStates() {
		const processedCategories = new Set();
		const processedSubgroups = new Set();

		document.querySelectorAll( '.toggle-subgroup-abilities' ).forEach( ( toggle ) => {
			const key = toggle.dataset.category + '-' + toggle.dataset.subgroup;
			if ( ! processedSubgroups.has( key ) ) {
				processedSubgroups.add( key );
				this.updateSubgroupToggleState( toggle.dataset.category, toggle.dataset.subgroup );
			}
		} );

		document.querySelectorAll( '.toggle-category-abilities' ).forEach( ( toggle ) => {
			const category = toggle.dataset.category;
			if ( ! processedCategories.has( category ) ) {
				processedCategories.add( category );
				this.updateCategoryToggleState( category );
			}
		} );
	},

	updateCategoryToggleState( category ) {
		const checkboxes = document.querySelectorAll( `.ability-checkbox-category[data-category="${ category }"]` );
		const toggleAll = document.querySelector( `.toggle-category-abilities[data-category="${ category }"]` );

		if ( checkboxes.length === 0 || ! toggleAll ) {
			return;
		}

		const checkedCount = Array.from( checkboxes ).filter( ( cb ) => cb.checked ).length;
		toggleAll.checked = checkedCount === checkboxes.length;
	},

	updateSubgroupToggleState( category, subgroup ) {
		if ( ! subgroup ) {
			return;
		}

		const checkboxes = document.querySelectorAll( `.ability-checkbox-category[data-category="${ category }"][data-subgroup="${ subgroup }"]` );
		const toggleAll = document.querySelector( `.toggle-subgroup-abilities[data-category="${ category }"][data-subgroup="${ subgroup }"]` );

		if ( checkboxes.length === 0 || ! toggleAll ) {
			return;
		}

		const checkedCount = Array.from( checkboxes ).filter( ( cb ) => cb.checked ).length;
		toggleAll.checked = checkedCount === checkboxes.length;
	},
};

/**
 * Collapse/Expand functionality for ability groups with localStorage persistence.
 */
const CollapseModule = {
	storageKey: 'albert_collapsed_categories',

	init() {
		this.restoreCollapsedState();
		this.handleGroupCollapse();
		this.handleExpandCollapseAll();
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
		const expandAllBtn = document.getElementById( 'ea-expand-all' );
		const collapseAllBtn = document.getElementById( 'ea-collapse-all' );

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
 * Ability item click-to-toggle functionality.
 */
const AbilityItemModule = {
	init() {
		document.querySelectorAll( '.ability-item' ).forEach( ( item ) => {
			item.addEventListener( 'click', ( e ) => {
				// Let native label/input behavior handle clicks on those elements.
				if ( e.target.closest( 'label' ) || e.target.closest( 'input' ) ) {
					return;
				}

				// Skip premium items.
				if ( item.classList.contains( 'ability-item--premium' ) ) {
					return;
				}

				// For clicks elsewhere (description, padding, etc.), toggle the checkbox.
				const checkbox = item.querySelector( '.ability-checkbox:not(:disabled)' );
				if ( checkbox ) {
					checkbox.checked = ! checkbox.checked;
					checkbox.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
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
			const copyText = e.target.closest( '.ea-copy-text' );
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
			const button = e.target.closest( '.ea-copy-button' );
			if ( ! button ) {
				return;
			}

			const targetId = button.dataset.copyTarget;
			const target = document.getElementById( targetId );
			if ( ! target ) {
				return;
			}

			const text = target.textContent.trim();
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
		const modal = document.getElementById( 'ea-oauth-client-modal' );
		if ( ! modal ) {
			return;
		}

		this.modal = modal;
		this.form = document.getElementById( 'ea-client-form' );
		this.created = document.getElementById( 'ea-client-created' );
		this.spinner = modal.querySelector( '.spinner' );

		this.bindEvents();
	},

	bindEvents() {
		// Open modal.
		document.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( '#ea-add-client' ) ) {
				e.preventDefault();
				this.openModal();
			}
		} );

		// Close modal.
		document.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( '.ea-modal-close' ) || e.target.closest( '#ea-close-modal-btn' ) ) {
				this.closeModal();
			}
		} );

		// Close on backdrop click.
		this.modal.addEventListener( 'click', ( e ) => {
			if ( e.target.classList.contains( 'ea-modal' ) ) {
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
			if ( e.target.closest( '#ea-create-client-btn' ) ) {
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

		const userSelect = document.getElementById( 'ea-client-user' );
		const nameInput = document.getElementById( 'ea-client-name' );

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
		const userSelect = document.getElementById( 'ea-client-user' );
		const nameInput = document.getElementById( 'ea-client-name' );
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
				const clientIdEl = document.getElementById( 'ea-new-client-id' );
				const clientSecretEl = document.getElementById( 'ea-new-client-secret' );

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
	ToggleModule.init();
	CollapseModule.init();
	AbilityItemModule.init();
	ClipboardModule.init();
	ModalModule.init();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
