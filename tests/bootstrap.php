<?php
/**
 * PHPUnit bootstrap file for Albert plugin tests.
 *
 * @package Albert
 */

// Composer autoloader for the plugin.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define the path to the Yoast PHPUnit Polyfills.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/' );

// Get the tests directory.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load WooCommerce (when installed) and the plugin being tested.
 *
 * WooCommerce must load before Albert so class_exists('WooCommerce') is true
 * by the time AbilitiesManager registers the Woo abilities. The file path is
 * resolved against the WP test core dir so the same bootstrap works for both
 * the standard and the with-WooCommerce CI jobs.
 */
function _manually_load_plugin() {
	$wc_main = ABSPATH . 'wp-content/plugins/woocommerce/woocommerce.php';
	if ( file_exists( $wc_main ) ) {
		require_once $wc_main;
	}

	require dirname( __DIR__ ) . '/albert-ai-butler.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// WooCommerce roles and capabilities (edit_products, edit_shop_orders, etc.)
// are created during WC activation via WC_Install::create_roles(). The test
// suite loads WC without activating it, so the administrator role is missing
// these custom caps. Run create_roles() once after bootstrap to match a real
// site where WC has been activated.
if ( class_exists( 'WC_Install' ) ) {
	WC_Install::create_roles();
}
