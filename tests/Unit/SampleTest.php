<?php
/**
 * Sample unit tests.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Sample unit test class.
 *
 * Unit tests don't require WordPress and test isolated functionality.
 */
class SampleTest extends TestCase {

	/**
	 * A sample test to verify PHPUnit is working.
	 *
	 * @return void
	 */
	public function test_phpunit_is_working(): void {
		$this->assertTrue( true );
	}

	/**
	 * Test that plugin files exist.
	 *
	 * @return void
	 */
	public function test_plugin_file_exists(): void {
		$plugin_file = dirname( __DIR__, 2 ) . '/albert.php';

		$this->assertFileExists( $plugin_file );
	}

	/**
	 * Test that composer autoload is available.
	 *
	 * @return void
	 */
	public function test_autoload_is_available(): void {
		$this->assertTrue(
			class_exists( 'Albert\Core\Plugin' ),
			'Plugin class should be autoloadable'
		);
	}
}
