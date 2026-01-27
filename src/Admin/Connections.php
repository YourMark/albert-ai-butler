<?php
/**
 * Connections Page
 *
 * Allows admins to view and manage all AI assistant connections (OAuth sessions).
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

use Albert\Contracts\Interfaces\Hookable;
use Albert\OAuth\Database\Installer;

/**
 * Connections class
 *
 * Provides a page for admins to manage AI assistant OAuth connections.
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
	 * Handle user actions.
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

		if ( $action === 'revoke' ) {
			$this->handle_revoke_session();
		} elseif ( $action === 'revoke_all' ) {
			$this->handle_revoke_all_sessions();
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
		$table       = $wpdb->prefix . 'albert_oauth_access_tokens';
		$current_uid = get_current_user_id();

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
	 * Handle revoking all user sessions.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_revoke_all_sessions(): void {
		// Verify nonce.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'revoke_all_my_sessions' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'albert' ) );
		}

		// Use shared method from Settings class.
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
	 * Render the page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert' ) );
		}

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
		<div class="wrap albert-settings">
			<h1><?php echo esc_html__( 'AI Assistant Connections', 'albert' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Active AI assistant connections to your WordPress site. Each connection represents an authorized AI tool that can interact with your site via the MCP protocol.', 'albert' ); ?>
			</p>

			<?php settings_errors( 'albert_connections' ); ?>

			<?php if ( empty( $sessions ) ) : ?>
				<div class="albert-card" style="margin-top: 20px;">
					<div class="albert-empty-connections">
						<span class="dashicons dashicons-networking"></span>
						<h3><?php esc_html_e( 'No Active Connections', 'albert' ); ?></h3>
						<p><?php esc_html_e( 'When you authorize an AI assistant to access this site, it will appear here.', 'albert' ); ?></p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert' ) ); ?>" class="button button-primary">
								<?php esc_html_e( 'View Setup Instructions', 'albert' ); ?>
							</a>
						</p>
					</div>
				</div>
			<?php else : ?>
				<div class="albert-connections-grid">
					<?php foreach ( $sessions as $session ) : ?>
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
									<span class="dashicons dashicons-admin-plugins"></span>
								</div>
								<div class="albert-connection-title">
									<h3><?php echo esc_html( $app_name ); ?></h3>
									<span class="albert-connection-session"><?php echo esc_html( substr( $session->token_id, 0, 12 ) . '...' ); ?></span>
								</div>
							</div>
							<div class="albert-connection-body">
								<div class="albert-connection-meta">
									<div class="albert-connection-meta-item">
										<span class="dashicons dashicons-admin-users"></span>
										<div>
											<strong><?php esc_html_e( 'User:', 'albert' ); ?></strong>
											<span><?php echo $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown', 'albert' ); ?></span>
										</div>
									</div>
									<div class="albert-connection-meta-item">
										<span class="dashicons dashicons-calendar-alt"></span>
										<div>
											<strong><?php esc_html_e( 'Connected:', 'albert' ); ?></strong>
											<span><?php echo esc_html( human_time_diff( $connected_at, time() ) . ' ' . __( 'ago', 'albert' ) ); ?></span>
										</div>
									</div>
									<div class="albert-connection-meta-item">
										<span class="dashicons dashicons-clock"></span>
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
									<span class="dashicons dashicons-dismiss"></span>
									<?php esc_html_e( 'Disconnect', 'albert' ); ?>
								</a>
							</div>
						</div>
					<?php endforeach; ?>
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
						<span class="dashicons dashicons-dismiss"></span>
						<?php esc_html_e( 'Disconnect All', 'albert' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
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
			'albert-user-sessions',
			ALBERT_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			ALBERT_VERSION
		);
	}
}
