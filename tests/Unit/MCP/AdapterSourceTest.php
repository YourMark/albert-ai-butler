<?php
/**
 * Unit tests for AdapterSource.
 *
 * @package Albert\Tests\Unit\MCP
 */

namespace Albert\Tests\Unit\MCP;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/wordpress-site.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/albert-adapter-source-plugins' );
}

use Albert\MCP\AdapterSource;
use PHPUnit\Framework\TestCase;

/**
 * AdapterSource tests.
 *
 * @covers \Albert\MCP\AdapterSource
 */
class AdapterSourceTest extends TestCase {

	/**
	 * Whether this test created the plugin file, so only that one is removed.
	 *
	 * @var bool
	 */
	private bool $created_plugin_file = false;

	/**
	 * Reset options, sites and Jetpack's activating list.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_options']                         = [];
		$GLOBALS['albert_test_site_options']                    = [];
		$GLOBALS['albert_test_site']                            = [];
		$GLOBALS['albert_test_transients']                      = [];
		$GLOBALS['jetpack_autoloader_activating_plugins_paths'] = null;
	}

	/**
	 * Remove the plugin file this test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( $this->created_plugin_file ) {
			unlink( WP_PLUGIN_DIR . '/' . AdapterSource::PLUGIN_FILE );
		}

		unset( $GLOBALS['albert_test_site_options'], $GLOBALS['albert_test_site'], $GLOBALS['albert_test_transients'] );
		$GLOBALS['jetpack_autoloader_activating_plugins_paths'] = null;
		parent::tearDown();
	}

	/**
	 * Put the MCP Adapter plugin's main file on disk.
	 *
	 * @return void
	 */
	private function install_plugin(): void {
		$file = WP_PLUGIN_DIR . '/' . AdapterSource::PLUGIN_FILE;

		if ( file_exists( $file ) ) {
			return;
		}

		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0777, true );
		}

		file_put_contents( $file, '<?php' );
		$this->created_plugin_file = true;
	}

	/**
	 * Albert offers its copy when the plugin is not installed.
	 *
	 * @return void
	 */
	public function test_offers_the_fallback_without_the_plugin(): void {
		AdapterSource::offer_fallback( '/site/wp-content/plugins/albert-ai-butler/' );

		$this->assertSame(
			[ '/site/wp-content/plugins/albert-ai-butler/lib/mcp-adapter' ],
			$GLOBALS['jetpack_autoloader_activating_plugins_paths']
		);
	}

	/**
	 * An installed but inactive plugin supplies nothing, so the fallback is offered.
	 *
	 * @return void
	 */
	public function test_an_inactive_plugin_does_not_count(): void {
		$this->install_plugin();

		$this->assertFalse( AdapterSource::plugin_active() );
	}

	/**
	 * An active plugin supplies the adapter, so Albert's copy stays out of the race.
	 *
	 * @return void
	 */
	public function test_steps_aside_when_the_plugin_is_active(): void {
		$this->install_plugin();
		$GLOBALS['albert_test_options']['active_plugins'] = [ 'albert-ai-butler/albert-ai-butler.php', AdapterSource::PLUGIN_FILE ];

		AdapterSource::offer_fallback( '/site/wp-content/plugins/albert-ai-butler' );

		$this->assertNull( $GLOBALS['jetpack_autoloader_activating_plugins_paths'] );
	}

	/**
	 * A network-activated plugin counts on multisite.
	 *
	 * @return void
	 */
	public function test_counts_a_network_activated_plugin(): void {
		$this->install_plugin();
		$GLOBALS['albert_test_site']['multisite']                       = true;
		$GLOBALS['albert_test_site_options']['active_sitewide_plugins'] = [ AdapterSource::PLUGIN_FILE => time() ];

		$this->assertTrue( AdapterSource::plugin_active() );
	}

	/**
	 * A plugin still listed as active after its folder was deleted supplies
	 * nothing, so the site keeps Albert's copy instead of having no adapter.
	 *
	 * @return void
	 */
	public function test_ignores_an_active_plugin_whose_files_are_gone(): void {
		$GLOBALS['albert_test_options']['active_plugins'] = [ AdapterSource::PLUGIN_FILE ];

		if ( file_exists( WP_PLUGIN_DIR . '/' . AdapterSource::PLUGIN_FILE ) ) {
			$this->markTestSkipped( 'The MCP Adapter plugin exists in WP_PLUGIN_DIR on this machine.' );
		}

		$this->assertFalse( AdapterSource::plugin_active() );
	}

	/**
	 * Paths other code already added to Jetpack's activating list are kept.
	 *
	 * @return void
	 */
	public function test_keeps_existing_activating_paths(): void {
		$GLOBALS['jetpack_autoloader_activating_plugins_paths'] = [ '/elsewhere' ];

		AdapterSource::offer_fallback( '/albert' );

		$this->assertSame( [ '/elsewhere', '/albert/lib/mcp-adapter' ], $GLOBALS['jetpack_autoloader_activating_plugins_paths'] );
	}

	/**
	 * Activating the MCP Adapter plugin clears Jetpack's cached paths; anything else does not.
	 *
	 * @return void
	 */
	public function test_forgets_cached_paths_only_for_the_adapter_plugin(): void {
		$GLOBALS['albert_test_transients']['jetpack_autoloader_plugin_paths'] = [ 'cached' ];

		AdapterSource::forget_cached_paths( 'akismet/akismet.php' );
		$this->assertArrayHasKey( 'jetpack_autoloader_plugin_paths', $GLOBALS['albert_test_transients'] );

		AdapterSource::forget_cached_paths( AdapterSource::PLUGIN_FILE );
		$this->assertArrayNotHasKey( 'jetpack_autoloader_plugin_paths', $GLOBALS['albert_test_transients'] );
	}
}
