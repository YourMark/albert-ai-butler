<?php
/**
 * Integration test: Albert's abilities declare the capability they require.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Core;

use Albert\Core\AbilitiesRegistry;
use Albert\Tests\TestCase;

/**
 * Declared capability tests.
 *
 * @covers \Albert\Core\AbilitiesRegistry::resolve_required_capability
 * @covers \Albert\Abstracts\BaseAbility::get_capability
 */
class DeclaredCapabilityTest extends TestCase {

	/**
	 * Every registered Albert ability shows a capability, so none reads as Not declared.
	 *
	 * @return void
	 */
	public function test_every_albert_ability_declares_a_capability(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		$checked = 0;

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! str_starts_with( $ability->get_name(), 'albert/' ) ) {
				continue;
			}

			++$checked;
			$this->assertNotSame( '', AbilitiesRegistry::resolve_required_capability( $ability ), $ability->get_name() );
		}

		$this->assertGreaterThan( 0, $checked );
	}

	/**
	 * The capability shown is the one the permission check requires.
	 *
	 * @return void
	 */
	public function test_shown_capability_is_the_checked_one(): void {
		$ability = wp_get_ability( 'albert/create-term' );

		if ( ! $ability ) {
			$this->markTestSkipped( 'albert/create-term is not registered.' );
		}

		$user = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $user );
		$this->assertSame( 'manage_categories', AbilitiesRegistry::resolve_required_capability( $ability ) );
		$this->assertInstanceOf( \WP_Error::class, $ability->check_permissions( [] ) );

		wp_get_current_user()->add_cap( 'manage_categories' );
		$this->assertTrue( $ability->check_permissions( [] ) );
	}
}
