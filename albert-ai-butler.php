<?php
/**
 * Plugin Name: Albert - The AI Butler
 * Plugin URI: https://wordpress.org/plugins/albert/
 * Description: At your service — Albert connects AI assistants to your WordPress site so they can manage content, handle tasks, and keep things running smoothly.
 * Version: 1.4.1
 * Author: Albert
 * Author URI: https://yourmark.nl
 * Text Domain: albert-ai-butler
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Albert
 */

// Prevent direct access.
use Albert\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'ALBERT_VERSION', '1.4.1' );
define( 'ALBERT_PLUGIN_FILE', __FILE__ );
define( 'ALBERT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALBERT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ALBERT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Load through Jetpack Autoloader, not Composer's own autoloader.
 *
 * `wordpress/mcp-adapter` coordinates through global WordPress hook names, a
 * fixed default-server id and a fixed REST route. None of those can be made
 * unique per copy, so two copies of the library in one request collide — which
 * is why upstream does not support namespace-prefixing it (WordPress/mcp-adapter#172)
 * and requires this autoloader instead.
 *
 * Jetpack Autoloader publishes each plugin's copy with its version and loads a
 * single newest copy site-wide, so Albert, WooCommerce and the standalone MCP
 * Adapter plugin all resolve the same `WP\MCP\` classes. One class, one
 * singleton, one `mcp_adapter_init` — the collision cannot occur rather than
 * being worked around.
 *
 * `autoload_packages.php` is Jetpack's entry point; it calls WordPress
 * functions, which is safe here because a plugin file only runs once core is
 * loaded. Composer's `vendor/autoload.php` still exists and is what the test
 * bootstraps use, since they load before WordPress does.
 */
if ( ! file_exists( ALBERT_PLUGIN_DIR . 'vendor/autoload_packages.php' ) ) {
	return;
}

require_once ALBERT_PLUGIN_DIR . 'vendor/autoload_packages.php';

/**
 * Initialize the plugin.
 *
 * @return void
 * @since 1.0.0
 */
function albert_init(): void {
	try {
		$plugin = Plugin::get_instance();
		$plugin->init();
	} catch ( \Exception $e ) {
		if ( is_admin() ) {
			add_action(
				'admin_notices',
				function () use ( $e ) {
					echo '<div class="notice notice-error"><p>';
					echo esc_html(
						sprintf(
						/* translators: %s: error message */
							__( 'Albert Plugin Error: %s', 'albert-ai-butler' ),
							$e->getMessage()
						)
					);
					echo '</p></div>';
				}
			);
		}

		// Log the error for debugging.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG_LOG is enabled.
			error_log( 'Albert Plugin Error: ' . $e->getMessage() );
		}
	}
}

// Initialize the plugin.
add_action( 'plugins_loaded', 'albert_init' );

/**
 * Plugin activation hook.
 *
 * @return void
 * @since 1.0.0
 */
function albert_activate(): void {
	Plugin::activate();
}

register_activation_hook( __FILE__, 'albert_activate' );

/**
 * Plugin deactivation hook.
 *
 * @return void
 * @since 1.0.0
 */
function albert_deactivate(): void {
	Plugin::deactivate();
}

register_deactivation_hook( __FILE__, 'albert_deactivate' );

// Drop all Albert tables (ability log + OAuth) on uninstall. Fires only when the
// plugin is deleted — not on update or deactivate.
register_uninstall_hook( __FILE__, [ 'Albert\Database\Installer', 'uninstall' ] );

/**
 * Add settings link to plugin action links.
 *
 * @param array $links Existing plugin action links.
 *
 * @return array Modified plugin action links.
 * @since 1.0.0
 */
function albert_plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=albert-settings' ) ),
		esc_html__( 'Settings', 'albert-ai-butler' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}

add_filter( 'plugin_action_links_' . ALBERT_PLUGIN_BASENAME, 'albert_plugin_action_links' );
