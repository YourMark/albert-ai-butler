<?php
/**
 * Settings Admin Page
 *
 * @package    ExtendedAbilities
 * @subpackage Admin
 * @since      1.0.0
 */

namespace ExtendedAbilities\Admin;

use ExtendedAbilities\Contracts\Interfaces\Hookable;
use ExtendedAbilities\MCP\Server as McpServer;

/**
 * Settings class
 *
 * Manages the plugin settings page and ability toggles.
 *
 * @since 1.0.0
 */
class Settings implements Hookable {
	/**
	 * Settings page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $page_slug = 'extended-abilities';

	/**
	 * Option group name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $option_group = 'extended_abilities_settings';

	/**
	 * Option name for storing settings.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $option_name = 'extended_abilities_options';

	/**
	 * Available tabs.
	 *
	 * @since 1.0.0
	 * @var array|null
	 */
	private ?array $tabs = null;

	/**
	 * Get available tabs.
	 *
	 * Tabs are lazy-loaded to avoid translation functions being called too early.
	 *
	 * @return array Array of tab slug => label pairs.
	 * @since 1.0.0
	 */
	private function get_tabs(): array {
		if ( null === $this->tabs ) {
			$this->tabs = [
				'abilities'      => __( 'Abilities', 'extended-abilities' ),
				'authentication' => __( 'Authentication', 'extended-abilities' ),
			];

			/**
			 * Filter the available settings tabs.
			 *
			 * @param array $tabs Array of tab slug => label pairs.
			 *
			 * @since 1.0.0
			 */
			$this->tabs = apply_filters( 'extended_abilities_settings_tabs', $this->tabs );
		}

		return $this->tabs;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_oauth_actions' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
		add_action( 'admin_post_ea_save_external_url', [ $this, 'handle_save_external_url' ] );
		add_action( 'admin_post_ea_add_allowed_user', [ $this, 'handle_add_allowed_user' ] );
	}

	/**
	 * Handle saving the external URL setting.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_save_external_url(): void {
		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ea_external_url_nonce'] ?? '' ) ), 'ea_save_external_url' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'extended-abilities' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change this setting.', 'extended-abilities' ) );
		}

		// Check if clearing.
		if ( isset( $_POST['ea_clear_url'] ) ) {
			delete_option( 'ea_external_url' );
		} else {
			$url = isset( $_POST['ea_external_url'] ) ? esc_url_raw( wp_unslash( $_POST['ea_external_url'] ) ) : '';

			// Remove trailing slash for consistency.
			$url = rtrim( $url, '/' );

			if ( ! empty( $url ) ) {
				update_option( 'ea_external_url', $url );
			} else {
				delete_option( 'ea_external_url' );
			}
		}

		// Redirect back.
		wp_safe_redirect(
			add_query_arg(
				[
					'page' => $this->page_slug,
					'tab'  => 'authentication',
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Get the current active tab.
	 *
	 * @return string The current tab slug.
	 * @since 1.0.0
	 */
	private function get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation doesn't require nonce.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'abilities';

		$tabs = $this->get_tabs();

		// Ensure the tab exists, fallback to first tab.
		if ( ! array_key_exists( $tab, $tabs ) ) {
			$tab = array_key_first( $tabs );
		}

