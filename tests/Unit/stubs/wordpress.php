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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stub WP_Error for unit tests.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		protected string $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		protected string $message;

		/**
		 * Error data.
		 *
		 * @var mixed
		 */
		protected mixed $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Get the error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}
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
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   Additional arguments.
	 *
	 * @return mixed The unmodified value.
	 */
	function apply_filters( string $hook_name, mixed $value, ...$args ): mixed {
		$GLOBALS['albert_test_hooks'][] = [
			'type' => 'filter',
			'hook' => $hook_name,
			'args' => array_merge( [ $value ], $args ),
		];
		return $value;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub get_option that reads from $GLOBALS['albert_test_options'].
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	function get_option( string $option, mixed $default = false ): mixed {
		return $GLOBALS['albert_test_options'][ $option ] ?? $default;
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
	 * Stub current_user_can that always returns true.
	 *
	 * @param string $capability Capability name.
	 *
	 * @return bool
	 */
	function current_user_can( string $capability ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * Stub wp_register_ability (no-op).
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability arguments.
	 */
	function wp_register_ability( string $name, array $args ): void {}
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
