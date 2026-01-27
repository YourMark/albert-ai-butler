<?php
/**
 * Plugin Name: Albert - Your AI Butler for WP
 * Plugin URI: https://albertwp.com
 * Description: Your AI butler for WordPress - streamline content management, automate tasks, and connect AI assistants with secure OAuth 2.0 and MCP integration.
 * Version: 1.0.0
 * Author: Mark Jansen - Your Mark Media
 * Author URI: https://yourmark.nl
 * Text Domain: albert
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.2
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
define( 'ALBERT_VERSION', '1.0.0' );
define( 'ALBERT_PLUGIN_FILE', __FILE__ );
define( 'ALBERT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALBERT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ALBERT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Load Composer autoloader if available.
if ( ! file_exists( ALBERT_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	return;
}

require_once ALBERT_PLUGIN_DIR . 'vendor/autoload.php';

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
							__( 'Albert Plugin Error: %s', 'albert' ),
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
		esc_html__( 'Settings', 'albert' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}

add_filter( 'plugin_action_links_' . ALBERT_PLUGIN_BASENAME, 'albert_plugin_action_links' );
