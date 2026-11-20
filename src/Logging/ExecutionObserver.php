<?php
/**
 * Ability execution observer.
 *
 * @package Albert
 * @subpackage Logging
 * @since      1.6.0
 */

namespace Albert\Logging;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;
use Albert\OAuth\Server\ConnectionContext;
use WP_Error;
use WP_Filter_Sentinel;

/**
 * Logs every ability call made over an Albert connection, once its outcome is known.
 *
 * Core has no hook that fires on every exit of `WP_Ability::execute()`, but each
 * early return passes a filter that still holds the error. Watching those, from
 * `wp_ability_invoked` to the first one that ends the call, gives one terminal
 * event per call with the real error code, for Albert's abilities and every
 * other plugin's alike. A success is only final on `wp_after_execute_ability`,
 * after output validation; `albert/abilities/after_execute` fires before it.
 *
 * Calls without a connection (WP-CLI, REST, the plugin itself) are not observed
 * and keep logging through {@see Logger} as before.
 *
 * Not logged: the two exits core takes before any filter (input for an ability
 * without an input schema, an invalid permission callback), and the outer call
 * when an ability calls itself, since calls are keyed by name.
 *
 * Mirrors Premium's `LifecycleObserver`, which writes its own rows; Free's
 * writes stand down for it through `albert/logging/enabled`.
 *
 * @since 1.6.0
 */
class ExecutionObserver implements Hookable {

	/**
	 * Calls opened and not yet ended, keyed by ability name.
	 *
	 * Static so {@see Logger} can ask without holding this instance.
	 *
	 * @since 1.6.0
	 * @var array<string, array{input: mixed}>
	 */
	private static array $open = [];

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Writes the row.
	 *
	 * @since 1.6.0
	 */
	public function __construct( private Logger $logger ) {
	}

	/**
	 * Register the hooks.
	 *
	 * The stage filters run last so they see the value core acts on.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function register_hooks(): void {
		add_action( 'wp_ability_invoked', [ $this, 'open' ], 1, 2 );
		add_filter( 'wp_pre_execute_ability', [ $this, 'on_pre_execute' ], PHP_INT_MAX, 2 );
		add_filter( 'wp_ability_normalize_input', [ $this, 'on_normalize_input' ], PHP_INT_MAX, 2 );
		add_filter( 'wp_ability_validate_input', [ $this, 'on_validate_input' ], PHP_INT_MAX, 3 );
		add_filter( 'wp_ability_permission_result', [ $this, 'on_permission_result' ], PHP_INT_MAX, 2 );
		add_filter( 'wp_ability_execute_result', [ $this, 'on_execute_result' ], PHP_INT_MAX, 2 );
		add_filter( 'wp_ability_validate_output', [ $this, 'on_validate_output' ], PHP_INT_MAX, 3 );
		add_action( 'wp_after_execute_ability', [ $this, 'on_after_execute' ], PHP_INT_MAX, 3 );
	}

	/**
	 * Whether a call to this ability is open, so its row is this class's to write.
	 *
	 * @param string $ability_name Ability identifier.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	public static function is_observing( string $ability_name ): bool {
		return isset( self::$open[ $ability_name ] );
	}

	/**
	 * Forget every open call. For test isolation.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public static function reset(): void {
		self::$open = [];
	}

	/**
	 * Open a call made over an Albert connection.
	 *
	 * The adapter's meta-tools are skipped: they proxy the ability that gets logged.
	 *
	 * @param string $ability_name The ability being invoked.
	 * @param mixed  $input        Raw input; the only copy left when normalisation fails.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function open( string $ability_name, mixed $input = null ): void {
		if ( ConnectionContext::client_id() === null || AbilitiesRegistry::is_transport_ability( $ability_name ) ) {
			return;
		}

		self::$open[ $ability_name ] = [ 'input' => $input ];
	}

	/**
	 * End a call another filter short-circuited, such as one safe mode holds.
	 *
	 * @param mixed  $pre          The short-circuit result, or core's sentinel.
	 * @param string $ability_name The ability being invoked.
	 *
	 * @return mixed The value unchanged.
	 * @since 1.6.0
	 */
	public function on_pre_execute( mixed $pre, string $ability_name ): mixed {
		if ( ! $pre instanceof WP_Filter_Sentinel ) {
			$this->end( $ability_name, $pre );
		}

		return $pre;
	}

	/**
	 * End a call whose input could not be normalised.
	 *
	 * @param mixed  $input        The normalised input, or the error core returns.
	 * @param string $ability_name The ability being invoked.
	 *
	 * @return mixed The value unchanged.
	 * @since 1.6.0
	 */
	public function on_normalize_input( mixed $input, string $ability_name ): mixed {
		if ( is_wp_error( $input ) ) {
			$this->end( $ability_name, $input );
		}

		return $input;
	}

