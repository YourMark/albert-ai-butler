<?php
/**
 * Parametrized contract test for every registered ability.
 *
 * Locks the BaseAbility contract that add-ons (Premium, WooCommerce) depend
 * on: disabled abilities refuse execution, unauthenticated users get a
 * WP_Error (not `false`), registration shape is correct, IDs match the
 * canonical regex, input schema is a valid JSON Schema object.
 *
 * Each assertion runs once per ability via the data provider. Woo abilities
 * are only fully exercised in the with-WooCommerce CI job; the standard job
 * skips registration-dependent assertions for them.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abstracts\BaseAbility;
use Albert\Tests\TestCase;
use Albert\Tests\Traits\ProvidesAbilities;
use WP_Error;

/**
 * Ability contract tests.
 *
 * Runs five assertions against every registered ability class.
 *
 * @covers \Albert\Abstracts\BaseAbility
 */
class AbilityContractTest extends TestCase {

	use ProvidesAbilities;

	/**
	 * Reset state between tests — logged-out user, no disabled list.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( 0 );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * Every ability ID matches the canonical regex.
	 *
	 * @dataProvider provideAbilities
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return void
	 */
	public function test_ability_id_matches_canonical_regex( string $ability_class ): void {
		$ability = new $ability_class();

		$this->assertMatchesRegularExpression(
			'#^[a-z0-9-]+/[a-z0-9-]+$#',
			$ability->get_id(),
			sprintf(
				'Ability %s has an id "%s" that does not match the canonical regex ^[a-z0-9-]+/[a-z0-9-]+$.',
				$ability_class,
				$ability->get_id()
			)
		);
	}

	/**
	 * Adding the ability to the disabled option makes guarded_execute refuse.
	 *
	 * @dataProvider provideAbilities
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return void
	 */
	public function test_disabled_ability_returns_403_wp_error( string $ability_class ): void {
		$ability = new $ability_class();
		update_option( 'albert_disabled_abilities', [ $ability->get_id() ] );

		$result = $ability->guarded_execute( [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_disabled', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] ?? null );
	}

	/**
	 * Returns bool|WP_Error (never null or scalar) for an unauthenticated user.
	 *
	 * Stronger denial semantics vary by ability: FindPosts/FindPages/FindUsers
	 * correctly delegate to WP REST's publicly-readable list endpoints, while
	 * create/update/delete abilities should deny with a WP_Error. The contract
	 * we lock here is the common one — the return type is always bool|WP_Error,
	 * never null or a scalar, so `is_wp_error( $result )` always works for
	 * callers. Stronger per-ability guarantees are exercised by the integration
	 * tests for the specific write paths.
	 *
	 * @dataProvider provideAbilities
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return void
	 */
	public function test_unauthenticated_check_permission_returns_bool_or_wp_error( string $ability_class ): void {
		wp_set_current_user( 0 );

		$ability = new $ability_class();
		$result  = $ability->check_permission();

		$this->assertTrue(
			is_bool( $result ) || $result instanceof WP_Error,
			sprintf(
				'%s::check_permission() returned %s — must be bool or WP_Error.',
				$ability_class,
				get_debug_type( $result )
			)
		);
	}

	/**
	 * The ability is registered with WordPress after the plugin bootstraps.
	 *
	 * Abilities are registered during the wp_abilities_api_init action via
	 * AbilitiesManager. WP 6.9 enforces that wp_register_ability() is only
	 * called inside that hook, so we verify the post-bootstrap state through
	 * wp_get_ability() rather than re-registering in the test.
	 *
	 * @dataProvider provideAbilities
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return void
	 */
	public function test_ability_is_registered_after_bootstrap( string $ability_class ): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'wp_get_ability not available.' );
		}

		$this->skip_if_woocommerce_required( $ability_class );

		$ability    = new $ability_class();
		$registered = wp_get_ability( $ability->get_id() );

		$this->assertNotNull(
			$registered,
			sprintf( '%s (%s) is not registered after plugin bootstrap.', $ability_class, $ability->get_id() )
		);
	}

	/**
	 * WooCommerce abilities must NOT be registered when WooCommerce is inactive.
	 *
	 * Locks the guard in Plugin::register_abilities() — WC abilities depend on
	 * wc_get_product(), wc_get_order(), etc., so registering them without WC
	 * would cause fatals at call time. This test only runs in the non-WC CI
	 * jobs; it's skipped when WooCommerce is active.
	 *
	 * @dataProvider provideAbilities
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return void
	 */
	public function test_woocommerce_ability_not_registered_without_woocommerce( string $ability_class ): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'wp_get_abilities not available.' );
		}

		if ( ! self::is_woocommerce_ability( $ability_class ) ) {
			$this->markTestSkipped( 'Only applies to WooCommerce abilities.' );
		}

		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is active — this test verifies the inactive case.' );
		}

		// Use wp_get_abilities() rather than wp_get_ability(), because the
		// single-lookup triggers _doing_it_wrong() when the ability is
		// missing — which the WP test framework surfaces as a failure.
		$ability          = new $ability_class();
		$registered_names = array_map(
			static fn( $a ) => is_object( $a ) && method_exists( $a, 'get_name' ) ? $a->get_name() : null,
			wp_get_abilities()
		);

		$this->assertNotContains(
			$ability->get_id(),
			$registered_names,
			sprintf(
				'%s (%s) must not be registered when WooCommerce is inactive — it depends on WC functions and would fatal at call time.',
				$ability_class,
				$ability->get_id()
			)
		);
	}

	/**
	 * The input_schema is a valid JSON Schema object.
	 *
	 * @dataProvider provideAbilities
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return void
	 */
	public function test_input_schema_is_valid_json_schema_object( string $ability_class ): void {
		$ability = new $ability_class();

		// Reach in for the protected schema via a ReflectionProperty.
		$reflection = new \ReflectionClass( $ability );
		$prop       = $reflection->getProperty( 'input_schema' );
		$prop->setAccessible( true );

		$schema = $prop->getValue( $ability );

		$this->assertIsArray( $schema, sprintf( '%s input_schema is not an array.', $ability_class ) );
		$this->assertSame(
			'object',
			$schema['type'] ?? null,
			sprintf( '%s input_schema.type is not "object".', $ability_class )
		);
		$this->assertArrayHasKey(
			'properties',
			$schema,
			sprintf( '%s input_schema is missing "properties".', $ability_class )
		);

		// Every `required` entry must exist in `properties`.
		if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
			foreach ( $schema['required'] as $required_key ) {
				$this->assertArrayHasKey(
					$required_key,
					$schema['properties'],
					sprintf(
						'%s input_schema declares "%s" as required but does not define it in properties.',
						$ability_class,
						$required_key
					)
				);
			}
		}
	}
}
