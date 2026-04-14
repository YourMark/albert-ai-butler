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

// Composer autoloader for the plugin.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
