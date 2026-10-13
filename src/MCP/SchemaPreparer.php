<?php
/**
 * Client-facing JSON Schema preparation at the MCP boundary.
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.4.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Support\WpCompat;

/**
 * Shapes the ability schemas Albert hands an MCP client.
 *
 * The Abilities API stores canonical, WordPress-style schemas that may carry
 * server-only keys — `sanitize_callback`, `validate_callback`, `arg_options`,
 * `readonly`. Those are meaningful to the server but noise (or worse) to a
 * client, and the adapter does not strip them itself. WordPress 7.1 added
 * `wp_prepare_json_schema_for_client()` to remove them; this class applies it to
 * the `input_schema` / `output_schema` that `get-ability-info` returns, caught on
 * the raw result via `mcp_adapter_tool_call_result`, leaving the registered
 * canonical schema (used for execution and validation) untouched.
 *
 * `tools/list` is not prepared: Albert's server lists only the adapter's three
 * meta-tools, whose schemas carry neither problem. Abilities reach the client
 * through `get-ability-info`.
 *
 * Two things happen there. Server-only key stripping runs through
 * `wp_prepare_json_schema_for_client()` and needs WordPress 7.1; below that the
 * canonical schema keeps its server-only keys, the honest degraded behaviour
 * since the function does not exist. Correcting an object schema's empty
 * `default` from `[]` to `{}` is Albert's own and runs on every version, because
 * the contradiction it fixes is one Albert put there.
 *
 * @since 1.4.0
 */
class SchemaPreparer {

	/**
	 * The adapter's get-ability-info meta-tool, as its ability is registered.
	 *
	 * The name arriving on `mcp_adapter_tool_call_result` is whatever the client
	 * sent, which is the MCP-sanitised spelling (`mcp-adapter-get-ability-info`)
	 * — a slash is not a legal character in an MCP tool name. Comparing against
	 * the slash form alone matched nothing, so the meta-tool's schemas were
	 * emitted unprepared. {@see self::is_ability_info_tool()} matches either
	 * spelling.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const ABILITY_INFO_TOOL = 'mcp-adapter/get-ability-info';

	/**
	 * Register the get-ability-info result filter.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_filter( 'mcp_adapter_tool_call_result', [ $this, 'prepare_ability_info_result' ], 10, 5 );
	}

	/**
	 * Run one schema through client preparation.
	 *
	 * `wp_prepare_json_schema_for_client()` recurses only into arrays, but a
	 * registered schema may hold `stdClass` sub-schemas, so the tree is first
	 * normalised to a pure array tree. The normalisation is a manual walk rather
	 * than a `json_encode()`/`json_decode()` round-trip: a round-trip returns
	 * `false` for a value it cannot serialise, which would make this fall back to
	 * the raw, unstripped schema. Failing open is the wrong direction for a
	 * function whose whole job is to remove server-only keys, so the walk is used
	 * instead and cannot fail.
	 *
	 * @param array<string, mixed> $schema The schema to prepare.
	 *
	 * @return array<string, mixed> The client-prepared schema.
	 * @since 1.4.0
	 */
	private function prepare_schema( array $schema ): array {
		$normalised = array_map( [ $this, 'to_array_deep' ], $schema );

		// Stripping server-only keys needs 7.1; below that they stay, since the
		// function does not exist. 6.9/7.0 fallback — removable, see WpCompat.
		// The object-default correction below runs on every version.
		if ( WpCompat::supports_client_schema_prep() ) {
			$normalised = wp_prepare_json_schema_for_client( $normalised );
		}

		return $this->objectify_object_defaults( $normalised );
	}

	/**
	 * Render an object schema's empty default as `{}` rather than `[]`.
	 *
	 * {@see \Albert\Abstracts\BaseAbility::prepare_input_schema()} gives every
	 * object-typed input schema a top-level `default` of an empty PHP array, so
	 * that a call arriving with no arguments at all is rescued into an empty
	 * object instead of failing validation as "input is not of type object".
	 * That is a server-side rescue, but PHP cannot tell an empty list from an
	 * empty map, so it reached the client as `"default": []` on a schema that
	 * says `"type": "object"` two lines up — a contradiction a strict JSON
	 * Schema consumer is entitled to complain about.
	 *
	 * The default is not dropped, because it is true: these abilities really can
	 * be called with nothing. It is restored to the object it was always meant
	 * to be. Only an *empty* default on an *object*-typed schema is touched, so
	 * an array-typed property whose default is genuinely `[]` is left alone.
	 *
	 * @param array<string, mixed> $schema The schema to correct.
	 *
	 * @return array<string, mixed> The schema with object defaults rendered as objects.
	 * @since 1.4.0
	 */
	private function objectify_object_defaults( array $schema ): array {
		foreach ( $schema as $key => $value ) {
			if ( is_array( $value ) ) {
				$schema[ $key ] = $this->objectify_object_defaults( $value );
			}
		}

		if ( ( $schema['default'] ?? null ) === [] && $this->is_object_type( $schema['type'] ?? null ) ) {
			$schema['default'] = new \stdClass();
		}

		return $schema;
	}

