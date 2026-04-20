<?php
/**
 * Integration tests for input validation through WP_Ability::execute().
 *
 * Tests that WordPress core's input validation (via rest_validate_value_from_schema)
 * properly rejects missing required fields and wrong types. Uses the auto-discovered
 * ability list from ProvidesAbilities — new abilities get coverage automatically.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abstracts\BaseAbility;
use Albert\Tests\TestCase;
use Albert\Tests\Traits\ProvidesAbilities;
use WP_Error;

/**
 * Input validation tests — fully dynamic, no hardcoded ability lists.
 *
 * @since 1.1.0
 */
class InputValidationTest extends TestCase {

	use ProvidesAbilities;

	/**
	 * Every test runs as an authenticated administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * Get the registered WP_Ability for a given class, or skip.
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return \WP_Ability The registered ability.
	 */
	private function get_registered_ability( string $ability_class ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'wp_get_ability() not available.' );
		}

		$this->skip_if_woocommerce_required( $ability_class );

		$instance = new $ability_class();
		$ability  = wp_get_ability( $instance->get_id() );

		if ( ! $ability ) {
			$this->markTestSkipped( $instance->get_id() . ' is not registered.' );
		}

		return $ability;
	}

	/**
	 * Missing required fields return WP_Error with ability_invalid_input code.
	 *
	 * Reads each ability's input_schema to find required fields, then tests
	 * each one by calling execute() with that field omitted.
	 *
	 * @dataProvider provideAbilities
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return void
	 */
	public function test_missing_required_fields_return_wp_error( string $ability_class ): void {
		$ability = $this->get_registered_ability( $ability_class );
		$schema  = $ability->get_input_schema();

		$required = $schema['required'] ?? [];

		if ( empty( $required ) ) {
			$this->expectNotToPerformAssertions();
			return;
		}

		$properties = $schema['properties'] ?? [];

		foreach ( $required as $field ) {
			$args = $this->build_valid_args_from_schema( $properties, $required, $field );

			$result = $ability->execute( $args );

			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				sprintf(
					'Expected WP_Error when missing required field "%s" for %s.',
					$field,
					$ability->get_name()
				)
			);

			$this->assertSame(
				'ability_invalid_input',
				$result->get_error_code(),
				sprintf(
					'Expected error code "ability_invalid_input" for missing "%s" in %s.',
					$field,
					$ability->get_name()
				)
			);
		}
	}

	/**
	 * Wrong types return WP_Error with ability_invalid_input code.
	 *
	 * Reads each ability's input_schema properties, and for each typed field,
	 * passes a value of the wrong type.
	 *
	 * @dataProvider provideAbilities
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return void
	 */
	public function test_wrong_types_return_wp_error( string $ability_class ): void {
		$ability = $this->get_registered_ability( $ability_class );
		$schema  = $ability->get_input_schema();

		$properties = $schema['properties'] ?? [];
		$required   = $schema['required'] ?? [];
		$tested     = false;

		foreach ( $properties as $field_name => $field_schema ) {
			$type        = $field_schema['type'] ?? null;
			$wrong_value = $this->get_wrong_value_for_type( $type );

			if ( null === $wrong_value ) {
				continue;
			}

			$args                = $this->build_valid_args_from_schema( $properties, $required );
			$args[ $field_name ] = $wrong_value;

			$result = $ability->execute( $args );

			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				sprintf(
					'Expected WP_Error for wrong type on "%s" (expected %s) in %s.',
					$field_name,
					$type,
					$ability->get_name()
				)
			);

			$this->assertSame(
				'ability_invalid_input',
				$result->get_error_code(),
				sprintf(
					'Expected "ability_invalid_input" for wrong type on "%s" in %s.',
					$field_name,
					$ability->get_name()
				)
			);

			$tested = true;
		}

		if ( ! $tested ) {
			$this->expectNotToPerformAssertions();
		}
	}

	/**
	 * Build valid args from schema, optionally excluding one required field.
	 *
	 * Generates a minimal set of args that satisfies all required fields
	 * (except the optionally excluded one) using type-appropriate values.
	 *
	 * @param array<string, array<string, mixed>> $properties Schema properties.
	 * @param array<int, string>                  $required   Required field names.
	 * @param string|null                         $exclude    Field to exclude.
	 *
	 * @return array<string, mixed>
	 */
	private function build_valid_args_from_schema( array $properties, array $required, ?string $exclude = null ): array {
		$args = [];

		foreach ( $required as $field ) {
			if ( $field === $exclude ) {
				continue;
			}

			$type           = $properties[ $field ]['type'] ?? 'string';
			$args[ $field ] = $this->get_valid_value_for_type( $type );
		}

		return $args;
	}

	/**
	 * Get a valid value for a given JSON Schema type.
	 *
	 * @param string $type JSON Schema type.
	 *
	 * @return mixed
	 */
	private function get_valid_value_for_type( string $type ) {
		return match ( $type ) {
			'integer' => 1,
			'string'  => 'test-value',
			'boolean' => true,
			'array'   => [],
			'number'  => 1.0,
			default   => 'test',
		};
	}

	/**
	 * Get a value that violates the given JSON Schema type.
	 *
	 * Returns null for types we can't meaningfully test (e.g. 'object' or
	 * unknown types).
	 *
	 * @param string|null $type JSON Schema type.
	 *
	 * @return mixed Value of wrong type, or null to skip.
	 */
	private function get_wrong_value_for_type( ?string $type ) {
		return match ( $type ) {
			'integer' => 'not-a-number',
			'string'  => [ 'not', 'a', 'string' ],
			'boolean' => 'not-a-bool',
			default   => null,
		};
	}
}
