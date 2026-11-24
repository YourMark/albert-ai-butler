<?php
/**
 * MCP Tool Call Observer
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.2.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Core\AbilitiesRegistry;
use Albert\MCP\Server;
use WP_Ability;

/**
 * Rewrites an input rejection inside `execute-ability`'s result into guidance
 * the assistant can act on.
 *
 * Core's message names one fault and stops, in translated prose. The guidance
 * is read off the ability's schema and the caller's input instead, so it names
 * every missing and unrecognised parameter in any locale. Logging is not done
 * here: {@see \Albert\Logging\ExecutionObserver} owns every call's row.
 *
 * @since 1.2.0
 * @since 1.6.0 Only rewrites; no longer logs.
 */
class ToolCallObserver {

	/**
	 * Register the adapter result filter.
	 *
	 * Five arguments for the fifth, the server: this filter is global, see
	 * {@see Server::is_albert_server()}.
	 *
	 * @return void
	 * @since 1.2.0
	 * @since 1.4.0 Takes the McpTool and McpServer arguments.
	 */
	public function register_hooks(): void {
		add_filter( 'mcp_adapter_tool_call_result', [ $this, 'handle' ], 10, 5 );
	}

	/**
	 * Improve a failed `execute-ability` result on Albert's server.
	 *
	 * Albert's server lists only the adapter's meta-tools, so anything else is
	 * another server's call and passes through.
	 *
	 * @param mixed  $result    The tool execution result.
	 * @param mixed  $args      The tool arguments used.
	 * @param string $tool_name The tool name that was called.
	 * @param mixed  $mcp_tool  The adapter's tool instance (unused).
	 * @param mixed  $server    The MCP server firing the filter.
	 *
	 * @return mixed The (possibly improved) result.
	 * @since 1.2.0
	 * @since 1.4.0 Ignores servers that are not Albert's own.
	 * @since 1.6.0 Only meta-tool results; no longer logs.
	 */
	public function handle( $result, $args, string $tool_name, $mcp_tool = null, $server = null ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( ! Server::is_albert_server( $server ) || ! AbilitiesRegistry::is_transport_ability( $tool_name ) ) {
			return $result;
		}

		return $this->improve_proxied_result( $result, is_array( $args ) ? $args : [] );
	}

	/**
	/**
	 * Improve the validation message carried inside a meta-tool result.
	 *
	 * `mcp-adapter/execute-ability` reports failure as
	 * `[ 'success' => false, 'error' => <message> ]`, without an error code.
	 * Re-validate instead: core validates before permissions and before the
	 * callback, so input that fails the schema is necessarily what is being
	 * reported. Input that passes is some other failure, left untouched.
	 *
	 * @param mixed                $result The raw meta-tool result.
	 * @param array<string, mixed> $args   The meta-tool arguments.
	 *
	 * @return mixed The result, with a clearer `error` when one applies.
	 * @since 1.4.0
	 */
	private function improve_proxied_result( $result, array $args ) {
		if ( ! is_array( $result ) || ( $result['success'] ?? null ) !== false ) {
			return $result;
		}

		// The meta-tool is told which ability to call, so the caller's own
		// argument is the ability id — no unpicking of a tool name required.
		$ability = $this->get_ability(
			isset( $args['ability_name'] ) && is_string( $args['ability_name'] ) ? $args['ability_name'] : ''
		);
		if ( $ability === null ) {
			return $result;
		}

		$supplied = isset( $args['parameters'] ) && is_array( $args['parameters'] ) ? $args['parameters'] : [];
		if ( $this->validation_reason( $ability, $supplied ) === null ) {
			return $result;
		}

		$improved = $this->build_validation_message( $ability, $supplied );
		if ( $improved !== '' ) {
			$result['error'] = $improved;
		}

		return $result;
	}

