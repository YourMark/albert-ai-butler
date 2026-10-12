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
	private const IDENT = '((?:`[^`]+`|[A-Za-z0-9_$]+)(?:\s*\.\s*(?:`[^`]+`|[A-Za-z0-9_$]+))?)';

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
		// MySQL runs a versioned comment's body, so it is code, not a comment.
		if ( str_contains( $sql, '/*!' ) ) {
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
			'INSERT'  => self::write( '/^INSERT\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?' . self::IDENT . '/i', $sql ),
			'REPLACE' => self::write( '/^REPLACE\s+(?:(?:LOW_PRIORITY|DELAYED)\s+)*(?:INTO\s+)?' . self::IDENT . '/i', $sql ),
			'UPDATE'  => self::single_table( '/^UPDATE\s+(?:(?:LOW_PRIORITY|IGNORE)\s+)*' . self::IDENT . '(.*?)\bSET\b/is', $sql ),
			'DELETE'  => self::single_table( '/^DELETE\s+(?:(?:LOW_PRIORITY|QUICK|IGNORE)\s+)*FROM\s+' . self::IDENT . '(.*?)(?:\bWHERE\b|\bORDER\b|\bLIMIT\b|$)/is', $sql ),
			'TRUNCATE' => self::write( '/^TRUNCATE\s+(?:TABLE\s+)?' . self::IDENT . '/i', $sql, true ),
			'ALTER'   => self::write( '/^ALTER\s+(?:(?:ONLINE|IGNORE)\s+)*TABLE\s+' . self::IDENT . '/i', $sql, true ),
			'DROP'    => self::write( '/^DROP\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+EXISTS\s+)?' . self::IDENT . '/i', $sql, true ),
			'CREATE'  => self::write( '/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . self::IDENT . '/i', $sql, true ),
			'RENAME'  => self::write( '/^RENAME\s+TABLE\s+' . self::IDENT . '/i', $sql, true ),
			default   => new self( self::UNREADABLE ),
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

		return new self( self::WRITE, self::table_name( $match[1] ), $structure );
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

		return new self( self::WRITE, self::table_name( $match[1] ) );
	}

	/**
	 * The table part of a possibly schema-qualified, backticked name.
	 *
	 * @param string $ident The identifier.
	 *
	 * @return string
	 */
	private static function table_name( string $ident ): string {
		$parts = preg_split( '/\s*\.\s*/', $ident );
		$last  = is_array( $parts ) ? (string) end( $parts ) : $ident;

		return trim( $last, '`' );
	}

	/**
	 * Remove leading whitespace, comments and opening parentheses.
	 *
	 * `--` starts a comment only when followed by whitespace or a control
	 * character, as in MySQL; otherwise it is left, and the query is unreadable.
	 *
	 * @param string $sql The query.
	 *
	 * @return string|null The rest, or null on an unterminated block comment.
	 */
	private static function strip_leading_comments( string $sql ): ?string {
		while ( true ) {
			$sql = ltrim( $sql, " \t\n\r\0\x0B\f(" );

			if ( str_starts_with( $sql, '/*' ) ) {
				$end = strpos( $sql, '*/', 2 );
				if ( $end === false ) {
					return null;
				}
				$sql = substr( $sql, $end + 2 );
				continue;
			}

			if ( str_starts_with( $sql, '#' ) || preg_match( '/^--(?:[\x00-\x20]|$)/', $sql ) ) {
				$end = strpos( $sql, "\n" );
				$sql = $end === false ? '' : substr( $sql, $end + 1 );
				continue;
			}

			return $sql;
		}
	}
}
