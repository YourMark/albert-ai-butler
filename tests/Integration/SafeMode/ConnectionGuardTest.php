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

		foreach ( [ self::OPTION, Gate::OPTION ] as $option ) {
			remove_all_filters( "sanitize_option_{$option}" );
			remove_all_filters( "pre_update_site_option_{$option}" );
			remove_all_filters( "pre_add_site_option_{$option}" );
			remove_all_actions( "pre_delete_site_option_{$option}" );
		}
		remove_all_actions( 'delete_option' );

		( new ConnectionGuard() )->register_hooks();

		$this->reports = [];
		foreach ( [ 'albert/safe_mode/option_write_blocked', 'albert/safe_mode/option_delete_detected' ] as $hook ) {
			add_action(
				$hook,
				function () use ( $hook ): void {
					$this->reports[ $hook ] = ( $this->reports[ $hook ] ?? 0 ) + 1;
				}
			);
		}

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
		$hook = $operation === 'delete' ? 'albert/safe_mode/option_delete_detected' : 'albert/safe_mode/option_write_blocked';

		return $this->reports[ $hook ] ?? 0;
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
	 * A delete through the site-option wrapper is detected once.
	 *
	 * @return void
	 */
	public function test_delete_site_option_is_detected_once(): void {
		ConnectionContext::reset();
		update_option( self::OPTION, 'balanced' );
		ConnectionContext::set( 'client-1' );

		delete_site_option( self::OPTION );

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
