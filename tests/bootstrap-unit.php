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
