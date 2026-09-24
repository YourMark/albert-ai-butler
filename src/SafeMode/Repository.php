<?php
/**
 * Safe-mode pending-actions repository.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

use Albert\Database\Tables;

/**
 * Reads and writes the safe-mode pending-actions queue.
 *
 * All times are stored and compared in UTC (`gmdate`), the same convention the
 * single-use token table uses, so expiry is independent of the site's timezone.
 *
 * @since 1.5.0
 */
class Repository {

	/**
	 * Stage a gated call, or return the one already staged for it.
	 *
	 * Retrying an unapproved destructive call is expected: an assistant that
	 * gets back "awaiting approval" may well try again. So an identical call
	 * (same ability, same captured input, same acting user) that is still
	 * pending and unexpired returns the existing row rather than a second one,
	 * which keeps the queue a list of decisions to make, not a log of attempts.
	 *
	 * @param string                    $ability_name The intercepted ability.
	 * @param array<string, mixed>      $input Its captured input.
	 * @param int                       $user_id      The user the call runs as once approved.
	 * @param string|null               $client_id    OAuth client id of the connection, if any.
	 * @param string|null               $client_name  Snapshotted client name, if any.
	 * @param int                       $ttl_seconds  How long the row may be approved for.
	 * @param array<string, mixed>|null $target  Snapshot of the affected object, or null.
	 *
	 * @return PendingAction The staged (or already-staged) action.
	 * @since 1.5.0
	 */
	public function stage( string $ability_name, array $input, int $user_id, ?string $client_id, ?string $client_name, int $ttl_seconds, ?array $target = null ): PendingAction {
		$fingerprint = $this->fingerprint( $ability_name, $input, $user_id );

		$existing = $this->find_open_duplicate( $ability_name, $fingerprint );
		if ( $existing instanceof PendingAction ) {
			return $existing;
		}

		global $wpdb;

		$now       = time();
		$action_id = wp_generate_uuid4();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct insert on a custom table.
		$wpdb->insert(
			Tables::pending_actions(),
			[
				'action_id'         => $action_id,
				'ability_name'      => $ability_name,
				'input'             => (string) wp_json_encode( $input ),
				'target'            => $target === null ? null : (string) wp_json_encode( $target ),
				'status'            => PendingAction::STATUS_PENDING,
				'user_id'           => $user_id,
				'client_id'         => $client_id,
				'client_name'       => $client_name,
				'input_fingerprint' => $fingerprint,
				'created_at'        => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'        => gmdate( 'Y-m-d H:i:s', $now + max( 1, $ttl_seconds ) ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		/**
		 * Fires when a new destructive call is held for approval.
		 *
		 * One per genuinely new hold (a de-duped retry does not re-fire, having
		 * returned early above). The single number worth watching before the
		 * anti-fatigue work is scheduled: holds per ability tells whether the queue
		 * is converging or growing. The pending-actions table already records the
		 * same, but this survives any future retention pruning.
		 *
		 * @since 1.5.0
		 *
		 * @param string $ability_name The ability whose call was held.
		 */
		do_action( 'albert/safe_mode/held', $ability_name );

		$staged = $this->find( $action_id );

		// A failed insert (or a race that deleted the row) still owes the caller a
		// value it can render; the unsaved instance carries everything just staged.
		return $staged ?? new PendingAction(
			0,
			$action_id,
			$ability_name,
			$input,
			PendingAction::STATUS_PENDING,
			$user_id,
			$client_id,
			$client_name,
			gmdate( 'Y-m-d H:i:s', $now ),
			gmdate( 'Y-m-d H:i:s', $now + max( 1, $ttl_seconds ) ),
			null,
			null,
			null,
			$target
		);
	}

	/**
	 * Find one action by its public reference, whatever its status.
	 *
	 * @param string $action_id The public reference.
	 *
	 * @return PendingAction|null
	 * @since 1.5.0
	 */
	public function find( string $action_id ): ?PendingAction {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct read on a custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE action_id = %s',
				Tables::pending_actions(),
				$action_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? PendingAction::from_row( $row ) : null;
	}

	/**
	 * Find one action only if it is still open to a decision.
	 *
	 * "Open" means pending and unexpired: an already-decided or lapsed row is
	 * treated as absent so an approval handler cannot act on it twice.
	 *
	 * @param string $action_id The public reference.
	 *
	 * @return PendingAction|null
	 * @since 1.5.0
	 */
	public function find_open( string $action_id ): ?PendingAction {
		global $wpdb;

		// Compared in SQL, like every other expiry check in this class. It used
		// to be a PHP `strtotime()` against a stored UTC string, which is correct
		// only because wp-settings.php sets the process timezone to UTC: a plugin
		// calling date_default_timezone_set() would have broken this one method
		// and left the other four right.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct read on a custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE action_id = %s AND status = %s AND expires_at > %s',
				Tables::pending_actions(),
				$action_id,
				PendingAction::STATUS_PENDING,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		return is_array( $row ) ? PendingAction::from_row( $row ) : null;
	}

	/**
	 * Actions still awaiting a decision, newest first.
	 *
	 * Expired rows are excluded so the queue only ever shows what a person can
	 * still act on; a separate sweep flips their status.
	 *
	 * @param int $limit Maximum rows.
	 *
	 * @return list<PendingAction>
	 * @since 1.5.0
	 */
	public function list_open( int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct read on a custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND expires_at > %s ORDER BY created_at DESC, id DESC LIMIT %d',
				Tables::pending_actions(),
				PendingAction::STATUS_PENDING,
				gmdate( 'Y-m-d H:i:s' ),
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_values( array_map( [ PendingAction::class, 'from_row' ], is_array( $rows ) ? $rows : [] ) );
	}

	/**
	 * The most recently decided actions, newest decision first.
	 *
	 * @param int $limit Maximum rows.
	 *
	 * @return list<PendingAction>
	 * @since 1.5.0
	 */
	public function list_decided( int $limit = 20 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct read on a custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status <> %s ORDER BY decided_at DESC, id DESC LIMIT %d',
				Tables::pending_actions(),
				PendingAction::STATUS_PENDING,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_values( array_map( [ PendingAction::class, 'from_row' ], is_array( $rows ) ? $rows : [] ) );
	}

	/**
	 * How many actions are awaiting a decision right now.
	 *
	 * @return int
	 * @since 1.5.0
	 */
	public function count_open(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct count on a custom table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND expires_at > %s',
				Tables::pending_actions(),
				PendingAction::STATUS_PENDING,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Atomically claim a pending action for execution.
	 *
	 * The whole race defence: a single conditional update flips the row from
	 * pending to executing, and only the caller that changed exactly one row won.
	 * A concurrent second approval (two tabs, a double-click, a proxy retry) then
	 * changes zero rows and is refused, so the action never runs twice. The
	 * expiry is folded into the same clause so a row that lapsed between the
	 * screen's read and this write cannot be claimed either.
	 *
	 * @param int $id         Row id.
	 * @param int $decided_by The user approving it.
	 *
	 * @return bool True when this caller won the claim.
	 * @since 1.5.0
	 */
	public function claim( int $id, int $decided_by ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic claim on a custom table.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, decided_at = %s, decided_by = %d WHERE id = %d AND status = %s AND expires_at > %s',
				Tables::pending_actions(),
				PendingAction::STATUS_EXECUTING,
				gmdate( 'Y-m-d H:i:s' ),
				$decided_by,
				$id,
				PendingAction::STATUS_PENDING,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return (int) $claimed === 1;
	}

	/**
	 * Record the outcome of an approved action.
	 *
	 * @param int                  $id         Row id.
	 * @param int                  $decided_by The user who approved it.
	 * @param bool                 $succeeded  Whether the ability ran without error.
	 * @param array<string, mixed> $result What the ability returned (or the error shape).
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function record_outcome( int $id, int $decided_by, bool $succeeded, array $result ): void {
		$this->update(
			$id,
			[
				'status'     => $succeeded ? PendingAction::STATUS_EXECUTED : PendingAction::STATUS_FAILED,
				'decided_at' => gmdate( 'Y-m-d H:i:s' ),
				'decided_by' => $decided_by,
				'result'     => (string) wp_json_encode( $result ),
			],
			[ '%s', '%s', '%d', '%s' ]
		);
	}

	/**
	 * Mark an action rejected by a person, only if it is still pending.
	 *
	 * Conditional for the same reason {@see self::claim()} is: a reject that
	 * lands after an approval has already claimed the row must not overwrite the
	 * outcome. Only a still-pending row is rejected.
	 *
	 * @param int $id         Row id.
	 * @param int $decided_by The user who rejected it.
	 *
	 * @return bool True when this caller rejected a still-pending row.
	 * @since 1.5.0
	 */
	public function reject( int $id, int $decided_by ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional update on a custom table.
		$rejected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, decided_at = %s, decided_by = %d WHERE id = %d AND status = %s',
				Tables::pending_actions(),
				PendingAction::STATUS_REJECTED,
				gmdate( 'Y-m-d H:i:s' ),
				$decided_by,
				$id,
				PendingAction::STATUS_PENDING
			)
		);

		return (int) $rejected === 1;
	}

	/**
	 * Flip every lapsed pending row to expired.
	 *
	 * @return int How many rows were expired.
	 * @since 1.5.0
	 */
	public function expire_lapsed(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update on a custom table.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s WHERE status = %s AND expires_at <= %s',
				Tables::pending_actions(),
				PendingAction::STATUS_EXPIRED,
				PendingAction::STATUS_PENDING,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * The pending rows that have lapsed, before they are expired.
	 *
	 * Read separately from {@see self::expire_lapsed()} so the sweep can say
	 * which actions lapsed rather than only how many. A bulk UPDATE cannot tell
	 * anybody what it touched.
	 *
	 * @param int $limit Maximum rows.
	 *
	 * @return list<PendingAction>
	 * @since 1.5.0
	 */
	public function list_lapsed( int $limit = 200 ): array {
		return $this->list_where(
			'status = %s AND expires_at <= %s',
			[ PendingAction::STATUS_PENDING, gmdate( 'Y-m-d H:i:s' ) ],
			$limit
		);
	}

	/**
	 * The claims that were never finished, before they are failed.
	 *
	 * @param int $stale_after_seconds How long a claim may sit unfinished.
	 * @param int $limit               Maximum rows.
	 *
	 * @return list<PendingAction>
	 * @since 1.5.0
	 */
	public function list_stale_claims( int $stale_after_seconds, int $limit = 200 ): array {
		return $this->list_where(
			'status = %s AND decided_at IS NOT NULL AND decided_at <= %s',
			[
				PendingAction::STATUS_EXECUTING,
				gmdate( 'Y-m-d H:i:s', time() - max( 1, $stale_after_seconds ) ),
			],
			$limit
		);
	}

	/**
	 * Fail every claim that was never finished.
	 *
	 * `claim()` flips a row to `executing` and then the ability runs. A fatal or
	 * a timeout in between leaves the row there for good: excluded from the open
	 * list because it is no longer pending, and shown in the decided list as
	 * "Approved, running" forever. Nothing else ever moves it, so this does.
	 *
	 * The ability may well have completed its work before dying, so this records
	 * `failed` rather than claiming nothing happened. What it actually asserts is
	 * narrower and true: the run stopped reporting.
	 *
	 * @param int $stale_after_seconds How long a claim may sit unfinished.
	 *
	 * @return int How many rows were failed.
	 * @since 1.5.0
	 */
	public function fail_stale_claims( int $stale_after_seconds ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update on a custom table.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, result = %s WHERE status = %s AND decided_at IS NOT NULL AND decided_at <= %s',
				Tables::pending_actions(),
				PendingAction::STATUS_FAILED,
				(string) wp_json_encode(
					[
						'code'    => 'albert_run_abandoned',
						'message' => __( 'Approved, but the run never reported back. It may or may not have completed.', 'albert-ai-butler' ),
					]
				),
				PendingAction::STATUS_EXECUTING,
				gmdate( 'Y-m-d H:i:s', time() - max( 1, $stale_after_seconds ) )
			)
		);
	}

