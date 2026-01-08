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
 * Manages the plugin settings page.
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
	private string $parent_slug = 'extended-abilities';

	/**
	 * Settings page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $page_slug = 'ea-settings';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'handle_oauth_actions' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'display_admin_notices' ] );
		add_action( 'admin_post_ea_save_external_url', [ $this, 'handle_save_external_url' ] );
		add_action( 'admin_post_ea_add_allowed_user', [ $this, 'handle_add_allowed_user' ] );
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
			__( 'Settings', 'extended-abilities' ),
			__( 'Settings', 'extended-abilities' ),
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
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'extended-abilities' ) );
		}

		// Check if viewing sessions for a specific user.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking for view action.
		$view_sessions = isset( $_GET['action'] ) && 'view_user_sessions' === $_GET['action'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just getting user ID for display.
		$view_user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		if ( $view_sessions && $view_user_id ) {
			$this->render_user_sessions_view( $view_user_id );
			return;
		}
		?>
		<div class="wrap extended-abilities-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors(); ?>

			<?php $this->render_mcp_server_section(); ?>

			<?php $this->render_authentication_section(); ?>
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
		$show_developer_settings = apply_filters( 'extended_abilities/settings/developer_mode', false );

		// Only use external URL if developer settings are enabled.
		$external_url = $show_developer_settings ? get_option( 'ea_external_url', '' ) : '';
		$mcp_endpoint = McpServer::get_endpoint_url( $external_url );
		?>
		<div class="ea-settings-section">
			<h2><?php esc_html_e( 'MCP Server', 'extended-abilities' ); ?></h2>

			<div class="ea-oauth-info-box">
				<h3><?php esc_html_e( 'Connection URL', 'extended-abilities' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Use this URL to connect AI tools (Claude Desktop, ChatGPT, etc.) to your site. Users will authenticate with their WordPress credentials.', 'extended-abilities' ); ?>
				</p>
				<div class="ea-copy-field">
					<code class="ea-copy-text" id="mcp-endpoint-url"><?php echo esc_html( $mcp_endpoint ); ?></code>
					<button type="button" class="button ea-copy-button" data-copy-target="mcp-endpoint-url">
						<?php esc_html_e( 'Copy', 'extended-abilities' ); ?>
					</button>
				</div>
			</div>

			<?php

			if ( $show_developer_settings ) :
				?>
				<div class="ea-oauth-info-box">
					<h3><?php esc_html_e( 'External URL (Developer)', 'extended-abilities' ); ?></h3>
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
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Authentication section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_authentication_section(): void {
		$allowed_users = get_option( 'ea_mcp_allowed_users', [] );

		// Get all users for the dropdown.
		$all_users = get_users(
			[
				'orderby' => 'display_name',
				'order'   => 'ASC',
			]
		);
		?>
		<div class="ea-settings-section">
			<h2><?php esc_html_e( 'Authentication', 'extended-abilities' ); ?></h2>

			<div class="ea-oauth-info-box">
				<h3><?php esc_html_e( 'Allowed Users', 'extended-abilities' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Select users who can connect AI tools to your site via MCP. Only these users will be able to authorize AI assistants.', 'extended-abilities' ); ?>
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
													'action' => 'view_user_sessions',
													'user_id' => $user_id,
												],
												admin_url( 'admin.php' )
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
													'action' => 'remove_allowed_user',
													'user_id' => $user_id,
												],
												admin_url( 'admin.php' )
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
		</div>
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
				[ 'page' => $this->page_slug ],
				admin_url( 'admin.php' )
			)
		);
		exit;
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
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'User not found.', 'extended-abilities' ) . '</p></div></div>';
			return;
		}

		$tokens_table  = $wpdb->prefix . 'ea_oauth_access_tokens';
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
			[ 'page' => $this->page_slug ],
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap extended-abilities-settings">
			<h1><?php esc_html_e( 'User Sessions', 'extended-abilities' ); ?></h1>

			<?php settings_errors(); ?>

			<p>
				<a href="<?php echo esc_url( $back_url ); ?>" class="button">
					&larr; <?php esc_html_e( 'Back to Settings', 'extended-abilities' ); ?>
				</a>
			</p>

			<div class="ea-settings-section">
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
													'page' => $this->page_slug,
													'action' => 'revoke_user_session',
													'token_id' => $session->id,
													'user_id' => $user_id,
												],
												admin_url( 'admin.php' )
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
								'action'  => 'revoke_all_user_sessions',
								'user_id' => $user_id,
							],
							admin_url( 'admin.php' )
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
		if ( $this->page_slug !== $_GET['page'] ) {
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
					[ 'page' => $this->page_slug ],
					admin_url( 'admin.php' )
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
				[ 'page' => $this->page_slug ],
				admin_url( 'admin.php' )
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
					'settings-updated' => 'true',
				],
				admin_url( 'admin.php' )
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
					'action'           => 'view_user_sessions',
					'user_id'          => $user_id,
					'settings-updated' => 'true',
				],
				admin_url( 'admin.php' )
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
					'action'           => 'view_user_sessions',
					'user_id'          => $user_id,
					'settings-updated' => 'true',
				],
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
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'abilities_page_' . $this->page_slug !== $hook ) {
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

		wp_localize_script(
			'extended-abilities-admin',
			'extendedAbilitiesAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ea_oauth_nonce' ),
				'i18n'    => [
					'copied'     => __( 'Copied!', 'extended-abilities' ),
					'copyFailed' => __( 'Copy failed', 'extended-abilities' ),
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
		$screen = get_current_screen();
		if ( ! $screen || 'abilities_page_' . $this->page_slug !== $screen->id ) {
			return;
		}

		/**
		 * Allow other components to add notices.
		 *
		 * @since 1.0.0
		 */
		do_action( 'extended_abilities/admin/notices' );
	}
}
