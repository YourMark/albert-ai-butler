<?php
/**
 * Integration tests for putting back protected options changed outside the option API.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\SafeMode;

use Albert\OAuth\Server\ConnectionContext;
use Albert\SafeMode\ConnectionGuard;
use Albert\SafeMode\Gate;
use Albert\SafeMode\OptionRestorer;
use Albert\Tests\TestCase;

/**
 * OptionRestorer integration tests.
 *
 * Every write here is raw SQL, the way a plugin's own code might write, so none
 * of the option hooks fire.
 *
 * @covers \Albert\SafeMode\OptionRestorer
 */
class OptionRestorerTest extends TestCase {

	/**
	 * The restorer under test.
	 *
	 * @var OptionRestorer
	 */
	private OptionRestorer $restorer;

	/**
	 * Restores heard, as `option` => restored.
	 *
	 * @var array<string, bool>
	 */
	private array $restored = [];

	/**
	 * A fresh restorer and guard, the safe-mode switch on.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		ConnectionGuardTest::remove_plugin_instances( ConnectionGuard::class );
		ConnectionGuardTest::remove_plugin_instances( OptionRestorer::class );
		( new ConnectionGuard() )->register_hooks();
		$this->restorer = new OptionRestorer();
		$this->restorer->register_hooks();

		$this->restored = [];
		add_action(
			'albert/safe_mode/option_restored',
			function ( $option, $restored ): void {
				$this->restored[ $option ] = $restored;
			},
			10,
			2
		);

		update_option( Gate::OPTION, 'on' );
		update_option( 'albert_disabled_abilities', [ 'albert/delete-post' ] );
		ConnectionContext::set( 'client-1' );
	}

	/**
	 * Clear the connection.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		ConnectionContext::reset();
		parent::tear_down();
	}

	/**
	 * A direct UPDATE turning safe mode off is put back.
	 *
	 * @return void
	 */
	public function test_a_direct_update_is_put_back(): void {
		$this->sql( "UPDATE {options} SET option_value = 'off' WHERE option_name = 'albert_safe_mode'" );
		$this->assertSame( 'off', $this->value( Gate::OPTION ) );

		$this->restorer->restore();

		$this->assertSame( 'on', $this->value( Gate::OPTION ) );
		$this->assertSame( [ Gate::OPTION => true ], $this->restored );
		$this->assertTrue( ( new Gate() )->is_enabled() );
	}

	/**
	 * A direct update under a lookalike name is put back too.
	 *
	 * @return void
	 */
	public function test_a_direct_update_under_a_lookalike_is_put_back(): void {
		$this->sql( "UPDATE {options} SET option_name = 'ALBÉRT_SAFE_MODE', option_value = 'off' WHERE option_name = 'albért_safe_mode'" );

		$this->restorer->restore();

		$this->assertSame( 'on', $this->value( Gate::OPTION ) );
		$this->assertSame( Gate::OPTION, $this->name( Gate::OPTION ) );
	}

	/**
	 * A direct DELETE of a control option is put back.
	 *
	 * @return void
	 */
	public function test_a_direct_delete_is_put_back(): void {
		$this->sql( "DELETE FROM {options} WHERE option_name IN ('albert_disabled_abilities', 'albert_safe_mode')" );

		$this->restorer->restore();

		$this->assertSame( [ 'albert/delete-post' ], get_option( 'albert_disabled_abilities' ) );
		$this->assertSame( 'on', $this->value( Gate::OPTION ) );
	}

	/**
	 * A control option that did not exist and was inserted directly is removed.
	 *
	 * @return void
	 */
	public function test_a_direct_insert_is_removed(): void {
		delete_option( 'albert_privacy_mode' );

		$this->sql( "REPLACE INTO {options} (option_name, option_value, autoload) VALUES ('Albert_Privacy_Mode', 'off', 'on')" );
		$this->restorer->restore();

		$this->assertNull( $this->value( 'albert_privacy_mode' ) );
	}

	/**
	 * OAuth key material may still appear: it is minted lazily.
	 *
	 * @return void
	 */
	public function test_key_material_may_appear(): void {
		delete_option( 'albert_oauth_public_key' );

		$this->sql( "INSERT INTO {options} (option_name, option_value, autoload) VALUES ('albert_oauth_public_key', 'minted', 'off')" );
		$this->restorer->restore();

		$this->assertSame( 'minted', $this->value( 'albert_oauth_public_key' ) );
		$this->assertSame( [], $this->restored );
	}

	/**
	 * Restoring happens when an ability finishes, without being asked.
	 *
	 * @return void
	 */
	public function test_it_restores_when_an_ability_finishes(): void {
		$this->sql( "UPDATE {options} SET option_value = 'off' WHERE option_name = 'albert_safe_mode'" );

		do_action( 'wp_after_execute_ability', 'acme/run-sql', [], [], null );

		$this->assertSame( 'on', $this->value( Gate::OPTION ) );
	}

	/**
	 * Restoring happens when the MCP adapter has the tool call's result, and
	 * the result passes through untouched.
	 *
	 * @return void
	 */
	public function test_it_restores_when_a_tool_call_ends(): void {
		$this->sql( "UPDATE {options} SET option_value = 'off' WHERE option_name = 'albert_safe_mode'" );

		$this->assertNotFalse( has_filter( 'mcp_adapter_tool_call_result', [ $this->restorer, 'restore_after_tool_call' ] ) );
		$this->assertSame( [ 'ok' ], $this->restorer->restore_after_tool_call( [ 'ok' ] ) );

		$this->assertSame( 'on', $this->value( Gate::OPTION ) );
	}

