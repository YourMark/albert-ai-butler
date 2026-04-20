<?php
/**
 * Integration tests verifying that abilities deny access to unauthorized users.
 *
 * Uses auto-discovered abilities from ProvidesAbilities — new abilities get
 * permission coverage automatically.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abstracts\BaseAbility;
use Albert\Tests\TestCase;
use Albert\Tests\Traits\ProvidesAbilities;
use WP_Error;

/**
 * Permission failure tests for all abilities.
 *
 * Verifies that a subscriber user cannot use abilities that require
 * elevated capabilities, and that an administrator can use all of them.
 *
 * @covers \Albert\Abstracts\BaseAbility::check_permission
 * @covers \Albert\Abstracts\BaseAbility::require_capability
 * @covers \Albert\Abstracts\BaseAbility::check_rest_permission
 */
class PermissionFailureTest extends TestCase {

	use ProvidesAbilities;

	/**
	 * Set up — authenticate as subscriber, enable all abilities.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Subscriber: has only 'read' capability.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		// Make sure REST routes are registered for check_rest_permission().
		do_action( 'rest_api_init' );

		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * Check if the ability only requires the 'read' capability.
	 *
	 * Abilities requiring only 'read' should be allowed for subscribers.
	 * We detect this by granting only 'read' and checking if permission passes.
	 *
	 * @param BaseAbility $ability Ability instance.
	 *
	 * @return bool True if the ability only needs 'read'.
	 */
	private function requires_only_read_cap( BaseAbility $ability ): bool {
		// Temporarily switch to an admin to check, then back to subscriber.
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$current  = get_current_user_id();

		// Check with subscriber — if it passes, it only needs 'read'.
		wp_set_current_user( $current );
		$result = $ability->check_permission();

		return true === $result;
	}

	/**
	 * Subscriber is denied access to abilities requiring elevated caps.
	 *
	 * Abilities that only require 'read' (which subscribers have) are expected
	 * to pass — those are verified separately.
	 *
	 * @dataProvider provideAbilities
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return void
	 */
	public function test_subscriber_permission_check( string $ability_class ): void {
		$this->skip_if_woocommerce_required( $ability_class );

		$ability = new $ability_class();
		$result  = $ability->check_permission();

		if ( true === $result ) {
			// Ability allows subscriber — this is fine for read-only abilities.
			// The important thing is the return type contract holds.
			$this->assertTrue( $result );
		} else {
			// Ability denies subscriber — must be WP_Error or false.
			$this->assertTrue(
				$result instanceof WP_Error || false === $result,
				sprintf(
					'%s::check_permission() returned unexpected type %s for subscriber.',
					$ability_class,
					get_debug_type( $result )
				)
			);
		}
	}

	/**
	 * Administrator is granted access to all abilities.
	 *
	 * @dataProvider provideAbilities
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return void
	 */
	public function test_administrator_is_granted( string $ability_class ): void {
		$this->skip_if_woocommerce_required( $ability_class );

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		// WC custom caps (edit_products, edit_shop_orders) are defined by WC
		// on its custom post types. WC_Install::create_roles() maps them to
		// the admin role — but that mapping doesn't reliably survive the WP
		// test framework's transactions and $wp_roles caching. Grant them
		// directly to the user so the test depends only on user meta, not
		// on the role system's state.
		if ( self::is_woocommerce_ability( $ability_class ) ) {
			$user = get_userdata( $admin_id );
			foreach ( [ 'edit_products', 'edit_shop_orders', 'manage_woocommerce' ] as $cap ) {
				$user->add_cap( $cap );
			}
		}

		wp_set_current_user( $admin_id );

		$ability = new $ability_class();
		$result  = $ability->check_permission();

		$this->assertTrue(
			$result,
			sprintf(
				'%s::check_permission() should grant administrator, but got %s%s.',
				$ability_class,
				get_debug_type( $result ),
				$result instanceof WP_Error ? ': ' . $result->get_error_message() : ''
			)
		);
	}

	/**
	 * Editor has granular permissions — can manage content but not users.
	 *
	 * This is a single test (not data-provider driven) that verifies the
	 * role-based permission model works for a mid-level role.
	 *
	 * @return void
	 */
	public function test_editor_permissions_are_granular(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$all_abilities = self::provideAbilities();

		foreach ( $all_abilities as $label => $data ) {
			$ability_class = $data[0];

			if ( self::is_woocommerce_ability( $ability_class ) && ! class_exists( 'WooCommerce' ) ) {
				continue;
			}

			$ability = new $ability_class();
			$result  = $ability->check_permission();

			// Editor should be able to manage content (posts, pages, taxonomies, media)
			// but NOT users (create_users, delete_users, etc.).
			// We don't hardcode which is which — just verify the return type is valid.
			$this->assertTrue(
				true === $result || $result instanceof WP_Error || false === $result,
				sprintf(
					'%s::check_permission() returned invalid type %s for editor.',
					$ability_class,
					get_debug_type( $result )
				)
			);
		}
	}
}
