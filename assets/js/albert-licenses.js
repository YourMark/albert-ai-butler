/**
 * Albert Licenses Page Scripts
 *
 * Calls the EDD SL SDK's AJAX handlers for license activation and
 * deactivation, then refreshes the table via Albert's own endpoint.
 *
 * @package Albert
 * @since   1.1.0
 */

const AlbertLicenses = {
	init() {
		this.keyInput = document.getElementById( 'albert-license-key' );
		this.activateBtn = document.getElementById( 'albert-activate-btn' );
		this.noticeEl = document.getElementById( 'albert-license-notice' );
		this.tableWrap = document.getElementById( 'albert-addons-table-wrap' );

		if ( ! this.activateBtn ) {
			return;
		}

		this.activateBtn.addEventListener( 'click', () => this.handleActivate() );

		this.keyInput.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				this.handleActivate();
			}
		} );

		// Event delegation for deactivate buttons.
		if ( this.tableWrap ) {
			this.tableWrap.addEventListener( 'click', ( e ) => {
				const btn = e.target.closest( '.albert-deactivate-btn' );
				if ( btn ) {
					this.handleDeactivate( btn );
				}
			} );
		}
	},

	handleActivate() {
		const key = this.keyInput.value.trim();
		const cfg = window.albertLicenses || {};
		const i18n = cfg.i18n || {};

		if ( ! key ) {
			this.showNotice( i18n.emptyKey || 'Please enter a license key.', 'error' );
			this.keyInput.focus();
			return;
		}

		this.setButtonLoading( this.activateBtn, true, i18n.activating || 'Activating...' );
		this.hideNotice();

		const promises = ( cfg.addons || [] ).map( ( addon ) => {
			return Albert.ajax.post( cfg.ajaxUrl || window.ajaxurl, {
				action: 'edd_sl_sdk_activate_' + addon.option_slug,
				license: key,
				token: cfg.token || '',
				timestamp: cfg.timestamp || '',
				nonce: cfg.eddNonce || '',
			} )
				.then( ( r ) => r.json() )
				.then( ( r ) => ( { addon: addon.name, success: r.success, data: r.data } ) )
				.catch( () => ( { addon: addon.name, success: false, data: null } ) );
		} );

		Promise.allSettled( promises ).then( ( results ) => {
			const activated = results
				.filter( ( r ) => r.status === 'fulfilled' && r.value.success )
				.map( ( r ) => r.value.addon );

			if ( activated.length ) {
				this.showNotice( 'License activated for: ' + activated.join( ', ' ), 'success' );
				this.keyInput.value = '';
				this.refreshTable();
			} else {
				this.showNotice( 'This license key is not valid for any installed addon.', 'error' );
			}

			this.setButtonLoading( this.activateBtn, false, i18n.activate || 'Activate' );
		} );
	},

	handleDeactivate( btn ) {
		const optionSlug = btn.dataset.optionSlug;
		const addonName = btn.dataset.addonName;
		const licenseKey = btn.dataset.licenseKey;
		const cfg = window.albertLicenses || {};
		const i18n = cfg.i18n || {};

		const message = ( i18n.confirmDeactivate || 'Deactivate license for %s?' ).replace( '%s', addonName );

		// eslint-disable-next-line no-alert
		if ( ! window.confirm( message ) ) {
			return;
		}

		this.setButtonLoading( btn, true, i18n.deactivating || 'Deactivating...' );
		this.hideNotice();

		Albert.ajax.post( cfg.ajaxUrl || window.ajaxurl, {
			action: 'edd_sl_sdk_deactivate_' + optionSlug,
			license: licenseKey,
			token: cfg.token || '',
			timestamp: cfg.timestamp || '',
			nonce: cfg.eddNonce || '',
		} )
			.then( ( r ) => r.json() )
			.then( ( r ) => {
				if ( r.success ) {
					this.showNotice( 'License deactivated for ' + addonName + '.', 'success' );
					this.refreshTable();
				} else {
					this.showNotice( r.data?.message || 'Deactivation failed.', 'error' );
				}
			} )
			.catch( () => {
				this.showNotice( i18n.networkError || 'A network error occurred.', 'error' );
			} )
			.finally( () => {
				this.setButtonLoading( btn, false, 'Deactivate' );
			} );
	},

	refreshTable() {
		const cfg = window.albertLicenses || {};

		Albert.ajax.post( cfg.ajaxUrl || window.ajaxurl, {
			action: 'albert_refresh_licenses_table',
			nonce: cfg.nonce || '',
		} )
			.then( ( r ) => r.json() )
			.then( ( r ) => {
				if ( r.success && r.data?.table_html && this.tableWrap ) {
					this.tableWrap.innerHTML = r.data.table_html;
				}
			} );
	},

	showNotice( message, type ) {
		if ( ! this.noticeEl ) {
			return;
		}

		this.noticeEl.textContent = message;
		this.noticeEl.className = 'albert-license-notice albert-license-notice--' + type;
		this.noticeEl.hidden = false;
	},

	hideNotice() {
		if ( ! this.noticeEl ) {
			return;
		}

		this.noticeEl.hidden = true;
		this.noticeEl.className = 'albert-license-notice';
		this.noticeEl.textContent = '';
	},

	setButtonLoading( btn, loading, text ) {
		if ( loading ) {
			btn.classList.add( 'albert-btn-loading' );
			btn.disabled = true;
			btn.textContent = text;
		} else {
			btn.classList.remove( 'albert-btn-loading' );
			btn.disabled = false;
			btn.textContent = text;
		}
	},
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => AlbertLicenses.init() );
} else {
	AlbertLicenses.init();
}
