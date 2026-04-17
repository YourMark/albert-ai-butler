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

/**
 * Logger class
 *
 * Hooks into the WordPress Abilities API to log successful ability executions.
 * Only logs when the `albert/logging/enabled` filter returns true (default).
 * Premium can disable this logger by returning false from that filter.
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
	 * @return void
	 * @since 1.1.0
	 */
	public function register_hooks(): void {
		add_action( 'wp_after_execute_ability', [ $this, 'log_execution' ], 10, 3 );
	}

	/**
	 * Log an ability execution.
	 *
	 * Called after a successful ability execution via the WP core hook.
	 * Wrapped in try/catch to ensure logging never breaks ability execution.
	 *
	 * Must be public so WordPress can invoke it via call_user_func_array
	 * from the wp_after_execute_ability action — a protected method
	 * throws a TypeError when dispatched externally.
	 *
	 * @param string $ability_name The ability identifier.
	 * @param mixed  $input        The input arguments (unused in Free tier).
	 * @param mixed  $result       The execution result (unused in Free tier).
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function log_execution( string $ability_name, $input, $result ): void {
		try {
			/**
			 * Filters whether Free's ability logging is enabled.
			 *
			 * Premium returns false from this filter to suppress Free's writes
			 * and use its own extended logging instead.
			 *
			 * @since 1.1.0
			 *
			 * @param bool $enabled Whether logging is enabled. Default true.
			 */
			$enabled = apply_filters( 'albert/logging/enabled', true );

			if ( ! $enabled ) {
				return;
			}

			$user_id = get_current_user_id();
			$this->repository->insert( $ability_name, $user_id );
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
