<?php
/**
 * Connection-scoped guard on gate-controlling options.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\OAuth\Server\ConnectionContext;

/**
 * Refuses writes to Albert's own gate-controlling options while a request is
 * driven by an assistant.
 *
 * Staging is the wrong answer for the switches that control the gate itself:
 * approving "turn safe mode off" is self-defeating. So these are refused
 * outright over a connection, not held. The guard is on the *resource*, keyed
 * on option name, so it is independent of which ability attempts the write —
 * it holds against any third-party ability that will ever be registered, not
 * only Albert's own surface.
 *
 * It hooks `sanitize_option_{$option}`, which fires on `add_option` *and*
 * `update_option`, including a direct `update_option()` call from an ability, so
 * a virtual option being created for the first time (`albert_safe_mode` has no
 * stored row until written) is covered too. A change to an existing option is
 * refused by returning its stored value; a first-time creation of an unset
 * option is left alone, so legitimate lazy creation (OAuth key material minted
 * while validating a token) still works.
 *
 * High-risk *site* options (`siteurl`, roles) are a different case: those are
 * held for approval by {@see RiskPolicy}, because a person may legitimately want
 * them changed. These are Albert's own controls, which nothing reached over a
 * connection should touch.
 *
 * @since 1.5.0
 */
class ConnectionGuard implements Hookable {

	/**
	 * The options refused over a connection.
	 *
	 * @since 1.5.0
	 * @var list<string>
	 */
	private const PROTECTED = [
		Gate::OPTION,
		'albert_disabled_abilities',
		'albert_allowed_users',
		'albert_privacy_mode',
		'albert_oauth_encryption_key',
		'albert_oauth_private_key',
		'albert_oauth_public_key',
	];

	/**
	 * The value a refused *creation* falls back to, per option.
	 *
	 * A control switch that has no stored row yet (`albert_safe_mode` is virtual
	 * until written) must not be creatable in a weakened state over a connection:
	 * an attempt to create it returns the secure default instead. Options absent
	 * here keep the "leave first creation alone" behaviour, so OAuth key material
	 * can still be minted lazily while a token is validated.
	 *
	 * @since 1.5.0
	 * @var array<string, mixed>
	 */
	private const SECURE_DEFAULT = [
		Gate::OPTION => Gate::DEFAULT_VALUE,
	];

	/**
	 * Bind the guard to each protected option's sanitize filter.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		foreach ( $this->protected_options() as $option ) {
			// Late priority so the guard has the final say over the stored value.
			add_filter( "sanitize_option_{$option}", [ $this, 'refuse_over_connection' ], 99, 2 );
		}
	}

	/**
	 * Keep the stored value when an assistant tries to change a protected option.
	 *
	 * @param mixed  $value  The value about to be written.
	 * @param string $option The option name.
	 *
	 * @return mixed The stored value over a connection (blocking the change), or
	 *               the incoming value otherwise / on first creation.
	 * @since 1.5.0
	 */
	public function refuse_over_connection( $value, string $option ) {
		if ( ConnectionContext::client_id() === null ) {
			return $value;
		}

		$sentinel = new \stdClass();
		$stored   = get_option( $option, $sentinel );

		// A change to an existing option is refused by keeping its stored value.
		if ( $stored !== $sentinel ) {
			return $stored;
		}

		// The option has no stored row. A control switch falls back to its secure
		// default so it cannot be created in a weakened state; anything else (OAuth
		// key material) is left to its legitimate first-time creation.
		return self::SECURE_DEFAULT[ $option ] ?? $value;
	}

	/**
	 * The options this guard protects.
	 *
	 * @return list<string>
	 * @since 1.5.0
	 */
	private function protected_options(): array {
		/**
		 * Filters the options refused over an assistant connection.
		 *
		 * Add-ons append their own gate-controlling option names.
		 *
		 * @since 1.5.0
		 *
		 * @param list<string> $options Option names.
		 */
		$options = apply_filters( 'albert/safe_mode/protected_options', self::PROTECTED );

		return is_array( $options ) ? $options : self::PROTECTED;
	}
}
