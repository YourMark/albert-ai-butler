<?php
/**
 * Integration tests for the safe-mode pending-actions store.
 *
 * Covers the parts that only a real table can answer: the conditional claim
 * that stops one approval running twice, the expiry comparisons, retry
 * de-duplication, and the sweep queries.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\SafeMode;

use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\SafeMode\PendingAction;
use Albert\SafeMode\Repository;
use Albert\Tests\TestCase;

/**
 * Pending-actions repository integration tests.
 *
 * @covers \Albert\SafeMode\Repository
 */
class RepositoryTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Reset the pending-actions table before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
		$this->repository = new Repository();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::pending_actions() ) );
	}

	/**
	 * Stage one action with a sane default shape.
	 *
	 * @param array<string, mixed> $input       Ability input.
	 * @param int                  $ttl_seconds How long it may be approved for.
	 *
	 * @return PendingAction
	 */
	private function stage( array $input = [ 'id' => 1 ], int $ttl_seconds = DAY_IN_SECONDS ): PendingAction {
		return $this->repository->stage( 'albert/delete-post', $input, 7, 'client-a', 'Claude', $ttl_seconds );
	}

	/**
	 * Stage a row and backdate its expiry so it has already lapsed.
	 *
	 * `stage()` clamps the TTL with `max( 1, ... )`, and rightly so: a TTL is a
	 * duration and a negative one is nonsense. So a lapsed row cannot be staged,
	 * only aged.
	 *
	 * @param array<string, mixed> $input Ability input.
	 *
	 * @return PendingAction
	 */
	private function stage_lapsed( array $input = [ 'id' => 1 ] ): PendingAction {
		$action = $this->stage( $input );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Backdating a row in a test.
		$wpdb->update(
			Tables::pending_actions(),
			[ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ],
			[ 'id' => $action->id ],
			[ '%s' ],
			[ '%d' ]
		);

		return $action;
	}

	/**
	 * Read one row's status straight from the table.
	 *
	 * @param int $id Row id.
	 *
	 * @return string|null
	 */
	private function status_of( int $id ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct read in a test.
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', Tables::pending_actions(), $id ) );

		return is_string( $status ) ? $status : null;
	}

	/**
	 * Claiming is a compare-and-set: the second caller gets nothing.
	 *
	 * Deliberately *not* named "is atomic". A single-process test cannot prove
	 * atomicity, which needs two connections racing on the same row. What it can
	 * prove, and what actually guards against the double-approve, is that the
	 * conditional clause refuses a second claim and leaves the row alone.
	 *
	 * @return void
	 */
	public function test_claim_is_a_compare_and_set(): void {
		$action = $this->stage();

		$this->assertTrue( $this->repository->claim( $action->id, 3 ) );
		$this->assertSame( PendingAction::STATUS_EXECUTING, $this->status_of( $action->id ) );

		$this->assertFalse( $this->repository->claim( $action->id, 4 ) );
		$this->assertSame( PendingAction::STATUS_EXECUTING, $this->status_of( $action->id ) );
	}

	/**
	 * A lapsed row cannot be claimed, even though it is still marked pending.
	 *
	 * The expiry lives in the same clause as the status precisely so a row that
	 * lapsed between the screen rendering and the button being pressed cannot be
	 * run.
	 *
	 * @return void
	 */
	public function test_a_lapsed_row_cannot_be_claimed(): void {
		$action = $this->stage_lapsed();

		$this->assertFalse( $this->repository->claim( $action->id, 3 ) );
		$this->assertSame( PendingAction::STATUS_PENDING, $this->status_of( $action->id ) );
	}

	/**
	 * Rejecting after a claim does not overwrite the outcome.
	 *
	 * @return void
	 */
	public function test_reject_loses_to_an_existing_claim(): void {
		$action = $this->stage();

		$this->assertTrue( $this->repository->claim( $action->id, 3 ) );
		$this->assertFalse( $this->repository->reject( $action->id, 4 ) );
		$this->assertSame( PendingAction::STATUS_EXECUTING, $this->status_of( $action->id ) );
	}

	/**
	 * A lapsed row is not open, and the expiry is compared in SQL.
	 *
	 * @return void
	 */
	public function test_find_open_refuses_a_lapsed_row(): void {
		$live   = $this->stage( [ 'id' => 1 ] );
		$lapsed = $this->stage_lapsed( [ 'id' => 2 ] );

		$this->assertInstanceOf( PendingAction::class, $this->repository->find_open( $live->action_id ) );
		$this->assertNull( $this->repository->find_open( $lapsed->action_id ) );
	}

	/**
	 * A row that has already been decided is not open.
	 *
	 * @return void
	 */
	public function test_find_open_refuses_a_decided_row(): void {
		$action = $this->stage();
		$this->repository->reject( $action->id, 3 );

		$this->assertNull( $this->repository->find_open( $action->action_id ) );
	}

	/**
	 * A retry of the same call returns the row already staged for it.
	 *
	 * @return void
	 */
	public function test_an_identical_retry_reuses_the_staged_row(): void {
		$first  = $this->stage( [ 'id' => 42 ] );
		$second = $this->stage( [ 'id' => 42 ] );

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( 1, $this->repository->count_open() );
	}

	/**
	 * Key order does not create a second row.
	 *
	 * A model reorders JSON keys between turns as a matter of course, and
	 * `wp_json_encode()` preserves insertion order, so without canonicalising the
	 * fingerprint the same retried call stages twice.
	 *
	 * @return void
	 */
	public function test_key_order_does_not_stage_a_duplicate(): void {
		$this->stage(
			[
				'id'    => 42,
				'force' => true,
			]
		);
		$this->stage(
			[
				'force' => true,
				'id'    => 42,
			]
		);

		$this->assertSame( 1, $this->repository->count_open() );
	}

	/**
	 * Nested key order does not create a second row either.
	 *
	 * @return void
	 */
	public function test_nested_key_order_does_not_stage_a_duplicate(): void {
		$this->stage(
			[
				'meta' => [
					'a' => 1,
					'b' => 2,
				],
			]
		);
		$this->stage(
			[
				'meta' => [
					'b' => 2,
					'a' => 1,
				],
			]
		);

		$this->assertSame( 1, $this->repository->count_open() );
	}

	/**
	 * List order is preserved, because two orderings are two different calls.
	 *
	 * @return void
	 */
	public function test_list_order_still_stages_separately(): void {
		$this->stage( [ 'ids' => [ 1, 2 ] ] );
		$this->stage( [ 'ids' => [ 2, 1 ] ] );

		$this->assertSame( 2, $this->repository->count_open() );
	}

	/**
	 * Lapsed rows are flipped to expired, so they stop vanishing from both lists.
	 *
	 * @return void
	 */
	public function test_expire_lapsed_flips_only_lapsed_rows(): void {
		$live   = $this->stage( [ 'id' => 1 ] );
		$lapsed = $this->stage_lapsed( [ 'id' => 2 ] );

		$this->assertSame( 1, $this->repository->expire_lapsed() );
		$this->assertSame( PendingAction::STATUS_PENDING, $this->status_of( $live->id ) );
		$this->assertSame( PendingAction::STATUS_EXPIRED, $this->status_of( $lapsed->id ) );
	}

	/**
	 * A claim that never finished is failed, a fresh one is left running.
	 *
	 * @return void
	 */
	public function test_stale_claims_are_failed(): void {
		$stale = $this->stage( [ 'id' => 1 ] );
		$fresh = $this->stage( [ 'id' => 2 ] );

		$this->repository->claim( $stale->id, 3 );
		$this->repository->claim( $fresh->id, 3 );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Backdating a claim in a test.
		$wpdb->update(
			Tables::pending_actions(),
			[ 'decided_at' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) ) ],
			[ 'id' => $stale->id ],
			[ '%s' ],
			[ '%d' ]
		);

		$this->assertSame( 1, $this->repository->fail_stale_claims( HOUR_IN_SECONDS ) );
		$this->assertSame( PendingAction::STATUS_FAILED, $this->status_of( $stale->id ) );
		$this->assertSame( PendingAction::STATUS_EXECUTING, $this->status_of( $fresh->id ) );
	}

	/**
	 * Old decided rows are deleted; pending and running ones never are.
	 *
	 * @return void
	 */
	public function test_purge_decided_spares_pending_and_running_rows(): void {
		$decided = $this->stage( [ 'id' => 1 ] );
		$pending = $this->stage( [ 'id' => 2 ] );
		$running = $this->stage( [ 'id' => 3 ] );

		$this->repository->reject( $decided->id, 3 );
		$this->repository->claim( $running->id, 3 );

		global $wpdb;
		// Retention measures from the decision, not from creation, which is what
		// the window means everywhere it is described.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Backdating rows in a test.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET created_at = %s, decided_at = IF( decided_at IS NULL, NULL, %s )',
				Tables::pending_actions(),
				gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) ),
				gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) )
			)
		);

		$this->assertSame( 1, $this->repository->purge_decided( 30 ) );
		$this->assertNull( $this->status_of( $decided->id ) );
		$this->assertSame( PendingAction::STATUS_PENDING, $this->status_of( $pending->id ) );
		$this->assertSame( PendingAction::STATUS_EXECUTING, $this->status_of( $running->id ) );
	}

	/**
	 * The sweep can name which rows lapsed, not just how many.
	 *
	 * A bulk UPDATE knows a count and nothing else, so the audit trail reads
	 * these first.
	 *
	 * @return void
	 */
	public function test_lapsed_rows_can_be_listed_before_they_are_expired(): void {
		$this->stage( [ 'id' => 1 ] );
		$lapsed = $this->stage_lapsed( [ 'id' => 2 ] );

		$listed = $this->repository->list_lapsed();

		$this->assertCount( 1, $listed );
		$this->assertSame( $lapsed->action_id, $listed[0]->action_id );
	}

	/**
	 * Stale claims can be listed before they are failed.
	 *
	 * @return void
	 */
	public function test_stale_claims_can_be_listed(): void {
		$stale = $this->stage( [ 'id' => 1 ] );
		$fresh = $this->stage( [ 'id' => 2 ] );

		$this->repository->claim( $stale->id, 3 );
		$this->repository->claim( $fresh->id, 3 );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Backdating a claim in a test.
		$wpdb->update(
			Tables::pending_actions(),
			[ 'decided_at' => gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) ) ],
			[ 'id' => $stale->id ],
			[ '%s' ],
			[ '%d' ]
		);

		$listed = $this->repository->list_stale_claims( HOUR_IN_SECONDS );

		$this->assertCount( 1, $listed );
		$this->assertSame( $stale->action_id, $listed[0]->action_id );
	}

	/**
	 * Retention of zero keeps everything.
	 *
	 * @return void
	 */
	public function test_purge_decided_keeps_everything_when_disabled(): void {
		$action = $this->stage();
		$this->repository->reject( $action->id, 3 );

		$this->assertSame( 0, $this->repository->purge_decided( 0 ) );
		$this->assertSame( PendingAction::STATUS_REJECTED, $this->status_of( $action->id ) );
	}

	/**
	 * A failed insert fires hold_failed, not held, and comes back unstored.
	 *
	 * @return void
	 */
	public function test_a_failed_insert_is_not_reported_as_a_hold(): void {
		global $wpdb;

		$break      = static fn ( string $query ): string => str_starts_with( $query, 'INSERT INTO `' . Tables::pending_actions() . '`' )
			? 'INSERT INTO albert_no_such_table VALUES (1)'
			: $query;
		add_filter( 'query', $break );
		$suppressed = $wpdb->suppress_errors( true );

		$held_before   = did_action( 'albert/safe_mode/held' );
		$failed_before = did_action( 'albert/safe_mode/hold_failed' );

		$action = $this->stage();

		$wpdb->suppress_errors( $suppressed );
		remove_filter( 'query', $break );

		$this->assertFalse( $action->is_stored() );
		$this->assertSame( $held_before, did_action( 'albert/safe_mode/held' ) );
		$this->assertSame( $failed_before + 1, did_action( 'albert/safe_mode/hold_failed' ) );
	}

	/**
	 * Deciding a row clears its owner's cached count, not only the site-wide one.
	 *
	 * @return void
	 */
	public function test_a_decision_refreshes_the_owners_cached_count(): void {
		$approved = $this->stage( [ 'id' => 1 ] );
		$rejected = $this->stage( [ 'id' => 2 ] );
		$this->assertSame( 2, $this->repository->count_open_for_user( 7 ) );

		$this->repository->claim( $approved->id, 3 );
		$this->assertSame( 1, $this->repository->count_open_for_user( 7 ) );

		$this->repository->reject( $rejected->id, 3 );
		$this->assertSame( 0, $this->repository->count_open_for_user( 7 ) );
	}
}
