<?php
/**
 * Unit tests for the connection-scoped option guard.
 *
 * Pins that Albert's gate-controlling options can't be written by an assistant:
 * changes to an existing one are refused, a control switch can't be created in a
 * weakened state, and non-connection writes and lazy key creation are untouched.
 *
 * @package Albert\Tests\Unit\SafeMode
 */

namespace Albert\Tests\Unit\SafeMode;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\OAuth\Server\ConnectionContext;
use Albert\SafeMode\ConnectionGuard;
use Albert\SafeMode\Gate;
use PHPUnit\Framework\TestCase;

/**
 * ConnectionGuard tests.
 *
 * @covers \Albert\SafeMode\ConnectionGuard
 */
class ConnectionGuardTest extends TestCase {

	/**
	 * The guard under test.
	 *
	 * @var ConnectionGuard
	 */
	private ConnectionGuard $guard;

	/**
	 * Reset options/connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_options'] = [];
		ConnectionContext::reset();
		$this->guard = new ConnectionGuard();
	}

	/**
	 * Clear the connection between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ConnectionContext::reset();
		parent::tearDown();
	}

	/**
	 * Without a connection, the write passes through unchanged.
	 *
	 * @return void
	 */
	public function test_allows_writes_without_a_connection(): void {
		$GLOBALS['albert_test_options'][ Gate::OPTION ] = 'on';

		$this->assertSame( 'off', $this->guard->refuse_over_connection( 'off', Gate::OPTION ) );
	}

	/**
	 * Over a connection, a change to an existing option keeps the stored value.
	 *
	 * @return void
	 */
	public function test_refuses_changing_an_existing_option_over_a_connection(): void {
		$GLOBALS['albert_test_options'][ Gate::OPTION ] = 'on';
		ConnectionContext::set( 'client-1' );

		$this->assertSame( 'on', $this->guard->refuse_over_connection( 'off', Gate::OPTION ) );
	}

	/**
	 * Over a connection, a control switch with no stored row can't be created off.
	 *
	 * @return void
	 */
	public function test_refuses_creating_the_switch_in_a_weakened_state(): void {
		ConnectionContext::set( 'client-1' );

		$this->assertSame( Gate::DEFAULT_VALUE, $this->guard->refuse_over_connection( 'off', Gate::OPTION ) );
	}

	/**
	 * Over a connection, an unset non-control option is still creatable (lazy keys).
	 *
	 * @return void
	 */
	public function test_allows_first_creation_of_key_material(): void {
		ConnectionContext::set( 'client-1' );

		$this->assertSame( 'generated-key', $this->guard->refuse_over_connection( 'generated-key', 'albert_oauth_private_key' ) );
	}
}