	/**
	 * End a call rejected by input validation.
	 *
	 * Not logged: the assistant gets the reason and retries, so a row is noise.
	 *
	 * @param mixed  $validity     The validation result core acts on.
	 * @param mixed  $input        The input being validated.
	 * @param string $ability_name The ability being invoked.
	 *
	 * @return mixed The value unchanged.
	 * @since 1.6.0
	 */
	public function on_validate_input( mixed $validity, mixed $input, string $ability_name ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook signature.
		$error = self::validation_error( $validity, 'ability_invalid_input' );

		if ( $error !== null ) {
			$rejected = in_array( (string) $error->get_error_code(), ObservabilityHandler::VALIDATION_REJECTION_CODES, true );
			$this->end( $ability_name, $error, ! $rejected );
		}

		return $validity;
	}

	/**
	 * End a denied call, with the reason core is about to replace.
	 *
	 * Core swaps the callback's error for a generic `ability_invalid_permissions`;
	 * this filter is the last place the real one exists.
	 *
	 * @param mixed  $permission   The permission result core acts on.
	 * @param string $ability_name The ability being invoked.
	 *
	 * @return mixed The value unchanged.
	 * @since 1.6.0
	 */
	public function on_permission_result( mixed $permission, string $ability_name ): mixed {
		if ( $permission === true ) {
			return $permission;
		}

		$error = is_wp_error( $permission ) && $permission->has_errors()
			? $permission
			: new WP_Error( 'ability_invalid_permissions', __( 'The permission check denied this call without giving a reason.', 'albert-ai-butler' ) );

		$this->end( $ability_name, $error );

		return $permission;
	}

	/**
	 * End a call whose callback failed, including an exception core caught.
	 *
	 * @param mixed  $result       The result core acts on.
	 * @param string $ability_name The ability being invoked.
	 *
	 * @return mixed The value unchanged.
	 * @since 1.6.0
	 */
	public function on_execute_result( mixed $result, string $ability_name ): mixed {
		if ( is_wp_error( $result ) ) {
			$this->end( $ability_name, $result );
		}

		return $result;
	}

	/**
	 * End a call whose output failed validation.
	 *
	 * @param mixed  $validity     The validation result core acts on.
	 * @param mixed  $output       The output being validated.
	 * @param string $ability_name The ability being invoked.
	 *
	 * @return mixed The value unchanged.
	 * @since 1.6.0
	 */
	public function on_validate_output( mixed $validity, mixed $output, string $ability_name ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook signature.
		$error = self::validation_error( $validity, 'ability_invalid_output' );

		if ( $error !== null ) {
			$this->end( $ability_name, $error );
		}

		return $validity;
	}

	/**
	 * End a successful call.
	 *
	 * @param string $ability_name The ability that ran.
	 * @param mixed  $input        The normalised input.
	 * @param mixed  $result       The validated result.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function on_after_execute( string $ability_name, mixed $input = null, mixed $result = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook signature.
		$this->end( $ability_name, $result );
	}

	/**
	 * Close the call and hand its outcome to the Logger.
	 *
	 * Closed before writing, so the Logger does not stand down for its own row.
	 * {@see ExecutionLogMarker} is not read: it would drop a repeat call in one request.
	 *
	 * @param string $ability_name The ability identifier.
	 * @param mixed  $result       The outcome: a WP_Error, or the successful result.
	 * @param bool   $log          False to close without writing a row.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private function end( string $ability_name, mixed $result, bool $log = true ): void {
		if ( ! isset( self::$open[ $ability_name ] ) ) {
			return;
		}

		$input = self::$open[ $ability_name ]['input'];
		unset( self::$open[ $ability_name ] );

		if ( ! $log ) {
			return;
		}

		// The Logger stores only the outcome, so a scalar result needs no shape.
		$this->logger->record(
			$ability_name,
			$input,
			$result instanceof WP_Error ? $result : (array) $result,
			get_current_user_id()
		);
	}

	/**
	 * The error a validation filter result means, or null when it passed.
	 *
	 * Mirrors core: `false` is a generic error, a WP_Error with errors stands.
	 *
	 * @param mixed  $validity      The filtered validation result.
	 * @param string $fallback_code Code for a bare false.
	 *
	 * @return WP_Error|null
	 * @since 1.6.0
	 */
	private static function validation_error( mixed $validity, string $fallback_code ): ?WP_Error {
		if ( $validity === false ) {
			return new WP_Error( $fallback_code, __( 'Validation failed.', 'albert-ai-butler' ) );
		}

		return is_wp_error( $validity ) && $validity->has_errors() ? $validity : null;
	}
}
