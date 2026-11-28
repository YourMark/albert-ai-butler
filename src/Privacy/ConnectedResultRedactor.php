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
 * On Balanced it also offers `reveal_personal_data` on every ability, and takes
 * it out of the input again before the ability sees it.
 *
 * @since 1.6.0
 */
class ConnectedResultRedactor implements Hookable {

	/**
	 * The input that asks for the real data.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public const REVEAL_INPUT = 'reveal_personal_data';

	/**
	 * Unredacted results awaiting output validation, per ability.
	 *
	 * @since 1.6.0
	 * @var array<string, list<mixed>>
	 */
	private array $unredacted = [];

	/**
	 * Whether the outermost connected call asked for the real data.
	 *
	 * @since 1.6.0
	 * @var bool
	 */
	private bool $reveal = false;

	/**
	 * Abilities given the reveal input by Albert, mapped to whether they had no input schema.
	 *
	 * @since 1.6.0
	 * @var array<string, bool>
	 */
	private array $offered = [];

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
		add_action( 'wp_ability_invoked', [ $this, 'enter' ], 1, 1 );
		add_filter( 'wp_register_ability_args', [ $this, 'offer_reveal' ], 30, 2 );
		add_filter( 'wp_ability_normalize_input', [ $this, 'take_reveal' ], 1, 2 );
		add_filter( 'wp_ability_execute_result', [ $this, 'redact' ], PHP_INT_MAX - 1, 2 );
		add_filter( 'wp_ability_validate_output', [ $this, 'validate_unredacted' ], 1, 3 );
	}

	/**
	 * Start a new outermost connected call: no reveal asked, no results awaiting validation.
	 *
	 * A result whose validation never ran, as when a later filter turned it into
	 * an error, must not be judged in place of this call's.
	 *
	 * @param string $ability_name The ability invoked.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function enter( string $ability_name ): void {
		if ( self::applies_to( $ability_name ) && self::is_outermost() ) {
			$this->unredacted = [];
			$this->reveal     = false;
		}
	}

	/**
	 * Add the optional `reveal_personal_data` input to an ability's schema, on Balanced.
	 *
	 * Only where it fits: an object schema or none. An ability that declares the
	 * input itself keeps it and reads it as before. One without an input schema
	 * gets one, and its callbacks are still called without input, as core did.
	 *
	 * @param array<string, mixed> $args The registration arguments.
	 * @param string               $name The ability being registered.
	 *
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public function offer_reveal( array $args, string $name ): array {
		if ( PrivacyMode::resolve() !== PrivacyMode::Balanced || AbilitiesRegistry::is_transport_ability( $name ) ) {
			return $args;
		}

		$schema = $args['input_schema'] ?? [];
		if ( ! is_array( $schema ) ) {
			return $args;
		}

		$no_schema = $schema === [];
		if ( $no_schema ) {
			$schema = [
				'type'                 => 'object',
				'additionalProperties' => false,
			];
			foreach ( [ 'execute_callback', 'permission_callback' ] as $key ) {
				if ( isset( $args[ $key ] ) && is_callable( $args[ $key ] ) ) {
					$callback     = $args[ $key ];
					$args[ $key ] = static fn() => call_user_func( $callback );
				}
			}
		} elseif ( ( $schema['type'] ?? null ) !== 'object' ) {
			return $args;
		}

		// `new stdClass()` is a common way to declare no properties, so it serialises as `{}`.
		$properties = $schema['properties'] ?? [];
		if ( is_object( $properties ) ) {
			$properties = get_object_vars( $properties );
		}
		if ( ! is_array( $properties ) || isset( $properties[ self::REVEAL_INPUT ] ) ) {
			return $args;
		}

		$schema['properties']                       = $properties;
		$schema['properties'][ self::REVEAL_INPUT ] = [
			'type'        => 'boolean',
			'description' => 'Return the real personal data instead of masked values. Anyone allowed to run this ability may ask; only on Balanced privacy mode.',
		];

		$args['input_schema']   = $schema;
		$this->offered[ $name ] = $no_schema;

		return $args;
	}

	/**
	 * Note whether the call asks for the real data, and take the input Albert added back out.
	 *
	 * Runs before validation, so a schema's own rules (`minProperties`, `anyOf`)
	 * judge only the ability's own input, and its callbacks never receive it.
	 *
	 * @param mixed  $input        The input.
	 * @param string $ability_name The ability.
	 *
	 * @return mixed The input without `reveal_personal_data` where Albert added it.
	 * @since 1.6.0
	 */
	public function take_reveal( mixed $input, string $ability_name ): mixed {
		if ( self::applies_to( $ability_name ) && self::is_outermost() ) {
			$this->reveal = is_array( $input ) && ! empty( $input[ self::REVEAL_INPUT ] );
		}

		if ( ! isset( $this->offered[ $ability_name ] ) ) {
			return $input;
		}

		// Its schema is Albert's, an object, which null would fail.
		if ( $input === null && $this->offered[ $ability_name ] ) {
			return [];
		}

		// Anything but a boolean stays, for the schema to refuse.
		if ( is_array( $input ) && is_bool( $input[ self::REVEAL_INPUT ] ?? null ) ) {
			unset( $input[ self::REVEAL_INPUT ] );
		}

		return $input;
	}

	/**
	 * Redact the result of the outermost connected call.
	 *
	 * @param mixed  $result       The callback's result, or a WP_Error.
	 * @param string $ability_name The ability that ran.
	 *
	 * @return mixed The redacted result.
	 * @since 1.6.0
	 */
	public function redact( mixed $result, string $ability_name ): mixed {
		if ( ! self::applies_to( $ability_name ) || $result instanceof WP_Error || ! self::is_outermost() ) {
			return $result;
		}

		$redacted = PiiPolicy::redact_value( $result, [ self::REVEAL_INPUT => $this->reveal ], self::anonymizer_options( $ability_name ) );

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
	 * What counts as personal data in one ability's result.
	 *
	 * Albert's own abilities return known shapes, so they get the full list,
	 * names and addresses included. Another plugin's result can be anything, a
	 * settings array or a form, so only keys that are personal everywhere apply.
	 *
	 * @param string $ability_name The ability.
	 *
	 * @return array<string, mixed> Options for {@see Anonymizer::anonymize()}.
	 * @since 1.6.0
	 */
	private static function anonymizer_options( string $ability_name ): array {
		$keys = str_starts_with( $ability_name, 'albert/' ) ? Anonymizer::fields() : Anonymizer::essential_fields();

		/**
		 * Filters the keys masked in one ability's result on a connected call.
		 *
		 * Grouped by rule, as `albert/privacy/pii_fields`. Albert's own abilities
		 * start from that full list; any other ability only from email, phone,
		 * IP address, password hash and token keys.
		 *
		 * @since 1.6.0
		 *
		 * @param array<string, array<int, string>> $keys         Key names per rule.
		 * @param string                            $ability_name The ability whose result is masked.
		 */
		$filtered = apply_filters( 'albert/privacy/personal_keys', $keys, $ability_name );

		return [
			'rules'     => is_array( $filtered ) ? $filtered : $keys,
			// Woo's customers are customers; everyone else is a WordPress user.
			'ref_label' => str_starts_with( $ability_name, 'albert/woo-' ) ? 'Customer' : 'User',
		];
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