	/**
	 * Delete decided actions older than a retention window.
	 *
	 * This table stores the input each call was about to run with, so it is the
	 * one place Albert keeps request payloads at rest. Deleting is the only thing
	 * that gets them out of next year's backups; masking them on screen does not.
	 *
	 * Pending rows are never touched, however old: an undecided request is not
	 * rubbish, and `expire_lapsed()` moves it on first.
	 *
	 * @param int $days Keep decided rows for this many days. 0 or less keeps everything.
	 *
	 * @return int How many rows were deleted.
	 * @since 1.5.0
	 */
	public function purge_decided( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct delete on a custom table.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE status <> %s AND status <> %s AND created_at <= %s',
				Tables::pending_actions(),
				PendingAction::STATUS_PENDING,
				PendingAction::STATUS_EXECUTING,
				gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);
	}

	/**
	 * An open, identical, still-decidable action for this fingerprint, if any.
	 *
	 * @param string $ability_name The ability.
	 * @param string $fingerprint  The input fingerprint.
	 *
	 * @return PendingAction|null
	 * @since 1.5.0
	 */
	private function find_open_duplicate( string $ability_name, string $fingerprint ): ?PendingAction {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct read on a custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ability_name = %s AND input_fingerprint = %s AND status = %s AND expires_at > %s ORDER BY id DESC LIMIT 1',
				Tables::pending_actions(),
				$ability_name,
				$fingerprint,
				PendingAction::STATUS_PENDING,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		return is_array( $row ) ? PendingAction::from_row( $row ) : null;
	}

	/**
	 * Rows matching a WHERE fragment, newest first.
	 *
	 * @param string       $where    SQL WHERE fragment with `%s`/`%d` placeholders.
	 * @param list<scalar> $bindings Values for those placeholders.
	 * @param int          $limit    Maximum rows.
	 *
	 * @return list<PendingAction>
	 * @since 1.5.0
	 */
	private function list_where( string $where, array $bindings, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a literal fragment from this class; every value is bound.
		$sql = 'SELECT * FROM %i WHERE ' . $where . ' ORDER BY id DESC LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Direct read on a custom table; bound below.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are bound here.
			$wpdb->prepare( $sql, array_merge( [ Tables::pending_actions() ], $bindings, [ max( 1, $limit ) ] ) ),
			ARRAY_A
		);

		return array_values( array_map( [ PendingAction::class, 'from_row' ], is_array( $rows ) ? $rows : [] ) );
	}

	/**
	 * Apply a column update to one row.
	 *
	 * @param int                  $id      Row id.
	 * @param array<string, mixed> $data    Column => value.
	 * @param list<string>         $formats `$wpdb` format specifiers matching $data.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function update( int $id, array $data, array $formats ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update on a custom table.
		$wpdb->update( Tables::pending_actions(), $data, [ 'id' => $id ], $formats, [ '%d' ] );
	}

	/**
	 * A stable fingerprint of a call, for retry de-duplication.
	 *
	 * @param string               $ability_name The ability.
	 * @param array<string, mixed> $input Its captured input.
	 * @param int                  $user_id      The acting user.
	 *
	 * @return string 64-char sha256 hex.
	 * @since 1.5.0
	 */
	private function fingerprint( string $ability_name, array $input, int $user_id ): string {
		return hash( 'sha256', $ability_name . '|' . $user_id . '|' . (string) wp_json_encode( self::canonicalize( $input ) ) );
	}

	/**
	 * Sort an input array by key, at every depth, so equal calls hash equally.
	 *
	 * `wp_json_encode()` preserves insertion order, and a model reorders JSON
	 * keys between turns as a matter of course. Without this, the same retried
	 * call spelled `{id, force}` one turn and `{force, id}` the next fingerprints
	 * differently and stages a second row, which is exactly the duplicate the
	 * de-duplication exists to prevent.
	 *
	 * List order is left alone: `[1,2]` and `[2,1]` are genuinely different
	 * inputs to most abilities, so normalising those would merge calls that are
	 * not the same.
	 *
	 * @param array<mixed> $input The input to canonicalise.
	 *
	 * @return array<mixed>
	 * @since 1.5.0
	 */
	private static function canonicalize( array $input ): array {
		if ( ! array_is_list( $input ) ) {
			ksort( $input );
		}

		foreach ( $input as $key => $value ) {
			if ( is_array( $value ) ) {
				$input[ $key ] = self::canonicalize( $value );
			}
		}

		return $input;
	}
}
