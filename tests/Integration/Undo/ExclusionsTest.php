<?php
/**
 * Integration tests for the change journal's secret scan.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Undo;

use Albert\Database\Tables;
use Albert\Tests\TestCase;
use Albert\Undo\Entity;
use Albert\Undo\Exclusions;

/**
 * Exclusions integration tests.
 *
 * @covers \Albert\Undo\Exclusions
 */
class ExclusionsTest extends TestCase {

	/**
	 * The memory limit before a test lowered it.
	 *
	 * @var string
	 */
	private string $memory_limit = '';

	/**
	 * Remember the memory limit.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->memory_limit = (string) ini_get( 'memory_limit' );
	}

	/**
	 * Restore the memory limit.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		ini_set( 'memory_limit', $this->memory_limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restoring what the test changed.
		parent::tear_down();
	}

	/**
	 * A value too big to decode in the memory left is not decoded, and the
	 * state counts as unreadable rather than clean.
	 *
	 * @return void
	 */
	public function test_a_value_too_big_to_decode_is_unreadable(): void {
		$blob = wp_json_encode( array_fill( 0, 50000, [ 'a' => 1 ] ) );

		// Room for the test, not for decoding the blob (over 50 times its size).
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 4 * strlen( $blob ) ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The condition under test.

		$this->assertSame( Exclusions::UNREADABLE, Exclusions::holds_secret( Entity::option( 'builder_data' ), $this->option( 'builder_data', $blob ) ) );
	}

	/**
	 * With memory to spare, the same value is decoded and found clean.
	 *
	 * @return void
	 */
	public function test_a_value_that_fits_is_decoded(): void {
		$blob = wp_json_encode( array_fill( 0, 50000, [ 'a' => 1 ] ) );

		$this->assertNull( Exclusions::holds_secret( Entity::option( 'builder_data' ), $this->option( 'builder_data', $blob ) ) );
	}

	/**
	 * A corrupt serialized value is checked string by string: a credential in
	 * it is a secret, and one without is plain text, not a secret.
	 *
	 * @return void
	 */
	public function test_a_corrupt_serialized_value_is_read_string_by_string(): void {
		$entity = Entity::option( 'gateway' );

		$this->assertSame( Exclusions::SECRET, Exclusions::holds_secret( $entity, $this->option( 'gateway', 'a:1:{s:7:"api_key";s:12:"live-abc123";' ) ) );
		$this->assertNull( Exclusions::holds_secret( $entity, $this->option( 'gateway', 'a:1:{s:5:"title";s:5:"Hello";' ) ) );
		$this->assertNull( Exclusions::holds_secret( $entity, $this->option( 'gateway', 'b:0;' ) ) );
		$this->assertSame( Exclusions::SECRET, Exclusions::holds_secret( $entity, $this->option( 'gateway', 'a:1:{s:4:"auth";s:10:"Bearer xyz";' ) ) );
	}

	/**
	 * A corrupt value the scan cannot read through is unreadable, not clean.
	 *
	 * @return void
	 */
	public function test_a_corrupt_value_that_cannot_be_read_is_unreadable(): void {
		$limit = (string) ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '10' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- The condition under test.

		try {
			$verdict = Exclusions::holds_secret( Entity::option( 'gateway' ), $this->option( 'gateway', 'a:1:{s:5:"title";s:9:"' . str_repeat( 'x', 200 ) . '";' ) );
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restoring.
		}

		$this->assertSame( Exclusions::UNREADABLE, $verdict );
	}

	/**
	 * A nested value that is the serialized `true` is data, not a secret.
	 *
	 * @return void
	 */
	public function test_a_serialized_true_is_not_a_secret(): void {
		$this->assertNull( Exclusions::holds_secret( Entity::option( 'flags' ), $this->option( 'flags', serialize( [ 'enabled' => 'b:1;' ] ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A stored value.
	}

	/**
	 * Albert's tables are recognised on every blog of a network, not only this one.
	 *
	 * @return void
	 */
	public function test_albert_tables_are_recognised_on_any_blog(): void {
		global $wpdb;

		$this->assertTrue( Tables::is_albert_table( Tables::changes() ) );
		$this->assertTrue( Tables::is_albert_table( $wpdb->base_prefix . '7_albert_change_items', true ) );
		$this->assertTrue( Tables::is_albert_table( $wpdb->base_prefix . '7_albert_ability_log' ) );
		$this->assertFalse( Tables::is_albert_table( $wpdb->base_prefix . '7_albert_ability_log', true ) );
		$this->assertFalse( Tables::is_albert_table( $wpdb->base_prefix . 'albert_changes_copy' ) );
		$this->assertFalse( Tables::is_albert_table( 'other_albert_changes' ) );
	}

	/**
	 * An option's state as the snapshot reads it.
	 *
	 * @param string $name  Option name.
	 * @param string $value Stored value.
	 *
	 * @return array<string, string>
	 */
	private function option( string $name, string $value ): array {
		return [
			'option_name'  => $name,
			'option_value' => $value,
		];
	}
}
