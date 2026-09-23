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
	 * (same ability, same resolved input, same acting user) that is still
	 * pending and unexpired returns the existing row rather than a second one,
	 * which keeps the queue a list of decisions to make, not a log of attempts.
	 *
	 * @param string               $ability_name The intercepted ability.
	 * @param array<string, mixed> $input Its resolved input.
	 * @param int                  $user_id      The user the call runs as once approved.
	 * @param string|null          $client_id    OAuth client id of the connection, if any.
	 * @param string|null          $client_name  Snapshotted client name, if any.
	 * @param int                  $ttl_seconds  How long the row may be approved for.
	 *
	 * @return PendingAction The staged (or already-staged) action.
	 * @since 1.5.0
	 */
	public function stage( string $ability_name, array $input, int $user_id, ?string $client_id, ?string $client_name, int $ttl_seconds ): PendingAction {
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
				'status'            => PendingAction::STATUS_PENDING,
				'user_id'           => $user_id,
				'client_id'         => $client_id,
				'client_name'       => $client_name,
				'input_fingerprint' => $fingerprint,
				'created_at'        => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'        => gmdate( 'Y-m-d H:i:s', $now + max( 1, $ttl_seconds ) ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

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
			null
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
		$action = $this->find( $action_id );

		if ( ! $action instanceof PendingAction || ! $action->is_pending() ) {
			return null;
		}

		return strtotime( $action->expires_at ) < time() ? null : $action;
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
	 * Mark an action rejected by a person.
	 *
	 * @param int $id         Row id.
	 * @param int $decided_by The user who rejected it.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function reject( int $id, int $decided_by ): void {
		$this->update(
			$id,
			[
				'status'     => PendingAction::STATUS_REJECTED,
				'decided_at' => gmdate( 'Y-m-d H:i:s' ),
				'decided_by' => $decided_by,
			],
			[ '%s', '%s', '%d' ]
		);
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
	 * @param array<string, mixed> $input Its resolved input.
	 * @param int                  $user_id      The acting user.
	 *
	 * @return string 64-char sha256 hex.
	 * @since 1.5.0
	 */
	private function fingerprint( string $ability_name, array $input, int $user_id ): string {
		return hash( 'sha256', $ability_name . '|' . $user_id . '|' . (string) wp_json_encode( $input ) );
	}
}
