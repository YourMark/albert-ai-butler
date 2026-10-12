<?php
/**
 * What the journal never records.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.5.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

use Albert\Database\Tables;

/**
 * Noise and secrets: two lists, two treatments.
 *
 * Noise (transients, cron, locks) is rewritten constantly by WordPress itself
 * and reverting it would be meaningless, so it is ignored. Secrets (salts,
 * session tokens, application passwords, OAuth keys) are never stored either,
 * but a change to one is noted without its value: the entry then cannot be
 * reverted, and says why, instead of looking complete.
 *
 * The filters can only add noise. A filter that removed an entry would make
 * Albert store more, and a pattern of `*` or a two-letter prefix would quietly
 * exclude everything, so both are refused.
 *
 * @since 1.5.0
 */
final class Exclusions {

	public const NOISE  = 'noise';
	public const SECRET = 'secret';

	/**
	 * Options rewritten by WordPress itself. A trailing `*` matches a prefix.
	 *
	 * @var array<int, string>
	 */
	private const NOISE_OPTIONS = [ '_transient_*', '_site_transient_*', 'cron', 'rewrite_rules', 'auto_updater.lock', 'core_updater.lock' ];

	/**
	 * Options holding credentials.
	 *
	 * @var array<int, string>
	 */
	private const SECRET_OPTIONS = [ 'auth_key', 'auth_salt', 'secure_auth_key', 'secure_auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'recovery_keys', 'albert_oauth_*' ];

	/**
	 * Meta keys rewritten by WordPress itself, for every meta type.
	 *
	 * @var array<int, string>
	 */
	private const NOISE_META_KEYS = [ '_edit_lock', '_edit_last' ];

	/**
	 * Meta keys holding credentials, for every meta type.
	 *
	 * @var array<int, string>
	 */
	private const SECRET_META_KEYS = [ 'session_tokens', '_application_passwords', 'mcp_adapter_sessions*' ];

	/**
	 * Shortest prefix a filter may exclude with a trailing `*`.
	 *
	 * @var int
	 */
	private const MIN_FILTER_PREFIX = 3;

	/**
	 * User columns never stored.
	 *
	 * @var array<int, string>
	 */
	public const USER_COLUMNS = [ 'user_pass', 'user_activation_key' ];

	/**
	 * Whether an entity is noise, a secret, or neither.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return string|null NOISE, SECRET, or null when it is recorded.
	 * @since 1.5.0
	 */
	public static function classify( Entity $entity ): ?string {
		return match ( $entity->type ) {
			Entity::OPTION => self::option( $entity->parts[0] ),
			Entity::META   => self::meta_key( $entity->parts[2] ?? '' ),
			default        => null,
		};
	}

	/**
	 * Classify an option.
	 *
	 * @param string $name Option name.
	 *
	 * @return string|null
	 * @since 1.5.0
	 */
	public static function option( string $name ): ?string {
		if ( self::matches( $name, self::SECRET_OPTIONS ) ) {
			return self::SECRET;
		}

		/**
		 * Filters the options the change journal ignores as noise.
		 *
		 * Can only add: Albert's own entries are kept whatever is returned. A
		 * trailing `*` matches a prefix of at least three characters.
		 *
		 * @since 1.5.0
		 *
		 * @param array<int, string> $options Option names or prefixes.
		 */
		$extra = apply_filters( 'albert/undo/excluded_options', [] );

		return self::matches( $name, array_merge( self::NOISE_OPTIONS, self::filtered( $extra ) ) ) ? self::NOISE : null;
	}

	/**
	 * Classify a meta key.
	 *
	 * @param string $meta_key Meta key.
	 *
	 * @return string|null
	 * @since 1.5.0
	 */
	public static function meta_key( string $meta_key ): ?string {
		if ( self::matches( $meta_key, self::SECRET_META_KEYS ) ) {
			return self::SECRET;
		}

		/**
		 * Filters the meta keys the change journal ignores as noise, for every
		 * meta type.
		 *
		 * Can only add: Albert's own entries are kept whatever is returned. A
		 * trailing `*` matches a prefix of at least three characters.
		 *
		 * @since 1.5.0
		 *
		 * @param array<int, string> $meta_keys Meta keys or prefixes.
		 */
		$extra = apply_filters( 'albert/undo/excluded_meta_keys', [] );

		return self::matches( $meta_key, array_merge( self::NOISE_META_KEYS, self::filtered( $extra ) ) ) ? self::NOISE : null;
	}

	/**
	 * Whether a table is Albert's own bookkeeping.
	 *
	 * @param string $table Table name.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function own_table( string $table ): bool {
		return in_array( strtolower( $table ), array_map( 'strtolower', Tables::all() ), true );
	}

	/**
	 * Whether a table is one of the journal's own.
	 *
	 * @param string $table Table name.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function journal_table( string $table ): bool {
		return in_array( strtolower( $table ), [ strtolower( Tables::changes() ), strtolower( Tables::change_items() ) ], true );
	}

	/**
	 * Patterns from a filter, without the ones that would exclude nearly everything.
	 *
	 * @param mixed $patterns Filter result.
	 *
	 * @return array<int, string>
	 */
	private static function filtered( $patterns ): array {
		$kept = [];

		foreach ( is_array( $patterns ) ? $patterns : [] as $pattern ) {
			if ( ! is_string( $pattern ) || $pattern === '' ) {
				continue;
			}

			if ( str_ends_with( $pattern, '*' ) && strlen( $pattern ) - 1 < self::MIN_FILTER_PREFIX ) {
				continue;
			}

			$kept[] = $pattern;
		}

		return $kept;
	}

	/**
	 * Match a name against exact names and `prefix*` patterns.
	 *
	 * @param string             $name     The name.
	 * @param array<int, string> $patterns Patterns.
	 *
	 * @return bool
	 */
	private static function matches( string $name, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( str_ends_with( $pattern, '*' ) ? str_starts_with( $name, substr( $pattern, 0, -1 ) ) : $name === $pattern ) {
				return true;
			}
		}

		return false;
	}
}
