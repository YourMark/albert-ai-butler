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
use WP_Error;

/**
 * Logs other plugins' abilities when an assistant calls them over an Albert connection.
 *
 * `albert/abilities/after_execute` fires only from {@see BaseAbility::guarded_execute()},
 * so this fires it for everyone else from core's hooks: failures (including a
 * thrown exception, which core turns into a WP_Error) from `wp_ability_execute_result`,
 * successes from `wp_after_execute_ability`, which runs after output validation.
 * Ownership is decided at registration, because BaseAbility abilities fire the
 * hooks themselves, also on paths where the ExecutionLogMarker stays unset.
 *
 * Input and output reach the hook unmasked: another plugin's ability declares no
 * sensitive keys. Free's Logger stores only the outcome; a listener that stores
 * payloads must mask them itself.
 *
 * @since 1.6.0
 */
class OtherPluginCallObserver implements Hookable {

	/**
	 * Names of the abilities this observer logs.
	 *
	 * @since 1.6.0
	 * @var array<string, true>
	 */
	private static array $observed = [];

	/**
	 * Register the hooks.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function register_hooks(): void {
		add_filter( 'wp_register_ability_args', [ $this, 'note_ability' ], 30, 2 );
		add_filter( 'wp_ability_execute_result', [ $this, 'record_failure' ], 10, 3 );
		add_action( 'wp_after_execute_ability', [ $this, 'record_success' ], 10, 3 );
	}

	/**
	 * Note an ability another plugin registers; the arguments pass through untouched.
	 *
	 * The adapter's meta-tools are skipped: they only proxy the ability that gets logged.
	 *
	 * @param array<string, mixed> $args Ability registration arguments.
	 * @param string               $name Ability name.
	 *
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public function note_ability( array $args, string $name ): array {
		$callback  = $args['execute_callback'] ?? null;
		$is_albert = is_array( $callback ) && ( $callback[0] ?? null ) instanceof BaseAbility;

		if ( ! $is_albert && ! AbilitiesRegistry::is_transport_ability( $name ) ) {
			self::$observed[ $name ] = true;
		}

		return $args;
	}

	/**
	 * Record a failed call; the result passes through untouched.
	 *
	 * @param mixed  $result Whatever the execute callback returned.
	 * @param string $name   Ability name.
	 * @param mixed  $input  The normalised input.
	 *
	 * @return mixed
	 * @since 1.6.0
	 */
	public function record_failure( $result, string $name, $input = null ) {
		if ( $result instanceof WP_Error ) {
			self::record( $name, $input, $result );
		}

		return $result;
	}

	/**
	 * Record a call core accepted.
	 *
	 * @param string $name   Ability name.
	 * @param mixed  $input  The normalised input.
	 * @param mixed  $result The validated result.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function record_success( string $name, $input, $result ): void {
		self::record( $name, $input, (array) $result );
	}

	/**
	 * Fire the after_execute hooks for an observed call over an Albert connection.
	 *
	 * @param string                       $name   Ability name.
	 * @param mixed                        $input  The normalised input.
	 * @param array<mixed, mixed>|WP_Error $result The result.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private static function record( string $name, $input, array|WP_Error $result ): void {
		if ( ! isset( self::$observed[ $name ] ) || ConnectionContext::client_id() === null || ExecutionLogMarker::has( $name ) ) {
			return;
		}

		BaseAbility::fire_after_execute_hooks( $name, (array) $input, $result, get_current_user_id() );
	}
}
