<?php
/**
 * Settings validators
 *
 * @package Albert
 * @subpackage Settings
 * @since      1.4.0
 */

namespace Albert\Settings;

defined( 'ABSPATH' ) || exit;

use Albert\Media\UploadLinks\UploadLinkService;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\ConnectionRetention;
use Albert\Privacy\PrivacyMode;
use Albert\SafeMode\Gate;
use Albert\SafeMode\Interceptor;

/**
 * The rule that decides whether an override of a given setting is usable.
 *
 * A validator makes an override layer skippable: one it rejects is passed over
 * and {@see Value} continues to the next layer, so a typo in a `wp-config.php`
 * constant falls through to the stored value instead of pinning the site to
 * nonsense.
 *
 * **Keyed by option name, and reachable without a boot step**, both on purpose.
 *
 * *By option name*, because every caller has to reach the same verdict. When
 * the rule lived at the call site, {@see PrivacyMode::resolve()} applied one and
 * the Settings screen did not, so `define( 'ALBERT_PRIVACY_MODE', 'bananas' )`
 * made the screen render the field read-only, showing `bananas` and naming the
 * constant, while the site went on running the stored mode, locking an owner
 * out of a setting the constant did not control.
 *
 * *Without a boot step*, because these are consulted by code that has no reason
 * to wait for one. An earlier version published them on the validator filter
 * from a `Hookable`, which made `PrivacyMode::resolve()` correct only after
 * `plugins_loaded` had fired. A fine assumption right up until something asks
 * a privacy question earlier, at which point the fall-through quietly stops
 * happening. A plain static map has no such window.
 *
 * Add-ons declare their own with `albert/settings/validator/{option_name}`,
 * which {@see Value::validator()} consults first.
 *
 * @since 1.4.0
 */
class Validators {

	/**
	 * Free's own validator for one setting, if it has one.
	 *
	 * @since 1.4.0
	 *
	 * @param string $option_name The option name.
	 *
	 * @return callable|null `fn( $value ): bool`, or null when Free does not
	 *                       constrain this option.
	 */
	public static function for_option( string $option_name ): ?callable {
		$validators = self::all();

		return $validators[ $option_name ] ?? null;
	}

	/**
	 * Every setting Free constrains, and how.
	 *
	 * Each of these is a setting an owner can see on the Settings screen, so
	 * each of them can be reported as overridden, which is exactly why each of
	 * them needs the reader and the screen to agree on whether a given override
	 * is usable.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, callable> Option name => `fn( $value ): bool`.
	 */
	private static function all(): array {
		// A day count: whole, not negative, and inside the range the field
		// declares. `is_numeric` first, because a constant is as likely to hold
		// "30" as 30 and both are perfectly good answers.
		$days = static function ( $value ): bool {
			return is_numeric( $value ) && (int) $value >= 0 && (int) $value <= 3650;
		};

		return [
			// The vocabulary check lives on the enum that owns the vocabulary;
			// this only points at it.
			'albert_privacy_mode'                  => static function ( $value ): bool {
				return is_scalar( $value ) && PrivacyMode::try_parse( (string) $value ) !== null;
			},
			// Safe mode is a plain on/off switch; anything else falls through to
			// the default rather than pinning the site to a nonsense value.
			Gate::OPTION                           => static function ( $value ): bool {
				return $value === 'on' || $value === 'off';
			},
			// A window outside the allowed range is not usable, so an override
			// naming one falls through rather than pinning the site to it.
			Interceptor::TTL_OPTION                => static function ( $value ): bool {
				return is_numeric( $value )
					&& (int) $value >= Interceptor::MIN_TTL_MINUTES
					&& (int) $value <= Interceptor::MAX_TTL_MINUTES;
			},
			AllowedUsers::EXPIRY_OPTION            => $days,
			ConnectionRetention::NEVER_USED_OPTION => $days,
			ConnectionRetention::IDLE_OPTION       => $days,
			UploadLinkService::MAX_BYTES_OPTION    => static function ( $value ): bool {
				return is_numeric( $value )
					&& (int) $value >= 1
					&& (int) $value <= UploadLinkService::MAX_SETTABLE_MB;
			},
		];
	}
}
