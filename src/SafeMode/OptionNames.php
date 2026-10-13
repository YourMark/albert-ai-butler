<?php
/**
 * Option names compared the way the database compares them.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

use RuntimeException;

/**
 * Tells which of a list of option names the database treats as the same row
 * as a given name.
 *
 * `option_name` is matched under the column's collation, which on a typical
 * site ignores case, accents, width and zero-width characters and treats `ß`
 * as `ss`: `update_option( 'albért_safe_mode', 'off' )` writes the real
 * `albert_safe_mode` row. No PHP normalisation reproduces every collation, so
 * the database is asked, using the collation the column actually has.
 *
 * @since 1.5.0
 */
class OptionNames {

	/**
	 * Column collation per options table, as `[ charset, collation ]`.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private static array $collations = [];

	/**
	 * Answers already given this request, keyed by table, name and list.
	 *
	 * @var array<string, string|null>
	 */
	private array $found = [];

	/**
	 * The name in `$candidates` the database treats as equal to `$name`.
	 *
	 * @param string       $name       Option name as passed to WordPress.
	 * @param list<string> $candidates Names to compare against.
	 *
	 * @return string|null The matching candidate, or null when none matches.
	 * @throws RuntimeException When the database cannot answer. Callers fail closed.
	 * @since 1.5.0
	 */
	public function find( string $name, array $candidates ): ?string {
		$candidates = array_values( array_filter( $candidates, 'is_string' ) );

		if ( in_array( $name, $candidates, true ) ) {
			return $name;
		}

		if ( $candidates === [] ) {
			return null;
		}

		global $wpdb;
		$key = $wpdb->options . "\0" . $name . "\0" . implode( "\0", $candidates );
		if ( ! array_key_exists( $key, $this->found ) ) {
			$this->found[ $key ] = $this->ask( $name, $candidates );
		}

		return $this->found[ $key ];
	}

	/**
	 * Compare one name against the candidates in a single query.
	 *
	 * @param string       $name       Option name.
	 * @param list<string> $candidates Names to compare against.
	 *
	 * @return string|null
	 * @throws RuntimeException When the query fails.
	 */
	private function ask( string $name, array $candidates ): ?string {
		global $wpdb;

		[ $charset, $collation ] = self::collation();

		// Hex keeps the query ASCII: wpdb refuses a non-ASCII query it cannot tie to a table.
		$as      = "CONVERT(UNHEX(%s) USING {$charset}) COLLATE {$collation}";
		$selects = implode( ' UNION ALL ', array_fill( 0, count( $candidates ), "SELECT %d AS i, {$as} AS n" ) );
		$args    = [];
		foreach ( $candidates as $i => $candidate ) {
			$args[] = $i;
			$args[] = bin2hex( $candidate );
		}
		$args[] = bin2hex( $name );

		$suppressed = $wpdb->suppress_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Charset and collation are validated identifiers read from the column; every value is a placeholder.
		$index = $wpdb->get_var( $wpdb->prepare( "SELECT i FROM ({$selects}) AS c WHERE n = {$as} LIMIT 1", $args ) );
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( $suppressed );

		if ( $error !== '' ) {
			throw new RuntimeException( 'Could not compare option names: ' . esc_html( $error ) );
		}

		return $index === null ? null : $candidates[ (int) $index ];
	}

	/**
	 * The charset and collation of the current options table's `option_name`.
	 *
	 * @return array{0: string, 1: string}
	 * @throws RuntimeException When it cannot be read.
	 */
	private static function collation(): array {
		global $wpdb;

		$table = $wpdb->options;
		if ( isset( self::$collations[ $table ] ) ) {
			return self::$collations[ $table ];
		}

		$suppressed = $wpdb->suppress_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema read, cached for the request.
		$column = $wpdb->get_row( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i LIKE %s', $table, 'option_name' ), ARRAY_A );
		$wpdb->suppress_errors( $suppressed );

		$collation = is_array( $column ) ? (string) ( $column['Collation'] ?? '' ) : '';
		if ( preg_match( '/^[A-Za-z0-9]+(?:_[A-Za-z0-9_]+)?$/', $collation ) !== 1 ) {
			throw new RuntimeException( 'Could not read the option_name collation.' );
		}

		// A collation is named after its charset: `utf8mb4_unicode_520_ci`, `latin1_swedish_ci`, `binary`.
		self::$collations[ $table ] = [ explode( '_', $collation )[0], $collation ];

		return self::$collations[ $table ];
	}
}
