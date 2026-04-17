<?php
/**
 * Logging Repository
 *
 * @package Albert
 * @subpackage Logging
 * @since      1.1.0
 */

namespace Albert\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Repository class
 *
 * Handles database operations for the ability log table.
 * Free tier retains only the last 2 records per ability_name.
 *
 * @since 1.1.0
 */
class Repository {

	/**
	 * Number of log entries to keep per ability.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const RETENTION_COUNT = 2;

	/**
	 * Insert a log entry and prune old entries for the ability.
	 *
	 * @param string $ability_name The ability identifier.
	 * @param int    $user_id      The user ID who executed the ability.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function insert( string $ability_name, int $user_id ): void {
		global $wpdb;

		$table_name = Installer::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert required for logging.
		$wpdb->insert(
			$table_name,
			[
				'ability_name' => $ability_name,
				'user_id'      => $user_id,
			],
			[ '%s', '%d' ]
		);

		// Prune old entries after insert.
		$this->prune_for_ability( $ability_name );
	}

	/**
	 * Get the latest log entry for a specific ability.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return object|null The log entry or null if none found.
	 * @since 1.1.0
	 */
	public function latest_for_ability( string $ability_name ): ?object {
		global $wpdb;

		$table_name = Installer::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for fetching latest log entry.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, ability_name, user_id, created_at FROM %i WHERE ability_name = %s ORDER BY created_at DESC, id DESC LIMIT 1',
				$table_name,
				$ability_name
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Get the latest log entry overall.
	 *
	 * @return object|null The log entry or null if none found.
	 * @since 1.1.0
	 */
	public function latest_overall(): ?object {
		global $wpdb;

		$table_name = Installer::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for fetching latest log entry.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, ability_name, user_id, created_at FROM %i ORDER BY created_at DESC, id DESC LIMIT 1',
				$table_name
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Get the most recent log entries across all abilities.
	 *
	 * @param int $limit Maximum number of rows to return.
	 *
	 * @return array<int, object{id: int, ability_name: string, user_id: int, created_at: string}> List of log rows, newest first.
	 * @since 1.1.0
	 */
	public function recent( int $limit = 5 ): array {
		global $wpdb;

		$table_name = Installer::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for dashboard recent activity.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, ability_name, user_id, created_at FROM %i ORDER BY created_at DESC, id DESC LIMIT %d',
				$table_name,
				$limit
			)
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Get the latest log entry for each ability in a list.
	 *
	 * Returns an associative array keyed by ability_name, each value being
	 * the most recent log row for that ability. Abilities with no log
	 * entries are omitted from the result.
	 *
	 * @param array<int, string> $ability_names List of ability identifiers.
	 *
	 * @return array<string, object> Map of ability_name => log row object.
	 * @since 1.1.0
	 */
	public function latest_bulk( array $ability_names ): array {
		global $wpdb;

		if ( empty( $ability_names ) ) {
			return [];
		}

		$table_name = Installer::get_table_name();

		// Sanitize ability names for direct use in the query.
		// Each name is escaped via esc_sql() and wrapped in quotes.
		$escaped_names = array_map(
			static function ( string $name ): string {
				return "'" . esc_sql( $name ) . "'";
			},
			$ability_names
		);
		$in_clause     = implode( ',', $escaped_names );

		// Use a subquery to get the max id per ability, then join to get full rows.
		// This is more efficient than a correlated subquery for each ability.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IN clause built from esc_sql() escaped values.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.ability_name, l.user_id, l.created_at
				FROM %i l
				INNER JOIN (
					SELECT ability_name, MAX(id) as max_id
					FROM %i
					WHERE ability_name IN ({$in_clause})
					GROUP BY ability_name
				) latest ON l.id = latest.max_id",
				$table_name,
				$table_name
			)
		);
		// phpcs:enable

		$map = [];
		if ( $results ) {
			foreach ( $results as $row ) {
				$map[ $row->ability_name ] = $row;
			}
		}

		return $map;
	}

	/**
	 * Prune old log entries for an ability, keeping only the most recent.
	 *
	 * Uses a derived table pattern to work around MySQL's restriction on
	 * specifying the target table in a subquery within DELETE.
	 *
	 * @param string $ability_name The ability identifier.
	 * @param int    $keep         Number of entries to keep (default: 2).
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function prune_for_ability( string $ability_name, int $keep = self::RETENTION_COUNT ): void {
		global $wpdb;

		$table_name = Installer::get_table_name();

		// Delete all rows for this ability except the most recent $keep rows.
		// The subselect-in-derived-table pattern avoids MySQL's "can't specify
		// target table for update in FROM clause" error.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for pruning.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
				WHERE ability_name = %s
				AND id NOT IN (
					SELECT id FROM (
						SELECT id FROM %i
						WHERE ability_name = %s
						ORDER BY created_at DESC, id DESC
						LIMIT %d
					) AS keep
				)',
				$table_name,
				$ability_name,
				$table_name,
				$ability_name,
				$keep
			)
		);
	}

	/**
	 * Truncate the entire log table.
	 *
	 * Use with caution. Primarily for testing or complete reset.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function truncate(): void {
		global $wpdb;

		$table_name = Installer::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for truncate.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name ) );
	}
}
