<?php
/**
 * Connections Page
 *
 * Allows admins to manage who can connect (allowed users) and view active
 * AI assistant connections (OAuth sessions).
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

use Albert\Contracts\Interfaces\Hookable;
use Albert\MCP\Server as McpServer;
use Albert\OAuth\Database\Installer;

/**
 * Connections class
 *
 * Provides a page for admins to manage allowed users and AI assistant OAuth connections.
 *
 * @since 1.0.0
 */
class Connections implements Hookable {

	/**
	 * Parent menu slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $parent_slug = 'albert';

	/**
	 * Page slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $page_slug = 'albert-connections';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_albert_add_allowed_user', [ $this, 'handle_add_allowed_user' ] );
	}

	/**
	 * Add the connections page to admin menu.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			$this->parent_slug,
			__( 'Connections', 'albert' ),
			__( 'Connections', 'albert' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Handle user actions (GET-based).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		if ( ! isset( $_GET['action'] ) || ! isset( $_GET['page'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		if ( $this->page_slug !== $_GET['page'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$action = sanitize_key( $_GET['action'] );

		switch ( $action ) {
			case 'revoke':
				$this->handle_revoke_session();
				break;
			case 'revoke_all':
				$this->handle_revoke_all_sessions();
				break;
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
	 * Handle revoking a single session.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_revoke_session(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$token_id = isset( $_GET['token_id'] ) ? absint( $_GET['token_id'] ) : 0;

		if ( ! $token_id ) {
			return;
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'revoke_my_session_' . $token_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'albert' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'albert_oauth_access_tokens';

		// Admins can revoke any connection.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			[ 'revoked' => 1 ],
			[ 'id' => $token_id ],
			[ '%d' ],
			[ '%d' ]
		);

		add_settings_error(
			'albert_connections',
			'session_revoked',
			__( 'Session revoked successfully.', 'albert' ),
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
	 * Handle revoking all sessions.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_revoke_all_sessions(): void {
		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'revoke_all_my_sessions' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'albert' ) );
		}

		Settings::revoke_user_tokens( get_current_user_id() );

		add_settings_error(
			'albert_connections',
			'all_sessions_revoked',
			__( 'All sessions revoked successfully.', 'albert' ),
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
	 * Handle adding a user to the allowed users list.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_add_allowed_user(): void {
		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['albert_add_user_nonce'] ?? '' ) ), 'albert_add_allowed_user' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'albert' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage MCP access.', 'albert' ) );
		}

		$user_id = isset( $_POST['albert_user_id'] ) ? absint( $_POST['albert_user_id'] ) : 0;

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
			wp_die( esc_html__( 'Invalid user selected.', 'albert' ) );
		}

		// Get current allowed users and add the new one.
		$allowed_users = get_option( 'albert_allowed_users', [] );

		if ( ! in_array( $user_id, $allowed_users, true ) ) {
			$allowed_users[] = $user_id;
			update_option( 'albert_allowed_users', $allowed_users );
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
			wp_die( esc_html__( 'Security check failed.', 'albert' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage MCP access.', 'albert' ) );
		}

		// Remove user from allowed list.
		$allowed_users = get_option( 'albert_allowed_users', [] );
		$allowed_users = array_filter( $allowed_users, fn( $id ) => $id !== $user_id );
		update_option( 'albert_allowed_users', array_values( $allowed_users ) );

		// Revoke all their sessions.
		Settings::revoke_user_tokens( $user_id );

		add_settings_error(
			'albert_connections',
			'user_removed',
			__( 'User removed and all their sessions revoked.', 'albert' ),
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
			wp_die( esc_html__( 'Security check failed.', 'albert' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to revoke sessions.', 'albert' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'albert_oauth_access_tokens';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			[ 'revoked' => 1 ],
			[ 'id' => $token_id ],
			[ '%d' ],
			[ '%d' ]
		);

		add_settings_error(
			'albert_connections',
			'session_revoked',
			__( 'Session revoked successfully.', 'albert' ),
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
			wp_die( esc_html__( 'Security check failed.', 'albert' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to revoke sessions.', 'albert' ) );
		}

		Settings::revoke_user_tokens( $user_id );

		add_settings_error(
			'albert_connections',
			'all_sessions_revoked',
			__( 'All sessions revoked successfully.', 'albert' ),
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
	 * Render the page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert' ) );
		}

		// Check if viewing sessions for a specific user.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking for view action.
		$view_sessions = isset( $_GET['action'] ) && $_GET['action'] === 'view_user_sessions';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just getting user ID for display.
		$view_user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		if ( $view_sessions && $view_user_id ) {
			$this->render_user_sessions_view( $view_user_id );
			return;
		}

		$mcp_endpoint = McpServer::get_endpoint_url();
		?>
		<div class="wrap albert-settings">
			<h1><?php echo esc_html__( 'Connections', 'albert' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Manage who can connect AI assistants and view active connections.', 'albert' ); ?>
			</p>

			<?php settings_errors( 'albert_connections' ); ?>

			<!-- MCP Endpoint Bar -->
			<div class="albert-endpoint-bar">
				<div class="albert-endpoint-bar-header">
					<label class="albert-endpoint-bar-label" for="mcp-endpoint-url"><?php esc_html_e( 'MCP Endpoint', 'albert' ); ?></label>
					<span class="albert-endpoint-bar-helper"><?php esc_html_e( 'Add this URL to your AI assistant as an MCP connector.', 'albert' ); ?></span>
				</div>
				<div class="albert-endpoint-bar-field">
					<input
						type="text"
						id="mcp-endpoint-url"
						class="albert-endpoint-url"
						value="<?php echo esc_url( $mcp_endpoint ); ?>"
						readonly
					/>
					<button type="button" class="button button-secondary albert-copy-button" data-copy-target="mcp-endpoint-url">
						<?php esc_html_e( 'Copy', 'albert' ); ?>
					</button>
				</div>
			</div>

			<div class="albert-settings-grid">
				<?php $this->render_allowed_users_section(); ?>
				<?php $this->render_active_connections_section(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Allowed Users section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_allowed_users_section(): void {
		$allowed_users = get_option( 'albert_allowed_users', [] );

		// Get all users for the dropdown.
		$all_users = get_users(
			[
				'orderby' => 'display_name',
				'order'   => 'ASC',
			]
		);
		?>
		<section class="albert-settings-card">
			<div class="albert-settings-card-header">
				<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'Allowed Users', 'albert' ); ?></h2>
			</div>
			<div class="albert-settings-card-body">
				<div class="albert-field-group">
					<p class="albert-field-description">
						<?php esc_html_e( 'Select users who can connect AI tools to your site. Only these users can authorize AI assistants.', 'albert' ); ?>
					</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="albert-inline-form">
						<?php wp_nonce_field( 'albert_add_allowed_user', 'albert_add_user_nonce' ); ?>
						<input type="hidden" name="action" value="albert_add_allowed_user" />
						<label for="albert-add-user-select" class="screen-reader-text"><?php esc_html_e( 'Select user to add', 'albert' ); ?></label>
						<select name="albert_user_id" id="albert-add-user-select" class="albert-select-input">
							<option value=""><?php esc_html_e( '— Select User —', 'albert' ); ?></option>
							<?php foreach ( $all_users as $user ) { ?>
								<?php if ( ! in_array( $user->ID, $allowed_users, true ) ) { ?>
									<option value="<?php echo esc_attr( $user->ID ); ?>">
										<?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
									</option>
								<?php } ?>
							<?php } ?>
						</select>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Add User', 'albert' ); ?></button>
					</form>
				</div>

				<?php if ( empty( $allowed_users ) ) { ?>
					<div class="albert-empty-state">
						<span class="dashicons dashicons-groups" aria-hidden="true"></span>
						<p><?php esc_html_e( 'No users have access yet. Add users above to allow them to connect AI tools.', 'albert' ); ?></p>
					</div>
				<?php } else { ?>
					<div class="albert-users-list">
						<?php foreach ( $allowed_users as $user_id ) { ?>
							<?php
							$user          = get_user_by( 'id', $user_id );
							$session_count = $this->get_user_session_count( $user_id );

							if ( ! $user ) {
								continue;
							}

							$remove_url = wp_nonce_url(
								add_query_arg(
									[
										'page'    => $this->page_slug,
										'action'  => 'remove_allowed_user',
										'user_id' => $user_id,
									],
									admin_url( 'admin.php' )
								),
								'remove_user_' . $user_id
							);

							$sessions_url = add_query_arg(
								[
									'page'    => $this->page_slug,
									'action'  => 'view_user_sessions',
									'user_id' => $user_id,
								],
								admin_url( 'admin.php' )
							);
							?>
							<div class="albert-user-row">
								<div class="albert-user-info">
									<span class="albert-user-avatar"><?php echo get_avatar( $user_id, 32 ); ?></span>
									<div class="albert-user-details">
										<strong class="albert-user-name"><?php echo esc_html( $user->display_name ); ?></strong>
										<span class="albert-user-email"><?php echo esc_html( $user->user_email ); ?></span>
									</div>
								</div>
								<div class="albert-user-sessions">
									<?php if ( $session_count > 0 ) { ?>
										<a href="<?php echo esc_url( $sessions_url ); ?>" class="albert-sessions-link">
											<?php
											printf(
												/* translators: %d: number of sessions */
												esc_html( _n( '%d session', '%d sessions', $session_count, 'albert' ) ),
												(int) $session_count
											);
											?>
										</a>
									<?php } else { ?>
										<span class="albert-no-sessions"><?php esc_html_e( 'No sessions', 'albert' ); ?></span>
									<?php } ?>
								</div>
								<div class="albert-user-actions">
									<a href="<?php echo esc_url( $remove_url ); ?>"
										class="albert-remove-link"
										onclick="return confirm('<?php echo esc_js( __( 'Remove this user\'s access? All their sessions will be revoked.', 'albert' ) ); ?>');">
										<?php esc_html_e( 'Remove', 'albert' ); ?>
									</a>
								</div>
							</div>
						<?php } ?>
					</div>
				<?php } ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render the Active Connections section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_active_connections_section(): void {
		global $wpdb;

		$tables = Installer::get_table_names();

		// Get all active connections (all users) grouped by client.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					t.id,
					t.client_id,
					t.user_id,
					t.token_id,
					t.created_at,
					t.expires_at,
					COALESCE(c.name, 'Unknown Client') as client_name
				FROM %i t
				LEFT JOIN %i c ON t.client_id = c.client_id
				WHERE t.revoked = 0 AND t.expires_at > NOW()
				ORDER BY t.created_at DESC",
				$tables['access_tokens'],
				$tables['clients']
			)
		);

		?>
		<section class="albert-settings-card">
			<div class="albert-settings-card-header">
				<span class="dashicons dashicons-networking" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'Active Connections', 'albert' ); ?></h2>
			</div>
			<div class="albert-settings-card-body">
				<?php if ( empty( $sessions ) ) { ?>
					<div class="albert-empty-state">
						<span class="dashicons dashicons-networking" aria-hidden="true"></span>
						<p><?php esc_html_e( 'No active connections yet. Once an allowed user authorizes an AI assistant, connections will appear here.', 'albert' ); ?></p>
					</div>
				<?php } else { ?>
					<div class="albert-connections-grid">
						<?php foreach ( $sessions as $session ) { ?>
							<?php
							$app_name     = ! empty( $session->client_name ) ? $session->client_name : __( 'Unknown Client', 'albert' );
							$user         = get_userdata( $session->user_id );
							$connected_at = strtotime( $session->created_at );
							$expires_at   = strtotime( $session->expires_at );
							$is_expiring  = ( $expires_at - time() ) < DAY_IN_SECONDS;

							$revoke_url = wp_nonce_url(
								add_query_arg(
									[
										'page'     => $this->page_slug,
										'action'   => 'revoke',
										'token_id' => $session->id,
									],
									admin_url( 'admin.php' )
								),
								'revoke_my_session_' . $session->id
							);
							?>
							<div class="albert-card albert-connection-card">
								<div class="albert-connection-header">
									<div class="albert-connection-icon">
										<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
									</div>
									<div class="albert-connection-title">
										<h3><?php echo esc_html( $app_name ); ?></h3>
										<span class="albert-connection-session"><?php echo esc_html( substr( $session->token_id, 0, 12 ) . '...' ); ?></span>
									</div>
								</div>
								<div class="albert-connection-body">
									<div class="albert-connection-meta">
										<div class="albert-connection-meta-item">
											<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
											<div>
												<strong><?php esc_html_e( 'User:', 'albert' ); ?></strong>
												<span><?php echo $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown', 'albert' ); ?></span>
											</div>
										</div>
										<div class="albert-connection-meta-item">
											<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
											<div>
												<strong><?php esc_html_e( 'Connected:', 'albert' ); ?></strong>
												<span><?php echo esc_html( human_time_diff( $connected_at, time() ) . ' ' . __( 'ago', 'albert' ) ); ?></span>
											</div>
										</div>
										<div class="albert-connection-meta-item">
											<span class="dashicons dashicons-clock" aria-hidden="true"></span>
											<div>
												<strong><?php esc_html_e( 'Expires:', 'albert' ); ?></strong>
												<span class="<?php echo $is_expiring ? 'albert-expiring' : ''; ?>">
													<?php
													if ( $expires_at > time() ) {
														echo esc_html( human_time_diff( time(), $expires_at ) . ' ' . __( 'from now', 'albert' ) );
													} else {
														echo esc_html__( 'Expired', 'albert' );
													}
													?>
												</span>
											</div>
										</div>
									</div>
								</div>
								<div class="albert-connection-footer">
									<a href="<?php echo esc_url( $revoke_url ); ?>"
										class="button button-secondary button-small albert-disconnect-btn"
										onclick="return confirm('<?php echo esc_js( __( 'Disconnect this AI assistant?', 'albert' ) ); ?>');">
										<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
										<?php esc_html_e( 'Disconnect', 'albert' ); ?>
									</a>
								</div>
							</div>
						<?php } ?>
					</div>

					<?php
					$revoke_all_url = wp_nonce_url(
						add_query_arg(
							[
								'page'   => $this->page_slug,
								'action' => 'revoke_all',
							],
							admin_url( 'admin.php' )
						),
						'revoke_all_my_sessions'
					);
					?>
					<div class="albert-connections-actions">
						<a href="<?php echo esc_url( $revoke_all_url ); ?>"
							class="button albert-disconnect-all-btn"
							onclick="return confirm('<?php echo esc_js( __( 'Disconnect ALL AI assistants? This action cannot be undone.', 'albert' ) ); ?>');">
							<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
							<?php esc_html_e( 'Disconnect All', 'albert' ); ?>
						</a>
					</div>
				<?php } ?>
			</div>
		</section>
		<?php
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
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'User not found.', 'albert' ) . '</p></div></div>';
			return;
		}

		$tables = Installer::get_table_names();

		// Get active sessions grouped by client, with first connection time.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					t.client_id,
					MAX(t.id) as id,
					MAX(t.token_id) as token_id,
					COALESCE(c.name, 'Unknown') as client_name,
					MIN(t.created_at) as first_connected
				FROM %i t
				LEFT JOIN %i c ON t.client_id = c.client_id
				WHERE t.user_id = %d AND t.revoked = 0
				GROUP BY t.client_id
				ORDER BY first_connected DESC",
				$tables['access_tokens'],
				$tables['clients'],
				$user_id
			)
		);

		$back_url = add_query_arg(
			[ 'page' => $this->page_slug ],
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap albert-settings">
			<h1><?php esc_html_e( 'User Sessions', 'albert' ); ?></h1>

			<?php settings_errors( 'albert_connections' ); ?>

			<p>
				<a href="<?php echo esc_url( $back_url ); ?>" class="button">
					&larr; <?php esc_html_e( 'Back to Connections', 'albert' ); ?>
				</a>
			</p>

			<div class="albert-settings-section">
				<div class="albert-oauth-info-box">
					<h3><?php esc_html_e( 'User Details', 'albert' ); ?></h3>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Name', 'albert' ); ?></th>
							<td><strong><?php echo esc_html( $user->display_name ); ?></strong></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Email', 'albert' ); ?></th>
							<td><?php echo esc_html( $user->user_email ); ?></td>
						</tr>
					</table>
				</div>

				<h3><?php esc_html_e( 'Active Sessions', 'albert' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Each session represents an AI tool that has been authorized. Revoking a session will disconnect that tool.', 'albert' ); ?>
				</p>

				<?php if ( empty( $sessions ) ) { ?>
					<p><em><?php esc_html_e( 'No active sessions. The user has not authorized any tools yet.', 'albert' ); ?></em></p>
				<?php } else { ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'App', 'albert' ); ?></th>
								<th><?php esc_html_e( 'Session ID', 'albert' ); ?></th>
								<th><?php esc_html_e( 'Connected', 'albert' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'albert' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $sessions as $session ) { ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $session->client_name ?? __( 'Unknown', 'albert' ) ); ?></strong>
									</td>
									<td><code><?php echo esc_html( substr( $session->token_id, 0, 16 ) . '...' ); ?></code></td>
									<td>
										<?php
										$first_connected = $session->first_connected ?? $session->created_at;
										echo esc_html(
											sprintf(
												/* translators: %s: human-readable time difference */
												__( '%s ago', 'albert' ),
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
											onclick="return confirm('<?php echo esc_js( __( 'Revoke this session?', 'albert' ) ); ?>');">
											<?php esc_html_e( 'Revoke', 'albert' ); ?>
										</a>
									</td>
								</tr>
							<?php } ?>
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
							onclick="return confirm('<?php echo esc_js( __( 'Revoke ALL sessions for this user?', 'albert' ) ); ?>');">
							<?php esc_html_e( 'Revoke All Sessions', 'albert' ); ?>
						</a>
					</p>
				<?php } ?>
			</div>
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
		$tables = Installer::get_table_names();

		// Count distinct clients with non-revoked tokens (sessions persist via refresh tokens).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT client_id) FROM %i WHERE user_id = %d AND revoked = 0',
				$tables['access_tokens'],
				$user_id
			)
		);
	}

	/**
	 * Enqueue assets.
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
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'albert_oauth_nonce' ),
				'i18n'    => [
					'copied'     => __( 'Copied!', 'albert' ),
					'copyFailed' => __( 'Copy failed', 'albert' ),
				],
			]
		);
	}
}
