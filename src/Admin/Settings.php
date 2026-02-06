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
 * (MCP endpoint, API key, developer settings).
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
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_albert_save_external_url', [ $this, 'handle_save_external_url' ] );
		add_action( 'wp_ajax_albert_activate_license', [ $this, 'handle_activate_license' ] );
		add_action( 'wp_ajax_albert_deactivate_license', [ $this, 'handle_deactivate_license' ] );
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
			__( 'Settings', 'albert' ),
			__( 'Settings', 'albert' ),
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
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert' ) );
		}
		?>
		<div class="wrap albert-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors(); ?>

			<div class="albert-content-header">
				<p class="albert-content-description">
					<?php esc_html_e( 'Configure plugin settings and connection details.', 'albert' ); ?>
				</p>
			</div>

			<div class="albert-settings-grid">
				<?php $this->render_license_section(); ?>
				<?php $this->render_mcp_server_section(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the License section.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function render_license_section(): void {
		$license_key = get_option( 'albert_license_key', '' );
		$status      = get_option( 'albert_license_status', 'inactive' );
		$is_active   = $status === 'active';
		?>
		<section class="albert-settings-card" id="albert-license-card">
			<div class="albert-settings-card-header">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'License', 'albert' ); ?></h2>
			</div>
			<div class="albert-settings-card-body">
				<div class="albert-field-group">
					<label class="albert-field-label" for="albert-license-key"><?php esc_html_e( 'License Key', 'albert' ); ?></label>
					<p class="albert-field-description">
						<?php esc_html_e( 'Enter your license key to activate premium addons.', 'albert' ); ?>
					</p>
					<div class="albert-license-key-field">
						<input
							type="password"
							name="albert_license_key"
							id="albert-license-key"
							value="<?php echo esc_attr( $license_key ); ?>"
							placeholder="<?php esc_attr_e( 'Enter your license key', 'albert' ); ?>"
							class="albert-text-input"
							autocomplete="off"
						/>
						<button type="button" class="button albert-license-visibility-toggle" aria-label="<?php esc_attr_e( 'Toggle key visibility', 'albert' ); ?>">
							<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
						</button>
					</div>
				</div>
				<div class="albert-field-group">
					<div class="albert-license-status">
						<span class="albert-license-status-dot<?php echo $is_active ? ' albert-license-status-dot--active' : ''; ?>" aria-hidden="true"></span>
						<span class="albert-license-status-text"><?php echo $is_active ? esc_html__( 'Active', 'albert' ) : esc_html__( 'Inactive', 'albert' ); ?></span>
					</div>
					<div class="albert-license-actions">
						<?php if ( $is_active ) { ?>
							<button type="button" class="button albert-license-button" data-action="deactivate"><?php esc_html_e( 'Deactivate', 'albert' ); ?></button>
						<?php } else { ?>
							<button type="button" class="button button-primary albert-license-button" data-action="activate"><?php esc_html_e( 'Activate', 'albert' ); ?></button>
						<?php } ?>
						<span class="spinner albert-license-spinner"></span>
					</div>
					<div class="albert-license-message" role="alert" aria-live="polite"></div>
				</div>
			</div>
		</section>
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
				<h2><?php esc_html_e( 'MCP Server', 'albert' ); ?></h2>
			</div>
			<div class="albert-settings-card-body">
				<div class="albert-field-group">
					<strong class="albert-field-label"><?php esc_html_e( 'Connection URL', 'albert' ); ?></strong>
					<p class="albert-field-description">
						<?php esc_html_e( 'Use this URL to connect AI tools (Claude Desktop, ChatGPT, etc.) to your site.', 'albert' ); ?>
					</p>
					<div class="albert-url-field">
						<code class="albert-url-value" id="mcp-endpoint-url"><?php echo esc_html( $mcp_endpoint ); ?></code>
						<button type="button" class="button albert-copy-button" data-copy-target="mcp-endpoint-url">
							<?php esc_html_e( 'Copy', 'albert' ); ?>
						</button>
					</div>
				</div>

				<?php if ( $show_developer_settings ) { ?>
					<div class="albert-field-group">
						<label class="albert-field-label" for="albert-external-url"><?php esc_html_e( 'External URL', 'albert' ); ?></label>
						<p class="albert-field-description">
							<?php esc_html_e( 'If your site is behind a tunnel or reverse proxy, enter the public URL here.', 'albert' ); ?>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="albert-inline-form">
							<?php wp_nonce_field( 'albert_save_external_url', 'albert_external_url_nonce' ); ?>
							<input type="hidden" name="action" value="albert_save_external_url" />
							<input
								type="url"
								name="albert_external_url"
								id="albert-external-url"
								value="<?php echo esc_attr( $external_url ); ?>"
								placeholder="<?php esc_attr_e( 'https://your-tunnel-url.example.com', 'albert' ); ?>"
								class="albert-text-input"
							/>
							<button type="submit" class="button"><?php esc_html_e( 'Save', 'albert' ); ?></button>
							<?php if ( ! empty( $external_url ) ) { ?>
								<button type="submit" name="albert_clear_url" value="1" class="button"><?php esc_html_e( 'Clear', 'albert' ); ?></button>
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
			wp_die( esc_html__( 'Security check failed.', 'albert' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change this setting.', 'albert' ) );
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
	 * AJAX handler: activate license.
	 *
	 * Stores the license key, fires the activation action for addons,
	 * and returns a JSON response.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function handle_activate_license(): void {
		check_ajax_referer( 'albert_license', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'albert' ) ], 403 );
		}

		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

		if ( empty( $license_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a license key.', 'albert' ) ] );
		}

		update_option( 'albert_license_key', $license_key );

		$results = [];

		/**
		 * Fires when a license key is being activated.
		 *
		 * Addons hook into this action to call their own activation logic
		 * (e.g. WC API Manager) and append results.
		 *
		 * @param string  $license_key The license key to activate.
		 * @param array[] $results     Passed by reference. Each addon appends its result.
		 *
		 * @since 1.1.0
		 */
		do_action_ref_array( 'albert/license/activate', [ $license_key, &$results ] );

		$any_success = false;
		foreach ( $results as $result ) {
			if ( ! empty( $result['success'] ) ) {
				$any_success = true;
				break;
			}
		}

		if ( $any_success ) {
			update_option( 'albert_license_status', 'active' );
			wp_send_json_success(
				[
					'status'  => 'active',
					'message' => __( 'License activated.', 'albert' ),
					'results' => $results,
				]
			);
		}

		// No addon responded successfully.
		$error_message = __( 'License activation failed.', 'albert' );
		foreach ( $results as $result ) {
			if ( ! empty( $result['message'] ) ) {
				$error_message = $result['message'];
				break;
			}
		}

		if ( empty( $results ) ) {
			$error_message = __( 'No addons are installed to activate.', 'albert' );
		}

		wp_send_json_error( [ 'message' => $error_message ] );
	}

	/**
	 * AJAX handler: deactivate license.
	 *
	 * Fires the deactivation action for addons and updates the status.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function handle_deactivate_license(): void {
		check_ajax_referer( 'albert_license', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to do this.', 'albert' ) ], 403 );
		}

		$license_key = get_option( 'albert_license_key', '' );
		$results     = [];

		/**
		 * Fires when a license key is being deactivated.
		 *
		 * Addons hook into this action to call their own deactivation logic
		 * and append results.
		 *
		 * @param string  $license_key The license key to deactivate.
		 * @param array[] $results     Passed by reference. Each addon appends its result.
		 *
		 * @since 1.1.0
		 */
		do_action_ref_array( 'albert/license/deactivate', [ $license_key, &$results ] );

		update_option( 'albert_license_status', 'inactive' );

		wp_send_json_success(
			[
				'status'  => 'inactive',
				'message' => __( 'License deactivated.', 'albert' ),
				'results' => $results,
			]
		);
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
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/js/admin-settings.js',
			[],
			ALBERT_VERSION,
			true
		);

		wp_localize_script(
			'albert-admin',
			'albertAdmin',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'albert_oauth_nonce' ),
				'licenseNonce' => wp_create_nonce( 'albert_license' ),
				'i18n'         => [
					'copied'       => __( 'Copied!', 'albert' ),
					'copyFailed'   => __( 'Copy failed', 'albert' ),
					'activating'   => __( 'Activating…', 'albert' ),
					'deactivating' => __( 'Deactivating…', 'albert' ),
					'active'       => __( 'Active', 'albert' ),
					'inactive'     => __( 'Inactive', 'albert' ),
					'activated'    => __( 'License activated.', 'albert' ),
					'deactivated'  => __( 'License deactivated.', 'albert' ),
					'error'        => __( 'An error occurred.', 'albert' ),
				],
			]
		);
	}
}
