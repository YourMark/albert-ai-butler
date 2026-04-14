<?php
/**
 * Settings Admin Page
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\MCP\Server as McpServer;
use Albert\OAuth\Database\Installer;

/**
 * Settings class
 *
 * Manages the plugin settings page for configuration options
 * (MCP endpoint, developer settings, addon licenses).
 *
 * @since 1.0.0
 */
class Settings implements Hookable {

	/**
	 * Parent menu slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $parent_slug = 'albert';

	/**
	 * Settings page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $page_slug = 'albert-settings';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_albert_save_external_url', [ $this, 'handle_save_external_url' ] );
	}

	/**
	 * Add settings page to WordPress admin menu.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_settings_page(): void {
		add_submenu_page(
			$this->parent_slug,
			__( 'Settings', 'albert-ai-butler' ),
			__( 'Settings', 'albert-ai-butler' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}
		?>
		<div class="wrap albert-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors(); ?>

			<div class="albert-content-header">
				<p class="albert-content-description">
					<?php esc_html_e( 'Configure plugin settings and connection details.', 'albert-ai-butler' ); ?>
				</p>
			</div>

			<div class="albert-main-content">
				<?php $this->render_mcp_server_section(); ?>
				<?php $this->render_licenses_section(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the MCP Server section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_mcp_server_section(): void {
		/**
		 * Filter to show developer settings like External URL.
		 *
		 * @param bool $show Whether to show developer settings. Default false.
		 *
		 * @since 1.0.0
		 */
		$show_developer_settings = apply_filters( 'albert/developer_mode', false );

