<?php
/**
 * Ability Execution Logger
 *
 * @package Albert
 * @subpackage Logging
 * @since      1.1.0
 */

namespace Albert\Logging;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use WP_Error;

/**
 * Logger class
 *
 * Hooks into Albert's own after_execute action to log ability executions,
 * including failures. Only writes to the DB when the `albert/logging/enabled`
 * filter returns true (default). This filter means "Free's writers are active" —
 * returning false suppresses Free's DB writes so Premium can take over logging
 * on the shared table without double-writes. The `albert/logging/ability_failed`
 * notification hook fires regardless of the storage gate so Premium always
 * receives failure signals even if it hooks in via a different mechanism.
 *
 * @since 1.1.0
 */
class Logger implements Hookable {

	/**
	 * The repository instance.
	 *
	 * @since 1.1.0
	 * @var Repository
	 */
	protected Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository The logging repository.
	 *
	 * @since 1.1.0
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * Hooks into Albert's own `albert/abilities/after_execute` action instead of
	 * the WP core `wp_after_execute_ability` (success-only) so failures are
	 * also captured.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function register_hooks(): void {
		add_action( 'albert/abilities/after_execute', [ $this, 'log_execution' ], 10, 4 );
	}

	/**
	 * Log an ability execution (success or failure).
	 *
	 * Called on `albert/abilities/after_execute`, for both WP_Error results and
	 * successful ones. Other plugins' abilities reach it through
	 * {@see OtherPluginCallObserver}. Wrapped in try/catch so logging never
	 * breaks ability execution.
	 *
	 * Must be public so WordPress can invoke it via call_user_func_array
	 * from the albert/abilities/after_execute action — a protected method
	 * throws a TypeError when dispatched externally.
	 *
	 * @param string                         $ability_name The ability identifier.
	 * @param mixed                          $input        The input arguments.
	 * @param array<string, mixed>|\WP_Error $result       The execution result.
	 * @param int                            $user_id      The user ID who executed the ability.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function log_execution( string $ability_name, mixed $input, array|WP_Error $result, int $user_id ): void {
		try {
			$is_error      = is_wp_error( $result );
			$status        = Outcome::classify( $result, $ability_name );
			$error_code    = $is_error ? (string) $result->get_error_code() : null;
			$error_message = $is_error ? (string) $result->get_error_message() : null;

			// Fire the Premium-facing failure notification BEFORE the storage gate
			// so Premium always receives the signal even when Free's writes are off.
			//
			// Gated on the classified status, not on `is_wp_error()` alone: this
			// is a failure signal, and two of the three outcomes are not
			// failures. A `success` — asked whether a term exists, looked,
			// answered no — is an answer, and a `warning` is the site's own
			// permission rules doing exactly what they were configured to do.
			// Neither should wake anyone. The `$is_error` half is redundant
			// (only an error can classify as one) and kept so the WP_Error type
			// is provable here.
			if ( $is_error && $status === Outcome::ERROR ) {
				/**
				 * Fires when an ability execution results in a genuine failure.
				 *
				 * Decoupled from Free's storage gate so Premium (or any add-on)
				 * can react to failures even when `albert/logging/enabled` returns
				 * false. Core never depends on who hooks here.
				 *
				 * Does NOT fire when {@see Outcome} classifies the error as
				 * `success` — a legitimately negative answer such as
				 * `term_not_found` — or as `warning`, a request the site refused
				 * on purpose. Use `albert/logging/outcome` to change how a code
				 * is classified.
				 *
				 * @since 1.2.0
				 * @since 1.4.0 Fires only for a classified `error`; a
				 *               `success` or `warning` outcome stays silent.
				 *
				 * @param string   $ability_name The ability identifier.
				 * @param \WP_Error $result       The error returned by the ability.
				 * @param int      $user_id      The ID of the user who triggered the execution.
				 * @param mixed    $input        The input arguments passed to the ability.
				 */
				do_action( 'albert/logging/ability_failed', $ability_name, $result, $user_id, $input );
			}

			/**
			 * Filters whether Free's ability log writers are active.
			 *
			 * Returning false from this filter suppresses Free's DB writes
			 * (both Logger and ObservabilityHandler). This is the takeover
			 * protocol used by Premium: it returns false here and owns all
			 * writes to the shared table itself. It does NOT disable logging
			 * globally — Premium's own logger still runs regardless of this value.
			 *
			 * @since 1.1.0
			 *
			 * @param bool $enabled Whether Free's writers are active. Default true.
			 */
			$enabled = apply_filters( 'albert/logging/enabled', true );

			if ( ! $enabled ) {
				return;
			}

			$context = $error_message !== null ? [ 'error_message' => $error_message ] : [];
			$this->repository->insert( $ability_name, $user_id, $status, $error_code, $context );
			ExecutionLogMarker::mark( $ability_name );
		} catch ( \Throwable $e ) {
			// Never rethrow — logging must not break ability execution.
			// Silently fail. In debug mode, WordPress will log the error.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
				error_log( 'Albert Logger Error: ' . $e->getMessage() );
			}
		}
	}
}
