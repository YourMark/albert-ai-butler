<?php
/**
 * Unit tests for AbilitiesManager::wrap_permission_callback — the wrapper that
 * runs each ability's own permission_callback and then passes the result
 * through the `albert/abilities/check_permission` filter.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Core\AbilitiesManager;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Permission-callback wrapping tests.
 *
 * @covers \Albert\Core\AbilitiesManager::wrap_permission_callback
 */
class PermissionWrapperTest extends TestCase {

	/**
	 * Reset recorded hooks, the acting user, and any injected filter return.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks']   = [];
		$GLOBALS['albert_test_user_id'] = 42;
		unset( $GLOBALS['albert_test_filter_returns'] );
	}

	/**
	 * Wrap an args array and return the resulting permission_callback.
	 *
	 * @param callable|null $original Original permission_callback.
	 * @return callable The wrapped callback.
	 */
	private function wrap( ?callable $original ): callable {
		$args    = [ 'permission_callback' => $original ];
		$wrapped = ( new AbilitiesManager() )->wrap_permission_callback( $args, 'test/ability' );

		return $wrapped['permission_callback'];
	}

	/**
	 * The most recent recorded apply_filters call for the check_permission hook.
	 *
	 * @return array<string, mixed>
	 */
	private function last_check_permission_call(): array {
		$calls = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( $hook ) => ( $hook['hook'] ?? '' ) === 'albert/abilities/check_permission'
		);
		$this->assertNotEmpty( $calls, 'The check_permission filter was not applied.' );

		return end( $calls );
	}

	/**
	 * A base allow (true) with no filter override passes through as true.
	 *
	 * @return void
	 */
	public function test_base_allow_passes_through(): void {
		$callback = $this->wrap( static fn() => true );

		$this->assertTrue( $callback() );
	}

	/**
	 * A base deny (WP_Error) is preserved when no filter loosens it — the wrapper
	 * is fail-closed by default.
	 *
	 * @return void
	 */
	public function test_base_deny_is_preserved(): void {
		$error    = new WP_Error( 'forbidden', 'No.' );
		$callback = $this->wrap( static fn() => $error );

		$this->assertSame( $error, $callback() );
	}

	/**
	 * A null original callback defaults to allow (true) before the filter runs.
	 *
	 * @return void
	 */
	public function test_null_original_defaults_to_true_before_filter(): void {
		$callback = $this->wrap( null );

		$this->assertTrue( $callback() );
		$this->assertTrue( $this->last_check_permission_call()['args'][0] );
	}

	/**
	 * The filter receives the ability id and the current user id, and can DENY by
	 * returning a WP_Error (the path Premium's Enforcer uses).
	 *
	 * @return void
	 */
	public function test_filter_receives_context_and_can_deny(): void {
		$denial = new WP_Error( 'permission_denied', 'Denied by rule.' );

		$GLOBALS['albert_test_filter_returns']['albert/abilities/check_permission'] = $denial;

		$callback = $this->wrap( static fn() => true );

		$this->assertSame( $denial, $callback() );

		$call = $this->last_check_permission_call();
		$this->assertSame( 'test/ability', $call['args'][1] );
		$this->assertSame( 42, $call['args'][2] );
	}
}
