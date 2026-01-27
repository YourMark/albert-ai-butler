<?php
/**
 * Dashboard Admin Page
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

use Albert\Contracts\Interfaces\Hookable;
use Albert\OAuth\Database\Installer;

/**
 * Dashboard class
 *
 * Manages the plugin dashboard page - primary landing page for Albert.
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
			__( 'Albert Dashboard', 'albert' ),
			__( 'Albert', 'albert' ),
			'manage_options',
			$this->page_slug,
			[ $this, 'render_dashboard_page' ],
			'dashicons-networking',
			80
		);

		// Add Dashboard submenu (replaces auto-generated first submenu).
		add_submenu_page(
			$this->parent_slug,
			__( 'Dashboard', 'albert' ),
			__( 'Dashboard', 'albert' ),
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
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert' ) );
		}

		// Get MCP endpoint URL.
		$mcp_endpoint = rest_url( 'albert/v1/mcp' );

		// Get OAuth discovery URL.
		$oauth_discovery = home_url( '/.well-known/oauth-authorization-server' );

		// Count active connections.
		$active_connections = $this->get_active_connections_count();

		// Count enabled abilities.
		$enabled_abilities = $this->get_enabled_abilities_count();

		?>
		<div class="wrap albert-settings">
			<h1><?php echo esc_html__( 'Albert Dashboard', 'albert' ); ?></h1>
			<p class="description">
				<?php echo esc_html__( 'Connect your WordPress site to AI assistants like Claude and ChatGPT.', 'albert' ); ?>
			</p>

			<div class="albert-dashboard-grid">
				<!-- MCP Endpoint Section -->
				<div class="albert-card albert-endpoint-card">
					<h2><?php echo esc_html__( '🔗 Your MCP Endpoint', 'albert' ); ?></h2>
					<p class="description">
						<?php echo esc_html__( 'Use this URL to connect AI assistants to your WordPress site:', 'albert' ); ?>
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
							<?php echo esc_html__( 'Copy', 'albert' ); ?>
						</button>
					</div>
				</div>

				<!-- Quick Setup Guide -->
				<div class="albert-card albert-setup-card">
					<h2><?php echo esc_html__( '🚀 Quick Setup Guide', 'albert' ); ?></h2>
					<ol class="albert-setup-steps">
						<li><?php echo esc_html__( 'Copy the MCP endpoint URL above', 'albert' ); ?></li>
						<li><?php echo esc_html__( 'Add it to Claude Desktop or ChatGPT as an MCP connector', 'albert' ); ?></li>
						<li><?php echo esc_html__( 'Authorize when prompted (you\'ll be redirected to WordPress)', 'albert' ); ?></li>
						<li><?php echo esc_html__( 'Start managing your site with AI!', 'albert' ); ?></li>
					</ol>
					<p>
						<a href="https://albertwp.com/docs/setup" target="_blank" class="button button-secondary">
							<?php echo esc_html__( 'View Full Documentation', 'albert' ); ?>
						</a>
					</p>
				</div>

				<!-- Status Overview -->
				<div class="albert-card albert-status-card">
					<h2><?php echo esc_html__( '📊 Status', 'albert' ); ?></h2>
					<ul class="albert-status-list">
						<li>
							<span class="albert-status-indicator albert-status-active"></span>
							<strong><?php echo esc_html__( 'OAuth Server:', 'albert' ); ?></strong>
							<span class="albert-status-value"><?php echo esc_html__( 'Active', 'albert' ); ?></span>
						</li>
						<li>
							<span class="albert-status-indicator albert-status-active"></span>
							<strong><?php echo esc_html__( 'MCP Endpoint:', 'albert' ); ?></strong>
							<span class="albert-status-value"><?php echo esc_html__( 'Active', 'albert' ); ?></span>
						</li>
						<li>
							<span class="albert-status-indicator albert-status-info"></span>
							<strong><?php echo esc_html__( 'Active Connections:', 'albert' ); ?></strong>
							<span class="albert-status-value"><?php echo esc_html( (string) $active_connections ); ?></span>
						</li>
						<li>
							<span class="albert-status-indicator albert-status-info"></span>
							<strong><?php echo esc_html__( 'Enabled Abilities:', 'albert' ); ?></strong>
							<span class="albert-status-value"><?php echo esc_html( $enabled_abilities ); ?></span>
						</li>
					</ul>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert-connections' ) ); ?>" class="button button-secondary">
							<?php echo esc_html__( 'View Connections', 'albert' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=albert' ) ); ?>" class="button button-secondary">
							<?php echo esc_html__( 'Manage Abilities', 'albert' ); ?>
						</a>
					</p>
				</div>

				<!-- Recent Activity -->
				<div class="albert-card albert-activity-card">
					<h2><?php echo esc_html__( '📝 Recent Activity', 'albert' ); ?></h2>
					<?php
					$recent_activity = $this->get_recent_activity();
					if ( ! empty( $recent_activity ) ) :
						?>
						<ul class="albert-activity-list">
							<?php foreach ( $recent_activity as $activity ) : ?>
								<li>
									<span class="albert-activity-icon"><?php echo esc_html( $activity['icon'] ); ?></span>
									<span class="albert-activity-text"><?php echo esc_html( $activity['text'] ); ?></span>
									<span class="albert-activity-time"><?php echo esc_html( $activity['time'] ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="description">
							<?php echo esc_html__( 'No recent activity. Connect an AI assistant to get started!', 'albert' ); ?>
						</p>
					<?php endif; ?>
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

		// Count non-expired tokens.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT client_id) FROM %i WHERE expires_at > %s',
				$tables['access_tokens'],
				gmdate( 'Y-m-d H:i:s' )
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
		$options       = get_option( 'albert_options', [] );
		$all_abilities = wp_get_abilities();

		$enabled_count = 0;
		$total_count   = count( $all_abilities );

		foreach ( $all_abilities as $ability_id => $ability_data ) {
			// Check if ability is enabled (default is enabled).
			$is_enabled = isset( $options[ $ability_id ] ) ? (bool) $options[ $ability_id ] : true;
			if ( $is_enabled ) {
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
			$client_name = $result->name ?? __( 'Unknown Client', 'albert' );
			$time_diff   = human_time_diff( strtotime( $result->created_at ), time() );
			$activity[]  = [
				'icon' => '🔗',
				'text' => sprintf(
					/* translators: 1: Client name, 2: Username */
					__( '%1$s connected by %2$s', 'albert' ),
					$client_name,
					$user ? $user->display_name : __( 'Unknown', 'albert' )
				),
				'time' => sprintf(
					/* translators: %s: Time difference */
					__( '%s ago', 'albert' ),
					$time_diff
				),
			];
		}

		return $activity;
	}
}
