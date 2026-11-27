<?php
/**
 * Privacy Mode
 *
 * @package Albert
 * @subpackage Privacy
 * @since      1.3.0
 */

namespace Albert\Privacy;

defined( 'ABSPATH' ) || exit;

use Albert\Settings\Value;

/**
 * PrivacyMode enum
 *
 * The three anonymisation modes and the precedence used to resolve the
 * active one:
 *
 *  1. `ALBERT_PRIVACY_MODE` constant (highest)
 *  2. `albert/privacy/mode` filter
 *  3. `albert_privacy_mode` option
 *  4. default `Balanced` (lowest)
 *
 * At each tier an unrecognised value is ignored and the next tier is
 * consulted; if none yields a valid mode the default applies.
 *
 * @since 1.3.0
 */
enum PrivacyMode: string {

	/**
	 * Personal data is always anonymised — never revealed.
	 */
	case Strict = 'strict';

	/**
	 * Anonymised by default; anyone allowed to run an ability may reveal its result.
	 */
	case Balanced = 'balanced';

	/**
	 * No anonymisation (payment/card data is still always removed).
	 */
	case Off = 'off';

	/**
	 * Resolve the active privacy mode from its configuration sources.
	 *
	 * @since 1.3.0
	 *
	 * @return self
	 */
	public static function resolve(): self {
		// Constant -> albert/settings/value/albert_privacy_mode -> option.
		// `albert/privacy/mode` still works and is still the documented way to
		// set this in code; Settings\Overrides feeds it into the filter layer,
		// which is also what lets the Settings screen show it as in force.
		//
		// No validator is passed. A layer holding something that is not one of
		// the three modes still has to be skipped, since a typo in
		// ALBERT_PRIVACY_MODE must fall through rather than resolve to nonsense.
		// But the rule for that is declared once, against the option, by
		// Settings\Overrides, and Value finds it from the name alone. Passing
		// one here is what made this method and the Settings screen disagree:
		// the screen asked without a validator, accepted the typo, and locked
		// the field to a value the site was not using.
		$value = Value::get( 'albert_privacy_mode', '' );

		return self::try_parse( is_scalar( $value ) ? (string) $value : '' ) ?? self::Balanced;
	}

	/**
	 * Sanitize a raw value to a valid mode string (default on failure).
	 *
	 * Suitable as a settings `sanitize_callback`.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string One of `strict`, `balanced`, or `off`.
	 */
	public static function sanitize( $value ): string {
		$mode = is_scalar( $value ) ? self::try_parse( (string) $value ) : null;

		return ( $mode ?? self::Balanced )->value;
	}

	/**
	 * Parse a raw string to a case, or null when it is not one of the three.
	 *
	 * Public because it is the vocabulary check, and more than this enum needs
	 * to make it: {@see \Albert\Settings\Overrides} answers the option's
	 * `albert/settings/validator/albert_privacy_mode` with it, so the Settings
	 * screen and {@see self::resolve()} judge an override by the same rule
	 * rather than each keeping a copy.
	 *
	 * @since 1.3.0
	 * @since 1.4.0 Renamed from the private `normalize()` and made public.
	 *
	 * @param string $value The raw value.
	 *
	 * @return self|null
	 */
	public static function try_parse( string $value ): ?self {
		return self::tryFrom( strtolower( trim( $value ) ) );
	}
}
