<?php
/**
 * Integration tests for the connection-scoped option guard.
 *
 * Goes through WordPress's own option functions rather than calling the guard's
 * methods, because a hook bound under a name core never fires passes every
 * direct-call test. That is how an earlier multisite path shipped inert.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\SafeMode;

use Albert\OAuth\Server\ConnectionContext;
use Albert\SafeMode\ConnectionGuard;
use Albert\SafeMode\Gate;
use Albert\SafeMode\OptionRestorer;
use Albert\Tests\Unit\SafeMode\CaseInsensitiveOptionNames;
use Albert\Tests\TestCase;

/**
 * ConnectionGuard integration tests.
 *
 * @covers \Albert\SafeMode\ConnectionGuard
 */
class ConnectionGuardTest extends TestCase {

	/**
	 * A protected option with a secure default.
	 *
	 * @var string
	 */
	private const OPTION = 'albert_privacy_mode';

	/**
	 * Reports heard, keyed by action name.
	 *
	 * @var array<string, int>
	 */
	private array $reports = [];

	/**
	 * Swap the plugin's guard for a fresh one bound through register_hooks().
	 *
	 * The plugin's instance remembers its last report across tests, so a fresh
	 * one keeps each test independent. The hooks are restored after every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		self::remove_plugin_instances( ConnectionGuard::class );
		self::remove_plugin_instances( OptionRestorer::class );

		( new ConnectionGuard() )->register_hooks();

		$this->reports = [];
		add_action(
			'albert/safe_mode/option_write_blocked',
			function ( $option, $operation ): void {
				$this->reports[ $operation ] = ( $this->reports[ $operation ] ?? 0 ) + 1;
			},
			10,
			2
		);

		delete_option( self::OPTION );
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
	 * How many times a guard action fired.
	 *
	 * @param string $operation `write` or `delete`.
	 *
	 * @return int
	 */
	private function reported( string $operation ): int {
		return $this->reports[ $operation ] ?? 0;
	}

