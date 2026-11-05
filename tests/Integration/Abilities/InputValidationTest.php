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
use Albert\Blocks\BlockReader;
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
	 * A parameter the schema does not declare is refused, not carried.
	 *
	 * The reported case: `albert/view-term` takes `id`, and a call passing
	 * `term_id` (or `fields`, or anything else) used to have the extra key
	 * carried through validation and dropped by the implementation without a
	 * word — a *successful*, unfiltered answer the caller had every reason to
	 * think had been filtered. Every ability now declares
	 * `additionalProperties: false`, so the call fails instead and the caller
	 * is told which name it used and which names exist.
	 *
	 * @dataProvider provideAbilities
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return void
	 */
	public function test_undeclared_parameters_return_wp_error( string $ability_class ): void {
		$ability = $this->get_registered_ability( $ability_class );
		$schema  = $ability->get_input_schema();

		if ( ( $schema['type'] ?? null ) !== 'object' ) {
			$this->expectNotToPerformAssertions();
			return;
		}

		$this->assertFalse(
			$schema['additionalProperties'] ?? null,
			sprintf( '%s should refuse input it does not declare.', $ability->get_name() )
		);

		$properties = $schema['properties'] ?? [];
		$required   = $schema['required'] ?? [];

		$args = $this->build_valid_args_from_schema( $properties, $required );
		$args['albert_not_a_parameter'] = 'anything';

		$result = $ability->execute( $args );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			sprintf( 'Expected WP_Error for an undeclared parameter on %s.', $ability->get_name() )
		);

		$this->assertSame(
			'ability_invalid_input',
			$result->get_error_code(),
			sprintf( 'Expected "ability_invalid_input" for an undeclared parameter on %s.', $ability->get_name() )
		);
	}

	/**
	 * A nested object that declares a contract is held to it too.
	 *
	 * `additionalProperties` governs one object and is not inherited, so closing
	 * the root closes only the parameter list. Left open, a nested object
	 * reproduces the same bug one level down: a block spec carrying `content`
	 * where the block wants `attributes.content` validated, ran, and saved an
	 * empty paragraph.
	 *
	 * A map that declares no properties is a free-form bag by design — a
	 * block's `attributes`, an item's `meta` — and is deliberately left open.
	 *
	 * @dataProvider provideAbilities
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 *
	 * @return void
	 */
	public function test_nested_object_schemas_are_closed( string $ability_class ): void {
		$ability = $this->get_registered_ability( $ability_class );
		$open    = $this->find_open_object_schemas( $ability->get_input_schema(), 'input' );

		$this->assertSame(
			[],
			$open,
			sprintf(
				'%s leaves these declared object schemas open: %s',
				$ability->get_name(),
				implode( ', ', $open )
			)
		);
	}

	/**
	 * Paths of every object subschema that declares properties but stays open.
	 *
	 * @param array<string, mixed> $schema The schema to walk.
	 * @param string               $path   The path walked so far, for the message.
	 *
	 * @return array<int, string> Paths of the open schemas.
	 */
	private function find_open_object_schemas( array $schema, string $path ): array {
		$open = [];

		if ( ( $schema['type'] ?? null ) === 'object'
			&& ! empty( $schema['properties'] )
			&& is_array( $schema['properties'] )
			&& ( $schema['additionalProperties'] ?? null ) !== false
		) {
			$open[] = $path;
		}

		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $name => $subschema ) {
				if ( is_array( $subschema ) ) {
					$open = array_merge( $open, $this->find_open_object_schemas( $subschema, $path . '.' . $name ) );
				}
			}
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$open = array_merge( $open, $this->find_open_object_schemas( $schema['items'], $path . '[]' ) );
		}

		return $open;
	}

	/**
	 * A block read from a view-* call is accepted back unchanged.
	 *
	 * The documented edit loop is read the tree, change one block, send it back.
	 * What a read returns carries `plaintext` and `path`, and the write side has
	 * always accepted `html` as well — none of which the block spec declared, so
	 * closing it without declaring them would have refused the loop it exists
	 * to serve.
	 *
	 * @return void
	 */
	public function test_a_block_spec_accepts_what_a_read_returns(): void {
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'albert/edit-post-block' ) ) {
			$this->markTestSkipped( 'albert/edit-post-block is not registered.' );
		}

		$schema = wp_get_ability( 'albert/edit-post-block' )->get_input_schema();

		// Built by running the reader, not by hand: a hand-written fixture asserts
		// the loop closes on a shape nothing produces, and would not notice
		// BlockReader's output drifting away from what the write side accepts.
		$read = ( new BlockReader() )->read(
			'<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->'
			. '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Nested</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
		);

		$this->assertNotSame( [], $read, 'The reader must produce blocks to round-trip.' );

		foreach ( $read as $block ) {
			$this->assertTrue(
				rest_validate_value_from_schema(
					[
						'id'    => 1,
						'path'  => $block['path'],
						'block' => $block,
					],
					$schema,
					'input'
				),
				'A block as a view-* read returns it must be accepted back: ' . wp_json_encode( $block )
			);
		}

		// Classic (pre-block-editor) content reads back with a null name, which
		// BlockReader preserves on purpose. The write side has to accept it or the
		// loop is broken for every post never opened in the block editor.
		$classic = ( new BlockReader() )->read( 'An old post written in the classic editor.' );

		$this->assertSame( [ null ], array_column( $classic, 'name' ) );

		$this->assertTrue(
			rest_validate_value_from_schema(
				[
					'id'    => 1,
					'path'  => [ 0 ],
					'block' => $classic[0],
				],
				$schema,
				'input'
			),
			'Classic content read by view-post must be accepted back.'
		);

		$round_trip = [
			'id'    => 1,
			'path'  => [ 0 ],
			'block' => [
				'name'        => 'core/paragraph',
				'attributes'  => [ 'content' => 'Hello' ],
				'innerBlocks' => [],
				'plaintext'   => 'Hello',
				'path'        => [ 0 ],
			],
		];

		// Free-form attributes stay free-form: a block may carry any attribute.
		$round_trip['block']['attributes'] = [ 'anythingAtAll' => [ 'nested' => true ] ];
		$this->assertTrue(
			rest_validate_value_from_schema( $round_trip, $schema, 'input' ),
			'A block attribute map declares no properties and must stay open.'
		);

		// But a key the block spec does not have is refused, not dropped.
		$mistake = [
			'id'    => 1,
			'path'  => [ 0 ],
			'block' => [
				'name'    => 'core/paragraph',
				'content' => 'Hello',
			],
		];

		$this->assertWPError(
			rest_validate_value_from_schema( $mistake, $schema, 'input' ),
			'Text at block level belongs in attributes and must be refused, not silently dropped.'
		);
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

			if ( $wrong_value === null ) {
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