	/**
	 * Why the ability's schema rejects this input, or null if it does not.
	 *
	 * The same function `WP_Ability::validate_input()` calls, so the answer is
	 * core's rather than a reading of core's prose, and the reason comes back
	 * bare rather than wrapped in `Ability "X" has invalid input. Reason: …`.
	 *
	 * @param WP_Ability           $ability  The ability to validate against.
	 * @param array<string, mixed> $supplied The input the caller sent.
	 *
	 * @return string|null The validator's reason, or null when the input is valid.
	 * @since 1.4.0
	 */
	private function validation_reason( WP_Ability $ability, array $supplied ): ?string {
		if ( ! function_exists( 'rest_validate_value_from_schema' ) ) {
			return null;
		}

		$schema = $ability->get_input_schema();
		if ( $schema === [] ) {
			return null;
		}

		$valid = rest_validate_value_from_schema( $supplied, $schema, 'input' );
		if ( ! is_wp_error( $valid ) ) {
			return null;
		}

		return trim( (string) $valid->get_error_message() );
	}

	/**
	 * Turn a rejected input into actionable guidance.
	 *
	 * The validator names what is missing and stops: `{"term_id": 76}` on an
	 * ability taking `id` heard only that `id` was required, never that
	 * `term_id` was not a parameter at all. Both halves come from the schema and
	 * the input, never the validator's sentence; only a value fault falls back
	 * to its reason, as opaque text that is never matched on.
	 *
	 * Unrecognised names need a schema closed with `additionalProperties:
	 * false` — on an open schema an undeclared key is legal.
	 *
	 * @param WP_Ability           $ability  The ability the call was for.
	 * @param array<string, mixed> $supplied The input the caller sent.
	 *
	 * @return string A concise, LLM-friendly message (empty if there is nothing to say).
	 * @since 1.2.0
	 * @since 1.4.0 Names unrecognised input and the accepted parameters.
	 */
	private function build_validation_message( WP_Ability $ability, array $supplied ): string {
		$schema     = $ability->get_input_schema();
		$properties = $this->get_input_properties( $schema );

		$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : [];
		$missing  = array_values( array_diff( $required, array_keys( $supplied ) ) );

		// Walked, not diffed at the root: a nested object refuses its own keys,
		// and core's message drops the path, so `billing.company` would arrive as
		// a bare "company" the caller cannot place.
		$closed       = ( $schema['additionalProperties'] ?? null ) === false;
		$unrecognised = $this->unrecognised_keys( $supplied, $schema );

		// Minus every name reported below, at any depth, so the reason describes
		// a wrong *value* rather than restating a wrong name. Core stops at
		// whichever it hit first; this reports both.
		$reason = $this->validation_reason( $ability, $this->without( $supplied, $unrecognised ) );

		$lines = [];

		if ( $missing !== [] ) {
			$lines[] = sprintf(
				count( $missing ) === 1
					/* translators: %s: parameter name */
					? __( 'Missing required parameter: %s.', 'albert-ai-butler' )
					/* translators: %s: comma-separated list of parameter names */
					: __( 'Missing required parameters: %s.', 'albert-ai-butler' ),
				$this->name_list( $missing )
			);
		} elseif ( $reason !== null && $reason !== '' ) {
			// Nothing is missing, so the fault is in a value rather than a name.
			// Only the validator can say what is wrong with it.
			$lines[] = sprintf(
				/* translators: %s: the validator's reason */
				__( 'Invalid parameters: %s', 'albert-ai-butler' ),
				rtrim( $reason, '.' ) . '.'
			);
		}

		if ( $unrecognised !== [] ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated list of parameter names */
				__( 'Unrecognised parameters: %s.', 'albert-ai-butler' ),
				$this->name_list( $unrecognised )
			);
		}

		// Name the accepted parameters of the object that did the refusing, not
		// the root's, or the caller is handed a list its own mistake is not in.
		$nested = $this->deepest_offending_object( $unrecognised, $schema );

