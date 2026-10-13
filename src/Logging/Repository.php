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

use Albert\Database\Tables;
use Albert\Privacy\PrivacyMode;
use WP_Error;

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
	 * Insert a log entry and prune old entries for the ability/status partition.
	 *
	 * Accepts an optional $context array whose keys map to the richer columns
	 * added in 1.2.0. Unknown keys are silently ignored. Free always passes an
	 * empty array; Premium populates the full set.
	 *
	 * Context keys → columns:
	 *   duration_ms   (int|null)    → duration_ms
	 *   failure_stage (string|null) → failure_stage
	 *   user_agent    (string|null) → user_agent
	 *   privacy_mode  (string|null) → privacy_mode
	 *   input         (string|null) → input
	 *   output        (string|null) → output
	 *   client_id     (string|null) → client_id
	 *   client_name   (string|null) → client_name
	 *   error_message (string|null) → error_message
	 *
	 * `failure_stage` names where the invocation died — `short_circuit`,
	 * `input`, `permission`, `execute` or `output` — and stays null on success.
	 *
	 * `privacy_mode` is stamped here rather than left to each caller; see below.
	 *
	 * `status` is classified here for the same reason: an `error` handed in is
	 * re-read through {@see Outcome} so that a truthful negative answer —
	 * `term_not_found` — is stored as `success`, and a policy block — permission
	 * refused, ability switched off — is stored as `warning`, no matter which
	 * writer produced it. Every writer classifies identically instead of each
	 * one carrying its own copy of the rule.
	 *
	 * `ip_address`, `referrer` and `request_id` were dropped from the schema in
	 * 1.4.0. They are deliberately *not* deprecation-wrapped: the mapping loop
	 * has always ignored keys it does not recognise, so an add-on built against
	 * an older Free that still sends them degrades silently instead of erroring,
	 * and that same property is what lets one build of an add-on run against
	 * both the old and the new Free.
	 *
	 * The prune step is gated behind `albert/logging/enabled` so that Premium's
	 * full-history rows are never trimmed by Free's 2-row cap. When Premium is
	 * active it returns false from that filter and handles its own retention.
	 *
	 * @param string               $ability_name The ability identifier.
	 * @param int                  $user_id      The user ID who executed the ability.
	 * @param string               $status       Execution status: 'success', 'warning' or 'error'. Default 'success'.
	 * @param string|null          $error_code   WP_Error code when status is 'error'. Default null.
	 * @param array<string, mixed> $context      Optional extended context. Default [].
	 *
	 * @return void
	 * @since 1.1.0
	 * @since 1.4.0 Context gained `failure_stage` and `privacy_mode`, and lost
	 *               `ip_address`, `referrer` and `request_id`. `privacy_mode` is
	 *               stamped from the active setting when the caller omits it.
	 * @since 1.4.0 An `error` status is reclassified through {@see Outcome},
	 *               which may store it as `success` or `warning`.
	 */
	public function insert( string $ability_name, int $user_id, string $status = 'success', ?string $error_code = null, array $context = [] ): void {
		global $wpdb;

		$table_name = Tables::ability_log();

		// Classify centrally, for the same reason `privacy_mode` is stamped
		// centrally: every writer — Free's Logger, Free's ObservabilityHandler,
		// Premium's own logger — must land on the same value for the same
		// error, and the only way to guarantee that is to decide it here rather
		// than in each of them. A caller that already decided (it passed
		// `warning` or `success`) is left alone; only a plain `error` is
		// re-read. The stage is handed along because it outranks the code: a
		// writer that watched the call stop in the permission check knows more
		// than any spelling convention can. The WP_Error is rebuilt from what
		// the caller gave us so the `albert/logging/outcome` filter always sees
		// the same shape.
		if ( $status === Outcome::ERROR ) {
			$message = isset( $context['error_message'] ) && is_string( $context['error_message'] ) ? $context['error_message'] : '';
			$stage   = isset( $context['failure_stage'] ) && is_string( $context['failure_stage'] ) ? $context['failure_stage'] : null;
			$status  = Outcome::for_error( new WP_Error( (string) $error_code, $message ), $ability_name, $stage );
		}

		// A `success` carries no stage. The column is called *failure*_stage and
		// an answer — even "there is no such term" — is not a failure, which is
		// also what makes the "Failed at" badge correctly not render for these
		// rows, with no extra logic anywhere downstream. Forced rather than
		// merely defaulted: Premium stamps `execute` from where the invocation
		// died, and it died nowhere.
		//
		// A `warning` deliberately keeps its stage. It genuinely stopped at
		// `permission` (or was short-circuited), and that is the most useful
		// half of the row.
		if ( $status === Outcome::SUCCESS ) {
			$context['failure_stage'] = null;
		}

		$data    = [
			'ability_name' => $ability_name,
			'user_id'      => $user_id,
			'status'       => $status,
			'error_code'   => $error_code,
		];
		$formats = [ '%s', '%d', '%s', '%s' ];

		// The privacy mode is recorded as it stands *now*, at write time. It
		// cannot be recomputed when the row is read back: the site owner may
		// have changed the setting since, and a row rendered under today's mode
		// would misreport how its own stored payload was actually treated.
		// Stamping it here, once, means every writer gets it consistently
		// instead of each add-on having to remember. An explicit value from the
		// caller still wins — a writer that logs on behalf of an invocation
		// that ran under a different mode knows better than this method does.
		if ( ! array_key_exists( 'privacy_mode', $context ) ) {
			$context['privacy_mode'] = PrivacyMode::resolve()->value;
		}

		// Map allowed context keys to columns. Unknown keys are ignored, and
		// that is deliberate rather than an oversight: it is what lets a caller
		// built against a different Free version — one still sending the
		// `ip_address`, `referrer` or `request_id` retired in 1.4.0, or one
		// already sending a key a later Free will add — write a good row
		// instead of erroring. No deprecation wrapper is needed for the three
		// removed keys; silence is the compatibility mechanism.
		$allowed = [ 'duration_ms', 'failure_stage', 'user_agent', 'privacy_mode', 'input', 'output', 'client_id', 'client_name', 'error_message' ];
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $context ) ) {
				$data[ $key ] = $context[ $key ];
				$formats[]    = ( $key === 'duration_ms' && $context[ $key ] !== null ) ? '%d' : '%s';
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct insert required for logging.
		$inserted = $wpdb->insert( $table_name, $data, $formats );

		if ( $inserted !== false ) {
			ExecutionLogMarker::remember_row( $ability_name, (int) $wpdb->insert_id );
		}

		// Prune only when the row was actually written and Free is the writer
		// (i.e. albert/logging/enabled is true). Skipping the prune on a failed
		// insert avoids evicting a surviving row without adding a replacement.
		// When Premium is active the filter returns false and it manages its own
		// time-based retention — Free must not apply its 2-row cap.
		if ( $inserted !== false && apply_filters( 'albert/logging/enabled', true ) ) {
			$this->prune_for_ability( $ability_name, self::RETENTION_COUNT, $status );
		}
	}

	/**
	 * Get the latest log entry for a specific ability.
	 *
	 * Returns the most recent row regardless of status.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return object{id: string, ability_name: string, user_id: string, created_at: string, status: string, error_code: string|null}|null The log entry or null if none found.
	 * @since 1.1.0
	 */
	public function latest_for_ability( string $ability_name ): ?object {
		global $wpdb;

		$table_name = Tables::ability_log();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for fetching latest log entry.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, ability_name, user_id, created_at, status, error_code FROM %i WHERE ability_name = %s ORDER BY created_at DESC, id DESC LIMIT 1',
				$table_name,
				$ability_name
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Get the latest log entry overall.
	 *
	 * @return object{id: string, ability_name: string, user_id: string, created_at: string, status: string, error_code: string|null}|null The log entry or null if none found.
	 * @since 1.1.0
	 */
	public function latest_overall(): ?object {
		global $wpdb;

		$table_name = Tables::ability_log();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for fetching latest log entry.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, ability_name, user_id, created_at, status, error_code FROM %i ORDER BY created_at DESC, id DESC LIMIT 1',
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
	 * `created_ts` is the row's created_at converted to a Unix timestamp by the
	 * database, which sidesteps any mismatch between the MySQL server time zone
	 * (used by CURRENT_TIMESTAMP) and PHP's time zone.
	 *
	 * @return array<int, object{id: string, ability_name: string, user_id: string, created_at: string, created_ts: string, status: string, error_code: string|null}> List of log rows, newest first.
	 * @since 1.1.0
	 */
	public function recent( int $limit = 5 ): array {
		global $wpdb;

		$table_name = Tables::ability_log();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for dashboard recent activity.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, ability_name, user_id, created_at, UNIX_TIMESTAMP( created_at ) AS created_ts, status, error_code FROM %i ORDER BY created_at DESC, id DESC LIMIT %d',
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
	 * the most recent log row (regardless of status) for that ability.
	 * Abilities with no log entries are omitted from the result.
	 *
	 * @param array<int, string> $ability_names List of ability identifiers.
	 *
	 * @return array<string, object{id: string, ability_name: string, user_id: string, created_at: string, created_ts: string, status: string, error_code: string|null}> Map of ability_name => log row object.
	 * @since 1.1.0
	 */
	public function latest_bulk( array $ability_names ): array {
		global $wpdb;

		if ( empty( $ability_names ) ) {
			return [];
		}

		$table_name = Tables::ability_log();

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
				"SELECT l.id, l.ability_name, l.user_id, l.created_at, UNIX_TIMESTAMP( l.created_at ) AS created_ts, l.status, l.error_code, l.error_message
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
	 * Prune old log entries for an ability/status partition, keeping only the most recent.
	 *
	 * Uses a derived table pattern to work around MySQL's restriction on
	 * specifying the target table in a subquery within DELETE. Pruning is
	 * status-aware so a burst of failures cannot evict the last success row.
	 *
	 * @param string $ability_name The ability identifier.
	 * @param int    $keep         Number of entries to keep per status partition (default: RETENTION_COUNT).
	 * @param string $status       Status partition to prune ('success', 'warning' or 'error'). Default 'success'.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function prune_for_ability( string $ability_name, int $keep = self::RETENTION_COUNT, string $status = 'success' ): void {
		global $wpdb;

		$table_name = Tables::ability_log();

		// Delete all rows for this ability+status except the most recent $keep rows.
		// The subselect-in-derived-table pattern avoids MySQL's "can't specify
		// target table for update in FROM clause" error.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for pruning.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
				WHERE ability_name = %s
				AND status = %s
				AND id NOT IN (
					SELECT id FROM (
						SELECT id FROM %i
						WHERE ability_name = %s
						AND status = %s
						ORDER BY created_at DESC, id DESC
						LIMIT %d
					) AS keep
				)',
				$table_name,
				$ability_name,
				$status,
				$table_name,
				$ability_name,
				$status,
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

		$table_name = Tables::ability_log();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for truncate.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table_name ) );
	}
}