		// Only use external URL if developer settings are enabled.
		$external_url = $show_developer_settings ? get_option( 'albert_external_url', '' ) : '';
		$mcp_endpoint = McpServer::get_endpoint_url( $external_url );
		?>
		<section class="albert-settings-card">
			<div class="albert-settings-card-header">
				<span class="dashicons dashicons-cloud" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'MCP Server', 'albert-ai-butler' ); ?></h2>
			</div>
			<div class="albert-settings-card-body">
				<div class="albert-field-group">
					<strong class="albert-field-label"><?php esc_html_e( 'Connection URL', 'albert-ai-butler' ); ?></strong>
					<p class="albert-field-description">
						<?php esc_html_e( 'Use this URL to connect AI tools (Claude Desktop, ChatGPT, etc.) to your site.', 'albert-ai-butler' ); ?>
					</p>
					<div class="albert-url-field">
						<code class="albert-url-value" id="mcp-endpoint-url"><?php echo esc_html( $mcp_endpoint ); ?></code>
						<button type="button" class="button albert-copy-button" data-copy-target="mcp-endpoint-url">
							<?php esc_html_e( 'Copy', 'albert-ai-butler' ); ?>
						</button>
					</div>
				</div>

				<?php if ( $show_developer_settings ) { ?>
					<div class="albert-field-group">
						<label class="albert-field-label" for="albert-external-url"><?php esc_html_e( 'External URL', 'albert-ai-butler' ); ?></label>
						<p class="albert-field-description">
							<?php esc_html_e( 'If your site is behind a tunnel or reverse proxy, enter the public URL here.', 'albert-ai-butler' ); ?>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="albert-inline-form">
							<?php wp_nonce_field( 'albert_save_external_url', 'albert_external_url_nonce' ); ?>
							<input type="hidden" name="action" value="albert_save_external_url" />
							<input
								type="url"
								name="albert_external_url"
								id="albert-external-url"
								value="<?php echo esc_attr( $external_url ); ?>"
								placeholder="<?php esc_attr_e( 'https://your-tunnel-url.example.com', 'albert-ai-butler' ); ?>"
								class="albert-text-input"
							/>
							<button type="submit" class="button"><?php esc_html_e( 'Save', 'albert-ai-butler' ); ?></button>
							<?php if ( ! empty( $external_url ) ) { ?>
								<button type="submit" name="albert_clear_url" value="1" class="button"><?php esc_html_e( 'Clear', 'albert-ai-butler' ); ?></button>
							<?php } ?>
						</form>
					</div>
				<?php } ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Handle saving the external URL setting.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_save_external_url(): void {
		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['albert_external_url_nonce'] ?? '' ) ), 'albert_save_external_url' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'albert-ai-butler' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change this setting.', 'albert-ai-butler' ) );
		}

		// Check if clearing.
		if ( isset( $_POST['albert_clear_url'] ) ) {
			delete_option( 'albert_external_url' );
		} else {
			$url = isset( $_POST['albert_external_url'] ) ? esc_url_raw( wp_unslash( $_POST['albert_external_url'] ) ) : '';

			// Remove trailing slash for consistency.
			$url = rtrim( $url, '/' );

			if ( ! empty( $url ) ) {
				update_option( 'albert_external_url', $url );
			} else {
				delete_option( 'albert_external_url' );
			}
		}

		// Redirect back.
		wp_safe_redirect(
			add_query_arg(
				[ 'page' => $this->page_slug ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Revoke all tokens for a user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function revoke_user_tokens( int $user_id ): void {
		global $wpdb;

		$tables = Installer::get_table_names();

		// Get all access token IDs for this user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$token_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT token_id FROM %i WHERE user_id = %d',
				$tables['access_tokens'],
				$user_id
			)
		);

		// Revoke access tokens.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$tables['access_tokens'],
			[ 'revoked' => 1 ],
			[ 'user_id' => $user_id ],
			[ '%d' ],
			[ '%d' ]
		);

		// Revoke associated refresh tokens.
		if ( ! empty( $token_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $token_ids ), '%s' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET revoked = 1 WHERE access_token_id IN ({$placeholders})",
					$tables['refresh_tokens'],
					...$token_ids
				)
			);
			// phpcs:enable
		}
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets( string $hook ): void {
		// Hook format for submenu is: {parent_slug}_page_{menu_slug}.
		if ( 'albert_page_' . $this->page_slug !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			ALBERT_VERSION
		);

		wp_enqueue_script(
			'albert-admin-utils',
			ALBERT_PLUGIN_URL . 'assets/js/albert-admin-utils.js',
			[],
			ALBERT_VERSION,
			true
		);

		wp_enqueue_script(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/js/admin-settings.js',
			[ 'albert-admin-utils' ],
			ALBERT_VERSION,
			true
		);

		wp_localize_script(
			'albert-admin',
			'albertAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'albert_oauth_nonce' ),
				'i18n'    => [
					'copied'     => __( 'Copied!', 'albert-ai-butler' ),
					'copyFailed' => __( 'Copy failed', 'albert-ai-butler' ),
				],
			]
		);

		// License management assets.
		wp_enqueue_style(
			'albert-licenses',
			ALBERT_PLUGIN_URL . 'assets/css/albert-licenses.css',
			[ 'albert-admin' ],
			ALBERT_VERSION
		);

		wp_enqueue_script(
			'albert-licenses',
			ALBERT_PLUGIN_URL . 'assets/js/albert-licenses.js',
			[ 'albert-admin-utils' ],
			ALBERT_VERSION,
			true
		);

		$addons_for_js = [];
		if ( class_exists( '\Albert\Abstracts\AbstractAddon' ) ) {
			foreach ( \Albert\Abstracts\AbstractAddon::get_registered_addons() as $addon ) {
				$addons_for_js[] = [
					'name'        => $addon['name'],
					'option_slug' => $addon['option_slug'],
				];
			}
		}

		$timestamp = time();
		$token     = '';
		$edd_nonce = '';
		if ( class_exists( '\EasyDigitalDownloads\Updater\Utilities\Tokenizer' ) ) {
			$token     = \EasyDigitalDownloads\Updater\Utilities\Tokenizer::tokenize( $timestamp );
			$edd_nonce = wp_create_nonce( 'edd_sl_sdk_license_handler' );
		}

		wp_localize_script(
			'albert-licenses',
			'albertLicenses',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'albert_license_nonce' ),
				'token'     => $token,
				'timestamp' => $timestamp,
				'eddNonce'  => $edd_nonce,
				'addons'    => $addons_for_js,
				'i18n'      => [
					'activating'        => __( 'Activating...', 'albert-ai-butler' ),
					'activate'          => __( 'Activate', 'albert-ai-butler' ),
					'deactivating'      => __( 'Deactivating...', 'albert-ai-butler' ),
					/* translators: %s: addon name */
					'confirmDeactivate' => __( 'Deactivate license for %s?', 'albert-ai-butler' ),
					'emptyKey'          => __( 'Please enter a license key.', 'albert-ai-butler' ),
					'networkError'      => __( 'A network error occurred. Please try again.', 'albert-ai-butler' ),
				],
			]
		);
	}

	/**
	 * Render the licenses section.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function render_licenses_section(): void {
		$has_addons = class_exists( '\Albert\Abstracts\AbstractAddon' )
			&& ! empty( \Albert\Abstracts\AbstractAddon::get_registered_addons() );
		?>
		<section class="albert-settings-card albert-license-card">
			<div class="albert-settings-card-header">
				<span class="dashicons dashicons-admin-network" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'Licenses', 'albert-ai-butler' ); ?></h2>
			</div>
			<div class="albert-settings-card-body">
				<?php if ( $has_addons ) { ?>
					<div id="albert-license-notice" class="albert-license-notice" hidden></div>
					<div class="albert-license-form">
						<input
							type="text"
							id="albert-license-key"
							class="albert-text-input"
							placeholder="<?php esc_attr_e( 'Enter your license key', 'albert-ai-butler' ); ?>"
							autocomplete="off"
						/>
						<button type="button" id="albert-activate-btn" class="button button-primary">
							<?php esc_html_e( 'Activate', 'albert-ai-butler' ); ?>
						</button>
					</div>
					<p class="albert-field-description albert-license-hint">
						<?php esc_html_e( 'Enter your license key. It will be automatically matched to the correct addon.', 'albert-ai-butler' ); ?>
					</p>
					<div id="albert-addons-table-wrap">
						<?php self::render_licenses_table(); ?>
					</div>
				<?php } else { ?>
					<?php self::render_licenses_empty_state(); ?>
				<?php } ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render the licenses table.
	 *
	 * This method is public and static so the AJAX handler can call it to
	 * return refreshed table HTML.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function render_licenses_table(): void {
		if ( ! class_exists( '\Albert\Abstracts\AbstractAddon' ) ) {
			self::render_licenses_empty_state();
			return;
		}

		$addons = \Albert\Abstracts\AbstractAddon::get_registered_addons();

		if ( empty( $addons ) ) {
			self::render_licenses_empty_state();
			return;
		}
		?>
		<table class="albert-licenses-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Addon', 'albert-ai-butler' ); ?></th>
					<th><?php esc_html_e( 'Version', 'albert-ai-butler' ); ?></th>
					<th><?php esc_html_e( 'Status', 'albert-ai-butler' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'albert-ai-butler' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'albert-ai-butler' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $addons as $addon ) {
					$option_slug  = $addon['option_slug'];
					$license_data = get_option( "{$option_slug}_license", false );
					$license_key  = get_option( "{$option_slug}_license_key", '' );
					$status       = is_object( $license_data ) ? ( $license_data->license ?? '' ) : '';
					$expires      = is_object( $license_data ) ? ( $license_data->expires ?? '' ) : '';
					$store_url    = $addon['store_url'] ?? 'https://albertwp.com';
					?>
					<tr>
						<td><strong><?php echo esc_html( $addon['name'] ); ?></strong></td>
						<td><?php echo esc_html( $addon['version'] ); ?></td>
						<td><?php self::render_status( $status ); ?></td>
						<td><?php self::render_expires( $status, $expires ); ?></td>
						<td><?php self::render_actions( $status, $option_slug, $addon['name'], $store_url, $license_key ); ?></td>
					</tr>
				<?php } ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the status cell content.
	 *
	 * @since 1.1.0
	 *
	 * @param string $status The EDD license status.
	 *
	 * @return void
	 */
	private static function render_status( string $status ): void {
		switch ( $status ) {
			case 'valid':
				$class = 'albert-status-dot--valid';
				$label = __( 'Active', 'albert-ai-butler' );
				break;

			case 'expired':
				$class = 'albert-status-dot--expired';
				$label = __( 'Expired', 'albert-ai-butler' );
				break;

			case 'disabled':
			case 'invalid':
			case 'site_inactive':
			case 'item_name_mismatch':
			case 'no_activations_left':
				$class = 'albert-status-dot--invalid';
				$label = ucfirst( str_replace( '_', ' ', $status ) );
				break;

			default:
				$class = 'albert-status-dot--none';
				$label = __( 'Not activated', 'albert-ai-butler' );
				break;
		}

		echo '<span class="albert-status-dot ' . esc_attr( $class ) . '"></span> ';
		echo esc_html( $label );
	}

	/**
	 * Render the "Expires" cell content.
	 *
	 * @since 1.1.0
	 *
	 * @param string $status  The license status.
	 * @param string $expires The expiration date string.
	 *
	 * @return void
	 */
	private static function render_expires( string $status, string $expires ): void {
		if ( empty( $status ) || $status === 'inactive' || empty( $expires ) ) {
			echo '<span class="albert-no-license">&mdash;</span>';
			return;
		}

		if ( $expires === 'lifetime' ) {
			echo esc_html__( 'Lifetime', 'albert-ai-butler' );
			return;
		}

		$timestamp = strtotime( $expires );
		$formatted = $timestamp !== false ? wp_date( get_option( 'date_format' ), $timestamp ) : false;
		if ( $formatted !== false ) {
			echo esc_html( $formatted );
		} else {
			echo esc_html( $expires );
		}
	}

	/**
	 * Render the actions cell content.
	 *
	 * @since 1.1.0
	 *
	 * @param string $status      The license status.
	 * @param string $option_slug The addon option slug (basename of plugin dir).
	 * @param string $name        The addon name.
	 * @param string $store_url   The addon store URL.
	 * @param string $license_key The stored license key.
	 *
	 * @return void
	 */
	private static function render_actions( string $status, string $option_slug, string $name, string $store_url, string $license_key = '' ): void {
		if ( $status === 'valid' ) {
			echo '<button type="button" class="albert-deactivate-btn"'
				. ' data-option-slug="' . esc_attr( $option_slug ) . '"'
				. ' data-addon-name="' . esc_attr( $name ) . '"'
				. ' data-license-key="' . esc_attr( $license_key ) . '">'
				. esc_html__( 'Deactivate', 'albert-ai-butler' )
				. '</button>';
		} elseif ( $status === 'expired' ) {
			echo '<a href="' . esc_url( $store_url ) . '" target="_blank" rel="noopener noreferrer" class="button button-small">'
				. esc_html__( 'Renew', 'albert-ai-butler' )
				. '</a>';
		} else {
			echo '<span class="albert-no-license">&mdash;</span>';
		}
	}

	/**
	 * Render the empty state when no addons are installed.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private static function render_licenses_empty_state(): void {
		?>
		<div class="albert-empty-state">
			<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
			<p>
				<?php esc_html_e( 'Premium addons extend Albert with powerful features like bulk operations, WooCommerce management, and SEO tools.', 'albert-ai-butler' ); ?>
			</p>
			<p>
				<a href="https://albertwp.com/addons/" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Explore addons', 'albert-ai-butler' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
				</a>
			</p>
		</div>
		<?php
	}
}