		return $tab;
	}

	/**
	 * Render the tab navigation.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_tabs(): void {
		$current_tab = $this->get_current_tab();
		$tabs        = $this->get_tabs();
		?>
		<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Settings tabs', 'extended-abilities' ); ?>">
			<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . $this->page_slug . '&tab=' . $tab_slug ) ); ?>"
					class="nav-tab <?php echo $current_tab === $tab_slug ? 'nav-tab-active' : ''; ?>"
					<?php echo $current_tab === $tab_slug ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $tab_label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Add settings page to WordPress admin menu.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'Extended Abilities', 'extended-abilities' ),
			__( 'Extended Abilities', 'extended-abilities' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_settings(): void {
		// Register the main options.
		register_setting(
			$this->option_group,
			$this->option_name,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Get all abilities grouped by category.
	 *
	 * @return array Grouped abilities.
	 * @since 1.0.0
	 */
	private function get_grouped_abilities(): array {
		$wordpress_abilities   = apply_filters( 'extended_abilities_wordpress_abilities', [] );
		$woocommerce_abilities = apply_filters( 'extended_abilities_woocommerce_abilities', [] );
		$plugin_abilities      = apply_filters( 'extended_abilities_plugin_abilities', [] );

		$grouped = [];

		if ( ! empty( $wordpress_abilities ) ) {
			$grouped['wordpress'] = [
				'title'     => __( 'WordPress Abilities', 'extended-abilities' ),
				'abilities' => $this->organize_abilities_by_group( $wordpress_abilities ),
			];
		}

		if ( ! empty( $woocommerce_abilities ) ) {
			$grouped['woocommerce'] = [
				'title'     => __( 'WooCommerce Abilities', 'extended-abilities' ),
				'abilities' => $this->organize_abilities_by_group( $woocommerce_abilities ),
			];
		}

		if ( ! empty( $plugin_abilities ) ) {
			$grouped['plugins'] = [
				'title'     => __( 'Plugin Integrations', 'extended-abilities' ),
				'abilities' => $this->organize_abilities_by_group( $plugin_abilities ),
			];
		}

		return $grouped;
	}

	/**
	 * Organize abilities by their group property.
	 *
	 * @param array $abilities Flat array of abilities.
	 *
	 * @return array Organized abilities with groups.
	 * @since 1.0.0
	 */
	private function organize_abilities_by_group( array $abilities ): array {
		$organized = [
			'ungrouped' => [],
			'groups'    => [],
		];

		foreach ( $abilities as $ability_id => $ability ) {
			$group = $ability['group'] ?? '';

			if ( empty( $group ) ) {
				// No group - add to ungrouped list.
				$organized['ungrouped'][ $ability_id ] = $ability;
			} else {
				// Has a group - add to that group.
				if ( ! isset( $organized['groups'][ $group ] ) ) {
					$organized['groups'][ $group ] = [
						'label'     => $this->get_group_label( $group ),
						'abilities' => [],
					];
				}
				$organized['groups'][ $group ]['abilities'][ $ability_id ] = $ability;
			}
		}

		return $organized;
	}

	/**
	 * Get human-readable label for a group.
	 *
	 * @param string $group Group identifier.
	 *
	 * @return string Group label.
	 * @since 1.0.0
	 */
	private function get_group_label( string $group ): string {
		$labels = [
			'posts'    => __( 'Posts', 'extended-abilities' ),
			'pages'    => __( 'Pages', 'extended-abilities' ),
			'media'    => __( 'Media', 'extended-abilities' ),
			'users'    => __( 'Users', 'extended-abilities' ),
			'comments' => __( 'Comments', 'extended-abilities' ),
			'plugins'  => __( 'Plugins', 'extended-abilities' ),
			'themes'   => __( 'Themes', 'extended-abilities' ),
		];

		/**
		 * Filter group labels.
		 *
		 * @param array $labels Group labels.
		 *
		 * @since 1.0.0
		 */
		$labels = apply_filters( 'extended_abilities_group_labels', $labels );

		return $labels[ $group ] ?? ucfirst( $group );
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_settings_page(): void {
		// Check user capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'extended-abilities' ) );
		}

		$current_tab = $this->get_current_tab();
		?>
		<div class="wrap extended-abilities-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Beta Version:', 'extended-abilities' ); ?></strong>
					<?php esc_html_e( 'This plugin is currently in beta and is intended for testing purposes only. Please use with caution and do not use on production sites.', 'extended-abilities' ); ?>
				</p>
			</div>

			<?php settings_errors(); ?>

			<?php $this->render_tabs(); ?>

			<div class="extended-abilities-tab-content">
				<?php
				switch ( $current_tab ) {
					case 'authentication':
						$this->render_authentication_tab();
						break;
					case 'abilities':
					default:
						$this->render_abilities_tab();
						break;
				}
				?>
			</div>

			<div class="extended-abilities-footer">
				<p>
					<?php
					printf(
					/* translators: %s: plugin documentation URL */
						esc_html__( 'Need help? Check out the %s.', 'extended-abilities' ),
						'<a href="https://github.com/yourmark/extended-abilities" target="_blank">' . esc_html__( 'documentation', 'extended-abilities' ) . '</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Abilities tab content.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_abilities_tab(): void {
		// Get all abilities grouped by category.
		$grouped_abilities = $this->get_grouped_abilities();
		$options           = get_option( $this->option_name, [] );
		?>
		<form method="post" action="options.php" aria-label="<?php esc_attr_e( 'Extended Abilities Settings', 'extended-abilities' ); ?>">
			<?php settings_fields( $this->option_group ); ?>

			<p class="description extended-abilities-description">
				<?php esc_html_e( 'Configure which abilities are available to AI assistants. Enable only the abilities you trust AI assistants to use on your site.', 'extended-abilities' ); ?>
			</p>

			<?php foreach ( $grouped_abilities as $category => $data ) : ?>
				<section class="ability-group" aria-labelledby="<?php echo esc_attr( 'group-title-' . $category ); ?>">
					<div class="ability-group-header">
						<div class="ability-group-title-wrapper">
							<button
									type="button"
									class="ability-group-collapse-toggle"
									aria-expanded="true"
									aria-controls="<?php echo esc_attr( 'group-items-' . $category ); ?>"
							>
								<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
							</button>
							<h2 class="ability-group-title" id="<?php echo esc_attr( 'group-title-' . $category ); ?>">
								<?php echo esc_html( $data['title'] ); ?>
							</h2>
						</div>
						<div class="ability-group-toggle-all">
							<?php $toggle_all_id = 'toggle-all-' . esc_attr( $category ); ?>
							<label class="extended-abilities-toggle" for="<?php echo esc_attr( $toggle_all_id ); ?>">
								<input
										type="checkbox"
										id="<?php echo esc_attr( $toggle_all_id ); ?>"
										class="toggle-all-abilities"
										data-group="<?php echo esc_attr( $category ); ?>"
										<?php /* translators: %s: ability group name */ ?>
										aria-label="<?php echo esc_attr( sprintf( __( 'Enable all %s abilities', 'extended-abilities' ), $data['title'] ) ); ?>"
								/>
								<span class="extended-abilities-toggle-slider" aria-hidden="true"></span>
							</label>
							<label for="<?php echo esc_attr( $toggle_all_id ); ?>">
								<?php esc_html_e( 'Enable All', 'extended-abilities' ); ?>
							</label>
						</div>
					</div>

					<div class="ability-group-items" id="<?php echo esc_attr( 'group-items-' . $category ); ?>" role="group" aria-labelledby="<?php echo esc_attr( 'group-title-' . $category ); ?>">
						<?php
						// Render ungrouped abilities first.
						if ( ! empty( $data['abilities']['ungrouped'] ) ) {
							foreach ( $data['abilities']['ungrouped'] as $ability_id => $ability ) {
								$this->render_ability_item( $ability_id, $ability, $category, $options );
							}
						}

						// Render grouped abilities.
						if ( ! empty( $data['abilities']['groups'] ) ) {
							foreach ( $data['abilities']['groups'] as $group_id => $group_data ) {
								$this->render_ability_subgroup( $group_id, $group_data, $category, $options );
							}
						}
						?>
					</div>
				</section>
			<?php endforeach; ?>

			<?php if ( empty( $grouped_abilities ) ) : ?>
				<div class="notice notice-info">
					<p>
						<?php esc_html_e( 'No abilities are currently registered. Abilities will appear here once they are registered by this plugin or other installed plugins.', 'extended-abilities' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="submit">
				<?php submit_button( null, 'primary', 'submit', false ); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Render the Authentication tab content.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_authentication_tab(): void {
		// Check if viewing sessions for a specific user.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking for view action.
		$view_sessions = isset( $_GET['action'] ) && 'view_user_sessions' === $_GET['action'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just getting user ID for display.
		$view_user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		if ( $view_sessions && $view_user_id ) {
			$this->render_user_sessions_view( $view_user_id );
			return;
		}

		$external_url  = get_option( 'ea_external_url', '' );
		$mcp_endpoint  = McpServer::get_endpoint_url( $external_url );
		$allowed_users = get_option( 'ea_mcp_allowed_users', [] );

		// Get all users for the dropdown.
		$all_users = get_users(
			[
				'orderby' => 'display_name',
				'order'   => 'ASC',
			]
		);
		?>
		<div class="extended-abilities-auth-tab">
			<p class="description extended-abilities-description">
				<?php esc_html_e( 'Control which users can access the MCP server. Users you add here can connect AI tools using just the MCP URL below.', 'extended-abilities' ); ?>
			</p>

			<div class="ea-oauth-info-box">
				<h3><?php esc_html_e( 'MCP Server URL', 'extended-abilities' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Give this URL to allowed users. They enter it in their AI tool (Claude Desktop, etc.) and authorize with their WordPress login.', 'extended-abilities' ); ?></p>
				<p><code class="ea-copy-text"><?php echo esc_html( $mcp_endpoint ); ?></code></p>
			</div>

			<div class="ea-oauth-info-box">
				<h3><?php esc_html_e( 'External URL (for Tunnels/Proxies)', 'extended-abilities' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'If your site is behind a tunnel (Cloudflare, ngrok) or reverse proxy, enter the public URL here.', 'extended-abilities' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ea-external-url-form">
					<?php wp_nonce_field( 'ea_save_external_url', 'ea_external_url_nonce' ); ?>
					<input type="hidden" name="action" value="ea_save_external_url" />
					<input
						type="url"
						name="ea_external_url"
						id="ea-external-url"
						value="<?php echo esc_attr( $external_url ); ?>"
						placeholder="<?php esc_attr_e( 'https://your-tunnel-url.trycloudflare.com', 'extended-abilities' ); ?>"
						class="regular-text"
					/>
					<button type="submit" class="button"><?php esc_html_e( 'Save', 'extended-abilities' ); ?></button>
					<?php if ( ! empty( $external_url ) ) : ?>
						<button type="submit" name="ea_clear_url" value="1" class="button"><?php esc_html_e( 'Clear', 'extended-abilities' ); ?></button>
					<?php endif; ?>
				</form>
			</div>

			<div class="ea-oauth-info-box">
				<h3><?php esc_html_e( 'Allowed Users', 'extended-abilities' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Select users who can connect AI tools to your site via MCP.', 'extended-abilities' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ea-add-user-form">
					<?php wp_nonce_field( 'ea_add_allowed_user', 'ea_add_user_nonce' ); ?>
					<input type="hidden" name="action" value="ea_add_allowed_user" />
					<select name="ea_user_id" id="ea-add-user-select" class="regular-text">
						<option value=""><?php esc_html_e( '— Select User to Add —', 'extended-abilities' ); ?></option>
						<?php foreach ( $all_users as $user ) : ?>
							<?php if ( ! in_array( $user->ID, $allowed_users, true ) ) : ?>
								<option value="<?php echo esc_attr( $user->ID ); ?>">
									<?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
								</option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Add User', 'extended-abilities' ); ?></button>
				</form>

				<?php if ( empty( $allowed_users ) ) : ?>
					<p><em><?php esc_html_e( 'No users have MCP access yet. Add users above.', 'extended-abilities' ); ?></em></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'User', 'extended-abilities' ); ?></th>
								<th><?php esc_html_e( 'Active Sessions', 'extended-abilities' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'extended-abilities' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $allowed_users as $user_id ) : ?>
								<?php
								$user          = get_user_by( 'id', $user_id );
								$session_count = $this->get_user_session_count( $user_id );

								if ( ! $user ) {
									continue;
								}
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $user->display_name ); ?></strong>
										<br><small><?php echo esc_html( $user->user_email ); ?></small>
									</td>
									<td>
										<?php if ( $session_count > 0 ) : ?>
											<?php
											$sessions_url = add_query_arg(
												[
													'page' => $this->page_slug,
													'tab'  => 'authentication',
													'action' => 'view_user_sessions',
													'user_id' => $user_id,
												],
												admin_url( 'options-general.php' )
											);
											?>
											<a href="<?php echo esc_url( $sessions_url ); ?>">
												<?php
												printf(
													/* translators: %d: number of sessions */
													esc_html( _n( '%d active session', '%d active sessions', $session_count, 'extended-abilities' ) ),
													esc_html( $session_count )
												);
												?>
											</a>
										<?php else : ?>
											<span class="ea-no-sessions"><?php esc_html_e( 'No active sessions', 'extended-abilities' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php
										$remove_url = wp_nonce_url(
											add_query_arg(
												[
													'page' => $this->page_slug,
													'tab'  => 'authentication',
													'action' => 'remove_allowed_user',
													'user_id' => $user_id,
												],
												admin_url( 'options-general.php' )
											),
											'remove_user_' . $user_id
										);
										?>
										<a href="<?php echo esc_url( $remove_url ); ?>"
											class="button button-small"
											onclick="return confirm('<?php echo esc_js( __( 'Remove this user\'s MCP access? All their sessions will be revoked.', 'extended-abilities' ) ); ?>');">
											<?php esc_html_e( 'Remove Access', 'extended-abilities' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php
			/**
			 * Fires after the authentication tab content.
			 *
			 * @since 1.0.0
			 */
			do_action( 'extended_abilities_authentication_tab_content' );
			?>
		</div>
		<?php
	}

	/**
	 * Get the number of active sessions for a user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return int The number of active sessions.
	 * @since 1.0.0
	 */
	private function get_user_session_count( int $user_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'ea_oauth_access_tokens';

		// Count distinct clients with non-revoked tokens (sessions persist via refresh tokens).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT client_id) FROM {$table} WHERE user_id = %d AND revoked = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			)
		);
	}

	/**
	 * Render sessions view for a specific user.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_user_sessions_view( int $user_id ): void {
		global $wpdb;

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'User not found.', 'extended-abilities' ) . '</p></div>';
			return;
		}

		$tokens_table = $wpdb->prefix . 'ea_oauth_access_tokens';

		$clients_table = $wpdb->prefix . 'ea_oauth_clients';

		// Get active sessions grouped by client, with first connection time.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					t.client_id,
					MAX(t.id) as id,
					MAX(t.token_id) as token_id,
					COALESCE(c.name, 'Unknown') as client_name,
					MIN(t.created_at) as first_connected
				FROM {$tokens_table} t
				LEFT JOIN {$clients_table} c ON t.client_id = c.client_id
				WHERE t.user_id = %d AND t.revoked = 0
				GROUP BY t.client_id
				ORDER BY first_connected DESC",
				$user_id
			)
		);
		// phpcs:enable

		$back_url = add_query_arg(
			[
				'page' => $this->page_slug,
				'tab'  => 'authentication',
			],
			admin_url( 'options-general.php' )
		);
		?>
		<div class="extended-abilities-auth-tab">
			<p>
				<a href="<?php echo esc_url( $back_url ); ?>" class="button">
					&larr; <?php esc_html_e( 'Back to Allowed Users', 'extended-abilities' ); ?>
				</a>
			</p>

			<div class="ea-oauth-info-box">
				<h3><?php esc_html_e( 'User Details', 'extended-abilities' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Name', 'extended-abilities' ); ?></th>
						<td><strong><?php echo esc_html( $user->display_name ); ?></strong></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Email', 'extended-abilities' ); ?></th>
						<td><?php echo esc_html( $user->user_email ); ?></td>
					</tr>
				</table>
			</div>

			<h3><?php esc_html_e( 'Active Sessions', 'extended-abilities' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Each session represents an AI tool that has been authorized. Revoking a session will disconnect that tool.', 'extended-abilities' ); ?>
			</p>

			<?php if ( empty( $sessions ) ) : ?>
				<p><em><?php esc_html_e( 'No active sessions. The user has not authorized any tools yet.', 'extended-abilities' ); ?></em></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'App', 'extended-abilities' ); ?></th>
							<th><?php esc_html_e( 'Session ID', 'extended-abilities' ); ?></th>
							<th><?php esc_html_e( 'Connected', 'extended-abilities' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'extended-abilities' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sessions as $session ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $session->client_name ?? __( 'Unknown', 'extended-abilities' ) ); ?></strong>
								</td>
								<td><code><?php echo esc_html( substr( $session->token_id, 0, 16 ) . '...' ); ?></code></td>
								<td>
									<?php
									$first_connected = $session->first_connected ?? $session->created_at;
									echo esc_html(
										sprintf(
											/* translators: %s: human-readable time difference */
											__( '%s ago', 'extended-abilities' ),
											human_time_diff( strtotime( $first_connected ), time() )
										)
									);
									?>
								</td>
								<td>
									<?php
									$revoke_url = wp_nonce_url(
										add_query_arg(
											[
												'page'     => $this->page_slug,
												'tab'      => 'authentication',
												'action'   => 'revoke_user_session',
												'token_id' => $session->id,
												'user_id'  => $user_id,
											],
											admin_url( 'options-general.php' )
										),
										'revoke_session_' . $session->id
									);
									?>
									<a href="<?php echo esc_url( $revoke_url ); ?>"
										class="button button-small"
										onclick="return confirm('<?php echo esc_js( __( 'Revoke this session?', 'extended-abilities' ) ); ?>');">
										<?php esc_html_e( 'Revoke', 'extended-abilities' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php
				$revoke_all_url = wp_nonce_url(
					add_query_arg(
						[
							'page'    => $this->page_slug,
							'tab'     => 'authentication',
							'action'  => 'revoke_all_user_sessions',
							'user_id' => $user_id,
						],
						admin_url( 'options-general.php' )
					),
					'revoke_all_sessions_' . $user_id
				);
				?>
				<p style="margin-top: 15px;">
					<a href="<?php echo esc_url( $revoke_all_url ); ?>"
						class="button"
						onclick="return confirm('<?php echo esc_js( __( 'Revoke ALL sessions for this user?', 'extended-abilities' ) ); ?>');">
						<?php esc_html_e( 'Revoke All Sessions', 'extended-abilities' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle OAuth admin actions.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_oauth_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below.
		if ( ! isset( $_GET['action'] ) || ! isset( $_GET['page'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below.
		if ( 'extended-abilities' !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce is verified below.
		$action = sanitize_key( $_GET['action'] );

		switch ( $action ) {
			case 'remove_allowed_user':
				$this->handle_remove_allowed_user();
				break;
			case 'revoke_user_session':
				$this->handle_revoke_user_session();
				break;
			case 'revoke_all_user_sessions':
				$this->handle_revoke_all_user_sessions();
				break;
		}
	}

	/**
	 * Handle adding a user to the allowed users list.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_add_allowed_user(): void {
		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ea_add_user_nonce'] ?? '' ) ), 'ea_add_allowed_user' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'extended-abilities' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage MCP access.', 'extended-abilities' ) );
		}

		$user_id = isset( $_POST['ea_user_id'] ) ? absint( $_POST['ea_user_id'] ) : 0;

		if ( ! $user_id ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'page' => $this->page_slug,
						'tab'  => 'authentication',
					],
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		// Verify user exists.
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			wp_die( esc_html__( 'Invalid user selected.', 'extended-abilities' ) );
		}

		// Get current allowed users and add the new one.
		$allowed_users = get_option( 'ea_mcp_allowed_users', [] );

		if ( ! in_array( $user_id, $allowed_users, true ) ) {
			$allowed_users[] = $user_id;
			update_option( 'ea_mcp_allowed_users', $allowed_users );
		}

		// Redirect back.
		wp_safe_redirect(
			add_query_arg(
				[
					'page' => $this->page_slug,
					'tab'  => 'authentication',
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle removing a user from the allowed users list.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_remove_allowed_user(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		if ( ! $user_id ) {
			return;
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'remove_user_' . $user_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'extended-abilities' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage MCP access.', 'extended-abilities' ) );
		}

		// Remove user from allowed list.
		$allowed_users = get_option( 'ea_mcp_allowed_users', [] );
		$allowed_users = array_filter( $allowed_users, fn( $id ) => $id !== $user_id );
		update_option( 'ea_mcp_allowed_users', array_values( $allowed_users ) );

		// Revoke all their sessions.
		self::revoke_user_tokens( $user_id );

		add_settings_error(
			'extended_abilities',
			'user_removed',
			__( 'User removed and all their sessions revoked.', 'extended-abilities' ),
			'success'
		);

		// Redirect back.
		wp_safe_redirect(
			add_query_arg(
				[
					'page'             => $this->page_slug,
					'tab'              => 'authentication',
					'settings-updated' => 'true',
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle revoking a single user session.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_revoke_user_session(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$token_id = isset( $_GET['token_id'] ) ? absint( $_GET['token_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		if ( ! $token_id || ! $user_id ) {
			return;
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'revoke_session_' . $token_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'extended-abilities' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to revoke sessions.', 'extended-abilities' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ea_oauth_access_tokens';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			[ 'revoked' => 1 ],
			[ 'id' => $token_id ],
			[ '%d' ],
			[ '%d' ]
		);

		add_settings_error(
			'extended_abilities',
			'session_revoked',
			__( 'Session revoked successfully.', 'extended-abilities' ),
			'success'
		);

		// Redirect back to user sessions view.
		wp_safe_redirect(
			add_query_arg(
				[
					'page'             => $this->page_slug,
					'tab'              => 'authentication',
					'action'           => 'view_user_sessions',
					'user_id'          => $user_id,
					'settings-updated' => 'true',
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle revoking all sessions for a user.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_revoke_all_user_sessions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		if ( ! $user_id ) {
			return;
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'revoke_all_sessions_' . $user_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'extended-abilities' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to revoke sessions.', 'extended-abilities' ) );
		}

		self::revoke_user_tokens( $user_id );

		add_settings_error(
			'extended_abilities',
			'all_sessions_revoked',
			__( 'All sessions revoked successfully.', 'extended-abilities' ),
			'success'
		);

		// Redirect back to user sessions view.
		wp_safe_redirect(
			add_query_arg(
				[
					'page'             => $this->page_slug,
					'tab'              => 'authentication',
					'action'           => 'view_user_sessions',
					'user_id'          => $user_id,
					'settings-updated' => 'true',
				],
				admin_url( 'options-general.php' )
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

		$access_tokens_table  = $wpdb->prefix . 'ea_oauth_access_tokens';
		$refresh_tokens_table = $wpdb->prefix . 'ea_oauth_refresh_tokens';

		// Get all access token IDs for this user.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$token_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT token_id FROM {$access_tokens_table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			)
		);

		// Revoke access tokens.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$access_tokens_table,
			[ 'revoked' => 1 ],
			[ 'user_id' => $user_id ],
			[ '%d' ],
			[ '%d' ]
		);

		// Revoke associated refresh tokens.
		if ( ! empty( $token_ids ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $token_ids ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$refresh_tokens_table} SET revoked = 1 WHERE access_token_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...$token_ids
				)
			);
		}
	}

	/**
	 * Render an ability subgroup with toggle all functionality.
	 *
	 * @param string $group_id Group identifier.
	 * @param array  $group_data Group data including label and abilities.
	 * @param string $category Parent category.
	 * @param array  $options Current options.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_ability_subgroup( string $group_id, array $group_data, string $category, array $options ): void {
		$subgroup_toggle_id = 'toggle-subgroup-' . esc_attr( $category . '-' . $group_id );
		$subgroup_class     = 'subgroup-' . esc_attr( $category . '-' . $group_id );
		$subgroup_items_id  = 'subgroup-items-' . esc_attr( $category . '-' . $group_id );
		?>
		<div class="ability-subgroup">
			<div class="ability-subgroup-header">
				<div class="ability-subgroup-title-wrapper">
					<button
							type="button"
							class="ability-subgroup-collapse-toggle"
							aria-expanded="true"
							aria-controls="<?php echo esc_attr( $subgroup_items_id ); ?>"
					>
						<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					</button>
					<h3 class="ability-subgroup-title"><?php echo esc_html( $group_data['label'] ); ?></h3>
				</div>
				<div class="ability-subgroup-toggle-all">
					<label class="extended-abilities-toggle" for="<?php echo esc_attr( $subgroup_toggle_id ); ?>">
						<input
								type="checkbox"
								id="<?php echo esc_attr( $subgroup_toggle_id ); ?>"
								class="toggle-subgroup-abilities"
								data-subgroup="<?php echo esc_attr( $subgroup_class ); ?>"
								<?php /* translators: %s: ability subgroup name */ ?>
								aria-label="<?php echo esc_attr( sprintf( __( 'Enable all %s abilities', 'extended-abilities' ), $group_data['label'] ) ); ?>"
						/>
						<span class="extended-abilities-toggle-slider" aria-hidden="true"></span>
					</label>
					<label for="<?php echo esc_attr( $subgroup_toggle_id ); ?>">
						<?php
						printf(
							/* translators: %s: group name */
							esc_html__( 'Enable All %s', 'extended-abilities' ),
							esc_html( $group_data['label'] )
						);
						?>
					</label>
				</div>
			</div>
			<div class="ability-subgroup-items" id="<?php echo esc_attr( $subgroup_items_id ); ?>">
				<?php
				foreach ( $group_data['abilities'] as $ability_id => $ability ) {
					$this->render_ability_item( $ability_id, $ability, $category, $options, $subgroup_class );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single ability item.
	 *
	 * @param string $ability_id Ability ID.
	 * @param array  $ability Ability data.
	 * @param string $category Parent category.
	 * @param array  $options Current options.
	 * @param string $subgroup_class Optional subgroup class for grouped abilities.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_ability_item( string $ability_id, array $ability, string $category, array $options, string $subgroup_class = '' ): void {
		$field_name      = $this->option_name . '[' . $ability_id . ']';
		$field_id        = 'ability-' . sanitize_key( $ability_id );
		$checked         = isset( $options[ $ability_id ] ) && $options[ $ability_id ];
		$has_description = ! empty( $ability['description'] );
		$checkbox_class  = 'ability-checkbox';

		if ( ! empty( $subgroup_class ) ) {
			$checkbox_class .= ' ability-checkbox-subgroup ' . esc_attr( $subgroup_class );
		}
		?>
		<div class="ability-item">
			<div class="ability-item-toggle">
				<label class="extended-abilities-toggle" for="<?php echo esc_attr( $field_id ); ?>">
					<input
							type="checkbox"
							id="<?php echo esc_attr( $field_id ); ?>"
							name="<?php echo esc_attr( $field_name ); ?>"
							value="1"
							class="<?php echo esc_attr( $checkbox_class ); ?>"
							data-group="<?php echo esc_attr( $category ); ?>"
						<?php if ( $has_description ) : ?>
							aria-describedby="<?php echo esc_attr( $field_id . '-description' ); ?>"
						<?php endif; ?>
						<?php checked( $checked ); ?>
					/>
					<span class="extended-abilities-toggle-slider" aria-hidden="true"></span>
				</label>
			</div>
			<div class="ability-item-content">
				<label class="ability-item-label" for="<?php echo esc_attr( $field_id ); ?>">
					<?php echo esc_html( $ability['label'] ); ?>
				</label>
				<?php if ( $has_description ) : ?>
					<p class="ability-item-description" id="<?php echo esc_attr( $field_id . '-description' ); ?>">
						<?php echo esc_html( $ability['description'] ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Input settings.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function sanitize_settings( $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$sanitized = [];

		foreach ( $input as $key => $value ) {
			// Only allow specific keys (ability IDs).
			if ( $this->is_valid_ability_id( $key ) ) {
				$sanitized[ $key ] = (bool) $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Check if ability ID is valid.
	 *
	 * @param string $ability_id Ability ID.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_valid_ability_id( string $ability_id ): bool {
		// Get all registered abilities from filters.
		$wordpress_abilities   = apply_filters( 'extended_abilities_wordpress_abilities', [] );
		$woocommerce_abilities = apply_filters( 'extended_abilities_woocommerce_abilities', [] );
		$plugin_abilities      = apply_filters( 'extended_abilities_plugin_abilities', [] );

		$all_abilities = array_merge(
			$wordpress_abilities,
			$woocommerce_abilities,
			$plugin_abilities
		);

		return array_key_exists( $ability_id, $all_abilities );
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
		// Only load on our settings page.
		if ( 'settings_page_' . $this->page_slug !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'extended-abilities-admin',
			EXTENDED_ABILITIES_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			EXTENDED_ABILITIES_VERSION
		);

		wp_enqueue_script(
			'extended-abilities-admin',
			EXTENDED_ABILITIES_PLUGIN_URL . 'assets/js/admin-settings.js',
			[ 'jquery' ],
			EXTENDED_ABILITIES_VERSION,
			true
		);

		// Localize script with AJAX data.
		wp_localize_script(
			'extended-abilities-admin',
			'extendedAbilitiesAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ea_oauth_nonce' ),
				'i18n'    => [
					'copied'      => __( 'Copied!', 'extended-abilities' ),
					'copyFailed'  => __( 'Copy failed', 'extended-abilities' ),
					'createError' => __( 'Error creating client. Please try again.', 'extended-abilities' ),
				],
			]
		);
	}

	/**
	 * Display admin notices.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function display_admin_notices(): void {
		// Only show on our settings page.
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_' . $this->page_slug !== $screen->id ) {
			return;
		}

		/**
		 * Allow other components to add notices.
		 *
		 * @since 1.0.0
		 */
		do_action( 'extended_abilities_admin_notices' );
	}
}
