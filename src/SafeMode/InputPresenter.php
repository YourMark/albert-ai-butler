<?php
/**
 * Staged-input presentation.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares a staged call's input for display on the approvals screen.
 *
 * **This is a display convenience, not a control, and the difference matters.**
 * It masks values whose *key name* looks like a secret. That works for the names
 * it happens to know and does nothing at all for a third-party ability that
 * calls its credential something else. Nobody should read a masked screen as
 * evidence that the queue holds no secrets.
 *
 * The actual controls are elsewhere and are the ones to rely on: Albert's own
 * abilities no longer accept a password at all, and {@see \Albert\Cron\PendingActionSweep}
 * deletes decided rows so nothing lingers. Masking only stops a credential being
 * read over somebody's shoulder, or sitting in a screenshot, in the window
 * between staging and deletion.
 *
 * **Masking never touches what is stored.** Approval replays the input exactly
 * as captured, so redacting at rest would break the call it exists to run.
 *
 * @since 1.5.0
 */
class InputPresenter {

	/**
	 * Key names masked before display.
	 *
	 * Matched case-insensitively as substrings, so `user_pass`, `password`,
	 * `apiKey` and `refresh_token` are all caught by a shorter list than it
	 * looks: `pass` covers the password family, and listing `password` beside it
	 * would be dead weight.
	 *
	 * Short on purpose. `pass` also matches `bypass` and `compass`, which is the
	 * cost of substring matching and an acceptable one here: over-masking makes a
	 * screen less useful, never less safe, and this is a display convenience
	 * rather than a control.
	 *
	 * @since 1.5.0
	 * @var list<string>
	 */
	private const SECRET_HINTS = [ 'pass', 'secret', 'token', 'api_key', 'apikey', 'credential', 'private_key' ];

	/**
	 * What is shown in place of a masked value.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const MASK = '[hidden]';

	/**
	 * A copy of the input safe to print on screen.
	 *
	 * @param array<mixed> $input        The captured input.
	 * @param string       $ability_name The ability it was staged for.
	 *
	 * @return array<mixed>
	 * @since 1.5.0
	 */
	public static function for_display( array $input, string $ability_name ): array {
		$masked = self::mask( $input );

		/**
		 * Filters a staged call's input before the approvals screen prints it.
		 *
		 * For an ability whose input carries something that should not be read
		 * over somebody's shoulder under a key name Albert cannot guess. Return
		 * the array with those values replaced.
		 *
		 * Display only. The stored input is untouched and approval still replays
		 * the real call, so masking here cannot break anything, and equally
		 * cannot be relied on as a security control.
		 *
		 * @since 1.5.0
		 *
		 * @param array<mixed> $masked       The input with known secret-ish keys already masked.
		 * @param string       $ability_name The ability the input was staged for.
		 * @param array<mixed> $input        The original, unmasked input.
		 */
		$filtered = apply_filters( 'albert/safe_mode/display_input', $masked, $ability_name, $input );

		return is_array( $filtered ) ? $filtered : $masked;
	}

	/**
	 * Replace values under secret-looking keys, at every depth.
	 *
	 * Recursive, unlike {@see \Albert\Abstracts\BaseAbility::redact_sensitive_output()},
	 * which is top-level only. That one can impose a contract on ability authors
	 * ("flatten it or do not return it"); this one runs over input from
	 * abilities Albert has never seen and cannot impose anything, so it has to
	 * look everywhere.
	 *
	 * @param array<mixed> $input The input to mask.
	 *
	 * @return array<mixed>
	 * @since 1.5.0
	 */
	private static function mask( array $input ): array {
		foreach ( $input as $key => $value ) {
			if ( is_array( $value ) ) {
				$input[ $key ] = self::mask( $value );
				continue;
			}

			if ( is_string( $key ) && self::looks_secret( $key ) && $value !== null && $value !== '' ) {
				$input[ $key ] = self::MASK;
			}
		}

		return $input;
	}

	/**
	 * Whether a key name reads like it holds a credential.
	 *
	 * @param string $key The input key.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	private static function looks_secret( string $key ): bool {
		$needle = strtolower( $key );

		foreach ( self::SECRET_HINTS as $hint ) {
			if ( str_contains( $needle, $hint ) ) {
				return true;
			}
		}

		return false;
	}
}
