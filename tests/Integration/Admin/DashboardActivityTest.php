<?php
/**
 * Integration tests for the Dashboard's recent-activity list.
 *
 * The fade at the foot of that table is a claim: there is more than this. It
 * used to be drawn unconditionally, so it sat over the last real row of a
 * complete list and implied entries that did not exist. These tests hold it to
 * being a fact rather than a decoration.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\Dashboard;
use Albert\Database\Tables;
use Albert\Logging\Repository as LoggingRepository;
use Albert\Tests\TestCase;

/**
 * Recent activity tests.
 *
 * @covers \Albert\Admin\Dashboard
 */
class DashboardActivityTest extends TestCase {

	/**
	 * Start from an empty log, so the counts under test are the ones this test
	 * wrote. Deleted rather than truncated: the suite wraps each test in a
	 * transaction, so a DELETE rolls back with everything else.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . Tables::ability_log() ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Record some successful runs.
	 *
	 * @param int $count How many.
	 *
	 * @return void
	 */
	private function record( int $count ): void {
		$repository = new LoggingRepository();

		for ( $i = 0; $i < $count; $i++ ) {
			// A distinct ability each time. The log keeps only a couple of rows
			// per ability and prunes on write, so repeating one would measure
			// retention rather than truncation.
			$repository->insert( 'test/ability-' . $i, 1, 'success' );
		}
	}

	/**
	 * Whether the Dashboard considers its activity list truncated.
	 *
	 * @return bool
	 */
	private function is_truncated(): bool {
		$dashboard = new Dashboard( new LoggingRepository() );

		$activity = new \ReflectionMethod( $dashboard, 'get_recent_activity' );
		$activity->invoke( $dashboard );

		$flag = new \ReflectionProperty( $dashboard, 'activity_truncated' );

		return (bool) $flag->getValue( $dashboard );
	}

	/**
	 * A list that fits is not claimed to be truncated.
	 *
	 * @return void
	 */
	public function test_a_short_list_is_not_truncated(): void {
		$this->record( 3 );

		$this->assertFalse( $this->is_truncated(), 'Three events fit in five rows, so nothing is hidden.' );
	}

	/**
	 * Exactly filling the list is still not truncation.
	 *
	 * The off-by-one worth pinning: five events in five rows means everything is
	 * visible, and a fade there would be decoration.
	 *
	 * @return void
	 */
	public function test_a_full_list_is_not_truncated(): void {
		$this->record( 5 );

		$this->assertFalse( $this->is_truncated() );
	}

	/**
	 * More events than rows is the one case where the fade may appear.
	 *
	 * @return void
	 */
	public function test_more_events_than_rows_is_truncated(): void {
		$this->record( 8 );

		$this->assertTrue( $this->is_truncated() );
	}
}
