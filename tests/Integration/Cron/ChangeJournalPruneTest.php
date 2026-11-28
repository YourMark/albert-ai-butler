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
use Albert\Undo\Retention\BoundedRetentionPolicy;
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
		$this->assertSame( 14, $policy->max_age_days() );
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
		$this->age( $first, 15 * DAY_IN_SECONDS );

		( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();

		$this->assertSame( [ null ], $this->stored( $first ), 'Its session had a call within the window.' );

		$this->age( $last, 15 * DAY_IN_SECONDS );
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
		$this->age( $old, 15 * DAY_IN_SECONDS );

		$this->assertSame( 'age', $this->item( $old )['pruned'] );
		$this->assertNull( $this->item( $old )['before'] );
	}

	/**
	 * Retention is by time and size only: an item changed by many sessions
	 * keeps every one of them inside the window.
	 *
	 * @return void
	 */
	public function test_many_sessions_on_one_item_are_all_kept(): void {
		$sessions = [];
		foreach ( [ 'client-a', 'client-b', 'client-c', 'client-d' ] as $client ) {
			$sessions[] = $this->record( $client, Entity::post( 42 ) );
		}

		( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();

		foreach ( $sessions as $session ) {
			$this->assertSame( [ null ], $this->stored( $session ) );
			$this->assertNull( $this->item( $session )['pruned'] );
		}
	}

	/**
	 * A longer window is not retroactive: a session last active before the
	 * policy's start keeps Free's 14 days, a later one the longer window.
	 *
	 * @return void
	 */
	public function test_a_bounded_policy_only_extends_later_sessions(): void {
		$before = $this->record( 'client-a', Entity::option( 'a' ) );
		$after  = $this->record( 'client-b', Entity::option( 'b' ) );
		$this->age( $before, 14 * DAY_IN_SECONDS + 6 * HOUR_IN_SECONDS );
		$this->age( $after, 14 * DAY_IN_SECONDS + HOUR_IN_SECONDS );

		$policy = $this->bounded( 365, time() - 14 * DAY_IN_SECONDS - 3 * HOUR_IN_SECONDS );

		$this->assertSame( 'age', $this->with_policy( $policy, fn() => $this->item( $before )['pruned'] ), 'Enforced on read.' );
		$this->assertNull( $this->with_policy( $policy, fn() => $this->item( $after )['pruned'] ) );

		( new ChangeJournalPrune( $this->journal, $policy ) )->run();

		$this->assertSame( [ 'age' ], $this->stored( $before ) );
		$this->assertSame( [ null ], $this->stored( $after ) );
	}

	/**
	 * Entries older than the window lose their before-images but keep their rows.
	 *
	 * @return void
	 */
	public function test_old_entries_are_pruned_by_age(): void {
		$old   = $this->record( 'client-a', Entity::option( 'a' ) );
		$fresh = $this->record( 'client-b', Entity::option( 'b' ) );
		$this->age( $old, 15 * DAY_IN_SECONDS );

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

		( new ChangeJournalPrune( $this->journal, $this->policy( 14, $bytes * 2 ) ) )->run();

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

		( new ChangeJournalPrune( $this->journal, $this->policy( 14, $bytes * 2 ) ) )->run();

		$this->assertSame( 'size', $this->item( $quiet )['pruned'] );
		$this->assertNull( $this->item( $long_running )['pruned'] );
		$this->assertNull( $this->item( $latest )['pruned'] );
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
		$pruned  = $this->record( 'client-b', Entity::option( 'b' ) );
		$this->age( $ancient, ( 14 + ChangeJournalPrune::DELETE_AFTER_DAYS ) * DAY_IN_SECONDS + HOUR_IN_SECONDS );
		$this->age( $pruned, 14 * DAY_IN_SECONDS + HOUR_IN_SECONDS );

		( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();

		$this->assertNull( $this->journal->entry( $ancient ) );
		$this->assertSame( [], $this->journal->items( $ancient ) );
		$this->assertNotNull( $this->journal->entry( $pruned ), 'Inside the grace day the row stays, without its image.' );
		$this->assertSame( [ 'age' ], $this->stored( $pruned ) );
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
	 *
	 * @return RetentionPolicy
	 */
	private function policy( int $days, int $bytes ): RetentionPolicy {
		return new class( $days, $bytes ) implements RetentionPolicy {

			/**
			 * Build.
			 *
			 * @param int $days  Days.
			 * @param int $bytes Bytes.
			 */
			public function __construct( private int $days, private int $bytes ) {}

			/**
			 * {@inheritDoc}
			 */
			public function max_age_days(): int {
				return $this->days;
			}

			/**
			 * {@inheritDoc}
			 */
			public function max_bytes(): int {
				return $this->bytes;
			}
		};
	}

	/**
	 * A longer policy that starts at a given time.
	 *
	 * @param int $days  Days.
	 * @param int $since Unix timestamp it applies from.
	 *
	 * @return BoundedRetentionPolicy
	 */
	private function bounded( int $days, int $since ): BoundedRetentionPolicy {
		return new class( $days, $since ) implements BoundedRetentionPolicy {

			/**
			 * Build.
			 *
			 * @param int $days  Days.
			 * @param int $since Start.
			 */
			public function __construct( private int $days, private int $since ) {}

			/**
			 * {@inheritDoc}
			 */
			public function max_age_days(): int {
				return $this->days;
			}

			/**
			 * {@inheritDoc}
			 */
			public function max_bytes(): int {
				return PHP_INT_MAX;
			}

			/**
			 * {@inheritDoc}
			 */
			public function not_before(): int {
				return $this->since;
			}
		};
	}

	/**
	 * Run a read with a policy registered.
	 *
	 * @param RetentionPolicy $policy The policy.
	 * @param callable        $read   The read.
	 *
	 * @return mixed What the read returned.
	 */
	private function with_policy( RetentionPolicy $policy, callable $read ) {
		$hook = static fn( Registrar $registrar ) => $registrar->register( $policy );

		add_action( 'albert/undo/register_retention_policy', $hook );
		try {
			return $read();
		} finally {
			remove_action( 'albert/undo/register_retention_policy', $hook );
		}
	}
}
