<?php
/**
 * Integration tests for building abilities on the Abilities API's own hook.
 *
 * Autoloading some 50 ability classes was paid on every request, frontend
 * views included, only because they were built on `init`. They are now built
 * on `wp_abilities_api_init`, or when something asks for the manager. These
 * tests hold both halves: nothing is built early, and everything is there
 * when it is needed.
 *
 * Each test uses its own `Plugin`, not the singleton the suite booted, whose
 * abilities are already built and registered.
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
 * @covers \Albert\Core\Plugin::ensure_abilities_built
 * @covers \Albert\Core\Plugin::get_abilities_manager
 */
class DeferredAbilitiesTest extends TestCase {

	/**
	 * A plugin with its manager set up, as it is after `init`.
	 *
	 * @return Plugin
	 */
	private function plugin_after_init(): Plugin {
		$plugin = new Plugin();
		$plugin->register_abilities();

		return $plugin;
	}

	/**
	 * The manager's abilities, read without the side effect of asking for it.
	 *
	 * @param Plugin $plugin Plugin under test.
	 *
	 * @return array<string, mixed>
	 */
	private function held_abilities( Plugin $plugin ): array {
		$manager = ( new \ReflectionProperty( Plugin::class, 'abilities_manager' ) )->getValue( $plugin );

		$this->assertInstanceOf( AbilitiesManager::class, $manager );

		return ( new \ReflectionProperty( AbilitiesManager::class, 'abilities' ) )->getValue( $manager );
	}

	/**
	 * After `init` alone the manager exists but holds no abilities: the saving.
	 *
	 * @return void
	 */
	public function test_init_builds_no_abilities(): void {
		$this->assertSame( [], $this->held_abilities( $this->plugin_after_init() ) );
	}

	/**
	 * Admin screens resolve labels through the manager without the Abilities
	 * API having initialised, so asking for it must build the abilities.
	 *
	 * @return void
	 */
	public function test_the_manager_is_populated_when_asked_for(): void {
		$manager = $this->plugin_after_init()->get_abilities_manager();

		$this->assertInstanceOf( AbilitiesManager::class, $manager );
		$this->assertNotNull( $manager->get_label( 'albert/create-post' ) );
	}

	/**
	 * Before `init` there is no manager to build into, as before.
	 *
	 * @return void
	 */
	public function test_there_is_no_manager_before_init(): void {
		$this->assertNull( ( new Plugin() )->get_abilities_manager() );
	}

	/**
	 * Add-ons register on `albert/abilities/register`; it fires once however
	 * often the build is asked for.
	 *
	 * @return void
	 */
	public function test_the_register_action_fires_once(): void {
		$plugin = $this->plugin_after_init();
		$before = did_action( 'albert/abilities/register' );

		$plugin->ensure_abilities_built();
		$plugin->ensure_abilities_built();
		$plugin->get_abilities_manager();

		$this->assertSame( $before + 1, did_action( 'albert/abilities/register' ) );
	}

	/**
	 * Both init aliases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function provideInitHooks(): array {
		return [
			'wp_abilities_api_init' => [ 'wp_abilities_api_init' ],
			'abilities_api_init'    => [ 'abilities_api_init' ],
		];
	}

	/**
	 * The build must land before the manager pushes its abilities into the
	 * WordPress registry at priority 10, or nothing gets registered.
	 *
	 * Fires the hook for real with the manager's registry callbacks swapped for
	 * a probe at the same priority, since the suite's registry already holds
	 * every Albert ability and registering them again would be refused.
	 *
	 * @dataProvider provideInitHooks
	 *
	 * @param string $hook Abilities API init hook.
	 *
	 * @return void
	 */
	public function test_abilities_are_built_before_they_are_registered( string $hook ): void {
		remove_all_actions( $hook );

		$plugin  = $this->plugin_after_init();
		$manager = ( new \ReflectionProperty( Plugin::class, 'abilities_manager' ) )->getValue( $plugin );

		$this->assertSame( 5, has_action( $hook, [ $plugin, 'ensure_abilities_built' ] ) );
		$this->assertSame( 10, has_action( $hook, [ $manager, 'register_abilities' ] ) );

		remove_action( $hook, [ $manager, 'register_abilities' ] );
		remove_action( $hook, [ $manager, 'heal_transport_tools' ], 1 );
		remove_action( $hook, [ $manager, 'reconcile_new_abilities' ], PHP_INT_MAX - 1 );
		remove_action( $hook, [ $manager, 'enforce_disabled' ], PHP_INT_MAX );

		$seen = null;
		add_action(
			$hook,
			function () use ( $plugin, &$seen ): void {
				$seen = count( $this->held_abilities( $plugin ) );
			},
			10
		);

		do_action( $hook );

		$this->assertGreaterThan( 0, $seen, 'The abilities must be built by the time the manager registers them.' );
	}

	/**
	 * The booted plugin's abilities reached the WordPress registry.
	 *
	 * @return void
	 */
	public function test_the_registry_holds_albert_abilities(): void {
		if ( ! function_exists( 'wp_has_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		wp_get_abilities();

		$this->assertTrue( wp_has_ability( 'albert/create-post' ) );
	}
}
