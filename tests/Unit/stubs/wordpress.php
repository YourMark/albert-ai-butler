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
	 */
	function do_action( string $hook_name, ...$args ): void {
		$GLOBALS['albert_test_hooks'][] = [
			'type' => 'action',
			'hook' => $hook_name,
			'args' => $args,
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

		return $value;
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
	 * @param string $capability Capability name.
	 *
	 * @return bool
	 */
	function current_user_can( string $capability ): bool {
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

// phpcs:enable
