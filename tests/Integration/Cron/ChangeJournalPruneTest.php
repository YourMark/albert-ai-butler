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

		$this->assertNull( $this->item( $first )['pruned'], 'Its session had a call within the window.' );
		$this->assertIsArray( $this->item( $first )['before'] );

		$this->age( $last, 3 * DAY_IN_SECONDS );
		( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();

		$this->assertSame( 'age', $this->item( $first )['pruned'] );
		$this->assertSame( 'age', $this->item( $last )['pruned'] );
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

		$this->assertSame( 'runs', $this->item( $oldest )['pruned'], 'Enforced before the nightly prune runs.' );

		( new ChangeJournalPrune( $this->journal, new FreeRetentionPolicy() ) )->run();

		$this->assertSame( [ 'runs', 'runs' ], array_column( $this->journal->items( $oldest ), 'pruned' ) );
		$this->assertNull( $this->item( $middle )['pruned'] );
		$this->assertNull( $this->item( $newest )['pruned'], 'Two calls in one session count once.' );
		$this->assertNull( $this->item( $more )['pruned'] );
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

		$this->assertSame( 'runs', $this->item( $between )['pruned'] );
		$this->assertNull( $this->item( $early_start )['pruned'] );
		$this->assertNull( $this->item( $late_change )['pruned'] );
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
		$this->assertSame( 'age', $this->item( $old )['pruned'] );
		$this->assertNull( $this->item( $fresh )['pruned'] );
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
			public function runs_per_item(): int {
				return 0;
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
