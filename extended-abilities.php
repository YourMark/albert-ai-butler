<?php
/**
 * Plugin Name: Extended Abilities
 * Plugin URI: https://yourmark.nl
 * Description: Extend the abilities of WordPress, WooCommerce and other plugins with the abilities API
 * Version: 1.0.0-alpha.1
 * Author: Mark Jansen - Your Mark Media
 * Author URI: https://yourmark.nl
 * Text Domain: extended-abilities
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package ExtendedAbilities
 */

// Prevent direct access.
use ExtendedAbilities\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'EXTENDED_ABILITIES_VERSION', '1.0.0-alpha.1' );
define( 'EXTENDED_ABILITIES_PLUGIN_FILE', __FILE__ );
define( 'EXTENDED_ABILITIES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTENDED_ABILITIES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTENDED_ABILITIES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Load Composer autoloader if available.
if ( ! file_exists( EXTENDED_ABILITIES_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	return;
}

require_once EXTENDED_ABILITIES_PLUGIN_DIR . 'vendor/autoload.php';

/**
 * Initialize the plugin.
 *
 * @return void
 * @since 1.0.0
 */
function init_extended_abilities(): void {
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
							__( 'Extended Abilities Plugin Error: %s', 'extended-abilities' ),
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
			error_log( 'Extended Abilities Plugin Error: ' . $e->getMessage() );
		}
	}
}

// Initialize the plugin.
add_action( 'plugins_loaded', 'init_extended_abilities' );

/**
 * Plugin activation hook.
 *
 * @return void
 * @since 1.0.0
 */
function activate_extended_abilities(): void {
	Plugin::activate();
}

register_activation_hook( __FILE__, 'activate_extended_abilities' );

/**
 * Plugin deactivation hook.
 *
 * @return void
 * @since 1.0.0
 */
function deactivate_extended_abilities(): void {
	Plugin::deactivate();
}

register_deactivation_hook( __FILE__, 'deactivate_extended_abilities' );

/**
 * Add settings link to plugin action links.
 *
 * @param array $links Existing plugin action links.
 *
 * @return array Modified plugin action links.
 * @since 1.0.0
 */
function extended_abilities_plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=extended-abilities-settings' ) ),
		esc_html__( 'Settings', 'extended-abilities' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}

add_filter( 'plugin_action_links_' . EXTENDED_ABILITIES_PLUGIN_BASENAME, 'extended_abilities_plugin_action_links' );
