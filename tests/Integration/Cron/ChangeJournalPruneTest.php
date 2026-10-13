<?php
/**
 * Integration tests for the change-journal retention prune.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Cron;

use Albert\Cron\ChangeJournalPrune;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Tests\TestCase;
use Albert\Undo\Entity;
use Albert\Undo\FailureCounter;
use Albert\Undo\Retention\FreeRetentionPolicy;
use Albert\Undo\Retention\Registrar;
use Albert\Undo\Retention\RetentionPolicy;
use Albert\Undo\Storage\Repository;
use Albert\Undo\WindowContext;

/**
 * Retention tests.
 *
 * @covers \Albert\Cron\ChangeJournalPrune
 * @covers \Albert\Undo\Storage\Repository
 * @covers \Albert\Undo\Retention\Registrar
 */
class ChangeJournalPruneTest extends TestCase {

	/**
	 * Journal storage.
	 *
	 * @var Repository
	 */
	private Repository $journal;

	/**
	 * Fresh tables.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		global $wpdb;
		foreach ( [ Tables::changes(), Tables::change_items() ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
		}

		$this->journal = new Repository();
	}

	/**
	 * Free's limits are the documented constants.
	 *
	 * @return void
	 */
	public function test_free_limits(): void {
		$policy = Registrar::current();

		$this->assertInstanceOf( FreeRetentionPolicy::class, $policy );
		$this->assertSame( 2, $policy->max_age_days() );
		$this->assertSame( 2, $policy->runs_per_item() );
		$this->assertSame( 50 * 1024 * 1024, $policy->max_bytes() );
	}

	/**
	 * Only a registered policy class changes the limits.
	 *
	 * @return void
	 */
	public function test_a_registered_policy_replaces_free(): void {
		$policy = $this->policy( 90, 1000 );
		$hook   = static fn( Registrar $registrar ) => $registrar->register( $policy );

		add_action( 'albert/undo/register_retention_policy', $hook );
		try {
			$this->assertSame( $policy, Registrar::current() );
		} finally {
			remove_action( 'albert/undo/register_retention_policy', $hook );
		}
	}