	/**
	 * When the rows cannot be read back, that is reported as not restored
	 * rather than passed over as unchanged.
	 *
	 * @return void
	 */
	public function test_an_unreadable_table_is_reported(): void {
		$this->sql( "UPDATE {options} SET option_value = 'off' WHERE option_name = 'albert_safe_mode'" );

		$break      = static fn( $query ) => str_starts_with( (string) $query, "SELECT 'albert_" ) ? 'SELECT broken FROM nowhere' : $query;
		global $wpdb;
		$suppressed = $wpdb->suppress_errors();
		add_filter( 'query', $break, PHP_INT_MAX );
		$this->restorer->restore();
		$this->restorer->restore();
		remove_filter( 'query', $break, PHP_INT_MAX );
		$wpdb->suppress_errors( $suppressed );

		$this->assertFalse( $this->restored[ Gate::OPTION ] ?? true );
		$this->assertSame( count( ConnectionGuard::protected_names() ), count( $this->restored ) );

		$this->restorer->restore();
		$this->assertSame( 'on', $this->value( Gate::OPTION ) );
	}

	/**
	 * A plain CREATE TABLE, as creating a site on a network issues, is let
	 * through unread: the new table holds nothing to protect, and reading it
	 * first would fail and drop the statement.
	 *
	 * Passed to the restorer directly, because the test suite's own `query`
	 * filter rewrites every CREATE TABLE as TEMPORARY.
	 *
	 * @return void
	 */
	public function test_a_plain_create_table_is_let_through(): void {
		global $wpdb;

		$new_site = 'CREATE TABLE ' . $wpdb->base_prefix . '99_options (option_id bigint(20) unsigned NOT NULL)';
		$this->assertSame( $new_site, $this->restorer->note_unguarded_write( $new_site ) );

		$sql = "CREATE TABLE {$wpdb->options} (option_id bigint(20) unsigned NOT NULL)";
		$this->assertSame( $sql, $this->restorer->note_unguarded_write( $sql ) );

		// Nothing was read first: a change made now is not undone.
		ConnectionContext::reset();
		update_option( Gate::OPTION, 'off' );
		$this->restorer->restore();

		$this->assertSame( 'off', $this->value( Gate::OPTION ) );
		$this->assertSame( [], $this->restored );
	}

	/**
	 * A CREATE that can shadow the options table or write rows into it is
	 * read first, like any other write that skips the option API.
	 *
	 * @dataProvider watched_creates
	 *
	 * @param string $sql SQL with `{options}` for the table.
	 *
	 * @return void
	 */
	public function test_a_create_that_can_write_the_table_is_watched( string $sql ): void {
		global $wpdb;

		$sql = str_replace( '{options}', $wpdb->options, $sql );
		$this->assertSame( $sql, $this->restorer->note_unguarded_write( $sql ) );

		ConnectionContext::reset();
		update_option( Gate::OPTION, 'off' );
		$this->restorer->restore();

		$this->assertSame( 'on', $this->value( Gate::OPTION ) );
		$this->assertSame( [ Gate::OPTION => true ], $this->restored );
	}

	/**
	 * CREATE statements that can reach an existing options table.
	 *
	 * @return array<string, array{string}>
	 */
	public static function watched_creates(): array {
		return [
			'temporary shadow'      => [ 'CREATE TEMPORARY TABLE {options} AS SELECT * FROM {options}' ],
			'if not exists'         => [ 'CREATE TABLE IF NOT EXISTS {options} (option_id bigint(20) unsigned NOT NULL)' ],
			'if not exists replace' => [ "CREATE TABLE IF NOT EXISTS {options} REPLACE SELECT 1 AS option_id, 'albert_safe_mode' AS option_name, 'off' AS option_value, 'on' AS autoload" ],
			'select'                => [ 'CREATE TABLE {options}_copy SELECT * FROM {options}' ],
			'trigger'               => [ "CREATE TRIGGER albert_off AFTER INSERT ON {options} FOR EACH ROW UPDATE {options} SET option_value = 'off'" ],
		];
	}

	/**
	 * Without a connection, a direct write is somebody's own code and stays.
	 *
	 * @return void
	 */
	public function test_without_a_connection_nothing_is_put_back(): void {
		ConnectionContext::reset();

		$this->sql( "UPDATE {options} SET option_value = 'off' WHERE option_name = 'albert_safe_mode'" );
		$this->restorer->restore();

		$this->assertSame( 'off', $this->value( Gate::OPTION ) );
		$this->assertSame( [], $this->restored );
	}

	/**
	 * Writes through the option API are left to the guard and read nothing.
	 *
	 * @return void
	 */
	public function test_option_api_writes_are_not_tracked(): void {
		update_option( 'albert_test_ordinary', 'a' );
		set_transient( 'albert_test_transient', 'x' );

		ConnectionContext::reset();
		update_option( Gate::OPTION, 'off' );
		$this->restorer->restore();

		$this->assertSame( 'off', $this->value( Gate::OPTION ) );
		$this->assertSame( [], $this->restored );
	}

	/**
	 * Run raw SQL against the options table.
	 *
	 * @param string $sql SQL with `{options}` for the table.
	 *
	 * @return void
	 */
	private function sql( string $sql ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A plugin writing around the option API is the case under test.
		$wpdb->query( str_replace( '{options}', $wpdb->options, $sql ) );
	}

	/**
	 * The stored value of a protected option, read from the table.
	 *
	 * @param string $option The option.
	 *
	 * @return string|null
	 */
	private function value( string $option ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Asserting on the table, not a cache.
		return $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option ) );
	}

	/**
	 * The stored spelling of a protected option.
	 *
	 * @param string $option The option.
	 *
	 * @return string|null
	 */
	private function name( string $option ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Asserting on the table, not a cache.
		return $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name = %s', $wpdb->options, $option ) );
	}
}
