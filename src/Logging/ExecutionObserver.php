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
 * The input stage (normalise, validate) closes without a row: nothing ran and
 * no rule of the site was applied, and the assistant gets the reason and retries.
 * Every later exit is logged, with its stage, so a denial reads as a warning
 * whatever code the permission callback chose.
 *
 * Over MCP a denial never reaches `execute()`: the adapter runs
 * `execute-ability`'s permission check first, which checks the inner ability.
 * That denial is logged from the inner check once the meta-tool's own result
 * confirms it ended the call.
 *
 * Core has two exits before any filter, and neither needs a row: input for an
 * ability without an input schema is an input rejection, and an invalid
 * permission callback is refused at registration. Their call is never ended,
 * but it never started running either, so it cannot silence the Logger.
 *
 * @since 1.6.0
 */
class ExecutionObserver implements Hookable {

	/**
	 * The adapter's meta-tool that runs an ability for an MCP client.
	 *
	 * @since 1.6.0
	 */
	private const EXECUTE_TOOL = 'mcp-adapter/execute-ability';

	/**
	 * Calls opened and not yet ended: a stack per ability name, so a call to
	 * an ability from inside its own callback ends its own entry.
	 *
	 * Static so {@see Logger} can ask without holding this instance.
	 *
	 * @since 1.6.0
	 * @var array<string, list<array{input: mixed, running: bool, reported: bool}>>
	 */
	private static array $open = [];

	/**
	 * The last denial seen outside an open call, until `execute-ability`'s
	 * permission result says whether it ended a call.
	 *
	 * @since 1.6.0
	 * @var array{ability: string, error: WP_Error}|null
	 */
	private ?array $denied = null;

