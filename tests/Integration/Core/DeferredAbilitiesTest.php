<?php
/**
 * Integration tests for building abilities on the Abilities API's own hook.
 *
 * Building them on `init` autoloaded some 50 ability classes on every request,
 * frontend views included. They are now built on `wp_abilities_api_init`.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Core;

use Albert\Core\AbilitiesManager;
use Albert\Core\Plugin;
use Albert\Tests\TestCase;

/**
 * Deferred ability building.
 *
 * @covers \Albert\Core\Plugin::register_abilities
 * @covers \Albert\Core\Plugin::build_abilities
 * @covers \Albert\Core\Plugin::get_abilities_manager
 */
class DeferredAbilitiesTest extends TestCase {

	/**
	 * The manager a plugin holds, read without the registry side effect of
	 * get_abilities_manager().
	 *
	 * @param Plugin $plugin Plugin under test.
	 *
	 * @return AbilitiesManager
	 */
	private function manager_of( Plugin $plugin ): AbilitiesManager {
		$manager = ( new \ReflectionProperty( Plugin::class, 'abilities_manager' ) )->getValue( $plugin );

		$this->assertInstanceOf( AbilitiesManager::class, $manager );

		return $manager;
	}

	/**
	 * A fresh Plugin after `init`, with `wp_abilities_api_init` fired or not.
	 *
	 * WP_UnitTestCase restores `$wp_actions` after each test.
	 *
	 * @param bool $api_started Whether the Abilities API has started.
	 *
	 * @return Plugin
	 */
	private function plugin_after_init( bool $api_started ): Plugin {
		global $wp_actions;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_actions['wp_abilities_api_init'] = $api_started ? 1 : 0;

		$plugin = new Plugin();
		$plugin->register_abilities();

		return $plugin;
	}

	/**
	 * After `init` the manager exists but holds no abilities.
	 *
	 * @return void
	 */
	public function test_init_builds_no_abilities(): void {
		$this->assertSame( [], $this->manager_of( $this->plugin_after_init( false ) )->get_abilities() );
	}

	/**
	 * Built before the manager registers them with WordPress, or none get registered.
	 *
	 * @return void
	 */
	public function test_abilities_are_built_before_they_are_registered(): void {
		$plugin = $this->plugin_after_init( false );

		$this->assertSame( 5, has_action( 'wp_abilities_api_init', [ $plugin, 'build_abilities' ] ) );
		$this->assertSame( 10, has_action( 'wp_abilities_api_init', [ $this->manager_of( $plugin ), 'register_abilities' ] ) );
	}

	/**
	 * When something read the registry earlier in `init`, the hook has passed,
	 * so the manager is filled straight away for labels and counts.
	 *
	 * @return void
	 */
	public function test_a_registry_read_earlier_in_init_still_fills_the_manager(): void {
		$plugin = $this->plugin_after_init( true );

		$this->assertNotNull( $this->manager_of( $plugin )->get_label( 'albert/create-post' ) );
	}

	/**
	 * Before `init` there is no manager.
	 *
	 * @return void
	 */
	public function test_there_is_no_manager_before_init(): void {
		$this->assertNull( ( new Plugin() )->get_abilities_manager() );
	}

	/**
	 * The booted plugin's abilities reached both the manager and the registry.
	 *
	 * @return void
	 */
	public function test_the_booted_plugin_holds_and_registers_its_abilities(): void {
		$manager = Plugin::get_instance()->get_abilities_manager();

		$this->assertInstanceOf( AbilitiesManager::class, $manager );
		$this->assertNotNull( $manager->get_label( 'albert/create-post' ) );
		$this->assertTrue( wp_has_ability( 'albert/create-post' ) );
	}
}
