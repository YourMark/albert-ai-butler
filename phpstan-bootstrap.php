<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines constants that are normally defined by the main plugin file.
 *
 * @package AIBridge
 */

// Define plugin constants for PHPStan analysis.
if ( ! defined( 'AIBRIDGE_VERSION' ) ) {
	define( 'AIBRIDGE_VERSION', '1.0.0' );
}

if ( ! defined( 'AIBRIDGE_PLUGIN_FILE' ) ) {
	define( 'AIBRIDGE_PLUGIN_FILE', __DIR__ . '/ai-bridge.php' );
}

if ( ! defined( 'AIBRIDGE_PLUGIN_DIR' ) ) {
	define( 'AIBRIDGE_PLUGIN_DIR', __DIR__ . '/' );
}

if ( ! defined( 'AIBRIDGE_PLUGIN_URL' ) ) {
	define( 'AIBRIDGE_PLUGIN_URL', 'https://example.com/wp-content/plugins/ai-bridge/' );
}

if ( ! defined( 'AIBRIDGE_PLUGIN_BASENAME' ) ) {
	define( 'AIBRIDGE_PLUGIN_BASENAME', 'ai-bridge/ai-bridge.php' );
}