	/**
	 * Unhook the plugin's own instance of a class, so each test binds a fresh one.
	 *
	 * Only that class's callbacks go: the test suite's own `query` filters stay.
	 *
	 * @param string $class_name The class.
	 *
	 * @return void
	 */
	public static function remove_plugin_instances( string $class_name ): void {
		global $wp_filter;

		foreach ( $wp_filter as $hook => $handlers ) {
			foreach ( $handlers->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof $class_name ) {
						remove_filter( $hook, $callback['function'], $priority );
					}
				}
			}
		}
	}

	/**
	 * The protected row as stored, whatever its spelling, read straight from the table.
	 *
	 * @param string $option The protected option.
	 *
	 * @return array{option_name: string, option_value: string}|null
	 */
	private function stored( string $option ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Asserting on the table, not a cache.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name = %s', $wpdb->options, $option ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Store a protected option as a person would, with no connection.
	 *
	 * @param string $option The option.
	 * @param mixed  $value  Its value.
	 *
	 * @return void
	 */
	private function store( string $option, $value ): void {
		$client = ConnectionContext::client_id();
		ConnectionContext::reset();
		update_option( $option, $value );
		ConnectionContext::set( $client );
	}

	/**
	 * Changing an existing protected option keeps the stored value.
	 *
	 * @return void
	 */
	public function test_update_option_keeps_the_stored_value(): void {
		ConnectionContext::reset();
		update_option( Gate::OPTION, 'on' );
		ConnectionContext::set( 'client-1' );

		update_option( Gate::OPTION, 'off' );

		$this->assertSame( 'on', get_option( Gate::OPTION ) );
		$this->assertSame( 1, $this->reported( 'write' ) );
	}

	/**
	 * Creating a protected option stores its secure default, not the request.
	 *
	 * @return void
	 */
	public function test_add_option_stores_the_secure_default(): void {
		add_option( self::OPTION, 'off' );

		$this->assertSame( 'strict', get_option( self::OPTION ) );
		$this->assertSame( 1, $this->reported( 'write' ) );
	}

	/**
	 * Spellings the database treats as `albert_safe_mode`.
	 *
	 * @return array<string, array{string}>
	 */
	public static function lookalikes(): array {
		return [
			'mixed case'        => [ 'Albert_Safe_Mode' ],
			'upper case'        => [ 'ALBERT_SAFE_MODE' ],
			'trailing space'    => [ 'albert_safe_mode ' ],
			'accent'            => [ 'albért_safe_mode' ],
			'accents'           => [ 'àlbërt_sâfé_mödè' ],
			'full width'        => [ 'ａｌｂｅｒｔ＿ｓａｆｅ＿ｍｏｄｅ' ],
			'zero-width space'  => [ "albert\u{200B}_safe_mode" ],
			'control character' => [ "albert\x01_safe_mode" ],
			'no-break space'    => [ "albert_safe_mode\u{00A0}" ],
		];
	}

	/**
	 * An update under another spelling keeps the stored value.
	 *
	 * @dataProvider lookalikes
	 *
	 * @param string $name The spelling.
	 *
	 * @return void
	 */
	public function test_an_update_under_another_spelling_is_refused( string $name ): void {
		$this->store( Gate::OPTION, 'on' );

		update_option( $name, 'off' );

		$this->assertSame(
			[
				'option_name'  => Gate::OPTION,
				'option_value' => 'on',
			],
			$this->stored( Gate::OPTION )
		);
		$this->assertSame( 1, $this->reported( 'write' ) );
	}

	/**
	 * An add under another spelling stores nothing, with or without WordPress
	 * having cached that name as missing. One WordPress trims to the exact name
	 * stores the secure default, like the exact name does.
	 *
	 * @dataProvider lookalikes
	 *
	 * @param string $name The spelling.
	 *
	 * @return void
	 */
	public function test_an_add_under_another_spelling_stores_nothing( string $name ): void {
		delete_option( Gate::OPTION );

		$secure = [ null, Gate::DEFAULT_VALUE ];

		add_option( $name, 'off' );
		$this->assertContains( $this->stored( Gate::OPTION )['option_value'] ?? null, $secure );

		// A cached miss makes add_option() skip its own existence check.
		get_option( $name );
		add_option( $name, 'off' );
		update_option( $name, 'off' );

		$this->assertContains( $this->stored( Gate::OPTION )['option_value'] ?? null, $secure );
		wp_cache_flush();
		$this->assertTrue( ( new Gate() )->is_enabled() );
		$this->assertGreaterThanOrEqual( 1, $this->reported( 'write' ) );
	}

	/**
	 * An add under another spelling cannot overwrite the stored row either.
	 *
	 * `add_option()` inserts with ON DUPLICATE KEY UPDATE, which would rename
	 * the real row and replace its value.
	 *
	 * @dataProvider lookalikes
	 *
	 * @param string $name The spelling.
	 *
	 * @return void
	 */
	public function test_an_add_under_another_spelling_leaves_the_stored_row( string $name ): void {
		$this->store( Gate::OPTION, 'on' );
		wp_cache_set( 'notoptions', [ trim( $name ) => true ], 'options' );

		add_option( $name, 'off' );

		$this->assertSame(
			[
				'option_name'  => Gate::OPTION,
				'option_value' => 'on',
			],
			$this->stored( Gate::OPTION )
		);
	}

	/**
	 * A delete under any spelling leaves the row where it is.
	 *
	 * @dataProvider lookalikes
	 *
	 * @param string $name The spelling.
	 *
	 * @return void
	 */
	public function test_a_delete_under_another_spelling_is_refused( string $name ): void {
		$this->store( Gate::OPTION, 'on' );

		$this->assertFalse( delete_option( $name ) );

		$this->assertSame( 'on', $this->stored( Gate::OPTION )['option_value'] ?? null );
		$this->assertSame( 1, $this->reported( 'delete' ) );
	}

	/**
	 * Deleting a control option outright is refused: without the row,
	 * `albert_disabled_abilities` would switch every ability back on.
	 *
	 * @return void
	 */
	public function test_deleting_a_control_option_is_refused(): void {
		$this->store( 'albert_disabled_abilities', [ 'albert/delete-post' ] );
		$this->store( self::OPTION, 'strict' );

		$this->assertFalse( delete_option( 'albert_disabled_abilities' ) );
		$this->assertFalse( delete_option( self::OPTION ) );

		wp_cache_flush();
		$this->assertSame( [ 'albert/delete-post' ], get_option( 'albert_disabled_abilities' ) );
		$this->assertSame( 'strict', get_option( self::OPTION ) );
		$this->assertSame( 2, $this->reported( 'delete' ) );
	}

	/**
	 * `ß` reaches `ss`, so an option an add-on protects is matched that way too.
	 *
	 * @return void
	 */
	public function test_sharp_s_reaches_double_s(): void {
		$protect = static fn( array $options ): array => array_merge( $options, [ 'albert_pass_test' ] );
		add_filter( 'albert/safe_mode/protected_options', $protect );
		$this->store( 'albert_pass_test', 'kept' );

		update_option( 'albert_paß_test', 'changed' );
		delete_option( 'albert_paß_test' );

		$this->assertSame( 'kept', $this->stored( 'albert_pass_test' )['option_value'] ?? null );
		remove_filter( 'albert/safe_mode/protected_options', $protect );
	}

	/**
	 * When the database cannot compare names, any write that might reach a
	 * protected option is refused rather than let through.
	 *
	 * @return void
	 */
	public function test_writes_are_refused_when_names_cannot_be_compared(): void {
		self::remove_plugin_instances( ConnectionGuard::class );
		$names       = new CaseInsensitiveOptionNames();
		$names->fail = true;
		( new ConnectionGuard( $names ) )->register_hooks();

		$this->store( 'albert_safe_mode', 'on' );

		add_option( 'Albert_Privacy_Mode', 'off' );
		update_option( 'Albert_Safe_Mode', 'off' );
		$this->assertFalse( delete_option( 'Albert_Safe_Mode' ) );

		$this->assertNull( $this->stored( self::OPTION ) );
		$this->assertSame( 'on', $this->stored( Gate::OPTION )['option_value'] ?? null );
	}

	/**
	 * The OAuth key material is minted with `update_option()` and may be, once.
	 *
	 * @return void
	 */
	public function test_key_material_may_be_minted_but_not_changed(): void {
		delete_option( 'albert_oauth_encryption_key' );

		update_option( 'albert_oauth_encryption_key', 'minted' );
		update_option( 'albert_oauth_encryption_key', 'replaced' );
		update_option( 'Albert_OAuth_Encryption_Key', 'replaced' );

		$this->assertSame(
			[
				'option_name'  => 'albert_oauth_encryption_key',
				'option_value' => 'minted',
			],
			$this->stored( 'albert_oauth_encryption_key' )
		);
	}

	/**
	 * An add-on's protected option cannot be created over a connection, even
	 * when it was added to the list after the guard bound its hooks.
	 *
	 * @return void
	 */
	public function test_an_add_on_option_cannot_be_created(): void {
		$protect = static fn( array $options ): array => array_merge( $options, [ 'acme_safety_switch' ] );
		add_filter( 'albert/safe_mode/protected_options', $protect );

		add_option( 'acme_safety_switch', 'off' );
		update_option( 'acme_safety_switch', 'off' );

		$this->assertNull( $this->stored( 'acme_safety_switch' ) );
		remove_filter( 'albert/safe_mode/protected_options', $protect );
	}

	/**
	 * Ordinary options and transients are untouched over a connection.
	 *
	 * @return void
	 */
	public function test_ordinary_options_still_work_over_a_connection(): void {
		$this->assertTrue( add_option( 'albert_test_ordinary', 'a' ) );
		$this->assertTrue( update_option( 'albert_test_ordinary', 'b' ) );
		$this->assertSame( 'b', get_option( 'albert_test_ordinary' ) );
		$this->assertTrue( delete_option( 'albert_test_ordinary' ) );

		$this->assertTrue( set_transient( 'albert_test_transient', 'x' ) );
		$this->assertTrue( delete_transient( 'albert_test_transient' ) );
		$this->assertSame( 0, $this->reported( 'write' ) + $this->reported( 'delete' ) );
	}

	/**
	 * The site-option wrappers are refused too, and reported once per attempt.
	 *
	 * On a single site `add_site_option()` passes the network filter and then
	 * `add_option()`'s sanitize filter; both refuse, one report.
	 *
	 * @return void
	 */
	public function test_add_site_option_is_refused_and_reported_once(): void {
		add_site_option( self::OPTION, 'off' );

		$this->assertSame( 'strict', get_site_option( self::OPTION ) );
		$this->assertSame( 1, $this->reported( 'write' ) );
	}

	/**
	 * Changing an existing option through the site-option wrapper is refused.
	 *
	 * @return void
	 */
	public function test_update_site_option_keeps_the_stored_value(): void {
		ConnectionContext::reset();
		update_site_option( self::OPTION, 'balanced' );
		ConnectionContext::set( 'client-1' );

		update_site_option( self::OPTION, 'off' );

		$this->assertSame( 'balanced', get_site_option( self::OPTION ) );
		$this->assertSame( 1, $this->reported( 'write' ) );
	}

	/**
	 * A delete through the site-option wrapper is refused, and reported once.
	 *
	 * @return void
	 */
	public function test_delete_site_option_is_refused_once(): void {
		$this->store( self::OPTION, 'balanced' );

		$this->assertFalse( delete_site_option( self::OPTION ) );

		$this->assertSame( 'balanced', $this->stored( self::OPTION )['option_value'] ?? null );
		$this->assertSame( 1, $this->reported( 'delete' ) );
	}

	/**
	 * The multisite paths are bound under the names core actually fires.
	 *
	 * This suite runs single-site, where those paths fall through to the blog
	 * hooks and would pass with a misspelt name. The names are core's, from
	 * `add_network_option()`, `update_network_option()` and `delete_network_option()`.
	 *
	 * @return void
	 */
	public function test_multisite_hooks_use_core_names(): void {
		$this->assertNotFalse( has_filter( 'pre_update_site_option_' . self::OPTION ) );
		$this->assertNotFalse( has_filter( 'pre_add_site_option_' . self::OPTION ) );
		$this->assertNotFalse( has_action( 'pre_delete_site_option_' . self::OPTION ) );
	}

	/**
	 * Without a connection nothing is refused or reported.
	 *
	 * @return void
	 */
	public function test_writes_without_a_connection_pass_through(): void {
		ConnectionContext::reset();

		add_option( self::OPTION, 'off' );
		update_option( Gate::OPTION, 'off' );

		$this->assertSame( 'off', get_option( self::OPTION ) );
		$this->assertSame( 'off', get_option( Gate::OPTION ) );
		$this->assertSame( 0, $this->reported( 'write' ) );
	}
}