	/**
	 * A session is pruned whole, counted from its last call: an old first call
	 * stays revertable while the session it belongs to is recent.
	 *
	 * @return void
	 */
	public function test_a_session_is_pruned_whole(): void {
		$first = $this->record( 'client-a', Entity::post( 42 ) );
		$last  = $this->record( 'client-a', Entity::post( 42 ) );
		$this->age( $first, 3 * DAY_IN_SECONDS );

		( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();

		$this->assertSame( [ null ], $this->stored( $first ), 'Its session had a call within the window.' );

		$this->age( $last, 3 * DAY_IN_SECONDS );
		( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();

		$this->assertSame( [ 'age' ], $this->stored( $first ) );
		$this->assertSame( [ 'age' ], $this->stored( $last ) );
	}

	/**
	 * The window holds at read time too, before the nightly prune has run.
	 *
	 * @return void
	 */
	public function test_the_window_holds_without_the_prune(): void {
		$old = $this->record( 'client-a', Entity::option( 'a' ) );
		$this->age( $old, 3 * DAY_IN_SECONDS );

		$this->assertSame( 'age', $this->item( $old )['pruned'] );
		$this->assertNull( $this->item( $old )['before'] );
	}

	/**
	 * An item keeps its last two sessions. The session before them is pruned
	 * whole, including what it changed that nothing later touched, so it can
	 * never be half reverted.
	 *
	 * @return void
	 */
	public function test_the_cap_counts_sessions_and_prunes_them_whole(): void {
		$oldest = $this->record( 'client-a', Entity::post( 42 ), Entity::option( 'only-here' ) );
		$middle = $this->record( 'client-b', Entity::post( 42 ) );
		$newest = $this->record( 'client-c', Entity::post( 42 ) );
		$more   = $this->record( 'client-c', Entity::post( 42 ) );

		$this->assertSame( 'runs', $this->item( $oldest )['pruned'], 'Enforced on read before the prune runs.' );
		$this->assertSame( [ null, null ], $this->stored( $oldest ) );

		( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();

		$this->assertSame( [ 'runs', 'runs' ], $this->stored( $oldest ) );
		$this->assertSame( [ null ], $this->stored( $middle ) );
		$this->assertSame( [ null ], $this->stored( $newest ), 'Two calls in one session count once.' );
		$this->assertSame( [ null ], $this->stored( $more ) );
	}

	/**
	 * Sessions rank by their last change to the item, not by when they
	 * started: a session that began first but changed the item last is kept.
	 *
	 * @return void
	 */
	public function test_overlapping_sessions_rank_by_their_last_change(): void {
		$early_start = $this->record( 'client-a', Entity::option( 'x' ) );
		$between     = $this->record( 'client-b', Entity::option( 'x' ) );
		$late_change = $this->record( 'client-a', Entity::option( 'x' ) );
		$this->record( 'client-c', Entity::option( 'x' ) );

		( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();

		$this->assertSame( [ 'runs' ], $this->stored( $between ) );
		$this->assertSame( [ null ], $this->stored( $early_start ) );
		$this->assertSame( [ null ], $this->stored( $late_change ) );
		$this->assertNull( $this->item( $early_start )['pruned'], 'Read-time ranking agrees.' );
	}

	/**
	 * Entries older than the window lose their before-images but keep their rows.
	 *
	 * @return void
	 */
	public function test_old_entries_are_pruned_by_age(): void {
		$old   = $this->record( 'client-a', Entity::option( 'a' ) );
		$fresh = $this->record( 'client-b', Entity::option( 'b' ) );
		$this->age( $old, 3 * DAY_IN_SECONDS );

		( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();

		$this->assertNotNull( $this->journal->entry( $old ), 'A pruned entry keeps its row.' );
		$this->assertSame( [ 'age' ], $this->stored( $old ) );
		$this->assertSame( [ null ], $this->stored( $fresh ) );
	}

	/**
	 * Past the byte ceiling the oldest go first.
	 *
	 * @return void
	 */
	public function test_the_byte_ceiling_prunes_oldest_first(): void {
		$first  = $this->record( 'client-a', Entity::option( 'a' ) );
		$second = $this->record( 'client-b', Entity::option( 'b' ) );
		$third  = $this->record( 'client-c', Entity::option( 'c' ) );
		$bytes  = (int) $this->item( $third )['image_bytes'];

		( new ChangeJournalPrune( $this->journal, $this->policy( 2, $bytes * 2 ) ) )->run();

		$this->assertSame( 'size', $this->item( $first )['pruned'] );
		$this->assertNull( $this->item( $second )['pruned'] );
		$this->assertNull( $this->item( $third )['pruned'] );
	}

	/**
	 * Past the byte ceiling, the session active longest ago goes first, not
	 * the one that started first.
	 *
	 * @return void
	 */
	public function test_the_byte_ceiling_spares_a_long_running_session(): void {
		$long_running = $this->record( 'client-a', Entity::option( 'a' ) );
		$quiet        = $this->record( 'client-b', Entity::option( 'b' ) );
		$latest       = $this->record( 'client-a', Entity::option( 'c' ) );
		$bytes        = (int) $this->item( $latest )['image_bytes'];

		( new ChangeJournalPrune( $this->journal, $this->policy( 2, $bytes * 2 ) ) )->run();

		$this->assertSame( 'size', $this->item( $quiet )['pruned'] );
		$this->assertNull( $this->item( $long_running )['pruned'] );
		$this->assertNull( $this->item( $latest )['pruned'] );
	}

	/**
	 * A policy may lift the per-item cap (0) or tighten it.
	 *
	 * @return void
	 */
	public function test_a_policy_sets_the_per_item_cap(): void {
		$first  = $this->record( 'client-a', Entity::option( 'x' ) );
		$second = $this->record( 'client-b', Entity::option( 'x' ) );
		$third  = $this->record( 'client-c', Entity::option( 'x' ) );

		( new ChangeJournalPrune( $this->journal, $this->policy( 2, PHP_INT_MAX, 0 ) ) )->run();
		$this->assertSame( [ null ], $this->stored( $first ), 'No limit keeps every session.' );

		( new ChangeJournalPrune( $this->journal, $this->policy( 2, PHP_INT_MAX, 1 ) ) )->run();
		$this->assertSame( [ 'runs' ], $this->stored( $first ) );
		$this->assertSame( [ 'runs' ], $this->stored( $second ) );
		$this->assertSame( [ null ], $this->stored( $third ) );
	}

	/**
	 * A prune whose query fails is counted, not mistaken for one with nothing to do.
	 *
	 * @return void
	 */
	public function test_a_failing_prune_is_counted(): void {
		delete_option( FailureCounter::OPTION );
		$break = static fn( $query ) => is_string( $query ) && str_contains( $query, 'HAVING MAX(created_at)' ) ? 'SELECT broken FROM nowhere_at_all' : $query;

		add_filter( 'query', $break );
		$suppress = $GLOBALS['wpdb']->suppress_errors( true );
		try {
			( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();
		} finally {
			$GLOBALS['wpdb']->suppress_errors( $suppress );
			remove_filter( 'query', $break );
		}

		$this->assertSame( 1, FailureCounter::get()['count'] );
	}

	/**
	 * The nightly event is scheduled once and fully removed on deactivation.
	 *
	 * @return void
	 */
	public function test_schedule_and_unschedule(): void {
		ChangeJournalPrune::unschedule();
		ChangeJournalPrune::schedule();
		ChangeJournalPrune::schedule();

		$this->assertNotFalse( wp_next_scheduled( ChangeJournalPrune::HOOK ) );
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn( $hooks ) => isset( $hooks[ ChangeJournalPrune::HOOK ] ) ) );

		ChangeJournalPrune::unschedule();
		$this->assertFalse( wp_next_scheduled( ChangeJournalPrune::HOOK ) );
	}

	/**
	 * A request that recorded something prunes at its end, at most once an hour,
	 * so retention holds when WP-Cron never runs.
	 *
	 * @return void
	 */
	public function test_a_recording_request_prunes_at_most_hourly(): void {
		delete_transient( ChangeJournalPrune::SOON_LOCK );
		remove_all_actions( 'shutdown' );

		ChangeJournalPrune::soon();
		ChangeJournalPrune::soon();

		$this->assertSame( 1, count( $GLOBALS['wp_filter']['shutdown']->callbacks[ PHP_INT_MAX ] ?? [] ) );
		$this->assertNotFalse( get_transient( ChangeJournalPrune::SOON_LOCK ) );
	}

	/**
	 * Entries are deleted outright once long past the window.
	 *
	 * @return void
	 */
	public function test_entries_are_deleted_long_after_the_window(): void {
		$ancient = $this->record( 'client-a', Entity::option( 'a' ) );
		$this->age( $ancient, ( 2 + ChangeJournalPrune::DELETE_AFTER_DAYS + 1 ) * DAY_IN_SECONDS );

		( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();

		$this->assertNull( $this->journal->entry( $ancient ) );
		$this->assertSame( [], $this->journal->items( $ancient ) );
	}

	/**
	 * An entry stuck recording (the process died without shutdown) is closed.
	 *
	 * @return void
	 */
	public function test_an_abandoned_recording_is_closed(): void {
		$entry = $this->journal->create_entry( new WindowContext( WindowContext::SOURCE_MCP, 'acme/work', 1, 'client-a' ) );
		$this->age( $entry['id'], 2 * HOUR_IN_SECONDS );

		( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();

		$this->assertSame( 'interrupted', $this->journal->entry( $entry['id'] )['status'] );
	}

	/**
	 * Record one call changing the given entities.
	 *
	 * @param string $client_id   Connection; distinct clients make distinct sessions.
	 * @param Entity ...$entities The entities.
	 *
	 * @return int Entry id.
	 */
	private function record( string $client_id, Entity ...$entities ): int {
		$entry = $this->journal->create_entry( new WindowContext( WindowContext::SOURCE_MCP, 'acme/work', 1, $client_id ) );
		foreach ( $entities as $entity ) {
			$this->journal->insert_item( $entry['id'], $entry['run_id'], $entity, Repository::encode( [ 'value' => str_repeat( 'x', 100 ) . $client_id ] ), 'update', [ 'value' => 'hash' ], 'hook', null );
		}
		$this->journal->finish_entry( $entry['id'], 'recorded', null, [], [], count( $entities ), null );

		return $entry['id'];
	}

	/**
	 * Each item's `pruned` column as stored, without the read-time check, so a
	 * test proves the prune itself ran.
	 *
	 * @param int $change_id Entry id.
	 *
	 * @return array<int, string|null>
	 */
	private function stored( int $change_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assertion read.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT pruned, before_image IS NULL AS emptied FROM %i WHERE change_id = %d ORDER BY id', Tables::change_items(), $change_id ), ARRAY_A );

		foreach ( $rows as $row ) {
			$this->assertSame( $row['pruned'] !== null, (bool) $row['emptied'], 'A pruned item has lost its before-image, and only then.' );
		}

		return array_column( $rows, 'pruned' );
	}

	/**
	 * An entry's single item.
	 *
	 * @param int $change_id Entry id.
	 *
	 * @return array<string, mixed>
	 */
	private function item( int $change_id ): array {
		return $this->journal->items( $change_id )[0];
	}

	/**
	 * Move an entry back in time.
	 *
	 * @param int $change_id Entry id.
	 * @param int $seconds   How far.
	 *
	 * @return void
	 */
	private function age( int $change_id, int $seconds ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->update( Tables::changes(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ], [ 'id' => $change_id ] );
	}

	/**
	 * A policy with given limits.
	 *
	 * @param int $days  Days.
	 * @param int $bytes Byte ceiling.
	 * @param int $runs  Sessions kept per item; 0 for no limit.
	 *
	 * @return RetentionPolicy
	 */
	private function policy( int $days, int $bytes, int $runs = 0 ): RetentionPolicy {
		return new class( $days, $bytes, $runs ) implements RetentionPolicy {

			/**
			 * Build.
			 *
			 * @param int $days  Days.
			 * @param int $bytes Bytes.
			 * @param int $runs  Sessions per item.
			 */
			public function __construct( private int $days, private int $bytes, private int $runs ) {}

			/**
			 * {@inheritDoc}
			 */
			public function max_age_days(): int {
				return $this->days;
			}

			/**
			 * {@inheritDoc}
			 */
			public function runs_per_item(): int {
				return $this->runs;
			}

			/**
			 * {@inheritDoc}
			 */
			public function max_bytes(): int {
				return $this->bytes;
			}
		};
	}
}
