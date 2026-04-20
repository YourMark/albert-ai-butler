<?php
/**
 * JSON Schema validator for ability output testing.
 *
 * Validates PHP arrays against the JSON Schema definitions used by abilities'
 * get_output_schema() methods. Supports the subset of JSON Schema that Albert
 * abilities actually use: type checks, required fields, nested objects and
 * typed arrays.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Helpers;

/**
 * Schema validator for ability output arrays.
 *
 * @since 1.1.0
 */
class SchemaValidator {

	/**
	 * Validate a data value against a JSON Schema definition.
	 *
	 * Returns an array of human-readable error strings. An empty array means
	 * the data matches the schema.
	 *
	 * @param mixed                $data   The data to validate.
	 * @param array<string, mixed> $schema JSON Schema definition.
	 * @param string               $path   Dot-path prefix for error messages.
	 *
	 * @return array<int, string> Validation errors (empty = valid).
	 */
	public static function validate( mixed $data, array $schema, string $path = '' ): array {
		$errors = [];

		// Top-level type check.
		if ( isset( $schema['type'] ) ) {
			if ( ! self::type_matches( $data, $schema['type'] ) ) {
				$errors[] = sprintf(
					'%s: expected type "%s", got "%s".',
					$path ? $path : 'root',
					$schema['type'],
					get_debug_type( $data )
				);

				// Type mismatch at this level — deeper checks are meaningless.
				return $errors;
			}
		}

		// Object: check required fields and recurse into properties.
		if ( ( $schema['type'] ?? '' ) === 'object' && is_array( $data ) ) {
			// Required fields.
			foreach ( $schema['required'] ?? [] as $field ) {
				if ( ! array_key_exists( $field, $data ) ) {
					$errors[] = sprintf(
						'%s: missing required field "%s".',
						$path ? $path : 'root',
						$field
					);
				}
			}

			// Recurse into defined properties.
			foreach ( $schema['properties'] ?? [] as $field => $field_schema ) {
				if ( ! array_key_exists( $field, $data ) ) {
					continue;
				}

				$field_path = $path ? "{$path}.{$field}" : $field;
				$errors     = array_merge(
					$errors,
					self::validate( $data[ $field ], $field_schema, $field_path )
				);
			}
		}

		// Array: check items schema.
		if ( ( $schema['type'] ?? '' ) === 'array' && is_array( $data ) && isset( $schema['items'] ) ) {
			foreach ( $data as $index => $item ) {
				$item_path = $path ? "{$path}[{$index}]" : "[{$index}]";
				$errors    = array_merge(
					$errors,
					self::validate( $item, $schema['items'], $item_path )
				);
			}
		}

		return $errors;
	}

	/**
	 * Check if a PHP value matches a JSON Schema type.
	 *
	 * @param mixed  $value The PHP value.
	 * @param string $type  The JSON Schema type.
	 *
	 * @return bool
	 */
	private static function type_matches( mixed $value, string $type ): bool {
		return match ( $type ) {
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'array'   => is_array( $value ),
			'object'  => is_array( $value ),
			'null'    => is_null( $value ),
			default   => true,
		};
	}
}
