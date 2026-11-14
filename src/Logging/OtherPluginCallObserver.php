<?php
/**
 * Other-plugin ability call observer.
 *
 * @package Albert
 * @subpackage Logging
 * @since      1.6.0
 */

namespace Albert\Logging;

defined( 'ABSPATH' ) || exit;

use Albert\Abstracts\BaseAbility;
use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;
use Albert\OAuth\Server\ConnectionContext;
use Throwable;
use WP_Error;

/**
 * Logs abilities from other plugins when an assistant calls them over an Albert connection.
 *
 * `albert/abilities/after_execute` fires from {@see BaseAbility::guarded_execute()},
 * which only Albert's own abilities run through, so a call to another plugin's
 * ability left no row and the Abilities screen said "Never used". This wraps
 * that ability's execute_callback, the way
 * {@see \Albert\Core\AbilitiesManager::wrap_permission_callback()} wraps every
 * permission_callback, and fires the same hook pair.
 *
 * A failure is recorded from the callback, since core has no failure hook below
 * WordPress 7.1. A success waits for `wp_after_execute_ability`, which fires
 * only after core accepted the output: recording it from the callback would log
 * `success` for a call that output validation then rejects.
 *
 * Calls from WP-CLI, the REST API or the plugin itself are not logged: Albert's
 * log is about what assistants did.
 *
 * @since 1.6.0
 */
class OtherPluginCallObserver implements Hookable {

	/**
	 * Names of the abilities whose callback this observer wrapped.
	 *
	 * @since 1.6.0
	 * @var array<string, true>
	 */
	private static array $wrapped = [];

	/**
	 * Wrap at registration, after Albert's permission wrapper at 20.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function register_hooks(): void {
		add_filter( 'wp_register_ability_args', [ $this, 'wrap_execute_callback' ], 30, 2 );
		add_action( 'wp_after_execute_ability', [ $this, 'record_success' ], 10, 3 );
	}

	/**
	 * Wrap another plugin's execute_callback so a connection call is logged.
	 *
	 * @param array<string, mixed> $args Ability registration arguments.
	 * @param string               $name Ability name.
	 *
	 * @return array<string, mixed> The arguments, with the callback wrapped when it belongs to another plugin.
	 * @since 1.6.0
	 */
	public function wrap_execute_callback( array $args, string $name ): array {
		$original = $args['execute_callback'] ?? null;

		// Albert's own abilities log themselves; the meta-tools only proxy one.
		if ( ! is_callable( $original ) || self::is_albert_ability( $original ) || AbilitiesRegistry::is_transport_ability( $name ) ) {
			return $args;
		}

		self::$wrapped[ $name ] = true;

		// Variadic: core passes the input only when the ability has an input schema.
		$args['execute_callback'] = static function ( ...$call_args ) use ( $original, $name ) {
			$input = $call_args[0] ?? null;

			try {
				$result = $original( ...$call_args );
			} catch ( Throwable $e ) {
				self::record( $name, $input, new WP_Error( 'ability_callback_exception', $e->getMessage() ) );
				throw $e;
			}

			if ( is_wp_error( $result ) ) {
				self::record( $name, $input, $result );
			}

			return $result;
		};

		return $args;
	}

	/**
	 * Record a call to a wrapped ability that core accepted.
	 *
	 * @param string $name   Ability name.
	 * @param mixed  $input  The normalised input.
	 * @param mixed  $result The validated result.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function record_success( string $name, $input, $result ): void {
		if ( isset( self::$wrapped[ $name ] ) ) {
			self::record( $name, $input, $result );
		}
	}

	/**
	 * Fire the after_execute hooks for a call made over an Albert connection.
	 *
	 * @param string $name   Ability name.
	 * @param mixed  $input  The input the callback received.
	 * @param mixed  $result What the callback returned.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private static function record( string $name, $input, $result ): void {
		if ( ConnectionContext::client_id() === null || ExecutionLogMarker::has( $name ) ) {
			return;
		}

		if ( $input === null ) {
			$input = [];
		}

		// The hook takes arrays; another plugin may return or accept a scalar.
		BaseAbility::fire_after_execute_hooks(
			$name,
			is_array( $input ) ? $input : [ 'input' => $input ],
			is_array( $result ) || is_wp_error( $result ) ? $result : [ 'result' => $result ],
			get_current_user_id()
		);
	}

	/**
	 * Whether a callback is a {@see BaseAbility}'s, which fires the hooks itself.
	 *
	 * Add-on abilities extend BaseAbility too, so this covers them.
	 *
	 * @param callable $callback The execute_callback.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	private static function is_albert_ability( callable $callback ): bool {
		return is_array( $callback ) && $callback[0] instanceof BaseAbility;
	}
}
