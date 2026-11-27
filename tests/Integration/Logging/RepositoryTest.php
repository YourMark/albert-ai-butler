<?php
/**
 * Integration tests for the Logging Repository.
 *
 * Exercises the real SQL — retention, bulk fetch, pruning, ordering —
 * against the WordPress test suite's MySQL. These are the queries most
 * likely to regress silently when someone refactors.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Logging;

use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Logging\Repository;
use Albert\Tests\TestCase;

/**
 * Repository integration tests.
 *
 * @covers \Albert\Logging\Repository
 */
class RepositoryTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Reset the log table before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Defensive: make sure the table exists even if activation didn't run.
		Installer::install();

		$this->repository = new Repository();
		$this->repository->truncate();
	}

	// ─── insert() + auto-prune ───────────────────────────────────────

	/**
	 * Insert writes a row with the expected columns including status and error_code.
	 *
	 * @return void
	 */
	public function test_insert_writes_row(): void {
		$this->repository->insert( 'albert/create-post', 42 );

		$row = $this->repository->latest_for_ability( 'albert/create-post' );

		$this->assertNotNull( $row );
		$this->assertSame( 'albert/create-post', $row->ability_name );
		$this->assertSame( 42, (int) $row->user_id );
		$this->assertNotEmpty( $row->created_at );
		$this->assertSame( 'success', $row->status );
		$this->assertNull( $row->error_code );
	}

	/**
	 * Insert with status='error' writes the error_code correctly.
	 *
	 * @return void
	 */
	public function test_insert_with_error_status_writes_error_code(): void {
		$this->repository->insert( 'albert/create-post', 5, 'error', 'db_unreachable' );

		$row = $this->repository->latest_for_ability( 'albert/create-post' );

		$this->assertNotNull( $row );
		$this->assertSame( 'error', $row->status );
		$this->assertSame( 'db_unreachable', $row->error_code );
	}

	// ─── central outcome classification ──────────────────────────────

	/**
	 * An `error` handed in with a `_not_found` code is stored as `success`.
	 *
	 * The classification lives in insert() rather than in each writer, so a
	 * caller that only knows `is_wp_error()` — Free's ObservabilityHandler,
	 * Premium's logger, an add-on writing directly — still lands on the right
	 * value. No schema change was needed: `status` is `varchar(20)`.
	 *
	 * @return void
	 */
	public function test_insert_classifies_a_not_found_error_as_success(): void {
		$this->repository->insert( 'albert/view-term', 5, 'error', 'term_not_found' );

		$row = $this->repository->latest_for_ability( 'albert/view-term' );

		$this->assertNotNull( $row );
		$this->assertSame( 'success', $row->status );
		$this->assertSame( 'term_not_found', $row->error_code );
	}

	/**
	 * An `error` handed in with a policy code is stored as `warning`.
	 *
	 * @return void
	 */
	public function test_insert_classifies_a_policy_refusal_as_warning(): void {
		$this->repository->insert( 'albert/create-post', 5, 'error', 'ability_permission_denied' );

		$row = $this->repository->latest_for_ability( 'albert/create-post' );

		$this->assertNotNull( $row );
		$this->assertSame( 'warning', $row->status );
		$this->assertSame( 'ability_permission_denied', $row->error_code );
	}

	/**
	 * The `permission` stage in the context outranks the error code.
	 *
	 * This is the rule that lets an add-on's own permission callback classify
	 * correctly without Albert ever having seen its error code.
	 *
	 * @return void
	 */
	public function test_permission_stage_classifies_an_unknown_code_as_warning(): void {
		$this->repository->insert(
			'acme/search',
			5,
			'error',
			'acme_nope',
			[ 'failure_stage' => 'permission' ]
		);

		$rows = $this->all_rows_for( 'acme/search' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'warning', $rows[0]->status );
		$this->assertSame( 'permission', $rows[0]->failure_stage );
	}

	/**
	 * A success row carries no failure stage.
	 *
	 * The column is called *failure*_stage and an answer is not a failure. A
	 * caller that stamped a stage anyway — Premium records `execute` from where
	 * the invocation died — has it cleared, which is what makes the "Failed at"
	 * badge correctly never render for these rows with no extra logic downstream.
	 *
	 * @return void
	 */
	public function test_success_row_has_a_null_failure_stage(): void {
		$this->repository->insert(
			'albert/view-term',
			5,
			'error',
			'term_not_found',
			[ 'failure_stage' => 'execute' ]
		);

		$rows = $this->all_rows_for( 'albert/view-term' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'success', $rows[0]->status );
		$this->assertNull( $rows[0]->failure_stage );
	}

	/**
	 * A warning keeps its stage — where it stopped is the useful half of the row.
	 *
	 * @return void
	 */
	public function test_warning_row_keeps_its_failure_stage(): void {
		$this->repository->insert(
			'albert/create-post',
			5,
			'error',
			'ability_permission_denied',
			[ 'failure_stage' => 'permission' ]
		);

		$rows = $this->all_rows_for( 'albert/create-post' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'warning', $rows[0]->status );
		$this->assertSame( 'permission', $rows[0]->failure_stage );
	}

	/**
	 * A genuine failure keeps both its status and its stage.
	 *
	 * @return void
	 */
	public function test_a_real_failure_keeps_its_status_and_stage(): void {
		$this->repository->insert(
			'albert/create-post',
			5,
			'error',
			'db_unreachable',
			[ 'failure_stage' => 'execute' ]
		);

		$rows = $this->all_rows_for( 'albert/create-post' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]->status );
		$this->assertSame( 'execute', $rows[0]->failure_stage );
	}

	/**
	 * The `albert/logging/outcome` filter reaches the repository too.
	 *
	 * @return void
	 */
	public function test_outcome_filter_applies_to_repository_writes(): void {
		$filter = static fn( string $status, string $ability ): string => $ability === 'acme/search' ? 'warning' : $status;
		add_filter( 'albert/logging/outcome', $filter, 10, 2 );

		$this->repository->insert( 'acme/search', 5, 'error', 'acme_not_licensed' );

		remove_filter( 'albert/logging/outcome', $filter, 10 );

		$row = $this->repository->latest_for_ability( 'acme/search' );

		$this->assertNotNull( $row );
		$this->assertSame( 'warning', $row->status );
	}

	/**
	 * A warning is its own retention partition.
	 *
	 * Pruning is status-aware, so a run of policy blocks cannot evict the last
	 * real failure or the last success.
	 *
	 * @return void
	 */
	public function test_warning_is_its_own_retention_partition(): void {
		$this->repository->insert( 'albert/view-term', 1, 'success' );
		$this->repository->insert( 'albert/view-term', 2, 'error', 'db_unreachable' );

		foreach ( range( 1, 4 ) as $i ) {
			$this->repository->insert( 'albert/view-term', 10 + $i, 'error', 'ability_permission_denied' );
		}

		$this->assertCount( 1, $this->all_rows_for_status( 'albert/view-term', 'success' ) );
		$this->assertCount( 1, $this->all_rows_for_status( 'albert/view-term', 'error' ) );
		$this->assertCount( Repository::RETENTION_COUNT, $this->all_rows_for_status( 'albert/view-term', 'warning' ) );
	}

	/**
	 * Retention keeps exactly RETENTION_COUNT (2) rows per ability per status.
	 *
	 * @return void
	 */
	public function test_insert_prunes_to_retention_count(): void {
		$this->repository->insert( 'albert/same', 1 );
		$this->repository->insert( 'albert/same', 2 );
		$this->repository->insert( 'albert/same', 3 );
		$this->repository->insert( 'albert/same', 4 );

		$rows = $this->all_rows_for( 'albert/same' );

		$this->assertCount( Repository::RETENTION_COUNT, $rows );
	}

	/**
	 * Per-status retention: a burst of errors does not evict the last success row.
	 *
	 * Each status partition keeps up to RETENTION_COUNT rows independently so
	 * a flood of failures cannot push out the success history.
	 *
	 * @return void
	 */
	public function test_per_status_retention_keeps_success_rows_when_errors_flood(): void {
		// Insert two success rows (fills the success partition).
		$this->repository->insert( 'albert/mixed', 1, 'success' );
		$this->repository->insert( 'albert/mixed', 2, 'success' );

		// Insert four error rows (fills + prunes the error partition).
		$this->repository->insert( 'albert/mixed', 10, 'error', 'err_a' );
		$this->repository->insert( 'albert/mixed', 11, 'error', 'err_b' );
		$this->repository->insert( 'albert/mixed', 12, 'error', 'err_c' );
		$this->repository->insert( 'albert/mixed', 13, 'error', 'err_d' );

		$success_rows = $this->all_rows_for_status( 'albert/mixed', 'success' );
		$error_rows   = $this->all_rows_for_status( 'albert/mixed', 'error' );

		// Both partitions should have exactly RETENTION_COUNT rows.
		$this->assertCount( Repository::RETENTION_COUNT, $success_rows, 'Success partition must retain its rows.' );
		$this->assertCount( Repository::RETENTION_COUNT, $error_rows, 'Error partition must prune to RETENTION_COUNT.' );
	}

	/**
	 * Retention keeps the newest rows, not arbitrary ones.
	 *
	 * @return void
	 */
	public function test_insert_keeps_most_recent_rows(): void {
		$this->repository->insert( 'albert/newest', 1 );
		$this->repository->insert( 'albert/newest', 2 );
		$this->repository->insert( 'albert/newest', 3 );

		$rows     = $this->all_rows_for( 'albert/newest' );
		$user_ids = array_map( static fn( $row ): int => (int) $row->user_id, $rows );

		$this->assertNotContains( 1, $user_ids, 'Oldest row (user 1) should be pruned.' );
	}

	/**
	 * Pruning a log entry for one ability does not touch other abilities.
	 *
	 * @return void
	 */
	public function test_insert_does_not_prune_other_abilities(): void {
		$this->repository->insert( 'albert/alpha', 10 );
		$this->repository->insert( 'albert/beta', 20 );
		$this->repository->insert( 'albert/alpha', 11 );
		$this->repository->insert( 'albert/alpha', 12 );
		$this->repository->insert( 'albert/alpha', 13 );

		$this->assertCount( 1, $this->all_rows_for( 'albert/beta' ) );
	}

	// ─── latest_for_ability() ───────────────────────────────────────

	/**
	 * Returns null when no rows exist for the ability.
	 *
	 * @return void
	 */
	public function test_latest_for_ability_returns_null_when_empty(): void {
		$this->assertNull( $this->repository->latest_for_ability( 'albert/nobody' ) );
	}

	/**
	 * Ordering breaks ties on id when created_at matches.
	 *
	 * Two rows with identical created_at (likely in real use within the same
	 * second) must order deterministically by id DESC — the higher id wins.
	 *
	 * @return void
	 */
	public function test_latest_for_ability_breaks_ties_on_id(): void {
		global $wpdb;

		$table = Tables::ability_log();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->insert(
			$table,
			[
				'ability_name' => 'albert/tied',
				'user_id'      => 1,
				'created_at'   => $now,
			],
			[ '%s', '%d', '%s' ]
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->insert(
			$table,
			[
				'ability_name' => 'albert/tied',
				'user_id'      => 2,
				'created_at'   => $now,
			],
			[ '%s', '%d', '%s' ]
		);

		$latest = $this->repository->latest_for_ability( 'albert/tied' );

		$this->assertSame( 2, (int) $latest->user_id, 'Tie-breaker should pick the higher id.' );
	}

	// ─── latest_bulk() ──────────────────────────────────────────────

	/**
	 * An empty input returns an empty array without querying the DB.
	 *
	 * @return void
	 */
	public function test_latest_bulk_returns_empty_for_empty_input(): void {
		$this->assertSame( [], $this->repository->latest_bulk( [] ) );
	}

	/**
	 * Maps each requested ability to its latest row.
	 *
	 * @return void
	 */
	public function test_latest_bulk_maps_by_ability_name(): void {
		$this->repository->insert( 'albert/one', 1 );
		$this->repository->insert( 'albert/two', 2 );
		$this->repository->insert( 'albert/one', 10 );

		$map = $this->repository->latest_bulk( [ 'albert/one', 'albert/two' ] );

		$this->assertArrayHasKey( 'albert/one', $map );
		$this->assertArrayHasKey( 'albert/two', $map );
		$this->assertSame( 10, (int) $map['albert/one']->user_id );
		$this->assertSame( 2, (int) $map['albert/two']->user_id );
	}

	/**
	 * Returns the new status and error_code fields via latest_bulk().
	 *
	 * @return void
	 */
	public function test_latest_bulk_returns_status_and_error_code_fields(): void {
		$this->repository->insert( 'albert/success-ability', 1, 'success' );
		// Not a refusal code: those classify as `warning`, and this test is
		// about which fields latest_bulk() returns.
		$this->repository->insert( 'albert/error-ability', 2, 'error', 'db_unreachable' );

		$map = $this->repository->latest_bulk( [ 'albert/success-ability', 'albert/error-ability' ] );

		$this->assertSame( 'success', $map['albert/success-ability']->status );
		$this->assertNull( $map['albert/success-ability']->error_code );

		$this->assertSame( 'error', $map['albert/error-ability']->status );
		$this->assertSame( 'db_unreachable', $map['albert/error-ability']->error_code );
	}

	/**
	 * Abilities without any logged rows are omitted from the result map.
	 *
	 * @return void
	 */
	public function test_latest_bulk_omits_abilities_without_rows(): void {
		$this->repository->insert( 'albert/exists', 1 );

		$map = $this->repository->latest_bulk( [ 'albert/exists', 'albert/ghost' ] );

		$this->assertArrayHasKey( 'albert/exists', $map );
		$this->assertArrayNotHasKey( 'albert/ghost', $map );
	}

	/**
	 * An ability name containing a single-quote is escaped via esc_sql.
	 *
	 * Guards against the IN-clause construction in latest_bulk() that
	 * interpolates after esc_sql(). A bypass would both crash the query
	 * and expose an injection surface.
	 *
	 * @return void
	 */
	public function test_latest_bulk_escapes_sql_injection_attempt(): void {
		$this->repository->insert( "albert/evil'; DROP TABLE x;--", 1 );

		$map = $this->repository->latest_bulk( [ "albert/evil'; DROP TABLE x;--" ] );

		$this->assertArrayHasKey( "albert/evil'; DROP TABLE x;--", $map );
	}

	// ─── recent() and latest_overall() ──────────────────────────────

	/**
	 * Returns the latest rows globally, newest first, capped by limit.
	 *
	 * @return void
	 */
	public function test_recent_returns_latest_rows_capped_by_limit(): void {
		$this->repository->insert( 'albert/a', 1 );
		$this->repository->insert( 'albert/b', 2 );
		$this->repository->insert( 'albert/c', 3 );
		$this->repository->insert( 'albert/d', 4 );

		$rows = $this->repository->recent( 2 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 4, (int) $rows[0]->user_id );
	}

	/**
	 * Returns null when the table is empty.
	 *
	 * @return void
	 */
	public function test_latest_overall_returns_null_when_empty(): void {
		$this->assertNull( $this->repository->latest_overall() );
	}

	/**
	 * Picks the most recent across every ability.
	 *
	 * @return void
	 */
	public function test_latest_overall_picks_most_recent(): void {
		$this->repository->insert( 'albert/older', 1 );
		$this->repository->insert( 'albert/newer', 99 );

		$latest = $this->repository->latest_overall();

		$this->assertSame( 'albert/newer', $latest->ability_name );
		$this->assertSame( 99, (int) $latest->user_id );
	}

	// ─── prune_for_ability() with custom keep ───────────────────────

	/**
	 * Accepts a custom $keep value, overriding the default retention count.
	 *
	 * @return void
	 */
	public function test_prune_for_ability_respects_custom_keep(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->repository->insert( 'albert/custom', $i );
		}

		// insert() has already pruned to RETENTION_COUNT=2. Now keep only 1.
		$this->repository->prune_for_ability( 'albert/custom', 1 );

		$this->assertCount( 1, $this->all_rows_for( 'albert/custom' ) );
	}

	// ─── truncate() ─────────────────────────────────────────────────

	/**
	 * Clears every row in the log table.
	 *
	 * @return void
	 */
	public function test_truncate_clears_all_rows(): void {
		$this->repository->insert( 'albert/one', 1 );
		$this->repository->insert( 'albert/two', 2 );

		$this->repository->truncate();

		$this->assertNull( $this->repository->latest_overall() );
	}

	// ─── helpers ────────────────────────────────────────────────────

	/**
	 * Fetch every row for an ability — test helper, bypasses Repository.
	 *
	 * @param string $ability_name Ability id.
	 *
	 * @return array<int, object>
	 */
	private function all_rows_for( string $ability_name ): array {
		global $wpdb;

		$table = Tables::ability_log();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper.
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ability_name = %s ORDER BY id DESC',
				$table,
				$ability_name
			)
		);
	}

	/**
	 * Fetch every row for an ability filtered by status — test helper.
	 *
	 * @param string $ability_name Ability id.
	 * @param string $status       Status value ('success' or 'error').
	 *
	 * @return array<int, object>
	 */
	private function all_rows_for_status( string $ability_name, string $status ): array {
		global $wpdb;

		$table = Tables::ability_log();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test helper.
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ability_name = %s AND status = %s ORDER BY id DESC',
				$table,
				$ability_name,
				$status
			)
		);
	}
}
