<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines constants that are normally defined by the main plugin file.
 *
 * @package Albert
 */

// Define plugin constants for PHPStan analysis.
if ( ! defined( 'ALBERT_VERSION' ) ) {
	define( 'ALBERT_VERSION', '1.0.0' );
}

if ( ! defined( 'ALBERT_PLUGIN_FILE' ) ) {
	define( 'ALBERT_PLUGIN_FILE', __DIR__ . '/albert.php' );
}

if ( ! defined( 'ALBERT_PLUGIN_DIR' ) ) {
	define( 'ALBERT_PLUGIN_DIR', __DIR__ . '/' );
}

if ( ! defined( 'ALBERT_PLUGIN_URL' ) ) {
	define( 'ALBERT_PLUGIN_URL', 'https://example.com/wp-content/plugins/albert/' );
}

if ( ! defined( 'ALBERT_PLUGIN_BASENAME' ) ) {
	define( 'ALBERT_PLUGIN_BASENAME', 'albert/albert.php' );
}
