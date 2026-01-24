<?php
/**
 * Plugin Name: AI Bridge for WordPress
 * Plugin URI: https://aibridgewp.com
 * Description: Connect your WordPress site to AI assistants with secure OAuth 2.0 authentication and the Model Context Protocol (MCP).
 * Version: 1.0.0
 * Author: Mark Jansen - Your Mark Media
 * Author URI: https://yourmark.nl
 * Text Domain: ai-bridge
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package AIBridge
 */

// Prevent direct access.
use AIBridge\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'AIBRIDGE_VERSION', '1.0.0' );
define( 'AIBRIDGE_PLUGIN_FILE', __FILE__ );
define( 'AIBRIDGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIBRIDGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIBRIDGE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Load Composer autoloader if available.
if ( ! file_exists( AIBRIDGE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	return;
}

require_once AIBRIDGE_PLUGIN_DIR . 'vendor/autoload.php';

/**
 * Initialize the plugin.
 *
 * @return void
 * @since 1.0.0
 */
function init_aibridge(): void {
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
							__( 'AI Bridge Plugin Error: %s', 'ai-bridge' ),
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
			error_log( 'AI Bridge Plugin Error: ' . $e->getMessage() );
		}
	}
}

// Initialize the plugin.
add_action( 'plugins_loaded', 'init_aibridge' );

/**
 * Plugin activation hook.
 *
 * @return void
 * @since 1.0.0
 */
function activate_aibridge(): void {
	Plugin::activate();
}

register_activation_hook( __FILE__, 'activate_aibridge' );

/**
 * Plugin deactivation hook.
 *
 * @return void
 * @since 1.0.0
 */
function deactivate_aibridge(): void {
	Plugin::deactivate();
}

register_deactivation_hook( __FILE__, 'deactivate_aibridge' );

/**
 * Add settings link to plugin action links.
 *
 * @param array $links Existing plugin action links.
 *
 * @return array Modified plugin action links.
 * @since 1.0.0
 */
function aibridge_plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=ai-bridge-settings' ) ),
		esc_html__( 'Settings', 'ai-bridge' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}

add_filter( 'plugin_action_links_' . AIBRIDGE_PLUGIN_BASENAME, 'aibridge_plugin_action_links' );
