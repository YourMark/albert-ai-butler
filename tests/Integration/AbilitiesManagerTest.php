<?php
/**
 * AbilitiesManager integration tests.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration;

use Albert\Tests\TestCase;
use Albert\Core\AbilitiesManager;

/**
 * Test the AbilitiesManager functionality.
 */
class AbilitiesManagerTest extends TestCase {

	/**
	 * The abilities manager instance.
	 *
	 * @var AbilitiesManager
	 */
	private AbilitiesManager $manager;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->manager = new AbilitiesManager();
	}

	/**
	 * Test that the manager can be instantiated.
	 *
	 * @return void
	 */
	public function test_manager_can_be_instantiated(): void {
		$this->assertInstanceOf( AbilitiesManager::class, $this->manager );
	}

	/**
	 * Test that get_abilities returns an array.
	 *
	 * @return void
	 */
	public function test_get_abilities_returns_array(): void {
		$abilities = $this->manager->get_abilities();

		$this->assertIsArray( $abilities );
	}

	/**
	 * Test that hooks are registered correctly.
	 *
	 * @return void
	 */
	public function test_hooks_are_registered(): void {
		$this->manager->register_hooks();

		// Verify the manager registered its hooks.
		$this->assertNotFalse(
			has_action( 'abilities_api_init', [ $this->manager, 'register_abilities' ] ),
			'register_abilities should be hooked to abilities_api_init'
		);
	}
}
