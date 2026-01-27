<?php
/**
 * Plugin integration tests.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration;

use Albert\Tests\TestCase;
use Albert\Core\Plugin;

/**
 * Test plugin initialization and core functionality.
 */
class PluginTest extends TestCase {

	/**
	 * Test that the plugin singleton is available.
	 *
	 * @return void
	 */
	public function test_plugin_instance_exists(): void {
		$plugin = Plugin::get_instance();

		$this->assertInstanceOf( Plugin::class, $plugin );
	}

	/**
	 * Test that plugin returns the same instance (singleton pattern).
	 *
	 * @return void
	 */
	public function test_plugin_is_singleton(): void {
		$instance1 = Plugin::get_instance();
		$instance2 = Plugin::get_instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test that plugin constants are defined.
	 *
	 * @return void
	 */
	public function test_plugin_constants_are_defined(): void {
		$this->assertTrue( defined( 'ALBERT_VERSION' ) );
		$this->assertTrue( defined( 'ALBERT_PLUGIN_FILE' ) );
		$this->assertTrue( defined( 'ALBERT_PLUGIN_DIR' ) );
	}

	/**
	 * Test that the plugin version is a valid semver string.
	 *
	 * @return void
	 */
	public function test_plugin_version_is_valid(): void {
		$version = ALBERT_VERSION;

		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/',
			$version,
			'Plugin version should be a valid semver string'
		);
	}
}
