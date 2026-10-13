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
 * Noise (transients, cron, locks) is ignored: WordPress rewrites it constantly.
 * Secrets are never stored, but a change to one is noted by name and makes the
 * entry unrevertable. Besides the known names, any option or meta key whose
 * name looks like a credential counts as a secret: storing an SMTP password or
 * API key in plain text costs more than an occasional harmless setting that
 * cannot be reverted.
 *
 * The filters can only add noise. A filter that removed an entry would make
 * Albert store more, and a pattern of `*` or a two-letter prefix would quietly
 * exclude everything, so both are refused.
 *
 * @since 1.5.0
 */
final class Exclusions {

	/**
	 * How an excluded entity is treated.
	 *
	 * @var string
	 */
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
	 * Names that look like a credential: a telling word anywhere, a `*_key`
	 * that names a kind of secret, or a short word standing as its own
	 * segment (`smtp_pass`, not `compass`). A false positive only costs a
	 * revert; a miss stores the credential.
	 *
	 * @var string
	 */
	private const SECRET_NAME = '/pass(?:word|wd|phrase)|secret|credential|authori[sz]ation|bearer|api[_\-.]?key|(?:access|consumer|client|encryption|signing|app|private|licen[cs]e|auth|totp|hmac|webhook|smtp|mail|stripe|paypal)[_\-.]?key|tokens?(?![a-z])|(?:backup|recovery)[_\-.]?codes?|(?:smtp|mail|db|ftp|proxy|admin)[_\-.]?pass|(?:^|[_\-.])(?:pass|pwd\d*|salt|otp)(?:$|[_\-.\d])/i';

	/**
	 * Values that are a credential whatever they are stored under: an
	 * authorization header, well-known API key formats, a private key.
	 *
	 * @var string
	 */
	private const SECRET_VALUE = '/^(?:Bearer\s+\S|Basic\s+[A-Za-z0-9+\/=]{8,})|^(?:sk|rk)_(?:live|test)_|^gh[pousr]_[A-Za-z0-9]{20,}|^github_pat_|^xox[abprs]-|^AKIA[0-9A-Z]{16}$|-----BEGIN [A-Z ]*PRIVATE KEY-----/';

	/**
	 * Longest value scanned for credentials. Anything larger is never stored
	 * either: the recorder treats it as too large.
	 *
	 * @var int
	 */
	private const MAX_SCAN_BYTES = 33554432;

	/**
	 * How deep a value is scanned. Data nested deeper is treated as a secret:
	 * what could not be read through is not stored.
	 *
	 * @var int
	 */
	private const MAX_SCAN_DEPTH = 32;

	/**
	 * Memory decoding a stored string may take, as a multiple of its length.
	 * Measured: JSON of tiny objects up to about 55x, `unserialize()` about 16x.
	 *
	 * @var int
	 */
	private const DECODE_COST = 64;

	/**
	 * What {@see self::decode()} returns for a value that must count as a
	 * secret; `true` is a value `b:1;` decodes to.
	 *
	 * @var string
	 */
	private const DECODED_SECRET = "\0secret";

	/**
	 * A captured state could not be checked for one, so it is not stored.
	 *
	 * @var string
	 */
	public const UNREADABLE = 'unreadable';

	/**
	 * Shortest prefix a filter may exclude with a trailing `*`.
	 *
	 * @var int
	 */
	private const MIN_FILTER_PREFIX = 3;

	/**
	 * Row columns never stored, by entity type. The first is the password, the
	 * one whose change is noted: WordPress clears the activation key on any
	 * email change, which is not a credential change worth refusing a revert for.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const CREDENTIAL_COLUMNS = [
		Entity::USER => [ 'user_pass', 'user_activation_key' ],
		Entity::POST => [ 'post_password' ],
	];

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
		if ( self::matches( $name, self::SECRET_OPTIONS ) || preg_match( self::SECRET_NAME, $name ) ) {
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
		if ( self::matches( $meta_key, self::SECRET_META_KEYS ) || preg_match( self::SECRET_NAME, $meta_key ) ) {
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
	 * Whether a captured state holds a credential the name check could not
	 * see: a stored option name or meta key that is one (MySQL matched the
	 * requested name ignoring case, accents and trailing spaces), or a value
	 * holding one at any depth, like a payment gateway's settings array.
	 *
	 * @param Entity                    $entity The entity.
	 * @param array<string, mixed>|null $state  Its state as {@see Snapshot} reads it.
	 *
	 * @return string|null {@see self::SECRET}, {@see self::UNREADABLE} when a
	 *                     value is too big to decode in the memory left, or
	 *                     null when it is clean.
	 * @since 1.5.0
	 */
	public static function holds_secret( Entity $entity, ?array $state ): ?string {
		if ( $state === null ) {
			return null;
		}

		$rows = is_array( $state['values'] ?? null ) ? $state['values'] : [];

		[ $names, $values ] = match ( $entity->type ) {
			Entity::OPTION => [ [ $state['option_name'] ?? '' ], [ $state['option_value'] ?? null ] ],
			Entity::META   => [ array_column( $rows, 'meta_key' ), array_column( $rows, 'meta_value' ) ],
			Entity::CUSTOM => [ [], [ $state ] ],
			default        => [ [], [] ],
		};

		foreach ( $names as $name ) {
			if ( is_string( $name ) && self::matches( $name, $entity->type === Entity::OPTION ? self::SECRET_OPTIONS : self::SECRET_META_KEYS ) ) {
				return self::SECRET;
			}
			if ( is_string( $name ) && preg_match( self::SECRET_NAME, $name ) ) {
				return self::SECRET;
			}
		}

		try {
			return self::contains_secret( $values, self::MAX_SCAN_DEPTH ) ? self::SECRET : null;
		} catch ( \LengthException $e ) {
			return self::UNREADABLE;
		}
	}

