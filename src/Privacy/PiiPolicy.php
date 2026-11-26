<?php
/**
 * PII Reveal Policy
 *
 * @package Albert
 * @subpackage Privacy
 * @since      1.3.0
 */

namespace Albert\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * PiiPolicy class
 *
 * Decides whether a tool call is permitted to see un-anonymised personal
 * data. Revealing is deliberately narrow: it is only ever allowed in
 * `Balanced` mode, when the caller explicitly opts in via a
 * `reveal_personal_data` argument, and when the current user holds the
 * gating capability. `Strict` never reveals; `Off` disables anonymisation
 * wholesale and is handled by the caller, not here.
 *
 * It also exposes the ability-facing {@see PiiPolicy::redact()} entrypoint that
 * resolves the active mode and applies the correct treatment via
 * {@see Anonymizer}.
 *
 * @since 1.3.0
 */
class PiiPolicy {

	/**
	 * Redact personal data in an ability's success payload.
	 *
	 * The ability-facing entrypoint: an ability that returns PII wraps its
	 * success payload in this method just before returning it. It resolves the
	 * active privacy mode and the caller's reveal request and applies:
	 *
	 *  - `Off`      — payment/card data removed, everything else returned raw.
	 *  - authorised reveal (Balanced + opt-in + capability) — same as `Off`.
	 *  - otherwise  — full anonymisation via {@see Anonymizer::anonymize()}.
	 *
	 * {@see ConnectedResultRedactor} redacts every connected call afterwards,
	 * so new code should not call this; it stays for add-ons that do.
	 *
	 * The top-level keys of an ability payload are always string-keyed, and this
	 * method never re-indexes that top array, so the return is narrowed to
	 * `array<string, mixed>` to slot straight into an ability's `execute()`
	 * return type.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $data The ability's result payload.
	 * @param array<string, mixed> $args The ability's input arguments (carries the
	 *                                    optional `reveal_personal_data` opt-in).
	 * @param array<string, mixed> $opts Anonymiser options forwarded to
	 *                                    {@see Anonymizer::anonymize()} — e.g.
	 *                                    `mask_context_names` for pure person records.
	 *
	 * @return array<string, mixed> The redacted (or payment-stripped) payload.
	 */
	public static function redact( array $data, array $args = [], array $opts = [] ): array {
		$mode = PrivacyMode::resolve();

		// No anonymisation, but payment/card data is never allowed through.
		if ( $mode === PrivacyMode::Off ) {
			$result = Anonymizer::strip_payment_data( $data );
		} elseif ( self::allows_reveal( $args, $mode, $opts ) ) {
			// Deliberate, authorised reveal — still strip payment/card data.
			$result = Anonymizer::strip_payment_data( $data );
		} else {
			$result = Anonymizer::anonymize( $data, $opts );
		}

		/**
		 * The anonymiser preserves the string-keyed top level it was given, so
		 * the payload can be narrowed back to the ability return shape.
		 *
		 * @var array<string, mixed> $result
		 */
		return $result;
	}

	/**
	 * Redact personal data in any ability result, whatever its shape.
	 *
	 * Only called once core has let the caller run the ability, so on Balanced
	 * the opt-in alone reveals: whoever may use an ability may see what it returns.
	 * An object is redacted as the JSON it becomes, and comes back as itself
	 * when nothing in it changed. A bare string is masked when it is an email address.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed                $data The ability's result.
	 * @param array<string, mixed> $args The ability's input.
	 *
	 * @return mixed The redacted result.
	 */
	public static function redact_value( mixed $data, array $args = [] ): mixed {
		$value = is_object( $data ) ? json_decode( (string) wp_json_encode( $data ), true ) : $data;

		if ( ! is_array( $value ) && ! is_string( $value ) ) {
			return $data;
		}

		$mode   = PrivacyMode::resolve();
		$reveal = $mode === PrivacyMode::Off
			|| ( $mode === PrivacyMode::Balanced && ! empty( $args['reveal_personal_data'] ) );

		if ( is_string( $value ) ) {
			return $reveal ? $value : Anonymizer::anonymize( [ $value ] )[0];
		}

		$redacted = $reveal ? Anonymizer::strip_payment_data( $value ) : Anonymizer::anonymize( $value );

		return $redacted === $value ? $data : $redacted;
	}

	/**
	 * Whether raw personal data may be revealed for this call.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $args The tool-call arguments.
	 * @param PrivacyMode          $mode The resolved privacy mode.
	 * @param array<string, mixed> $opts Redaction options; `reveal_capability` sets the
	 *                                   gating capability for this call (typically the
	 *                                   ability's own `check_permission()` capability).
	 *
	 * @return bool
	 */
	public static function allows_reveal( array $args, PrivacyMode $mode, array $opts = [] ): bool {
		if ( $mode !== PrivacyMode::Balanced ) {
			return false;
		}

		// Schema defaults are not injected into $args, so a missing key is false.
		if ( empty( $args['reveal_personal_data'] ) ) {
			return false;
		}

		return current_user_can( self::resolve_reveal_capability( $opts ) );
	}

	/**
	 * Resolve the capability that gates revealing personal data.
	 *
	 * Precedence: an explicit `$opts['reveal_capability']` (an ability passes its
	 * own `check_permission()` capability, so "if you may call it, you may reveal
	 * it"), otherwise the `albert/privacy/reveal_capability` filter, defaulting to
	 * `manage_options` — a capability that exists on every site, unlike
	 * `manage_woocommerce`.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $opts Redaction options.
	 *
	 * @return string The gating capability.
	 */
	private static function resolve_reveal_capability( array $opts ): string {
		if ( isset( $opts['reveal_capability'] ) && is_string( $opts['reveal_capability'] ) && $opts['reveal_capability'] !== '' ) {
			return $opts['reveal_capability'];
		}

		/**
		 * Filters the capability required to reveal personal data.
		 *
		 * Consulted only when a caller has not passed an explicit
		 * `reveal_capability` option.
		 *
		 * @since 1.3.0
		 *
		 * @param string $capability The gating capability (default `manage_options`).
		 */
		$capability = apply_filters( 'albert/privacy/reveal_capability', 'manage_options' );

		return ( is_string( $capability ) && $capability !== '' ) ? $capability : 'manage_options';
	}
}
