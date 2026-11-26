<?php
/**
 * Connected Result Redactor
 *
 * @package Albert
 * @subpackage Privacy
 * @since      1.6.0
 */

namespace Albert\Privacy;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;
use Albert\OAuth\Server\ConnectionContext;
use WP_Error;

/**
 * Applies the privacy mode to the result of every ability an assistant calls
 * over an Albert connection: Albert's, WordPress's and other plugins' alike.
 *
 * Runs on core's `wp_ability_execute_result`, so the assistant and the log see
 * the redacted result. Only the outermost call is redacted: an ability that
 * calls another internally gets the real data, as it would without Albert.
 *
 * Output validation judges the unredacted result. A masked email is no longer
 * a valid `format: email`, and that is the redaction's doing, not the ability's.
 *
 * A result returned from `wp_pre_execute_ability` skips core's execution path
 * and so this redaction too.
 *
 * @since 1.6.0
 */
class ConnectedResultRedactor implements Hookable {

	/**
	 * Unredacted results awaiting output validation, per ability.
	 *
	 * @since 1.6.0
	 * @var array<string, list<mixed>>
	 */
	private array $unredacted = [];

	/**
	 * Register the hooks.
	 *
	 * The result is redacted late, after other plugins have shaped it, but
	 * before the logging observer at PHP_INT_MAX. Output validation is swapped
	 * first, so filters after it still see core's question about the real data.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function register_hooks(): void {
		add_action( 'wp_before_execute_ability', [ $this, 'enter' ], 1, 1 );
		add_filter( 'wp_ability_execute_result', [ $this, 'redact' ], PHP_INT_MAX - 1, 3 );
		add_filter( 'wp_ability_validate_output', [ $this, 'validate_unredacted' ], 1, 3 );
	}

	/**
	 * Start a new outermost connected call with no results awaiting validation.
	 *
	 * A result whose validation never ran, as when a later filter turned it into
	 * an error, must not be judged in place of this call's.
	 *
	 * @param string $ability_name The ability about to run.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function enter( string $ability_name ): void {
		if ( self::applies_to( $ability_name ) && self::is_outermost() ) {
			$this->unredacted = [];
		}
	}

	/**
	 * Redact the result of the outermost connected call.
	 *
	 * @param mixed  $result       The callback's result, or a WP_Error.
	 * @param string $ability_name The ability that ran.
	 * @param mixed  $input        The normalised input; carries `reveal_personal_data`.
	 *
	 * @return mixed The redacted result.
	 * @since 1.6.0
	 */
	public function redact( mixed $result, string $ability_name, mixed $input = null ): mixed {
		if ( ! self::applies_to( $ability_name ) || $result instanceof WP_Error || ! self::is_outermost() ) {
			return $result;
		}

		$redacted = PiiPolicy::redact_value( $result, is_array( $input ) ? $input : [] );

		if ( $redacted !== $result ) {
			$this->unredacted[ $ability_name ][] = $result;
		}

		return $redacted;
	}

	/**
	 * Validate the unredacted result in place of the redacted one.
	 *
	 * Mirrors {@see \WP_Ability::validate_output()}.
	 *
	 * @param mixed  $validity     Core's verdict on the redacted output.
	 * @param mixed  $output       The redacted output.
	 * @param string $ability_name The ability that ran.
	 *
	 * @return mixed Core's verdict on the real output, or the value unchanged.
	 * @since 1.6.0
	 */
	public function validate_unredacted( mixed $validity, mixed $output, string $ability_name ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Hook signature.
		if ( empty( $this->unredacted[ $ability_name ] ) ) {
			return $validity;
		}

		$raw = array_pop( $this->unredacted[ $ability_name ] );
		if ( $this->unredacted[ $ability_name ] === [] ) {
			unset( $this->unredacted[ $ability_name ] );
		}

		$ability = wp_get_ability( $ability_name );
		$schema  = $ability !== null ? $ability->get_output_schema() : [];
		if ( empty( $schema ) ) {
			return true;
		}

		$valid = rest_validate_value_from_schema( $raw, $schema, 'output' );
		if ( ! is_wp_error( $valid ) ) {
			return true;
		}

		return new WP_Error(
			'ability_invalid_output',
			sprintf(
				/* translators: 1: ability name, 2: error message. */
				__( 'Ability "%1$s" has invalid output. Reason: %2$s', 'albert-ai-butler' ),
				$ability_name,
				$valid->get_error_message()
			)
		);
	}

	/**
	 * Whether no other connected call is running around the current one.
	 *
	 * Read from the call stack rather than counted in hooks: a callback that
	 * throws never reaches the result filter, and a stale count would leave
	 * every later call in the request unredacted. Abilities are counted once
	 * each, so an `execute()` override calling its parent is still one call.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	private static function is_outermost(): bool {
		$running = [];

		foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Detects nesting, nothing is output.
			$ability = $frame['object'] ?? null;
			if ( $frame['function'] === 'execute' && $ability instanceof \WP_Ability && self::applies_to( $ability->get_name() ) ) {
				$running[ spl_object_id( $ability ) ] = true;
			}
		}

		return count( $running ) <= 1;
	}

	/**
	 * Whether a call is an assistant's, over an Albert connection.
	 *
	 * The adapter's meta-tools are skipped: they carry the inner ability's
	 * result, which is redacted on its own call.
	 *
	 * @param string $ability_name The ability.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	private static function applies_to( string $ability_name ): bool {
		return ConnectionContext::client_id() !== null && ! AbilitiesRegistry::is_transport_ability( $ability_name );
	}
}
