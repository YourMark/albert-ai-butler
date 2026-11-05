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
	 * After `init` the manager exists but holds no abilities.
	 *
	 * A fresh Plugin, because the suite's singleton has already built its own.
	 *
	 * @return void
	 */
	public function test_init_builds_no_abilities(): void {
		$plugin = new Plugin();
		$plugin->register_abilities();

		$this->assertSame( [], $this->manager_of( $plugin )->get_abilities() );
	}

	/**
	 * Built before the manager registers them with WordPress, or none get registered.
	 *
	 * @return void
	 */
	public function test_abilities_are_built_before_they_are_registered(): void {
		$plugin = new Plugin();
		$plugin->register_abilities();

		$this->assertSame( 5, has_action( 'wp_abilities_api_init', [ $plugin, 'build_abilities' ] ) );
		$this->assertSame( 10, has_action( 'wp_abilities_api_init', [ $this->manager_of( $plugin ), 'register_abilities' ] ) );
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
