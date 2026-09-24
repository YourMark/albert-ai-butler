<?php
/**
 * Minimal WordPress function and class stubs for unit testing.
 *
 * Provides stub implementations of WordPress functions used by Albert classes.
 * Each stub records its calls to $GLOBALS['albert_test_hooks'] so tests can
 * assert correct hook names and parameters.
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// Class stubs live in their own files so this file can stay
// functions-only (keeps PHPCS's OO/function separation rule happy).
require_once __DIR__ . '/WP_Error.php';
require_once __DIR__ . '/WP_REST_Request.php';
require_once __DIR__ . '/WP_REST_Response.php';
require_once __DIR__ . '/WP.php';
require_once __DIR__ . '/WP_Abilities_Registry.php';

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub is_wp_error that mirrors the WordPress implementation.
	 *
	 * @param mixed $thing Value to check.
	 *
	 * @return bool
	 */
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

/**
 * Global hook-call tracker.
 *
 * Each entry: [ 'type' => 'action'|'filter', 'hook' => string, 'args' => array ]
 *
 * @var array<int, array<string, mixed>>
 */
$GLOBALS['albert_test_hooks'] = [];

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Stub do_action that records calls.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Hook arguments.
	 *
	 * @throws \RuntimeException When a test has armed `albert_test_throw_on_action` for this hook.
	 */
	function do_action( string $hook_name, ...$args ): void {
		// Lets a test simulate a subscriber that throws, so guards around
		// observer dispatch can be asserted rather than assumed.
		if ( isset( $GLOBALS['albert_test_throw_on_action'] ) && $GLOBALS['albert_test_throw_on_action'] === $hook_name ) {
			throw new \RuntimeException( 'observer exploded' );
		}

		$GLOBALS['albert_test_hooks'][] = [
			'type' => 'action',
			'hook' => $hook_name,
			'args' => $args,
		];
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Stub add_action that records the registration.
	 *
	 * Recorded under `type => registration` rather than `action`, so a test
	 * asserting that a hook *fired* is never satisfied by a hook merely being
	 * *registered*. The accepted-argument count is recorded because getting it
	 * wrong is a silent failure: WordPress defaults to 1, which would hand a
	 * three-argument callback only its first argument.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback to register.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Number of arguments the callback accepts.
	 */
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['albert_test_hooks'][] = [
			'type'          => 'registration',
			'hook'          => $hook_name,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub apply_filters that records calls and returns the value unmodified.
	 *
	 * Tests can simulate a filter callback by setting
	 * $GLOBALS['albert_test_filter_returns'][$hook_name]; when that key is
	 * present the stub returns its value instead of the passed-in $value.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Additional arguments.
	 *
	 * @return mixed The (optionally overridden) value.
	 */
	function apply_filters( string $hook_name, mixed $value, ...$args ): mixed {
		$GLOBALS['albert_test_hooks'][] = [
			'type' => 'filter',
			'hook' => $hook_name,
			'args' => array_merge( [ $value ], $args ),
		];

		if ( isset( $GLOBALS['albert_test_filter_returns'][ $hook_name ] ) ) {
			return $GLOBALS['albert_test_filter_returns'][ $hook_name ];
		}

		// A callback, for filters whose result depends on the value they are
		// handed. `albert/context/site` adds a section to whatever it receives,
		// which a fixed return value cannot express.
		if ( isset( $GLOBALS['albert_test_filter_callbacks'][ $hook_name ] ) ) {
			return call_user_func_array(
				$GLOBALS['albert_test_filter_callbacks'][ $hook_name ],
				array_merge( [ $value ], $args )
			);
		}

		return $value;
	}
}

if ( ! function_exists( '_deprecated_hook' ) ) {
	/**
	 * Stub _deprecated_hook that records calls to $GLOBALS['albert_test_deprecated_hooks'].
	 *
	 * @param string $hook_name   Hook name being deprecated.
	 * @param string $version     Version the hook was deprecated in.
	 * @param string $replacement Replacement hook name.
	 * @param string $message     Optional extra message.
	 */
	function _deprecated_hook( string $hook_name, string $version, string $replacement = '', string $message = '' ): void {
		if ( ! isset( $GLOBALS['albert_test_deprecated_hooks'] ) ) {
			$GLOBALS['albert_test_deprecated_hooks'] = [];
		}

		$GLOBALS['albert_test_deprecated_hooks'][] = [
			'hook_name'   => $hook_name,
			'version'     => $version,
			'replacement' => $replacement,
		];
	}
}

if ( ! function_exists( 'apply_filters_deprecated' ) ) {
	/**
	 * Stub apply_filters_deprecated. Mirrors core closely enough for tests:
	 * only records the deprecation (via _deprecated_hook) when the test has
	 * actually configured something to hook the deprecated name — the same
	 * has_filter() guard core applies before warning — then applies the value
	 * through the same apply_filters() stub above, so hook-call recording and
	 * override behaviour stay identical between the deprecated and the plain
	 * path.
	 *
	 * @param string             $hook_name   Deprecated hook name.
	 * @param array<int, mixed>  $args        Args passed to the hook; the first element is the value being filtered.
	 * @param string             $version     Version the hook was deprecated in.
	 * @param string             $replacement Replacement hook name.
	 * @param string             $message     Optional extra message.
	 *
	 * @return mixed
	 */
	function apply_filters_deprecated( string $hook_name, array $args, string $version, string $replacement = '', string $message = '' ): mixed {
		if ( isset( $GLOBALS['albert_test_filter_returns'][ $hook_name ] ) || isset( $GLOBALS['albert_test_filter_callbacks'][ $hook_name ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub recording to an array, not real output; the sniff matches on the real function's name.
			_deprecated_hook( $hook_name, $version, $replacement, $message );
		}

		return apply_filters( $hook_name, ...$args );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub get_option that reads from $GLOBALS['albert_test_options'].
	 *
	 * @param string $option   Option name.
	 * @param mixed  $fallback Value returned when the option is not set.
	 *
	 * @return mixed
	 */
	function get_option( string $option, mixed $fallback = false ): mixed {
		return $GLOBALS['albert_test_options'][ $option ] ?? $fallback;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Stub get_current_user_id that reads from $GLOBALS['albert_test_user_id'].
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return $GLOBALS['albert_test_user_id'] ?? 1;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub current_user_can that reads from $GLOBALS['albert_test_caps'].
	 *
	 * Defaults to `true` when no cap map is configured so legacy tests that
	 * do not set the global keep passing. When `$GLOBALS['albert_test_caps']`
	 * is set (array of allowed capability names), only those return true.
	 *
	 * Variadic, like WordPress's own. A meta capability takes an object id,
	 * Albert's read abilities call `current_user_can( 'read_post', $post_id )`,
	 * and a single-argument stub would fatal the moment a unit test reached one
	 * of those paths. The extra arguments are accepted and ignored: this stub
	 * answers from a flat cap list, and pretending to resolve a meta capability
	 * against it would be worse than plainly not doing so.
	 *
	 * @param string $capability Capability name.
	 * @param mixed  ...$args    Object id and any further arguments, as WordPress accepts.
	 *
	 * @return bool
	 */
	function current_user_can( string $capability, ...$args ): bool {
		if ( ! isset( $GLOBALS['albert_test_caps'] ) ) {
			return true;
		}

		return in_array( $capability, (array) $GLOBALS['albert_test_caps'], true );
	}
}

if ( ! function_exists( 'wp_get_abilities' ) ) {
	/**
	 * Stub wp_get_abilities that reads from $GLOBALS['albert_test_abilities'].
	 *
	 * Returns an array of ability-like objects that expose get_name() and
	 * get_meta(). Tests populate the global with test doubles.
	 *
	 * @return array<int, object>
	 */
	function wp_get_abilities(): array {
		return (array) ( $GLOBALS['albert_test_abilities'] ?? [] );
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	/**
	 * Stub rest_ensure_response: wrap non-response values in a WP_REST_Response.
	 *
	 * @param mixed $response Response data or object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	function rest_ensure_response( $response ) {
		if ( $response instanceof WP_REST_Response || $response instanceof WP_Error ) {
			return $response;
		}

		return new WP_REST_Response( $response );
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	/**
	 * Stub wp_get_ability that looks a test double up by name in
	 * $GLOBALS['albert_test_abilities'].
	 *
	 * @param string $ability_id Ability ID.
	 *
	 * @return object|null The matching double, or null when not registered.
	 */
	function wp_get_ability( string $ability_id ): ?object {
		foreach ( (array) ( $GLOBALS['albert_test_abilities'] ?? [] ) as $ability ) {
			if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) && $ability->get_name() === $ability_id ) {
				return $ability;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'wp_has_ability' ) ) {
	/**
	 * Stub wp_has_ability, core's silent existence check.
	 *
	 * Answers from the same doubles as the wp_get_ability() stub above, because
	 * in WordPress the two read one registry and any code asking both in a row
	 * is entitled to a consistent answer.
	 *
	 * It matters that this exists at all. Production code probes with
	 * `wp_has_ability()` rather than `wp_get_ability()` because the real
	 * `WP_Abilities_Registry::get_registered()` raises `_doing_it_wrong()` on a
	 * miss. With no stub, `function_exists()` is false here and every such
	 * probe takes its does-not-exist branch, which is how a controller test
	 * ended up asserting a 404 for an ability that was right there.
	 *
	 * @param string $ability_id Ability ID.
	 *
	 * @return bool Whether a double of this name is registered.
	 */
	function wp_has_ability( string $ability_id ): bool {
		return wp_get_ability( $ability_id ) !== null;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * Stub wp_register_ability that records calls to $GLOBALS['albert_test_registered_abilities'].
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability arguments.
	 */
	function wp_register_ability( string $name, array $args ): void {
		if ( ! isset( $GLOBALS['albert_test_registered_abilities'] ) ) {
			$GLOBALS['albert_test_registered_abilities'] = [];
		}

		$GLOBALS['albert_test_registered_abilities'][ $name ] = $args;
	}
}

if ( ! function_exists( '_deprecated_function' ) ) {
	/**
	 * Stub _deprecated_function that records calls to $GLOBALS['albert_test_deprecated_calls'].
	 *
	 * @param string $function_name Function/method name being deprecated.
	 * @param string $version       Version the function was deprecated in.
	 * @param string $replacement   Replacement function/method.
	 */
	function _deprecated_function( string $function_name, string $version, string $replacement = '' ): void {
		if ( ! isset( $GLOBALS['albert_test_deprecated_calls'] ) ) {
			$GLOBALS['albert_test_deprecated_calls'] = [];
		}

		$GLOBALS['albert_test_deprecated_calls'][] = [
			'function_name' => $function_name,
			'version'       => $version,
			'replacement'   => $replacement,
		];
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Stub wp_unslash that mirrors WordPress's stripslashes_deep behaviour.
	 *
	 * @param mixed $value Value to unslash.
	 *
	 * @return mixed
	 */
	function wp_unslash( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Stub wp_parse_url that delegates to PHP's parse_url.
	 *
	 * @param string $url       The URL to parse.
	 * @param int    $component The component to retrieve, or -1 for the full array.
	 *
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub translation function that returns the input string.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 *
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	/**
	 * Stub wp_get_environment_type reading $GLOBALS['albert_test_environment_type'].
	 *
	 * Defaults to 'production' so environment-gated code paths (e.g. SSRF host
	 * checks) run their strict branch unless a test opts into 'local'/'development'.
	 *
	 * @return string
	 */
	function wp_get_environment_type(): string {
		return $GLOBALS['albert_test_environment_type'] ?? 'production';
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub update_option that writes to $GLOBALS['albert_test_options'].
	 *
	 * @param string    $option   Option name.
	 * @param mixed     $value    Option value.
	 * @param bool|null $autoload Whether to autoload (ignored by the stub; mirrors WP's signature).
	 *
	 * @return bool
	 */
	function update_option( string $option, mixed $value, ?bool $autoload = null ): bool {
		if ( ! isset( $GLOBALS['albert_test_options'] ) || ! is_array( $GLOBALS['albert_test_options'] ) ) {
			$GLOBALS['albert_test_options'] = [];
		}

		// Count writes per option so tests can assert churn-free no-op paths.
		if ( ! isset( $GLOBALS['albert_test_option_writes'] ) || ! is_array( $GLOBALS['albert_test_option_writes'] ) ) {
			$GLOBALS['albert_test_option_writes'] = [];
		}
		$GLOBALS['albert_test_option_writes'][ $option ] = ( $GLOBALS['albert_test_option_writes'][ $option ] ?? 0 ) + 1;

		$GLOBALS['albert_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Stub set_transient that writes to $GLOBALS['albert_test_transients'].
	 *
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration Expiration in seconds (ignored by the stub).
	 *
	 * @return bool
	 */
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		if ( ! isset( $GLOBALS['albert_test_transients'] ) || ! is_array( $GLOBALS['albert_test_transients'] ) ) {
			$GLOBALS['albert_test_transients'] = [];
		}

		$GLOBALS['albert_test_transients'][ $transient ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Stub get_transient that reads from $GLOBALS['albert_test_transients'].
	 *
	 * @param string $transient Transient name.
	 *
	 * @return mixed Transient value, or false when not set.
	 */
	function get_transient( string $transient ): mixed {
		return $GLOBALS['albert_test_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Stub delete_transient that removes from $GLOBALS['albert_test_transients'].
	 *
	 * @param string $transient Transient name.
	 *
	 * @return bool
	 */
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['albert_test_transients'][ $transient ] );

		return true;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub esc_html.
	 *
	 * @param string $text Text to escape.
	 *
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Stub esc_html_e that echoes the escaped text.
	 *
	 * @param string $text   Text to escape and echo.
	 * @param string $domain Text domain.
	 *
	 * @return void
	 */
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub sanitize_text_field: strips tags and collapses whitespace.
	 *
	 * @param string $value Value to sanitize.
	 *
	 * @return string
	 */
	function sanitize_text_field( string $value ): string {
		$value = wp_strip_all_tags( $value );
		$value = (string) preg_replace( '/[\r\n\t ]+/', ' ', $value );

		return trim( $value );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Stub wp_strip_all_tags, mirroring core: <script>/<style> tags are removed
	 * along with their content (not just the tags), unlike plain strip_tags().
	 *
	 * @param string $value         Value to strip tags from.
	 * @param bool   $remove_breaks Whether to also collapse line breaks/whitespace.
	 *
	 * @return string
	 */
	function wp_strip_all_tags( string $value, bool $remove_breaks = false ): string {
		$value = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $value );
		$value = strip_tags( $value );

		if ( $remove_breaks ) {
			$value = (string) preg_replace( '/[\r\n\t ]+/', ' ', $value );
		}

		return trim( $value );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Stub esc_url_raw: unlike esc_url() this is meant for storage, not
	 * display, but for stub purposes the same filtering is close enough.
	 *
	 * @param string $url URL to sanitize.
	 *
	 * @return string
	 */
	function esc_url_raw( string $url ): string {
		return (string) filter_var( $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Stub esc_url.
	 *
	 * @param string $url URL to escape.
	 *
	 * @return string
	 */
	function esc_url( string $url ): string {
		return (string) filter_var( $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * Stub number_format_i18n.
	 *
	 * @param int|float $number   Number to format.
	 * @param int       $decimals Decimal places.
	 *
	 * @return string
	 */
	function number_format_i18n( int|float $number, int $decimals = 0 ): string {
		return number_format( (float) $number, $decimals );
	}
}

if ( ! function_exists( 'menu_page_url' ) ) {
	/**
	 * Stub menu_page_url that returns a predictable admin URL.
	 *
	 * @param string $slug    Menu slug.
	 * @param bool   $display Whether to echo (ignored by the stub).
	 *
	 * @return string
	 */
	function menu_page_url( string $slug, bool $display = true ): string {
		return 'http://example.test/wp-admin/admin.php?page=' . $slug;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Stub home_url reading $GLOBALS['albert_test_home_url'], defaulting to a
	 * fixed test domain.
	 *
	 * @param string $path Path relative to the home URL.
	 *
	 * @return string
	 */
	function home_url( string $path = '' ): string {
		$base = $GLOBALS['albert_test_home_url'] ?? 'https://example.test';

		return $path !== '' ? rtrim( $base, '/' ) . '/' . ltrim( $path, '/' ) : $base;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Stub is_admin that reads from $GLOBALS['albert_test_is_admin'].
	 *
	 * @return bool
	 */
	function is_admin(): bool {
		return (bool) ( $GLOBALS['albert_test_is_admin'] ?? false );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stub sanitize_key mirroring WordPress's lowercase + charset filtering.
	 *
	 * @param string $key Key to sanitize.
	 *
	 * @return string
	 */
	function sanitize_key( string $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'wp_unregister_ability' ) ) {
	/**
	 * Stub wp_unregister_ability that records unregistered IDs and drops the
	 * matching double from $GLOBALS['albert_test_abilities'].
	 *
	 * @param string $name Ability name.
	 *
	 * @return void
	 */
	function wp_unregister_ability( string $name ): void {
		if ( ! isset( $GLOBALS['albert_test_unregistered_abilities'] ) || ! is_array( $GLOBALS['albert_test_unregistered_abilities'] ) ) {
			$GLOBALS['albert_test_unregistered_abilities'] = [];
		}

		$GLOBALS['albert_test_unregistered_abilities'][] = $name;

		if ( isset( $GLOBALS['albert_test_abilities'] ) && is_array( $GLOBALS['albert_test_abilities'] ) ) {
			$GLOBALS['albert_test_abilities'] = array_values(
				array_filter(
					$GLOBALS['albert_test_abilities'],
					static function ( $ability ) use ( $name ): bool {
						return ! ( is_object( $ability ) && method_exists( $ability, 'get_name' ) && $ability->get_name() === $name );
					}
				)
			);
		}
	}
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// phpcs:enable

if ( ! function_exists( 'rest_validate_value_from_schema' ) ) {
	/**
	 * Stub of core's JSON Schema validator.
	 *
	 * Mirrors `rest_validate_value_from_schema()` for the subset Albert's own
	 * code depends on — required properties, `additionalProperties: false`, and
	 * scalar type checks — in core's order (required first, then each supplied
	 * property, then undeclared ones) and with core's own message wording, so a
	 * unit test sees the same reason a real request would.
	 *
	 * Parity with the real function is not assumed on the strength of this
	 * stub: `tests/Integration/Abilities/InputValidationTest.php` exercises the
	 * same paths against real WordPress.
	 *
	 * @param mixed  $value The value to validate.
	 * @param array  $args  The schema to validate against.
	 * @param string $param The parameter name used in messages.
	 *
	 * @return true|\WP_Error True when valid, WP_Error with core's reason otherwise.
	 */
	function rest_validate_value_from_schema( $value, $args, $param = '' ) {
		$type = $args['type'] ?? null;
		$type = is_array( $type ) ? ( $type[0] ?? null ) : $type;

		if ( $type === 'object' ) {
			if ( ! is_array( $value ) ) {
				return new \WP_Error(
					'rest_invalid_type',
					sprintf( '%1$s is not of type %2$s.', $param, 'object' )
				);
			}

			foreach ( (array) ( $args['required'] ?? [] ) as $name ) {
				if ( ! array_key_exists( $name, $value ) ) {
					return new \WP_Error(
						'rest_property_required',
						sprintf( '%1$s is a required property of %2$s.', $name, $param )
					);
				}
			}

			foreach ( $value as $property => $property_value ) {
				if ( isset( $args['properties'][ $property ] ) ) {
					$is_valid = rest_validate_value_from_schema(
						$property_value,
						$args['properties'][ $property ],
						$param . '[' . $property . ']'
					);

					if ( is_wp_error( $is_valid ) ) {
						return $is_valid;
					}

					continue;
				}

				if ( ( $args['additionalProperties'] ?? null ) === false ) {
					return new \WP_Error(
						'rest_additional_properties_forbidden',
						sprintf( '%1$s is not a valid property of Object.', $property )
					);
				}
			}

			return true;
		}

		$matches = [
			'integer' => static fn( $v ): bool => is_int( $v ),
			'number'  => static fn( $v ): bool => is_int( $v ) || is_float( $v ),
			'string'  => static fn( $v ): bool => is_string( $v ),
			'boolean' => static fn( $v ): bool => is_bool( $v ),
			'array'   => static fn( $v ): bool => is_array( $v ),
			'null'    => static fn( $v ): bool => $v === null,
		];

		if ( is_string( $type ) && isset( $matches[ $type ] ) && ! $matches[ $type ]( $value ) ) {
			return new \WP_Error(
				'rest_invalid_type',
				sprintf( '%1$s is not of type %2$s.', $param, $type )
			);
		}

		return true;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Stub admin_url returning a predictable absolute URL.
	 *
	 * @param string $path Path relative to wp-admin.
	 *
	 * @return string
	 */
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Minimal add_query_arg supporting the ( $key, $value, $url ) signature.
	 *
	 * @param string $key   Query var name.
	 * @param string $value Query var value.
	 * @param string $url   Base URL.
	 *
	 * @return string
	 */
	function add_query_arg( string $key, string $value, string $url ): string {
		$separator = ( strpos( $url, '?' ) === false ) ? '?' : '&';

		return $url . $separator . $key . '=' . $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Stub wp_json_encode delegating to json_encode.
	 *
	 * @param mixed $data  Data to encode.
	 * @param int   $flags json_encode flags.
	 *
	 * @return string|false
	 */
	function wp_json_encode( $data, int $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( 'get_role' ) ) {
	/**
	 * Stub get_role reading $GLOBALS['albert_test_roles'][$slug] => [cap => bool].
	 *
	 * @param string $role Role slug.
	 *
	 * @return object|null Role-like object with has_cap(), or null when unknown.
	 */
	function get_role( string $role ) {
		$roles = $GLOBALS['albert_test_roles'] ?? [];

		if ( ! isset( $roles[ $role ] ) ) {
			return null;
		}

		return new class( $roles[ $role ] ) {

			/**
			 * Capability map for this role.
			 *
			 * @var array<string, bool>
			 */
			private array $caps;

			/**
			 * Seed the role with its capability map.
			 *
			 * @param array<string, bool> $caps Capability => granted.
			 */
			public function __construct( array $caps ) {
				$this->caps = $caps;
			}

			/**
			 * Whether the role has a capability.
			 *
			 * @param string $cap Capability name.
			 *
			 * @return bool
			 */
			public function has_cap( string $cap ): bool {
				return ! empty( $this->caps[ $cap ] );
			}
		};
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * Stub get_site_option reading $GLOBALS['albert_test_site_options'].
	 *
	 * @param string $option   Option name.
	 * @param mixed  $fallback Value when unset.
	 *
	 * @return mixed
	 */
	function get_site_option( string $option, $fallback = false ) {
		$options = $GLOBALS['albert_test_site_options'] ?? [];

		return array_key_exists( $option, $options ) ? $options[ $option ] : $fallback;
	}
}
