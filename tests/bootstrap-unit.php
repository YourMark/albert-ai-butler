<?php
/**
 * PHPUnit bootstrap file for unit tests (no WordPress dependency).
 *
 * @package Albert
 */

// Define ABSPATH so source files guarded with `defined( 'ABSPATH' ) || exit;`
// can be loaded without a full WordPress bootstrap.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Plugin constants the main file defines. WP_PLUGIN_DIR points at a temp folder
// because AdapterSourceTest writes into it.
if ( ! defined( 'ALBERT_VERSION' ) ) {
	define( 'ALBERT_VERSION', '0.0.0-test' );
}
if ( ! defined( 'ALBERT_PLUGIN_BASENAME' ) ) {
	define( 'ALBERT_PLUGIN_BASENAME', 'albert-ai-butler/albert-ai-butler.php' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/albert-adapter-source-plugins' );
}

// Minimal i18n stubs — unit tests don't load WordPress.
if ( ! function_exists( '__' ) ) {
	/**
	 * Stub for WordPress __() translation helper.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Unused text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * Stub for WordPress _n() plural translation helper.
	 *
	 * @param string $single Singular form.
	 * @param string $plural Plural form.
	 * @param int    $number Count to decide the form.
	 * @param string $domain Unused text domain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) { // phpcs:ignore
		return (int) $number === 1 ? $single : $plural;
	}
}

// Composer autoloader for the plugin.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// The bundled MCP adapter lives in its own Composer project. See Albert\MCP\AdapterSource.
require_once dirname( __DIR__ ) . '/lib/mcp-adapter/vendor/autoload.php';