	/**
	 * Whether a schema's declared type is an object and nothing but an object.
	 *
	 * A union such as `[ 'object', 'array' ]` is left alone: an empty default is
	 * ambiguous there, and guessing would be worse than saying nothing.
	 *
	 * @param mixed $type The schema's `type` keyword.
	 *
	 * @return bool True when the type is exactly `object`.
	 * @since 1.4.0
	 */
	private function is_object_type( $type ): bool {
		if ( is_array( $type ) ) {
			return array_values( array_unique( $type ) ) === [ 'object' ];
		}

		return $type === 'object';
	}

	/**
	 * Recursively convert `stdClass` nodes to arrays, leaving other values as-is.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return mixed The value with any `stdClass` nodes turned into arrays.
	 * @since 1.4.0
	 */
	private function to_array_deep( $value ) {
		if ( $value instanceof \stdClass ) {
			$value = (array) $value;
		}

		return is_array( $value ) ? array_map( [ $this, 'to_array_deep' ], $value ) : $value;
	}

	/**
	 * Re-object empty JSON Schema keyword maps so they serialise as `{}`, not `[]`.
	 *
	 * The array normalisation cannot distinguish an empty object from an empty
	 * list, so a keyword like `properties: {}` becomes an empty PHP array and
	 * would render as the JSON array `[]`, which is invalid where an object is
	 * required. This restores the standard subschema-map keywords to `stdClass`
	 * when they are empty. Non-empty maps already have string keys and serialise
	 * as objects correctly, so only the empty case needs fixing.
	 *
	 * @param array<string, mixed> $schema The prepared schema.
	 *
	 * @return array<string, mixed> The schema with empty keyword maps re-objected.
	 * @since 1.4.0
	 */
	private function restore_object_maps( array $schema ): array {
		$object_map_keywords = [ 'properties', 'patternProperties', 'dependentSchemas', 'definitions', '$defs' ];

		foreach ( $schema as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			$value = $this->restore_object_maps( $value );

			$schema[ $key ] = ( $value === [] && in_array( $key, $object_map_keywords, true ) )
				? new \stdClass()
				: $value;
		}

		return $schema;
	}

	/**
	 * Prepare the schemas embedded in a get-ability-info result.
	 *
	 * Fires on the raw execution result, before the adapter wraps it into a
	 * protocol DTO, so the `input_schema` / `output_schema` keys are plain
	 * arrays returned by the meta-tool. Every other tool, and every non-array
	 * result (e.g. a WP_Error), passes through untouched.
	 *
	 * `mcp_adapter_tool_call_result` is a global hook that any adapter-based MCP
	 * server fires (including WooCommerce's bundled copy, which registers a
	 * meta-tool of the same name), so this acts only on Albert's own server.
	 *
	 * @param mixed  $result    The raw tool execution result.
	 * @param mixed  $args      The tool arguments used (unused).
	 * @param string $tool_name The tool name that was called.
	 * @param mixed  $mcp_tool  The MCP tool instance (unused).
	 * @param mixed  $server    The MCP server firing the filter.
	 *
	 * @return mixed The result with client-prepared schemas on 7.1+.
	 * @since 1.4.0
	 */
	public function prepare_ability_info_result( $result, $args, string $tool_name, $mcp_tool = null, $server = null ) {
		if ( ! Server::is_albert_server( $server ) ) {
			return $result;
		}

		if ( ! $this->is_ability_info_tool( $tool_name ) || ! is_array( $result ) ) {
			return $result;
		}

		foreach ( [ 'input_schema', 'output_schema' ] as $key ) {
			if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) ) {
				$result[ $key ] = $this->restore_object_maps( $this->prepare_schema( $result[ $key ] ) );
			}
		}

		return $result;
	}

	/**
	 * Whether a tool name refers to the get-ability-info meta-tool.
	 *
	 * Matches the sanitised spelling a client sends and the raw ability id
	 * alike, the way {@see \Albert\Core\AbilitiesRegistry::is_transport_ability()}
	 * does for the transport set.
	 *
	 * @param string $tool_name The tool name as it arrived on the filter.
	 *
	 * @return bool True when this is the get-ability-info meta-tool.
	 * @since 1.4.0
	 */
	private function is_ability_info_tool( string $tool_name ): bool {
		return str_replace( '/', '-', $tool_name ) === str_replace( '/', '-', self::ABILITY_INFO_TOOL );
	}
}
