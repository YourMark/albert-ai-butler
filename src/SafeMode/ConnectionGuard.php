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
 * Blog options go through `sanitize_option_{$option}`, which fires for both
 * `add_option()` and `update_option()`. Network options have their own store and
 * their own hooks, so they get their own; the shared sanitize filter cannot tell
 * the two apart, and an earlier version read the blog value while a network write
 * was in flight.
 *
 * Creation is refused as well as change. Most of these have no stored row until
 * something writes one, so allowing a first write would have been a bypass
 * wearing the word "creation". Only the OAuth key material may be created, since
 * it is minted lazily during token validation.
 *
 * Deletes can only be detected. `delete_option()` fires actions and no filter,
 * so there is nothing to return and the hook that records them is named for what
 * it can actually claim.
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
		Interceptor::TTL_OPTION,
		'albert_disabled_abilities',
		'albert_allowed_users',
		'albert_privacy_mode',
		'albert_oauth_encryption_key',
		'albert_oauth_private_key',
		'albert_oauth_public_key',
	];

	/**
	 * The value a refused *creation* falls back to, per control option.
	 *
	 * Blocking a change is not enough on its own: most of these have no stored
	 * row until something writes one, and `albert_privacy_mode` in particular is
	 * absent on any site that predates 1.4.0 and never opened Settings. Letting
	 * a first write through would have set anonymisation to `off` and called it
	 * creation.
	 *
	 * Only the OAuth key material is absent here, because it is genuinely minted
	 * lazily while a token is validated and a secure default for a keypair is a
	 * contradiction. It is still refused once it exists.
	 *
	 * @since 1.5.0
	 * @var array<string, mixed>
	 */
	private const SECURE_DEFAULT = [
		Gate::OPTION                => Gate::DEFAULT_VALUE,
		Interceptor::TTL_OPTION     => Interceptor::DEFAULT_TTL_MINUTES,
		'albert_disabled_abilities' => [],
		'albert_allowed_users'      => [],
		'albert_privacy_mode'       => 'strict',
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
			// This one filter covers the network path too: add_network_option()
			// and update_network_option() both call sanitize_option(), so there
			// is no separate multisite write filter to bind. There is no
			// `sanitize_site_option_*` in core; an earlier version hooked it and
			// the callback simply never ran.
			add_filter( "sanitize_option_{$option}", [ $this, 'refuse_over_connection' ], 99, 2 );

			// Multisite writes their own store and need their own hooks. These
			// are real filters with the network value to hand, so a network write
			// is refused properly rather than coerced through the shared
			// sanitize filter, which cannot tell the two stores apart.
			add_filter( "pre_update_site_option_{$option}", [ $this, 'refuse_network_update' ], 99, 3 );
			add_filter( "pre_add_site_option_{$option}", [ $this, 'refuse_network_add' ], 99, 2 );
			add_action( "pre_delete_site_option_{$option}", [ $this, 'note_network_delete_attempt' ] );
		}

		// Deletes cannot be prevented, only seen: `delete_option()` fires actions
		// only, so there is nothing to return. Detection still earns its place,
		// because an assistant deleting `albert_disabled_abilities` switches every
		// ability back on and would otherwise leave no trace.
		add_action( 'delete_option', [ $this, 'note_delete_attempt' ] );
	}

	/**
	 * Record an attempt to delete a protected network option.
	 *
	 * `pre_delete_site_option_{$option}` passes the option name as its first
	 * argument, so the signature matches {@see self::note_delete_attempt()}; it
	 * exists separately only because the hook is registered per option and so
	 * needs no list scan.
	 *
	 * @param string $option The option being deleted.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function note_network_delete_attempt( string $option ): void {
		if ( ConnectionContext::client_id() === null ) {
			return;
		}

		$this->announce_delete( $option );
	}

	/**
	 * Record an attempt to delete a protected option over a connection.
	 *
	 * Detection, not prevention, and the name says so. Fires before the row is
	 * removed but cannot stop it.
	 *
	 * `delete_option` is a global action that `delete_transient()` also routes
	 * through, so this is a hot path: the connection check comes first because
	 * it is a static null comparison, and the list scan only happens for the
	 * few requests an assistant is actually driving.
	 *
	 * @param string $option The option being deleted.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function note_delete_attempt( string $option ): void {
		if ( ConnectionContext::client_id() === null ) {
			return;
		}

		if ( ! in_array( $option, $this->protected_options(), true ) ) {
			return;
		}

		/**
		 * Fires when an assistant deletes one of Albert's protected options.
		 *
		 * Deliberately a different hook from `option_write_blocked`: that one
		 * says the write was refused, and this one cannot make that claim.
		 *
		 * @since 1.5.0
		 *
		 * @param string $option The option being deleted.
		 */
		$this->announce_delete( $option );
	}

	/**
	 * Announce a detected delete of a protected option.
	 *
	 * @param string $option The option being deleted.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function announce_delete( string $option ): void {
		/**
		 * Fires when an assistant deletes one of Albert's protected options.
		 *
		 * Deliberately a different hook from `option_write_blocked`: that one
		 * says the write was refused, and this one cannot make that claim.
		 *
		 * @since 1.5.0
		 *
		 * @param string $option The option being deleted.
		 */
		do_action( 'albert/safe_mode/option_delete_detected', $option );

		AuditTrail::option_blocked( $option, 'delete' );
	}

	/**
	 * Refuse a single-site option change made over a connection.
	 *
	 * @param mixed  $value  The value about to be written.
	 * @param string $option The option name.
	 *
	 * @return mixed
	 * @since 1.5.0
	 */
	public function refuse_over_connection( $value, string $option ) {
		return $this->refuse( $value, $option );
	}

	/**
	 * Refuse a change to an existing network option.
	 *
	 * @param mixed  $value     The value about to be written.
	 * @param mixed  $old_value The stored value.
	 * @param string $option    The option name.
	 *
	 * @return mixed The stored value over a connection, the incoming value otherwise.
	 * @since 1.5.0
	 */
	public function refuse_network_update( $value, $old_value, string $option ) {
		if ( ConnectionContext::client_id() === null ) {
			return $value;
		}

		$this->note_blocked( $option );

		return $old_value;
	}

	/**
	 * Refuse the creation of a network option.
	 *
	 * @param mixed  $value  The value about to be written.
	 * @param string $option The option name.
	 *
	 * @return mixed The secure default over a connection, the incoming value otherwise.
	 * @since 1.5.0
	 */
	public function refuse_network_add( $value, string $option ) {
		if ( ConnectionContext::client_id() === null ) {
			return $value;
		}

		if ( ! array_key_exists( $option, self::SECURE_DEFAULT ) ) {
			return $value;
		}

		$this->note_blocked( $option );

		return self::SECURE_DEFAULT[ $option ];
	}

	/**
	 * Keep the stored value when an assistant tries to change a protected option.
	 *
	 * A sanitize filter can only coerce the value, not raise an error, so the
	 * write "succeeds" from the caller's point of view while storing nothing new.
	 * That silent coercion is exactly the kind of attempt worth seeing, so every
	 * block fires an action an observer (Premium's log) can record.
	 *
	 * @param mixed  $value  The value about to be written.
	 * @param string $option The option name.
	 *
	 * @return mixed The stored value over a connection (blocking the change), the
	 *               secure default for a control switch, or the incoming value
	 *               otherwise / on legitimate first creation.
	 * @since 1.5.0
	 */
	private function refuse( $value, string $option ) {
		if ( ConnectionContext::client_id() === null ) {
			return $value;
		}

		$sentinel = new \stdClass();
		$stored   = get_option( $option, $sentinel );

		// A change to an existing option is refused by keeping its stored value.
		if ( $stored !== $sentinel ) {
			$this->note_blocked( $option );

			return $stored;
		}

		// A control switch with no stored row falls back to its secure default so
		// it cannot be created in a weakened state.
		if ( array_key_exists( $option, self::SECURE_DEFAULT ) ) {
			$this->note_blocked( $option );

			return self::SECURE_DEFAULT[ $option ];
		}

		// Anything else unset (OAuth key material) is left to its legitimate
		// first-time creation.
		return $value;
	}

	/**
	 * Announce a blocked write so it can be logged.
	 *
	 * @param string $option The option whose write was refused.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function note_blocked( string $option ): void {
		/**
		 * Fires when safe mode refuses an assistant's write to a protected option.
		 *
		 * @since 1.5.0
		 *
		 * @param string $option The option name whose write was refused.
		 */
		do_action( 'albert/safe_mode/option_write_blocked', $option );

		AuditTrail::option_blocked( $option );
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