	/**
	 * Whether data holds a non-empty credential, at any depth: under a telling
	 * key (`secret_key`), as a `name`/`value` pair naming one, as a value in a
	 * known credential format, or inside a serialized or JSON string.
	 *
	 * @param array<mixed> $data  The data.
	 * @param int          $depth Remaining depth.
	 *
	 * @return bool
	 */
	private static function contains_secret( array $data, int $depth ): bool {
		if ( $depth < 0 ) {
			return true;
		}

		foreach ( [ 'name', 'key', 'id' ] as $label ) {
			if ( isset( $data[ $label ], $data['value'] ) && is_string( $data[ $label ] ) && self::is_filled( $data['value'] ) && preg_match( self::SECRET_NAME, $data[ $label ] ) ) {
				return true;
			}
		}

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::is_filled( $value ) && preg_match( self::SECRET_NAME, $key ) ) {
				return true;
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				if ( self::contains_secret( self::fields( $value ), $depth - 1 ) ) {
					return true;
				}
				continue;
			}

			if ( ! is_string( $value ) || $value === '' || strlen( $value ) > self::MAX_SCAN_BYTES ) {
				continue;
			}

			if ( preg_match( self::SECRET_VALUE, $value ) ) {
				return true;
			}

			$decoded = self::decode( $value );
			if ( $decoded === self::DECODED_SECRET ) {
				return true;
			}
			if ( ( is_array( $decoded ) || is_object( $decoded ) ) && self::contains_secret( self::fields( $decoded ), $depth - 1 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A stored string's structure, if it has one.
	 *
	 * @param string $value The value.
	 *
	 * @return mixed The decoded value, null when it is plain text, or
	 *               {@see self::DECODED_SECRET} when it must count as a secret:
	 *               JSON nested too deep to read, or a corrupt serialized value
	 *               with a credential among its strings.
	 * @throws \LengthException When decoding could exhaust the memory left, or
	 *                           a corrupt value cannot be read through.
	 */
	private static function decode( string $value ) {
		$serialized = is_serialized( $value );
		if ( ! $serialized && $value[0] !== '{' && $value[0] !== '[' ) {
			return null;
		}

		// A 10 MB page-builder blob decodes to hundreds of MB; a fatal here would
		// kill a call that worked before the journal existed.
		if ( ! self::affordable( strlen( $value ) ) ) {
			throw new \LengthException( 'Too large to decode.' );
		}

		if ( ! $serialized ) {
			$decoded = json_decode( $value, true );

			return json_last_error() === JSON_ERROR_DEPTH ? self::DECODED_SECRET : $decoded;
		}

		// Silenced: a corrupt stored value must not print a notice into the call's response.
		$decoded = @unserialize( $value, [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- No classes allowed.
		if ( $decoded !== false || $value === 'b:0;' ) {
			return $decoded;
		}

		// Corrupt: its structure is lost, so read each of its strings as a
		// possible key or value.
		if ( preg_match_all( '/s:\d+:"(.*?)";/s', $value, $strings ) === false ) {
			throw new \LengthException( 'Could not read a corrupt value.' );
		}
		foreach ( $strings[1] as $string ) {
			if ( preg_match( self::SECRET_NAME, $string ) || preg_match( self::SECRET_VALUE, $string ) ) {
				return self::DECODED_SECRET;
			}
		}

		return null;
	}

	/**
	 * Whether decoding a string of this length fits in the memory left.
	 *
	 * @param int $bytes Its length.
	 *
	 * @return bool
	 */
	private static function affordable( int $bytes ): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return true;
		}

		// The limit applies to what PHP has reserved, not only what is in use.
		return memory_get_usage( true ) + $bytes * self::DECODE_COST < $limit;
	}

	/**
	 * An array's or object's fields, with private and protected names unmangled.
	 *
	 * Serialized objects come back as `__PHP_Incomplete_Class`, whose cast
	 * prefixes non-public names with NUL-delimited class names.
	 *
	 * @param array<mixed>|object $data The data.
	 *
	 * @return array<mixed>
	 */
	private static function fields( array|object $data ): array {
		$fields = [];

		foreach ( (array) $data as $key => $value ) {
			$fields[ is_string( $key ) ? (string) preg_replace( '/^\0[^\0]*\0/', '', $key ) : $key ] = $value;
		}

		return $fields;
	}

	/**
	 * Whether a value could be a credential: a non-empty string, or an array
	 * or object with any field. Numbers and booleans are counts and flags
	 * (`active_tokens => 0`), never a credential.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function is_filled( $value ): bool {
		if ( is_array( $value ) || is_object( $value ) ) {
			return (array) $value !== [];
		}

		return is_string( $value ) && $value !== '';
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
		return Tables::is_albert_table( $table );
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
		return Tables::is_albert_table( $table, true );
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
	 * Match a name against exact names and `prefix*` patterns, as MySQL would
	 * find the row: ignoring case and trailing spaces.
	 *
	 * @param string             $name     The name.
	 * @param array<int, string> $patterns Patterns.
	 *
	 * @return bool
	 */
	private static function matches( string $name, array $patterns ): bool {
		$name = strtolower( rtrim( $name, ' ' ) );

		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( $pattern );
			if ( str_ends_with( $pattern, '*' ) ? str_starts_with( $name, substr( $pattern, 0, -1 ) ) : $name === $pattern ) {
				return true;
			}
		}

		return false;
	}
}
