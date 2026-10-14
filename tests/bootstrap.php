<?php
/**
 * PHPUnit bootstrap file for Albert plugin tests.
 *
 * @package Albert
 */

// Composer autoloader for the plugin.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// The bundled MCP adapter lives in its own Composer project. See Albert\MCP\AdapterSource.
require_once dirname( __DIR__ ) . '/lib/mcp-adapter/vendor/autoload.php';

// The suite runs on Albert's bundled copy, which reports a "load the MCP Adapter
// plugin instead" deprecation the first time it starts. AdapterSource keeps it
// out of PHP's error output on real sites, but the test listener records the
// deprecation itself and would fail whichever test booted the adapter. The
// adapter skips the report once this constant exists.
define( 'WP_MCP_VERSION', '0.0.0-albert-tests' );

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

	// Composer's `files` autoload ran at the top of this bootstrap, before
	// WordPress existed, so src/functions.php hit its own ABSPATH guard and
	// returned. Load it now that ABSPATH is defined, so the public global
	// helpers — albert_register_setting(), which is how an add-on registers a
	// setting, and albert_get_setting() — exist in tests as they do on a real
	// site. Without this they are silently absent and nothing covering them can
	// run.
	//
	// `require`, not `require_once`: Composer already included this path, so
	// require_once would skip it. The guard is on a function rather than on
	// ABSPATH because that early return does not leave the file wholly
	// unloaded: PHP hoists unconditional function declarations at compile time,
	// so those exist regardless, and re-including would redeclare them. Every
	// function in that file is wrapped in `function_exists` for exactly this
	// reason — keep it that way.
	if ( ! function_exists( 'albert_get_setting' ) ) {
		require dirname( __DIR__ ) . '/src/functions.php';
	}
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// WooCommerce's full activation (custom tables, roles, caps, default pages)
// normally runs during plugin activation. The test suite loads WC without
// activating it, leaving tables missing and admin role without WC caps
// (edit_products, edit_shop_orders, etc.). Run the full install routine
// once after bootstrap to match a real site.
if ( class_exists( 'WC_Install' ) ) {
	WC_Install::install();
}
