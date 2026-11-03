<?php
/**
 * Integration tests for the find-roles ability.
 *
 * Real roles, including a custom one, and real user counts.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Users\FindRoles;
use Albert\SafeMode\Gate;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Find roles ability tests.
 */
class FindRolesAbilityTest extends TestCase {

	/**
	 * Run as an administrator, with two editors and a plugin-style role.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );

		self::factory()->user->create_many( 2, [ 'role' => 'editor' ] );
		add_role(
			'albert_booking_manager',
			'Booking Manager',
			[
				'read'            => true,
				'manage_bookings' => true,
				'export_bookings' => true,
			]
		);
	}

	/**
	 * Remove the custom role.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_role( 'albert_booking_manager' );
		parent::tear_down();
	}

	/**
	 * Every role is listed with a summary, its user count and whether assigning it is held.
	 *
	 * @return void
	 */
	public function test_lists_roles_with_summaries(): void {
		$result = ( new FindRoles() )->execute( [] );

		$this->assertIsArray( $result );
		$roles = array_column( $result['roles'], null, 'slug' );

		$this->assertSame( 'Editor', $roles['editor']['name'] );
		$this->assertSame( 2, $roles['editor']['user_count'] );
		$this->assertStringContainsString( 'everyone\'s posts and pages', $roles['editor']['summary'] );
		$this->assertFalse( $roles['editor']['assigning_is_held'] );
		$this->assertArrayNotHasKey( 'capabilities', $roles['editor'], 'The full list is only sent for one role.' );

		$this->assertStringContainsString( 'change site settings', $roles['administrator']['summary'] );
		$this->assertTrue( $roles['administrator']['assigning_is_held'] );

		$this->assertSame( 'Can write posts, but not publish them.', $roles['contributor']['summary'] );
		$this->assertSame( 'Can only read the site and edit their own profile.', $roles['subscriber']['summary'] );
		$this->assertStringContainsString( 'Has 2 capabilities', $roles['albert_booking_manager']['summary'] );

		$this->assertSame( get_option( 'default_role' ), $result['default_role'] );
	}

	/**
	 * Naming a role returns it alone, with every capability.
	 *
	 * @return void
	 */
	public function test_one_role_lists_its_capabilities(): void {
		$result = ( new FindRoles() )->execute( [ 'slug' => 'albert_booking_manager' ] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['roles'] );
		$this->assertSame( [ 'export_bookings', 'manage_bookings', 'read' ], $result['roles'][0]['capabilities'] );
		$this->assertSame( 3, $result['roles'][0]['capability_count'] );
	}

	/**
	 * An unknown role is not found, and the error names the roles that exist.
	 *
	 * @return void
	 */
	public function test_unknown_role_is_not_found(): void {
		$result = ( new FindRoles() )->execute( [ 'slug' => 'wizard' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'role_not_found', $result->get_error_code() );
		$this->assertStringContainsString( 'editor', $result->get_error_message() );
	}

	/**
	 * Users who may not list users cannot list roles.
	 *
	 * @return void
	 */
	public function test_editors_are_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertInstanceOf( WP_Error::class, ( new FindRoles() )->check_permission() );
	}

	/**
	 * A read, so safe mode never holds it.
	 *
	 * @return void
	 */
	public function test_safe_mode_passes_it(): void {
		$ability = wp_get_ability( 'albert/find-roles' );

		$this->assertNotNull( $ability );
		$this->assertFalse( ( new Gate() )->must_gate( $ability, [ 'slug' => 'administrator' ] ) );
	}
}
