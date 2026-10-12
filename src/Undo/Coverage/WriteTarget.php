<?php
/**
 * Which table a query writes to.
 *
 * @package Albert
 * @subpackage Undo\Coverage
 * @since      1.5.0
 */

namespace Albert\Undo\Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads only the target table of a query, failing closed.
 *
 * Deliberately not a parser. A statement is a read only when it starts with a
 * known read-only keyword; everything else is a write, and a write whose single
 * target table cannot be read off its first clause is unreadable. Unreadable is
 * reported as a gap, so a mistake here costs a warning, never a promise to
 * revert something that was not recorded.
 *
 * @since 1.5.0
 */
final class WriteTarget {

	/**
	 * What a query does.
	 *
	 * @var string
	 */
	public const READ       = 'read';
	public const WRITE      = 'write';
	public const UNREADABLE = 'unreadable';

	/**
	 * Leading keywords that never change data.
	 *
	 * @var array<int, string>
	 */
	private const READ_KEYWORDS = [ 'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'SET', 'BEGIN', 'START', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'LOCK', 'UNLOCK', 'USE' ];

	/**
	 * A table name: bare or backticked, optionally schema-qualified.
	 *
	 * @var string
	 */
	private const IDENT = '((?:`[^`]+`|[A-Za-z0-9_$]+)(?:\s*\.\s*(?:`[^`]+`|[A-Za-z0-9_$]+))?)(?![A-Za-z0-9_$\x80-\xFF])';

	/**
	 * Build a result.
	 *
	 * @param string      $kind      One of the class constants.
	 * @param string|null $table     The target table, for a write.
	 * @param bool        $structure Whether the write changes a table's structure.
	 */
	private function __construct(
		public readonly string $kind,
		public readonly ?string $table = null,
		public readonly bool $structure = false,
	) {}

	/**
	 * Classify a query.
	 *
	 * @param string $sql The query.
	 *
	 * @return WriteTarget
	 * @since 1.5.0
	 */
	public static function of( string $sql ): WriteTarget {
		if ( self::has_hidden_code( $sql ) ) {
			return new self( self::UNREADABLE );
		}

		$sql = self::strip_leading_comments( $sql );
		if ( $sql === null ) {
			return new self( self::UNREADABLE );
		}

		if ( $sql === '' ) {
			return new self( self::READ );
		}

		if ( ! preg_match( '/^([A-Za-z]+)/', $sql, $match ) ) {
			return new self( self::UNREADABLE );
		}

		$keyword = strtoupper( $match[1] );

		if ( in_array( $keyword, self::READ_KEYWORDS, true ) ) {
			return new self( self::READ );
		}

		return match ( $keyword ) {
			'INSERT'   => self::write( '/^INSERT\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?+' . self::IDENT . '/i', $sql ),
			'REPLACE'  => self::write( '/^REPLACE\s+(?:(?:LOW_PRIORITY|DELAYED)\s+)*(?:INTO\s+)?+' . self::IDENT . '/i', $sql ),
			'UPDATE'   => self::single_table( '/^UPDATE\s+(?:(?:LOW_PRIORITY|IGNORE)\s+)*' . self::IDENT . '(.*?)\bSET\b/is', $sql ),
			'DELETE'   => self::single_table( '/^DELETE\s+(?:(?:LOW_PRIORITY|QUICK|IGNORE)\s+)*FROM\s+' . self::IDENT . '(.*?)(?:\bWHERE\b|\bORDER\b|\bLIMIT\b|$)/is', $sql ),
			'TRUNCATE' => self::write( '/^TRUNCATE\s+(?:TABLE\s+)?' . self::IDENT . '/i', $sql, true ),
			'ALTER'    => self::write( '/^ALTER\s+(?:(?:ONLINE|IGNORE)\s+)*TABLE\s+' . self::IDENT . '/i', $sql, true ),
			'DROP'     => self::write( '/^DROP\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+EXISTS\s+)?' . self::IDENT . '/i', $sql, true ),
			'CREATE'   => self::write( '/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . self::IDENT . '/i', $sql, true ),
			'RENAME'   => self::write( '/^RENAME\s+TABLE\s+' . self::IDENT . '/i', $sql, true ),
			default    => new self( self::UNREADABLE ),
		};
	}

	/**
	 * Whether this is a write.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function is_write(): bool {
		return $this->kind === self::WRITE;
	}

	/**
	 * A write whose table is the pattern's first group.
	 *
	 * @param string $pattern   Pattern.
	 * @param string $sql       Query.
	 * @param bool   $structure Whether it changes structure.
	 *
	 * @return WriteTarget
	 */
	private static function write( string $pattern, string $sql, bool $structure = false ): WriteTarget {
		if ( ! preg_match( $pattern, $sql, $match ) ) {
			return new self( self::UNREADABLE );
		}

		$table = self::table_name( $match[1] );

		return $table === null ? new self( self::UNREADABLE ) : new self( self::WRITE, $table, $structure );
	}

	/**
	 * A write that must name exactly one table: anything between the table and
	 * the next clause other than an alias (a comma, a JOIN, USING) makes it
	 * multi-table, and unreadable.
	 *
	 * @param string $pattern Pattern; group 1 the table, group 2 what follows it.
	 * @param string $sql     Query.
	 *
	 * @return WriteTarget
	 */
	private static function single_table( string $pattern, string $sql ): WriteTarget {
		if ( ! preg_match( $pattern, $sql, $match ) ) {
			return new self( self::UNREADABLE );
		}

		$between = trim( $match[2] );
		if ( $between !== '' && ! preg_match( '/^(?:AS\s+)?[A-Za-z0-9_$`]+$/i', $between ) ) {
			return new self( self::UNREADABLE );
		}

		$table = self::table_name( $match[1] );

		return $table === null ? new self( self::UNREADABLE ) : new self( self::WRITE, $table );
	}

	/**
	 * The table of a possibly schema-qualified, backticked name, or null when it
	 * names another database: `other_db.wp_posts` is not this site's posts table.
	 *
	 * @param string $ident The identifier.
	 *
	 * @return string|null
	 */
	private static function table_name( string $ident ): ?string {
		$parts = preg_split( '/\s*\.\s*/', $ident );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$parts = array_map( static fn( string $part ): string => trim( $part, '`' ), $parts );

		if ( count( $parts ) > 1 && ( ! defined( 'DB_NAME' ) || strcasecmp( $parts[0], (string) DB_NAME ) !== 0 ) ) {
			return null;
		}

		return (string) end( $parts );
	}

	/**
	 * Whether the query holds code past what its first keyword says: a comment
	 * the server runs as code, or a second statement after a `;`. Quoted
	 * strings are skipped, so a CSS licence banner in post content is data,
	 * not a gap; an unbalanced quote fails closed.
	 *
	 * @param string $sql The query.
	 *
	 * @return bool
	 */
	private static function has_hidden_code( string $sql ): bool {
		if ( ! str_contains( $sql, '/*' ) && ! str_contains( $sql, ';' ) ) {
			return false;
		}

		// A scan rather than a regex: a pattern over quoted strings exhausts
		// PCRE's stack on a long post body. Strings and ordinary comments are
		// skipped whole, so a quote in either cannot pair with a later one and
		// hide the code between them.
		$length = strlen( $sql );
		$at     = 0;

		while ( true ) {
			$at += strcspn( $sql, "'\"`/#-;", $at );
			if ( $at >= $length ) {
				return false;
			}

			if ( self::is_executable_comment_at( $sql, $at ) ) {
				return true;
			}

			if ( $sql[ $at ] === ';' ) {
				// A trailing `;` ends the statement; anything after it is another.
				$rest = self::strip_leading_comments( substr( $sql, $at + 1 ) );
				return $rest === null || trim( $rest, " \t\n\r\0\x0B\f;" ) !== '';
			}

			$char = $sql[ $at ];
			$past = in_array( $char, [ "'", '"', '`' ], true ) ? self::string_end( $sql, $at, $char ) : self::comment_end( $sql, $at );

			if ( $past === null ) {
				return true;
			}

			// Not a string or comment after all: a lone `/` or `-`.
			$at = $past === $at ? $at + 1 : $past;
		}
	}

	/**
	 * Where a quoted string or identifier ends, just past its closing quote.
	 *
	 * @param string $sql   The query.
	 * @param int    $start Offset of the opening quote.
	 * @param string $quote The quote character.
	 *
	 * @return int|null Null when it never closes.
	 */
	private static function string_end( string $sql, int $start, string $quote ): ?int {
		$length = strlen( $sql );
		$stops  = $quote === '`' ? '`' : $quote . '\\';
		$at     = $start + 1;

		while ( true ) {
			$at += strcspn( $sql, $stops, $at );
			if ( $at >= $length ) {
				return null;
			}

			if ( $sql[ $at ] === '\\' ) {
				$at += 2;
				continue;
			}

			return $at + 1;
		}
	}

	/**
	 * Whether a comment the server runs as code starts at an offset: MySQL's
	 * `/*!` or MariaDB's `/*M!`. Anything else after `/*`, an optimizer hint
	 * `/*+` included, is a comment.
	 *
	 * Compared by character: PCRE looks ahead for a pattern's required `!`
	 * before matching, which costs a pass over the rest of a long query on
	 * every call.
	 *
	 * @param string $sql The query.
	 * @param int    $at  Offset.
	 *
	 * @return bool
	 */
	private static function is_executable_comment_at( string $sql, int $at ): bool {
		if ( substr( $sql, $at, 2 ) !== '/*' ) {
			return false;
		}

		$next = $sql[ $at + 2 ] ?? '';

		return $next === '!' || ( ( $next === 'M' || $next === 'm' ) && ( $sql[ $at + 3 ] ?? '' ) === '!' );
	}

	/**
	 * Where a comment starting at an offset ends, just past it.
	 *
	 * `--` starts a comment only when followed by whitespace or a control
	 * character, as in MySQL.
	 *
	 * @param string $sql The query.
	 * @param int    $at  Offset.
	 *
	 * @return int|null The offset past the comment, `$at` itself when none
	 *                  starts there, or null when a block comment never closes.
	 */
	private static function comment_end( string $sql, int $at ): ?int {
		$char = $sql[ $at ] ?? '';

		if ( $char === '/' && ( $sql[ $at + 1 ] ?? '' ) === '*' ) {
			$end = strpos( $sql, '*/', $at + 2 );
			return $end === false ? null : $end + 2;
		}

		if ( $char === '#' || ( $char === '-' && ( $sql[ $at + 1 ] ?? '' ) === '-' && ord( $sql[ $at + 2 ] ?? ' ' ) <= 0x20 ) ) {
			$end = strpos( $sql, "\n", $at );
			return $end === false ? strlen( $sql ) : $end + 1;
		}

		return $at;
	}

	/**
	 * Remove leading whitespace, comments and opening parentheses, by offset
	 * so a long run of them stays linear.
	 *
	 * @param string $sql The query.
	 *
	 * @return string|null The rest, or null on an unterminated block comment.
	 */
	private static function strip_leading_comments( string $sql ): ?string {
		$at = 0;

		while ( true ) {
			$at  += strspn( $sql, " \t\n\r\0\x0B\f(", $at );
			$past = self::comment_end( $sql, $at );

			if ( $past === null ) {
				return null;
			}

			if ( $past === $at ) {
				return substr( $sql, $at );
			}

			$at = $past;
		}
	}
}
