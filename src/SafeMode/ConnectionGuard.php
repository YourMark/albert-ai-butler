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
 * on option name, so it holds against any ability, not only Albert's own.
 *
 * Names are matched as the database matches them ({@see OptionNames}): under a
 * typical collation `Albert_Safe_Mode`, `albért_safe_mode` and a full-width
 * spelling all reach the real row, while the per-option hooks fire only for the
 * exact name. So every write is checked by name on the generic hooks too.
 *
 * - An update keeps the stored value (`sanitize_option_{$option}`, `pre_update_option`).
 * - The creation of a control option stores its secure default. Only the OAuth
 *   key material may be created, since it is minted lazily during token validation.
 * - A delete is refused at its row lookup, before WordPress touches its caches.
 * - An add under another spelling is stopped at the query: WordPress has no
 *   filter to refuse it, but fires `add_option` just before, and the `query`
 *   filter can then drop that one statement.
 *
 * Writes that skip the option API are {@see OptionRestorer}'s job.
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
	 * The protected options that may be created over a connection: the OAuth
	 * key material, minted lazily while a token is validated. Never changed.
	 *
	 * @since 1.5.0
	 * @var list<string>
	 */
	private const CREATABLE = [
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
	 * The last attempt reported, as `operation:option`.
	 *
	 * @since 1.5.0
	 * @var string|null
	 */
	private ?string $last_reported = null;

	/**
	 * Statements to drop when they reach the `query` filter.
	 *
	 * @since 1.5.0
	 * @var list<array{prefix: string, needle: string, name: string, option: string, operation: string}>
	 */
	private array $armed = [];

	/**
	 * Options whose cache WordPress may have marked deleted after a refused delete.
	 *
	 * @since 1.5.0
	 * @var list<string>
	 */
	private array $stale = [];

	/**
	 * Compares option names as the database does.
	 *
	 * @since 1.5.0
	 * @var OptionNames
	 */
	private OptionNames $names;

	/**
	 * Wire the guard.
	 *
	 * @param OptionNames|null $names Name matcher; defaults to a fresh one.
	 */
	public function __construct( ?OptionNames $names = null ) {
		$this->names = $names ?? new OptionNames();
	}

	/**
	 * Bind the guard to the option hooks.
	 *
	 * Last priority throughout, so the guard has the final say.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		foreach ( self::protected_names() as $option ) {
			add_filter( "sanitize_option_{$option}", [ $this, 'refuse_over_connection' ], PHP_INT_MAX, 2 );

			// Network options have their own store and hooks. The shared
			// sanitize filter cannot tell the two stores apart.
			add_filter( "pre_update_site_option_{$option}", [ $this, 'refuse_network_update' ], PHP_INT_MAX, 3 );
			add_filter( "pre_add_site_option_{$option}", [ $this, 'refuse_network_add' ], PHP_INT_MAX, 2 );
			add_action( "pre_delete_site_option_{$option}", [ $this, 'refuse_network_delete' ], PHP_INT_MAX );
		}

		// Keyed by any name, so other spellings of a protected option are seen.
		add_filter( 'pre_update_option', [ $this, 'refuse_variant_update' ], PHP_INT_MAX, 3 );
		add_action( 'add_option', [ $this, 'refuse_add' ], PHP_INT_MAX );
		add_action( 'delete_option', [ $this, 'refuse_delete' ], PHP_INT_MAX );
		add_filter( 'query', [ $this, 'filter_query' ], PHP_INT_MAX );
		add_action( 'shutdown', [ $this, 'forget_stale_cache' ], 0 );
	}

	/**
	 * Refuse an update to a protected option under any spelling, by keeping
	 * the stored value.
	 *
	 * @param mixed  $value     The new value.
	 * @param string $option    Option name as passed.
	 * @param mixed  $old_value The stored value.
	 *
	 * @return mixed
	 * @since 1.5.0
	 */
	public function refuse_variant_update( $value, $option, $old_value ) {
		if ( ConnectionContext::client_id() === null || ! is_string( $option ) ) {
			return $value;
		}

		$protected = $this->protected_match( $option );
		if ( $protected === null ) {
			return $value;
		}

		// The key material's first write goes through `update_option()`.
		if ( $protected === $option && $old_value === false && self::may_be_created( $option ) ) {
			return $value;
		}

		$this->note_blocked( $protected === false ? $option : $protected, 'write' );

		return $old_value;
	}

	/**
	 * Drop the INSERT of a protected option, unless it is the exact name and
	 * its creation is allowed: a control option's sanitize filter has already
	 * swapped in the secure default, and the key material may be minted.
	 *
	 * @param string $option Option name as added.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function refuse_add( $option ): void {
		if ( ConnectionContext::client_id() === null || ! is_string( $option ) ) {
			return;
		}

		$protected = $this->protected_match( $option );
		if ( $protected === null ) {
			return;
		}

		if ( $protected === $option && ( array_key_exists( $option, self::SECURE_DEFAULT ) || self::may_be_created( $option ) ) ) {
			return;
		}

		global $wpdb;
		$this->arm( "INSERT INTO `{$wpdb->options}`", $option, $protected === false ? $option : $protected, 'write' );
	}

	/**
	 * Drop the DELETE of a protected option, under any spelling: the second
	 * layer, should the row lookup {@see self::filter_query()} refuses ever
	 * change shape and get through.
	 *
	 * `delete_option` fires after the row is found and immediately before it is
	 * removed, so the statement it arms is the very next delete of that name.
	 *
	 * @param string $option Option name as passed.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function refuse_delete( $option ): void {
		if ( ConnectionContext::client_id() === null || ! is_string( $option ) ) {
			return;
		}

		$protected = $this->protected_match( $option );
		if ( $protected === null ) {
			return;
		}

		global $wpdb;
		$this->arm( "DELETE FROM `{$wpdb->options}`", $option, $protected === false ? $option : $protected, 'delete' );
	}

	/**
	 * Drop the DELETE of a protected network option.
	 *
	 * On a single site `delete_site_option()` falls through to
	 * `delete_option()`, which {@see self::refuse_delete()} covers.
	 *
	 * @param string $option The option being deleted.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function refuse_network_delete( $option ): void {
		if ( ConnectionContext::client_id() === null || ! is_string( $option ) || ! is_multisite() ) {
			return;
		}

		global $wpdb;
		$this->arm( "DELETE FROM `{$wpdb->sitemeta}`", $option, $option, 'delete' );
	}

	/**
	 * Refuse a delete at its lookup, and drop armed statements.
	 *
	 * `delete_option()` reads the row's autoload flag before anything else and
	 * returns when it finds none. Refusing that read stops a delete before
	 * WordPress touches its caches; dropping the DELETE alone would leave the
	 * option cached as deleted, which a persistent object cache keeps.
	 *
	 * Runs on every query, so it returns at once when nothing applies.
	 *
	 * @param string $query The SQL about to run.
	 *
	 * @return string The query, or an empty string to drop it.
	 * @since 1.5.0
	 */
	public function filter_query( $query ) {
		if ( ! is_string( $query ) ) {
			return $query;
		}

		if ( $this->stale !== [] ) {
			$this->forget_stale_cache();
		}

		if ( ConnectionContext::client_id() !== null && str_starts_with( $query, 'SELECT autoload FROM ' ) ) {
			$protected = $this->delete_lookup( $query );
			if ( $protected !== null ) {
				$this->note_blocked( $protected, 'delete' );

				// A lookup that finds nothing. An empty query would leave wpdb
				// handing back the previous query's row.
				global $wpdb;
				return "SELECT autoload FROM {$wpdb->options} WHERE 1 = 0";
			}
		}

		if ( $this->armed === [] ) {
			return $query;
		}

		foreach ( $this->armed as $i => $statement ) {
			if ( str_starts_with( ltrim( $query ), $statement['prefix'] ) && str_contains( $query, $statement['needle'] ) ) {
				array_splice( $this->armed, $i, 1 );
				$this->note_blocked( $statement['option'], $statement['operation'] );

				if ( $statement['operation'] === 'delete' ) {
					// WordPress updates its caches after the DELETE whatever it returned.
					$this->stale[] = $statement['name'];
				}

				return '';
			}
		}

		return $query;
	}

	/**
	 * The protected option a `delete_option()` row lookup names, if any.
	 *
	 * Matches the statement exactly as core builds it, so nothing else is caught.
	 *
	 * @param string $query The SQL.
	 *
	 * @return string|null
	 */
	private function delete_lookup( string $query ): ?string {
		global $wpdb;

		$pattern = '/^SELECT autoload FROM ' . preg_quote( $wpdb->options, '/' ) . " WHERE option_name = '((?:[^'\\\\]|\\\\.)*)'$/sD";
		if ( preg_match( $pattern, $query, $match ) !== 1 ) {
			return null;
		}

		// Undo mysqli_real_escape_string().
		$name = preg_replace_callback(
			'/\\\\(.)/s',
			static fn( array $c ): string => [
				'0' => "\0",
				'n' => "\n",
				'r' => "\r",
				'Z' => "\x1a",
			][ $c[1] ] ?? $c[1],
			$match[1]
		);

		$protected = $this->protected_match( (string) $name );

		return $protected === false ? (string) $name : $protected;
	}

	/**
	 * Drop the cached copies WordPress changed after a refused DELETE, so the
	 * option reads from the database again. Runs at the next query and at
	 * shutdown, so a persistent object cache never keeps "deleted".
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function forget_stale_cache(): void {
		if ( $this->stale === [] ) {
			return;
		}

		$names       = $this->stale;
		$this->stale = [];

		foreach ( $names as $name ) {
			wp_cache_delete( $name, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
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

		$this->note_blocked( $option, 'write' );

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

		$this->note_blocked( $option, 'write' );

		return self::SECURE_DEFAULT[ $option ];
	}

	/**
	 * The options this guard protects.
	 *
	 * @return list<string>
	 * @since 1.5.0
	 */
	public static function protected_names(): array {
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

		return is_array( $options ) ? array_values( array_filter( $options, 'is_string' ) ) : self::PROTECTED;
	}

	/**
	 * Whether a protected option may be created over a connection.
	 *
	 * Only the OAuth key material, minted lazily. A control option is created
	 * at its secure default instead, and an add-on's not at all.
	 *
	 * @param string $option A protected option name.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function may_be_created( string $option ): bool {
		return in_array( $option, self::CREATABLE, true );
	}

	/**
	 * Fire the refusal action and write its activity-log row.
	 *
	 * @param string $option    The protected option.
	 * @param string $operation `write` or `delete`.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function announce_blocked( string $option, string $operation ): void {
		/**
		 * Fires when safe mode refuses an assistant's write to a protected option.
		 *
		 * @since 1.5.0
		 *
		 * @param string $option    The option name whose write was refused.
		 * @param string $operation `write` or `delete`.
		 */
		do_action( 'albert/safe_mode/option_write_blocked', $option, $operation );

		AuditTrail::option_blocked( $option, $operation );
	}

	/**
	 * Keep the stored value when an assistant tries to change a protected option.
	 *
	 * A sanitize filter can only coerce the value, not raise an error, so the
	 * write "succeeds" from the caller's point of view while storing nothing new.
	 *
	 * @param mixed  $value  The value about to be written.
	 * @param string $option The option name.
	 *
	 * @return mixed The stored value over a connection (blocking the change), the
	 *               secure default for a control switch, or the incoming value
	 *               otherwise: the key material's first write, or an add-on's
	 *               option, whose INSERT {@see self::refuse_add()} drops.
	 * @since 1.5.0
	 */
	private function refuse( $value, string $option ) {
		if ( ConnectionContext::client_id() === null ) {
			return $value;
		}

		$sentinel = new \stdClass();
		$stored   = get_option( $option, $sentinel );

		if ( $stored !== $sentinel ) {
			$this->note_blocked( $option, 'write' );

			return $stored;
		}

		if ( array_key_exists( $option, self::SECURE_DEFAULT ) ) {
			$this->note_blocked( $option, 'write' );

			return self::SECURE_DEFAULT[ $option ];
		}

		return $value;
	}

	/**
	 * The protected option a name reaches, if any.
	 *
	 * @param string $option Option name as passed.
	 *
	 * @return string|false|null The protected name, null when it is none, or
	 *                           false when the database could not answer:
	 *                           callers then refuse, as for a variant.
	 * @since 1.5.0
	 */
	private function protected_match( string $option ): string|false|null {
		try {
			return $this->names->find( $option, self::protected_names() );
		} catch ( \RuntimeException $e ) {
			return false;
		}
	}

	/**
	 * Arm the next statement with this prefix that names this option.
	 *
	 * @param string $prefix    Verb and table, as WordPress writes them.
	 * @param string $name      Option name as the statement quotes it.
	 * @param string $option    The protected option, for the report.
	 * @param string $operation `write` or `delete`.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function arm( string $prefix, string $name, string $option, string $operation ): void {
		global $wpdb;

		$this->armed[] = [
			'prefix'    => $prefix,
			// As prepare() quotes it, minus the placeholder escape wpdb strips before this filter.
			'needle'    => "'" . $wpdb->remove_placeholder_escape( esc_sql( $name ) ) . "'",
			'name'      => $name,
			'option'    => $option,
			'operation' => $operation,
		];
	}

	/**
	 * Announce a refusal once per attempt.
	 *
	 * One call can pass two of the guard's hooks: `add_site_option()` falls
	 * through to `add_option()` on a single site, and an update passes both the
	 * sanitize filter and `pre_update_option`. Only the report is de-duplicated;
	 * every hook still refuses.
	 *
	 * @param string $option    The option whose write was refused.
	 * @param string $operation `write` or `delete`.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function note_blocked( string $option, string $operation ): void {
		$key = $operation . ':' . $option;
		if ( $this->last_reported === $key ) {
			return;
		}

		$this->last_reported = $key;
		self::announce_blocked( $option, $operation );
	}
}
