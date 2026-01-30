<?php
/**
 * Dashboard Admin Page
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
 * Dashboard class
 *
 * Manages the plugin dashboard page - primary landing page for Albert.
 * Shows a contextual setup checklist for new users and status for returning users.
 *
 * @since 1.0.0
 */
class Dashboard implements Hookable {

	/**
	 * Parent menu slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $parent_slug = 'albert';

	/**
	 * Dashboard page slug (same as parent to make it the first submenu).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $page_slug = 'albert';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_pages' ], 9 ); // Priority 9 to run before Abilities at 10.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Add top-level menu and dashboard page.
	 *
	 * Creates the top-level "Albert" menu with Dashboard as the default page,
	 * then adds "Dashboard" as the first submenu (which replaces the auto-generated one).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_menu_pages(): void {
		// Add top-level menu (shows Dashboard by default).
		add_menu_page(
			__( 'Albert Dashboard', 'albert-ai-butler' ),
			__( 'Albert', 'albert-ai-butler' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_dashboard_page' ],
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbDpzcGFjZT0icHJlc2VydmUiIHZpZXdCb3g9IjAgMCAyNTYgMjU2Ij48cGF0aCBmaWxsPSIjYTdhYWFkIiBkPSJNNjkuNCAxNC40Yy0uOS44LTEgMy40LTEgMTguOSAwIDE2LjcuMSAxOC4xIDEuMSAxOSAuNi42IDEuNSAxIDIgMXM5LjgtMi4zIDIwLjctNWwxOS44LTUgMi44IDIuOWM3LjggOC4xIDE5LjUgOC4yIDI3LjMuNWwzLjQtMy40IDIwLjEgNS4xYzEyLjggMy4zIDIwLjUgNC45IDIxLjQgNC42LjctLjIgMS42LTEuMSAyLTIgLjMtLjguNi04LjkuNi0xNy45IDAtMTcuOS0uMi0xOS40LTMuMi0xOS43LS45LS4xLTEwLjUgMi0yMS4zIDQuOGwtMTkuNyA1LTIuOC0zLjFjLTMuOS00LjEtNy45LTUuOC0xMy44LTUuOS01LjcgMC0xMC40IDItMTQuMSA2LjJsLTIuNSAyLjgtMTkuNC00LjljLTEwLjYtMi44LTIwLTUtMjAuOS01LS45LjEtMiAuNS0yLjUgMS4xeiIvPjxwYXRoIGZpbGw9IiNhN2FhYWQiIGQ9Ik0yNS4yIDUyLjRjLTYgMy41LTExLjUgNi44LTEyLjIgNy40LTMuMSAyLjgtMy0xLjctMyA5MS4xIDAgNjMuNC4yIDg3LjEuNyA4OC4yIDEuNyAzLjYtNSAzLjQgMTE3LjMgMy40czExNS43LjIgMTE3LjMtMy40Yy41LTEuMS43LTI0LjIuNy04NS43IDAtODIuOCAwLTg0LjItMS4yLTg2LjYtMS4zLTIuNi0yLTMuMy0xNS40LTEzLjgtNC45LTMuOS05LjEtNi44LTkuMy02LjYtLjIuMi0xOS40IDM3LjEtNDIuNyA4MS45LTIzLjIgNDQuOC00My44IDg0LjMtNDUuNyA4Ny44bC0zLjQgNi4zLTQuMS03LjktNDUuOC04Ny44Yy0yMi45LTQzLjktNDEuNy04MC4xLTQyLTgwLjMtLjEtLjEtNS4yIDIuNS0xMS4yIDZ6Ii8+PHBhdGggZmlsbD0iI2E3YWFhZCIgZD0iTTEyNS42IDc2LjdjLTcuOSAxLjUtMTMuNCA5LjgtMTEuNyAxNy44IDIuOCAxMi45IDE5LjQgMTYuNiAyNyA2IDguMS0xMS4yLTEuNy0yNi4zLTE1LjMtMjMuOHpNMTIzLjYgMTI4YTE0LjQgMTQuNCAwIDAgMC04LjIgNy41Yy0zLjEgNi4zLTEuOCAxMy42IDMuMyAxOC4xIDMuMSAyLjcgNS43IDMuNiAxMCAzLjYgNC40IDAgNy42LTEuMiAxMC4zLTMuOSA5LjctOS4zIDQuMS0yNS4yLTkuMy0yNi0yLjQtLjEtNC43LjEtNi4xLjd6Ii8+PC9zdmc+',
			80
		);

		// Add Dashboard submenu (replaces auto-generated first submenu).
		add_submenu_page(
			$this->parent_slug,
			__( 'Dashboard', 'albert-ai-butler' ),
			__( 'Dashboard', 'albert-ai-butler' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_dashboard_page' ]
		);
	}

	/**
	 * Enqueue dashboard assets.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets( string $hook ): void {
		// Only load on our dashboard page.
		if ( 'toplevel_page_albert' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			ALBERT_VERSION
		);

		wp_enqueue_script(
			'albert-dashboard',
			ALBERT_PLUGIN_URL . 'assets/js/admin-dashboard.js',
			[],
			ALBERT_VERSION,
			true
		);
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_dashboard_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}

		// Gather setup state.
		$has_allowed_users  = ! empty( get_option( 'albert_allowed_users', [] ) );
		$active_connections = $this->get_active_connections_count();
		$has_connections    = $active_connections > 0;
		$setup_complete     = $has_allowed_users && $has_connections;
		$enabled_abilities  = $this->get_enabled_abilities_count();
		$mcp_endpoint       = McpServer::get_endpoint_url();

		?>
		<div class="wrap albert-settings">
			<h1><?php echo esc_html__( 'Albert Dashboard', 'albert-ai-butler' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Connect your WordPress site to AI assistants like Claude and ChatGPT.', 'albert-ai-butler' ); ?>
			</p>

			<div class="albert-dashboard-grid">
				<!-- Setup Checklist -->
				<div class="albert-card albert-setup-checklist-card">
					<?php if ( $setup_complete ) { ?>
						<h2><?php esc_html_e( 'Setup', 'albert-ai-butler' ); ?></h2>
						<div class="albert-setup-complete-wrapper">
							<div class="albert-setup-complete-bar">
								<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
								<?php esc_html_e( 'Complete', 'albert-ai-butler' ); ?>
							</div>
							<div class="albert-setup-complete-content">
								<p class="albert-setup-complete-actions">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert-abilities' ) ); ?>" class="button button-primary">
										<?php esc_html_e( 'Manage Abilities', 'albert-ai-butler' ); ?>
									</a>
								</p>
							</div>
						</div>
					<?php } else { ?>
						<h2><?php echo esc_html__( 'Get Started', 'albert-ai-butler' ); ?></h2>
						<ol class="albert-checklist">
							<li class="albert-checklist-item albert-checklist-done">
								<span class="albert-checklist-icon dashicons dashicons-yes-alt" aria-hidden="true"></span>
								<span class="albert-checklist-text"><?php esc_html_e( 'Plugin installed', 'albert-ai-butler' ); ?></span>
							</li>
							<li class="albert-checklist-item <?php echo $has_allowed_users ? 'albert-checklist-done' : 'albert-checklist-current'; ?>">
								<span class="albert-checklist-icon dashicons <?php echo $has_allowed_users ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span>
								<span class="albert-checklist-text">
									<?php if ( $has_allowed_users ) { ?>
										<?php esc_html_e( 'Allowed user added', 'albert-ai-butler' ); ?>
									<?php } else { ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert-connections' ) ); ?>">
											<?php esc_html_e( 'Add an allowed user', 'albert-ai-butler' ); ?>
										</a>
									<?php } ?>
								</span>
							</li>
							<li class="albert-checklist-item <?php echo $has_allowed_users ? ( $has_connections ? 'albert-checklist-done' : 'albert-checklist-current' ) : 'albert-checklist-pending'; ?>">
								<span class="albert-checklist-icon dashicons <?php echo $has_connections ? 'dashicons-yes-alt' : ( $has_allowed_users ? 'dashicons-marker' : 'dashicons-marker' ); ?>" aria-hidden="true"></span>
								<span class="albert-checklist-text">
									<?php if ( $has_connections ) { ?>
										<?php esc_html_e( 'AI assistant connected', 'albert-ai-butler' ); ?>
									<?php } elseif ( $has_allowed_users ) { ?>
										<?php esc_html_e( 'Connect an AI assistant', 'albert-ai-butler' ); ?>
									<?php } else { ?>
										<?php esc_html_e( 'Connect an AI assistant', 'albert-ai-butler' ); ?>
									<?php } ?>
								</span>
							</li>
							<?php if ( $has_allowed_users && ! $has_connections ) { ?>
								<li class="albert-checklist-endpoint">
									<label class="albert-field-label"><?php esc_html_e( 'MCP Endpoint URL', 'albert-ai-butler' ); ?></label>
									<p class="albert-field-description">
										<?php esc_html_e( 'Add this URL to Claude Desktop or ChatGPT as an MCP connector:', 'albert-ai-butler' ); ?>
									</p>
									<div class="albert-endpoint-box">
										<input
											type="text"
											id="albert-mcp-endpoint"
											class="albert-endpoint-url"
											value="<?php echo esc_url( $mcp_endpoint ); ?>"
											readonly
										/>
										<button
											type="button"
											class="button button-secondary albert-copy-btn"
											data-clipboard-target="#albert-mcp-endpoint"
										>
											<?php echo esc_html__( 'Copy', 'albert-ai-butler' ); ?>
										</button>
									</div>
								</li>
							<?php } ?>
						</ol>
					<?php } ?>
				</div>

				<!-- Status Overview -->
				<div class="albert-card albert-status-card">
					<h2><?php echo esc_html__( 'Status', 'albert-ai-butler' ); ?></h2>
					<ul class="albert-status-list">
						<li>
							<span class="albert-status-indicator albert-status-active" aria-hidden="true"></span>
							<strong><?php echo esc_html__( 'OAuth Server:', 'albert-ai-butler' ); ?></strong>
							<span class="albert-status-value"><?php echo esc_html__( 'Active', 'albert-ai-butler' ); ?></span>
						</li>
						<li>
							<span class="albert-status-indicator albert-status-active" aria-hidden="true"></span>
							<strong><?php echo esc_html__( 'MCP Endpoint:', 'albert-ai-butler' ); ?></strong>
							<span class="albert-status-value"><?php echo esc_html__( 'Active', 'albert-ai-butler' ); ?></span>
						</li>
						<li>
							<span class="albert-status-indicator albert-status-info" aria-hidden="true"></span>
							<strong><?php echo esc_html__( 'Active Connections:', 'albert-ai-butler' ); ?></strong>
							<span class="albert-status-value"><?php echo esc_html( (string) $active_connections ); ?></span>
						</li>
						<li>
							<span class="albert-status-indicator albert-status-info" aria-hidden="true"></span>
							<strong><?php echo esc_html__( 'Enabled Abilities:', 'albert-ai-butler' ); ?></strong>
							<span class="albert-status-value"><?php echo esc_html( $enabled_abilities ); ?></span>
						</li>
					</ul>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert-connections' ) ); ?>" class="button button-secondary">
							<?php echo esc_html__( 'View Connections', 'albert-ai-butler' ); ?>
						</a>
					</p>
				</div>

				<!-- Resources -->
				<div class="albert-card albert-resources-card">
					<h2><?php esc_html_e( 'Resources', 'albert-ai-butler' ); ?></h2>
					<ul class="albert-resources-list">
						<li>
							<span class="dashicons dashicons-book" aria-hidden="true"></span>
							<a href="https://wordpress.org/plugins/albert/" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Documentation', 'albert-ai-butler' ); ?>
								<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
							</a>
						</li>
						<li>
							<span class="dashicons dashicons-sos" aria-hidden="true"></span>
							<a href="https://github.com/YourMark/albert-ai-butler/issues" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Report an Issue', 'albert-ai-butler' ); ?>
								<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'albert-ai-butler' ); ?></span>
							</a>
						</li>
					</ul>
				</div>

				<!-- Recent Activity -->
				<div class="albert-card albert-activity-card">
					<h2><?php echo esc_html__( 'Recent Activity', 'albert-ai-butler' ); ?></h2>
					<?php
					$recent_activity = $this->get_recent_activity();
					if ( ! empty( $recent_activity ) ) {
						?>
						<ul class="albert-activity-list">
							<?php foreach ( $recent_activity as $activity ) { ?>
								<li>
									<span class="albert-activity-icon"><?php echo esc_html( $activity['icon'] ); ?></span>
									<span class="albert-activity-text"><?php echo esc_html( $activity['text'] ); ?></span>
									<span class="albert-activity-time"><?php echo esc_html( $activity['time'] ); ?></span>
								</li>
							<?php } ?>
						</ul>
					<?php } else { ?>
						<p class="description">
							<?php echo esc_html__( 'No recent activity. Connect an AI assistant to get started!', 'albert-ai-butler' ); ?>
						</p>
					<?php } ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get count of active OAuth connections.
	 *
	 * @return int Number of active connections.
	 * @since 1.0.0
	 */
	private function get_active_connections_count(): int {
		global $wpdb;
		$tables = Installer::get_table_names();

		// Count distinct clients with non-revoked tokens (sessions persist via refresh tokens).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT client_id) FROM %i WHERE revoked = 0',
				$tables['access_tokens']
			)
		);

		return (int) $count;
	}

	/**
	 * Get count of enabled abilities.
	 *
	 * @return string Enabled/Total abilities count.
	 * @since 1.0.0
	 */
	private function get_enabled_abilities_count(): string {
		$disabled_abilities = AbstractAbilitiesPage::get_disabled_abilities();
		$all_abilities      = wp_get_abilities();

		$enabled_count = 0;
		$total_count   = count( $all_abilities );

		foreach ( $all_abilities as $ability ) {
			$name = $ability->get_name();
			// Ability is enabled if NOT in the disabled list.
			if ( ! in_array( $name, $disabled_abilities, true ) ) {
				++$enabled_count;
			}
		}

		return sprintf( '%d/%d', $enabled_count, $total_count );
	}

	/**
	 * Get recent activity from OAuth sessions.
	 *
	 * @return array<int, array{icon: string, text: string, time: string}> Recent activity items.
	 * @since 1.0.0
	 */
	private function get_recent_activity(): array {
		global $wpdb;
		$tables = Installer::get_table_names();

		// Get most recent token creations.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.client_id, t.user_id, t.created_at, c.name
				FROM %i t
				LEFT JOIN %i c ON t.client_id = c.client_id
				ORDER BY t.created_at DESC
				LIMIT %d',
				$tables['access_tokens'],
				$tables['clients'],
				5
			)
		);

		$activity = [];
		foreach ( $results as $result ) {
			$user        = get_userdata( $result->user_id );
			$client_name = $result->name ?? __( 'Unknown Client', 'albert-ai-butler' );
			$time_diff   = human_time_diff( strtotime( $result->created_at ), time() );
			$activity[]  = [
				'icon' => '🔗',
				'text' => sprintf(
					/* translators: 1: Client name, 2: Username */
					__( '%1$s connected by %2$s', 'albert-ai-butler' ),
					$client_name,
					$user ? $user->display_name : __( 'Unknown', 'albert-ai-butler' )
				),
				'time' => sprintf(
					/* translators: %s: Time difference */
					__( '%s ago', 'albert-ai-butler' ),
					$time_diff
				),
			];
		}

		return $activity;
	}
}