	/**
	 * The ability whose failure is being relayed on `albert/abilities/after_execute`,
	 * so the Logger does not write it a second row.
	 *
	 * @since 1.6.0
	 * @var string|null
	 */
	private static ?string $relaying = null;

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
		add_filter( 'wp_ability_permission_result', [ $this, 'on_permission_result' ], PHP_INT_MAX, 3 );
		add_action( 'wp_before_execute_ability', [ $this, 'on_before_execute' ], PHP_INT_MAX, 1 );
		add_action( 'albert/abilities/after_execute', [ $this, 'on_reported' ], 0, 1 );
		add_filter( 'wp_ability_execute_result', [ $this, 'on_execute_result' ], PHP_INT_MAX, 2 );
		add_filter( 'wp_ability_validate_output', [ $this, 'on_validate_output' ], PHP_INT_MAX, 3 );
		add_action( 'wp_after_execute_ability', [ $this, 'on_after_execute' ], PHP_INT_MAX, 3 );
	}

	/**
	 * Whether this ability's callback is running inside an open call, so its
	 * row is this class's to write. The Logger's hook fires only there.
	 *
	 * @param string $ability_name Ability identifier.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	public static function is_observing( string $ability_name ): bool {
		if ( self::$relaying === $ability_name ) {
			return true;
		}

		$calls = self::$open[ $ability_name ] ?? [];

		return $calls !== [] && end( $calls )['running'];
	}

	/**
	 * Forget every open call. For test isolation.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public static function reset(): void {
		self::$relaying = null;
		self::$open     = [];
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

		self::$open[ $ability_name ][] = [
			'input'    => $input,
			'running'  => false,
			'reported' => false,
		];
	}

	/**
	 * Note that the open call fired `albert/abilities/after_execute` itself.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function on_reported( string $ability_name ): void {
		$top = array_key_last( self::$open[ $ability_name ] ?? [] );

		if ( $top !== null && self::$relaying !== $ability_name ) {
			self::$open[ $ability_name ][ $top ]['reported'] = true;
		}
	}

	/**
	 * Mark the call as running its callback.
	 *
	 * @param string $ability_name The ability about to run.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function on_before_execute( string $ability_name ): void {
		$top = array_key_last( self::$open[ $ability_name ] ?? [] );

		if ( $top !== null ) {
			self::$open[ $ability_name ][ $top ]['running'] = true;
		}
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
			$this->end( $ability_name, $pre, 'short_circuit' );
		}

		return $pre;
	}

	/**
	 * Close a call whose input could not be normalised, without a row.
	 *
	 * @param mixed  $input        The normalised input, or the error core returns.
	 * @param string $ability_name The ability being invoked.
	 *
	 * @return mixed The value unchanged.
	 * @since 1.6.0
	 */
	public function on_normalize_input( mixed $input, string $ability_name ): mixed {
		if ( is_wp_error( $input ) ) {
			self::close( $ability_name );
		}

		return $input;
	}

	/**
	 * Close a call rejected by input validation, without a row.
	 *
	 * Whatever the code: a plugin's own validation filter is still input the
	 * assistant can correct.
	 *
	 * @param mixed  $validity     The validation result core acts on.
	 * @param mixed  $input        The input being validated.
	 * @param string $ability_name The ability being invoked.
	 *
	 * @return mixed The value unchanged.
	 * @since 1.6.0
	 */
	public function on_validate_input( mixed $validity, mixed $input, string $ability_name ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook signature.
		if ( self::validation_error( $validity, 'ability_invalid_input' ) !== null ) {
			self::close( $ability_name );
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
	 * @param string $ability_name The ability whose permission was checked.
	 * @param mixed  $input        The input it was checked against.
	 *
	 * @return mixed The value unchanged.
	 * @since 1.6.0
	 */
	public function on_permission_result( mixed $permission, string $ability_name, mixed $input = null ): mixed {
		$error = self::denial( $permission );

		if ( ! empty( self::$open[ $ability_name ] ) ) {
			if ( $error !== null ) {
				$this->end( $ability_name, $error, 'permission' );
			}
		} elseif ( $ability_name === self::EXECUTE_TOOL ) {
			$this->log_proxied_denial( $error, $input );
		} else {
			$this->denied = $error !== null ? [
				'ability' => $ability_name,
				'error'   => $error,
			] : null;
		}

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
			$this->end( $ability_name, $result, 'execute' );
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
			$this->end( $ability_name, $error, 'output' );
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
	 *
	 * @param string      $ability_name The ability identifier.
	 * @param mixed       $result       The outcome: a WP_Error, or the successful result.
	 * @param string|null $stage        Where a failed call stopped; see `Repository::insert()`.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private function end( string $ability_name, mixed $result, ?string $stage = null ): void {
		$call = self::close( $ability_name );
		if ( $call === null ) {
			return;
		}

		if ( $result instanceof WP_Error && ! $call['reported'] ) {
			self::relay_failure( $ability_name, $call['input'], $result );
		}

		// A success can be any value, `record()` takes an array, and only the
		// outcome is stored, so casting loses nothing.
		$this->logger->record(
			$ability_name,
			$call['input'],
			$result instanceof WP_Error ? $result : (array) $result,
			get_current_user_id(),
			$stage
		);
	}

	/**
	 * Remove the innermost open call to this ability.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return array{input: mixed, running: bool, reported: bool}|null The call, or null when none is open.
	 * @since 1.6.0
	 */
	private static function close( string $ability_name ): ?array {
		if ( empty( self::$open[ $ability_name ] ) ) {
			return null;
		}

		$call = array_pop( self::$open[ $ability_name ] );

		if ( self::$open[ $ability_name ] === [] ) {
			unset( self::$open[ $ability_name ] );
		}

		return $call;
	}

	/**
	 * Log the inner ability's denial when `execute-ability` was refused for it.
	 *
	 * The meta-tool's check can also fail on its own grounds (no login, an
	 * unknown ability); only a refusal by the inner ability's check is its call.
	 *
	 * @param WP_Error|null $error The meta-tool's denial, or null when it allowed the call.
	 * @param mixed         $input The meta-tool's input: `ability_name` and `parameters`.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private function log_proxied_denial( ?WP_Error $error, mixed $input ): void {
		$denied       = $this->denied;
		$this->denied = null;

		if ( $error === null || $denied === null || ConnectionContext::client_id() === null ) {
			return;
		}

		$input = is_array( $input ) ? $input : [];
		if ( ( $input['ability_name'] ?? null ) !== $denied['ability'] ) {
			return;
		}

		self::relay_failure( $denied['ability'], $input['parameters'] ?? null, $denied['error'] );
		$this->logger->record( $denied['ability'], $input['parameters'] ?? null, $denied['error'], get_current_user_id(), 'permission' );
	}

	/**
	 * Fire `albert/abilities/after_execute` for a failure nothing logged yet.
	 *
	 * Until 1.6.0 every failed call reached that action, also one that never ran
	 * or came from another plugin, and Albert Premium 1.1.0 still logs from it.
	 * Skipped when the call fired it already, or an add-on marked it logged.
	 *
	 * @param string   $ability_name The ability identifier.
	 * @param mixed    $input        The call's input.
	 * @param WP_Error $error        Why it failed.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private static function relay_failure( string $ability_name, mixed $input, WP_Error $error ): void {
		if ( ExecutionLogMarker::has( $ability_name ) ) {
			return;
		}

		self::$relaying = $ability_name;
		try {
			/** This action is documented in src/Abstracts/BaseAbility.php */
			do_action( 'albert/abilities/after_execute', $ability_name, is_array( $input ) ? $input : [], $error, get_current_user_id() );
		} finally {
			self::$relaying = null;
		}
	}

	/**
	 * The error a permission result means, or null when it allowed the call.
	 *
	 * Mirrors core: anything but `true` denies, and a bare denial gets a reason.
	 *
	 * @param mixed $permission The filtered permission result.
	 *
	 * @return WP_Error|null
	 * @since 1.6.0
	 */
	private static function denial( mixed $permission ): ?WP_Error {
		if ( $permission === true ) {
			return null;
		}

		return is_wp_error( $permission ) && $permission->has_errors()
			? $permission
			: new WP_Error( 'ability_invalid_permissions', __( 'The permission check denied this call without giving a reason.', 'albert-ai-butler' ) );
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