		if ( $lines !== [] && $nested !== null ) {
			$lines[] = sprintf(
				/* translators: 1: parameter name, 2: comma-separated list of accepted parameters */
				__( 'Accepted parameters for `%1$s`: %2$s.', 'albert-ai-butler' ),
				$nested['path'],
				implode( ', ', $nested['properties'] )
			);
		} elseif ( $lines !== [] && $properties !== [] ) {
			$lines[] = sprintf(
				/* translators: %s: comma-separated list of accepted parameters */
				__( 'Accepted parameters: %s.', 'albert-ai-butler' ),
				implode( ', ', $properties )
			);
		} elseif ( $lines !== [] && $closed ) {
			$lines[] = __( 'This ability accepts no parameters.', 'albert-ai-butler' );
		}

		return implode( ' ', $lines );
	}

	/**
	 * Every supplied key a closed schema does not declare, with its path.
	 *
	 * Walks objects and arrays of objects so a key refused three levels down is
	 * named `blocks[0].bogus` rather than `bogus`. Only closed schemas are
	 * reported: elsewhere an undeclared key is legal.
	 *
	 * @param mixed                $value  The input at this level.
	 * @param array<string, mixed> $schema The schema at this level.
	 * @param string               $path   The path walked so far.
	 *
	 * @return array<int, string> Dotted paths of the unrecognised keys.
	 * @since 1.4.0
	 */
	private function unrecognised_keys( $value, array $schema, string $path = '' ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		if ( ( $schema['type'] ?? null ) === 'array' ) {
			$items = $this->as_array( $schema['items'] ?? [] );
			$found = [];
			foreach ( $value as $index => $item ) {
				$found = array_merge( $found, $this->unrecognised_keys( $item, $items, $path . '[' . $index . ']' ) );
			}

			return $found;
		}

		if ( ( $schema['type'] ?? null ) !== 'object' ) {
			return [];
		}

		$properties = $this->as_array( $schema['properties'] ?? [] );
		$closed     = ( $schema['additionalProperties'] ?? null ) === false;

		$found = [];
		foreach ( $value as $key => $sub ) {
			$here = $path === '' ? (string) $key : $path . '.' . $key;

			if ( ! array_key_exists( $key, $properties ) ) {
				if ( $closed ) {
					$found[] = $here;
				}
				continue;
			}

			$found = array_merge( $found, $this->unrecognised_keys( $sub, $this->as_array( $properties[ $key ] ), $here ) );
		}

		return $found;
	}

	/**
	 * The input with the given paths removed, however deeply they are nested.
	 *
	 * @param array<array-key, mixed> $value The input to prune.
	 * @param array<int, string>      $paths Dotted/indexed paths to remove.
	 *
	 * @return array<array-key, mixed> The pruned input.
	 * @since 1.4.0
	 */
	private function without( array $value, array $paths ): array {
		foreach ( $paths as $path ) {
			$split = preg_split( '/\.|(?=\[)/', $path );
			$steps = array_values( array_filter( is_array( $split ) ? $split : [] ) );
			$last  = array_pop( $steps );
			if ( $last === null ) {
				continue;
			}

			$cursor = &$value;
			foreach ( $steps as $step ) {
				$key = str_starts_with( $step, '[' ) ? (int) trim( $step, '[]' ) : $step;
				if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
					continue 2;
				}
				$cursor = &$cursor[ $key ];
			}

			if ( is_array( $cursor ) ) {
				unset( $cursor[ str_starts_with( $last, '[' ) ? (int) trim( $last, '[]' ) : $last ] );
			}
			unset( $cursor );
		}

		return $value;
	}

	/**
	 * The nested object an unrecognised key was refused by, if it was nested.
	 *
	 * @param array<int, string>   $unrecognised Paths of the unrecognised keys.
	 * @param array<string, mixed> $schema       The root input schema.
	 *
	 * @return array{path: string, properties: array<string, string>}|null The object, or null at the root.
	 * @since 1.4.0
	 */
	private function deepest_offending_object( array $unrecognised, array $schema ): ?array {
		$parents = [];
		foreach ( $unrecognised as $found ) {
			$parent = preg_replace( '/(\.[^.\[]+|\[\d+\])$/', '', $found );

			// A root-level offender needs the root's list, so once one is present
			// the nested list alone would leave it unexplained.
			if ( ! is_string( $parent ) || $parent === '' || $parent === $found ) {
				return null;
			}

			// Indices are dropped when grouping: `blocks[0]` and `blocks[1]` are
			// different inputs but the same schema node, so one list covers both.
			$parents[ (string) preg_replace( '/\[\d+\]/', '[]', $parent ) ] = true;
		}

		// Only when every offender shares one parent: two lists confuse more
		// than they help, and the root list below still names the top level.
		if ( count( $parents ) !== 1 ) {
			return null;
		}

		$path   = (string) array_key_first( $parents );
		$cursor = $schema;
		$split  = preg_split( '/\.|(?=\[)/', $path );
		foreach ( is_array( $split ) ? $split : [] as $step ) {
			if ( $step === '' ) {
				continue;
			}

			$cursor = str_starts_with( $step, '[' )
				? $this->as_array( $cursor['items'] ?? [] )
				: $this->as_array( $this->as_array( $cursor['properties'] ?? [] )[ $step ] ?? [] );
		}

		$properties = $this->get_input_properties( $cursor );

		return $properties === [] ? null : [
			'path'       => $path,
			'properties' => $properties,
		];
	}

	/**
	 * Describe a schema's accepted input properties, keyed by name.
	 *
	 * Read from the registered schema, never rebuilt, so the list cannot describe
	 * something the validator does not enforce.
	 *
	 * @param array<string, mixed> $schema The registered input schema.
	 *
	 * @return array<string, string> Property name => rendered description.
	 * @since 1.4.0
	 */
	private function get_input_properties( array $schema ): array {
		$properties = $this->as_array( $schema['properties'] ?? [] );
		$required   = $this->as_array( $schema['required'] ?? [] );

		$described = [];
		foreach ( $properties as $name => $definition ) {
			$definition = $this->as_array( $definition );

			$type = $definition['type'] ?? '';
			$type = is_array( $type ) ? implode( '|', $type ) : (string) $type;

			// An enum beats the type it narrows: `published` for `publish` is not
			// fixed by being told the parameter is a string.
			$enum = $this->as_array( $definition['enum'] ?? [] );

			$notes = array_filter(
				[
					$enum === [] ? $type : implode( '|', array_map( 'strval', $enum ) ),
					in_array( $name, $required, true ) ? __( 'required', 'albert-ai-butler' ) : '',
				]
			);

			$described[ (string) $name ] = $notes === []
				? '`' . $name . '`'
				: sprintf( '`%s` (%s)', $name, implode( ', ', $notes ) );
		}

		return $described;
	}

	/**
	 * Read a schema node that may be an array or, for an empty map, a stdClass.
	 *
	 * A no-parameter ability may declare `properties` as `new stdClass()` so it
	 * encodes as `{}`; reading that as "nothing to describe" silenced the one
	 * case where "this takes nothing" is the whole answer.
	 *
	 * @param mixed $value The schema node.
	 *
	 * @return array<array-key, mixed> The node as an array, empty when it is neither.
	 * @since 1.4.0
	 */
	private function as_array( $value ): array {
		if ( is_object( $value ) ) {
			return get_object_vars( $value );
		}

		return is_array( $value ) ? $value : [];
	}

	/**
	 * Render parameter names as a backticked, comma-separated list.
	 *
	 * @param array<int|string, mixed> $names The names to render.
	 *
	 * @return string The rendered list.
	 * @since 1.4.0
	 */
	private function name_list( array $names ): string {
		return implode(
			', ',
			array_map(
				static function ( $name ): string {
					return '`' . (string) $name . '`';
				},
				array_values( $names )
			)
		);
	}

	/**
	 * Look an ability up by its exact registered id.
	 *
	 * `wp_has_ability()` is the quiet check; `wp_get_ability()` complains about
	 * an id it does not know, so it is never called blind.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return WP_Ability|null The ability, or null when it is not registered.
	 * @since 1.4.0
	 */
	private function get_ability( string $ability_name ): ?WP_Ability {
		if ( $ability_name === '' || ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability_name ) ) {
			return null;
		}

		return wp_get_ability( $ability_name );
	}
}
