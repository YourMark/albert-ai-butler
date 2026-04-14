/**
 * Albert Admin Settings Scripts
 *
 * @package Albert
 * @since   1.0.0
 */

/**
 * Flat abilities list: filtering, view toggle, pagination, row expand,
 * destructive-confirmation, and stats updates.
 *
 * Every ability row is rendered once by the server inside #albert-abilities-list.
 * All navigation is client-side via the `hidden` attribute so form submit still
 * includes every row regardless of the current filter/page.
 */
const AbilitiesListModule = {
	init() {
		this.list = document.getElementById( 'albert-abilities-list' );
		if ( ! this.list ) {
			return;
		}

		this.rows = Array.from( this.list.querySelectorAll( '.ability-row' ) );
		this.emptyState = this.list.querySelector( '.albert-abilities-empty' );
		this.searchInput = document.getElementById( 'albert-abilities-search' );
		this.categoryFilter = document.getElementById( 'albert-abilities-filter-category' );
		this.supplierFilter = document.getElementById( 'albert-abilities-filter-supplier' );
		this.annotationFilter = document.getElementById( 'albert-abilities-filter-annotation' );
		this.statsNode = document.getElementById( 'albert-abilities-stats' );
		this.pagination = document.querySelector( '.albert-abilities-pagination' );
		this.pagesNode = this.pagination ? this.pagination.querySelector( '.albert-pagination-pages' ) : null;
		this.viewButtons = Array.from( document.querySelectorAll( '.albert-view-toggle-btn' ) );
		this.errorNode = document.getElementById( 'albert-abilities-error' );

		this.total = parseInt( this.statsNode?.dataset.total || String( this.rows.length ), 10 );
		this.enabled = parseInt( this.statsNode?.dataset.enabledCount || '0', 10 );
		this.statsTemplate = this.statsNode?.dataset.templateAll || 'Showing %1$s of %2$s · %3$s enabled';

		// Initial view mode + rows-per-page come from data attributes the
		// server rendered, so the page paints in the correct state with no
		// flicker or JS-driven re-layout. localStorage is intentionally NOT
		// consulted — the preference lives in wp_options.
		this.viewMode = AbilitiesListModule.normalizeViewMode( this.list.dataset.viewMode );
		this.rowsPerPage = parseInt( this.list.dataset.rowsPerPage, 10 );
		this.currentPage = 1;

		this.bindSearch();
		this.bindFilters();
		this.bindViewToggle();
		this.bindRowExpand();
		this.bindRowToggle();
		this.bindPagination();
		this.bindChipDismiss();

		// Server already pre-rendered the correct view mode (toggle button
		// state, pagination nav visibility, rows beyond page 1 hidden when
		// paginated), so we don't call applyViewMode on init — calling it
		// would trigger renderPaginationWindow() which writes `hidden` on
		// every row and causes the visible flash we're trying to avoid.
		// We do still need an initial filter/stats pass to set things like
		// the enabled count and the pagination pager numbers.
		if ( 'paginated' === this.viewMode ) {
			this.renderPaginationWindow();
		} else {
			this.updateStats( this.rows.length );
		}
	},

	/**
	 * Coerce an arbitrary string to a valid view mode.
	 *
	 * Mirrors the PHP `AbilitiesPage::normalize_view_mode()` so the two
	 * sides apply identical validation. Anything that isn't `paginated`
	 * collapses to `list`.
	 */
	normalizeViewMode( mode ) {
		return 'paginated' === mode ? 'paginated' : 'list';
	},

	/**
	 * Escape dismisses any visible chip tooltip (WCAG 1.4.13 dismissible).
	 *
	 * Pressing Escape while a chip is focused hides its overlay until the
	 * user moves away; moving focus or pointer away clears the dismissed
	 * state so the next hover/focus shows the tooltip again.
	 */
	bindChipDismiss() {
		if ( ! this.list ) {
			return;
		}

		this.list.addEventListener( 'keydown', ( e ) => {
			if ( e.key !== 'Escape' ) {
				return;
			}
			const chip = e.target.closest( '.ability-chip' );
			if ( ! chip ) {
				return;
			}
			chip.classList.add( 'is-dismissed' );
		} );

		// Clear the dismissed state when the chip loses focus/hover, so the
		// tooltip is available again the next time the user lands on it.
		this.list.addEventListener(
			'focusout',
			( e ) => {
				const chip = e.target.closest( '.ability-chip' );
				if ( chip ) {
					chip.classList.remove( 'is-dismissed' );
				}
			},
			true
		);

		this.list.addEventListener(
			'mouseleave',
			( e ) => {
				const chip = e.target.closest( '.ability-chip' );
				if ( chip ) {
					chip.classList.remove( 'is-dismissed' );
				}
			},
			true
		);
	},

	/**
	 * Persist the view-mode preference to wp_options via admin-ajax.
	 *
	 * Fire-and-forget — failures are logged but don't block the UI. The
	 * preference is server-rendered on next page load, so a failed save
	 * just means the current session keeps the new mode but the next page
	 * load reverts to the previous one.
	 */
	saveViewMode( mode ) {
		const cfg = window.albertAdmin || {};
		if ( ! cfg.ajaxUrl || ! cfg.viewModeNonce ) {
			return;
		}
		Albert.ajax.post( cfg.ajaxUrl, {
			action: 'albert_save_view_mode',
			nonce: cfg.viewModeNonce,
			mode,
		} ).catch( ( err ) => {
			// eslint-disable-next-line no-console
			console.warn( 'Albert: failed to persist view mode', err );
		} );
	},

	bindSearch() {
		if ( ! this.searchInput ) {
			return;
		}
		let searchDebounceTimer;
		this.searchInput.addEventListener( 'input', () => {
			clearTimeout( searchDebounceTimer );
			searchDebounceTimer = setTimeout( () => {
				this.currentPage = 1;
				this.applyFilters();
			}, 120 );
		} );
	},

	bindFilters() {
		[ this.categoryFilter, this.supplierFilter, this.annotationFilter ].forEach( ( select ) => {
			if ( ! select ) {
				return;
			}
			select.addEventListener( 'change', () => {
				this.currentPage = 1;
				this.applyFilters();
			} );
		} );
	},

	bindViewToggle() {
		this.viewButtons.forEach( ( btn ) => {
			btn.addEventListener( 'click', () => {
				this.applyViewMode( AbilitiesListModule.normalizeViewMode( btn.dataset.view ) );
			} );
		} );
	},

	bindRowExpand() {
		this.list.addEventListener( 'click', ( e ) => {
			const button = e.target.closest( '.ability-row-expand' );
			if ( ! button ) {
				return;
			}
			const row = button.closest( '.ability-row' );
			const targetId = button.getAttribute( 'aria-controls' );
			const target = targetId ? document.getElementById( targetId ) : null;
			if ( ! row || ! target ) {
				return;
			}
			const isExpanded = button.getAttribute( 'aria-expanded' ) === 'true';
			button.setAttribute( 'aria-expanded', String( ! isExpanded ) );
			target.hidden = isExpanded;
			row.classList.toggle( 'is-expanded', ! isExpanded );
		} );
	},

	/**
	 * Per-row toggle handler with optimistic UI + AJAX save + revert.
	 *
	 * The flow on click is:
	 *
	 *   1. Confirm destructive abilities BEFORE optimistic update; if the
	 *      user cancels, snap the checkbox back and stop.
	 *   2. Apply the optimistic state immediately (data-enabled, the
	 *      visible "Enabled / Disabled" word, the live enabled count) so
	 *      sighted users see instant feedback.
	 *   3. Disable the checkbox so a rapid double-click can't race the
	 *      pending request, and POST to wp_ajax_albert_toggle_ability.
	 *   4. On success, re-enable the checkbox.
	 *      On failure, revert every optimistic mutation, re-enable the
	 *      checkbox, and surface the error in the inline notice.
	 */
	bindRowToggle() {
		this.list.addEventListener( 'change', ( e ) => {
			const checkbox = e.target.closest( '.ability-row-checkbox' );
			if ( ! checkbox ) {
				return;
			}
			const row = checkbox.closest( '.ability-row' );
			if ( ! row ) {
				return;
			}

			const i18n = window.albertAdmin?.i18n || {};
			const nextEnabled = checkbox.checked;

			// Destructive confirm before any optimistic update.
			if ( nextEnabled && row.dataset.destructive === '1' ) {
				const confirmText = i18n.destructiveConfirm || 'This ability can permanently delete data. Are you sure you want to enable it?';
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( confirmText ) ) {
					checkbox.checked = false;
					return;
				}
			}

			this.applyToggleState( row, nextEnabled );
			this.persistAbilityToggle( row, checkbox, nextEnabled );
		} );
	},

	/**
	 * Apply the visual state of an ability row to match its checkbox.
	 *
	 * Updates `data-enabled` and the running enabled count. The visible
	 * "Enabled / Disabled" text is handled entirely by CSS — both words
	 * are rendered in the DOM and cross-fade based on
	 * `.albert-toggle:has(input:checked)`, so there's no JS text swap.
	 */
	applyToggleState( row, enabled ) {
		row.dataset.enabled = enabled ? '1' : '0';
		this.enabled += enabled ? 1 : -1;
		this.updateStats();
	},

	/**
	 * POST the new state to wp_ajax_albert_toggle_ability.
	 *
	 * Disables the checkbox while in flight (to block double-clicks racing
	 * each other), reverts on failure, and surfaces an error notice if the
	 * request can't complete.
	 */
	persistAbilityToggle( row, checkbox, enabled ) {
		const cfg = window.albertAdmin || {};
		if ( ! cfg.ajaxUrl || ! cfg.toggleAbilityNonce ) {
			this.revertToggle( row, checkbox, ! enabled );
			this.showError( ( cfg.i18n && cfg.i18n.saveError ) || 'Could not save your change. Please try again.' );
			return;
		}

		checkbox.disabled = true;

		Albert.ajax.post( cfg.ajaxUrl, {
			action: 'albert_toggle_ability',
			nonce: cfg.toggleAbilityNonce,
			ability_id: row.dataset.abilityId || '',
			enabled: enabled ? '1' : '0',
		} )
			.then( ( response ) => {
				if ( ! response.ok ) {
					const msg = response.status === 403
						? ( cfg.i18n && cfg.i18n.sessionExpired ) || 'Your session has expired. Reload the page and try again.'
						: ( cfg.i18n && cfg.i18n.saveError ) || 'Could not save your change. Please try again.';
					throw new Error( msg );
				}
				checkbox.disabled = false;
			} )
			.catch( ( err ) => {
				checkbox.disabled = false;
				this.revertToggle( row, checkbox, ! enabled );
				this.showError( err.message || ( cfg.i18n && cfg.i18n.saveError ) || 'Could not save your change. Please try again.' );
			} );
	},

	/**
	 * Roll a row back to a previous state after a failed save.
	 *
	 * Resets the checkbox first so `applyToggleState` re-derives every
	 * dependent piece of UI from a consistent source. The optimistic
	 * `applyToggleState` + this revert form a +1/-1 pair on the enabled
	 * counter, so the running total ends at its original value.
	 */
	revertToggle( row, checkbox, previousEnabled ) {
		checkbox.checked = previousEnabled;
		this.applyToggleState( row, previousEnabled );
	},

	/**
	 * Show an error notice in the dedicated inline alert region.
	 *
	 * Auto-dismisses after a few seconds so a transient failure doesn't
	 * leave a permanent banner; the role="alert" attribute on the element
	 * means screen readers announce it as soon as it appears.
	 */
	showError( message ) {
		if ( ! this.errorNode ) {
			// eslint-disable-next-line no-console
			console.error( 'Albert:', message );
			return;
		}
		this.errorNode.textContent = message;
		this.errorNode.hidden = false;

		clearTimeout( this.errorDismissTimer );
		this.errorDismissTimer = setTimeout( () => {
			this.errorNode.hidden = true;
		}, 6000 );
	},

	bindPagination() {
		if ( ! this.pagination ) {
			return;
		}
		this.pagination.addEventListener( 'click', ( e ) => {
			const button = e.target.closest( 'button[data-direction], button[data-page]' );
			if ( ! button ) {
				return;
			}
			if ( button.dataset.direction === 'prev' ) {
				this.currentPage = Math.max( 1, this.currentPage - 1 );
			} else if ( button.dataset.direction === 'next' ) {
				this.currentPage = Math.min( this.totalPages(), this.currentPage + 1 );
			} else if ( button.dataset.page ) {
				this.currentPage = parseInt( button.dataset.page, 10 );
			}
			this.renderPaginationWindow();
		} );
	},

	applyViewMode( mode ) {
		this.viewMode = mode;
		this.viewButtons.forEach( ( btn ) => {
			const active = btn.dataset.view === mode;
			btn.classList.toggle( 'is-active', active );
			btn.setAttribute( 'aria-pressed', String( active ) );
		} );
		if ( this.pagination ) {
			this.pagination.hidden = mode !== 'paginated';
		}
		this.saveViewMode( mode );
		this.currentPage = 1;
		this.renderPaginationWindow();
	},

	applyFilters() {
		const query = ( this.searchInput?.value || '' ).trim().toLowerCase();
		const categoryFilter = this.categoryFilter?.value || '';
		const supplierFilter = this.supplierFilter?.value || '';
		const annotationFilter = this.annotationFilter?.value || '';

		this.rows.forEach( ( row ) => {
			const haystack = row.dataset.search || '';
			const matchesSearch = '' === query || haystack.includes( query );
			const matchesCategory = '' === categoryFilter || row.dataset.category === categoryFilter;
			const matchesSupplier = '' === supplierFilter || row.dataset.supplier === supplierFilter;
			const matchesAnnotation = '' === annotationFilter || row.dataset.annotation === annotationFilter;

			const visible = matchesSearch && matchesCategory && matchesSupplier && matchesAnnotation;
			row.classList.toggle( 'is-filtered-out', ! visible );
		} );

		this.renderPaginationWindow();
	},

	filteredRows() {
		return this.rows.filter( ( row ) => ! row.classList.contains( 'is-filtered-out' ) );
	},

	totalPages() {
		const visible = this.filteredRows().length;
		return Math.max( 1, Math.ceil( visible / this.rowsPerPage ) );
	},

	renderPaginationWindow() {
		const visible = this.filteredRows();

		// In list mode, show every filtered row and hide the rest.
		if ( this.viewMode !== 'paginated' ) {
			this.rows.forEach( ( row ) => {
				const isFilteredOut = row.classList.contains( 'is-filtered-out' );
				row.hidden = isFilteredOut;
			} );
			this.toggleEmptyState( visible.length === 0 );
			this.updateStats( visible.length );
			return;
		}

		const pages = this.totalPages();
		if ( this.currentPage > pages ) {
			this.currentPage = pages;
		}
		const start = ( this.currentPage - 1 ) * this.rowsPerPage;
		const end = start + this.rowsPerPage;

		// Hide everything first, then reveal the current slice.
		this.rows.forEach( ( row ) => {
			row.hidden = true;
		} );
		visible.slice( start, end ).forEach( ( row ) => {
			row.hidden = false;
		} );

		this.toggleEmptyState( visible.length === 0 );
		this.updateStats( visible.length );
		this.renderPager( pages );
	},

	renderPager( pages ) {
		if ( ! this.pagesNode ) {
			return;
		}
		this.pagesNode.innerHTML = '';

		for ( let i = 1; i <= pages; i++ ) {
			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button albert-pagination-page';
			btn.textContent = String( i );
			btn.dataset.page = String( i );
			if ( i === this.currentPage ) {
				btn.classList.add( 'is-current' );
				btn.setAttribute( 'aria-current', 'page' );
			}
			this.pagesNode.appendChild( btn );
		}

		const prev = this.pagination.querySelector( '.albert-pagination-prev' );
		const next = this.pagination.querySelector( '.albert-pagination-next' );
		if ( prev ) {
			prev.disabled = this.currentPage <= 1;
		}
		if ( next ) {
			next.disabled = this.currentPage >= pages;
		}
	},

	toggleEmptyState( isEmpty ) {
		if ( this.emptyState ) {
			this.emptyState.hidden = ! isEmpty;
		}
	},

	/**
	 * Update the stats line.
	 *
	 * The stats node is `aria-live="polite"`, so every textContent change
	 * would queue a screen-reader announcement — spammy while the user is
	 * typing in the search box. We split the write into two steps:
	 *   - The visible text is updated immediately (sighted users need to
	 *     see the filter results live).
	 *   - The announcement is debounced (~400ms): we temporarily remove
	 *     aria-live while updating, then restore it after a short delay so
	 *     only the settled value is announced.
	 */
	updateStats( visibleCount ) {
		if ( ! this.statsNode ) {
			return;
		}
		const visible = typeof visibleCount === 'number' ? visibleCount : this.filteredRows().length;
		const text = this.statsTemplate
			.replace( '%1$s', String( visible ) )
			.replace( '%2$s', String( this.total ) )
			.replace( '%3$s', String( this.enabled ) );

		// Suppress the live announcement while the value is changing.
		this.statsNode.setAttribute( 'aria-live', 'off' );
		this.statsNode.textContent = text;

		clearTimeout( this.statsAnnounceTimer );
		this.statsAnnounceTimer = setTimeout( () => {
			this.statsNode.setAttribute( 'aria-live', 'polite' );
		}, 400 );
	},
};

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

			const target = document.getElementById( button.dataset.copyTarget );
			if ( ! target ) {
				return;
			}

			const text = target.value !== undefined && null !== target.value
				? target.value
				: target.textContent.trim();

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
 * Disconnect dialog — populates and shows a native dialog for disconnect actions.
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

			this.title.textContent = 'Disconnect ' + ( trigger.dataset.clientName || '' ) + '?';
			this.connLink.href = trigger.dataset.revokeUrl;
			this.sessLink.href = trigger.dataset.revokeFullUrl;

			this.dialog.showModal();
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
	AbilitiesListModule.init();
	ClipboardModule.init();
	DisconnectModule.init();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
