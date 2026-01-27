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
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/albert.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
