<?php
/**
 * Unit tests for the one-shot approval ticket.
 *
 * Pins the properties the gate's safety rests on: a ticket authorises exactly
 * one execution, bound to one ability and input, and expires so an orphan (a
 * ticket minted but never consumed, on a fatal in a persistent worker) cannot
 * authorise a later identical call.
 *
 * @package Albert\Tests\Unit\SafeMode
 */

namespace Albert\Tests\Unit\SafeMode;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\SafeMode\ApprovalTicket;
use PHPUnit\Framework\TestCase;

/**
 * ApprovalTicket tests.
 *
 * @covers \Albert\SafeMode\ApprovalTicket
 */
class ApprovalTicketTest extends TestCase {

	/**
	 * No ticket may survive between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ApprovalTicket::clear();
	}

	/**
	 * Discard any armed ticket after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ApprovalTicket::clear();
		parent::tearDown();
	}

	/**
	 * With no ticket armed, nothing is authorised.
	 *
	 * @return void
	 */
	public function test_nothing_authorised_without_a_ticket(): void {
		$this->assertFalse( ApprovalTicket::consume( 'test/delete', [ 'id' => 1 ] ) );
	}

	/**
	 * An armed ticket authorises its exact execution.
	 *
	 * @return void
	 */
	public function test_authorises_its_execution(): void {
		ApprovalTicket::arm( 'test/delete', [ 'id' => 1 ] );

		$this->assertTrue( ApprovalTicket::consume( 'test/delete', [ 'id' => 1 ] ) );
	}

	/**
	 * A ticket is single-use: the second identical consume is refused.
	 *
	 * @return void
	 */
	public function test_is_single_use(): void {
		ApprovalTicket::arm( 'test/delete', [ 'id' => 1 ] );

		$this->assertTrue( ApprovalTicket::consume( 'test/delete', [ 'id' => 1 ] ) );
		$this->assertFalse( ApprovalTicket::consume( 'test/delete', [ 'id' => 1 ] ) );
	}

	/**
	 * A ticket is bound to its ability and input.
	 *
	 * @return void
	 */
	public function test_is_bound_to_ability_and_input(): void {
		ApprovalTicket::arm( 'test/delete', [ 'id' => 1 ] );

		$this->assertFalse( ApprovalTicket::consume( 'test/other', [ 'id' => 1 ] ) );
		$this->assertFalse( ApprovalTicket::consume( 'test/delete', [ 'id' => 2 ] ) );
	}

	/**
	 * A ticket older than its TTL is refused, and an orphan cannot authorise a
	 * later identical call once the window has passed.
	 *
	 * @return void
	 */
	public function test_expires_after_its_ttl(): void {
		ApprovalTicket::arm( 'test/delete', [ 'id' => 1 ] );

		// A matching call 11s later, as a later organic call would arrive.
		$this->assertFalse( ApprovalTicket::consume( 'test/delete', [ 'id' => 1 ], time() + 11 ) );
	}

	/**
	 * A refused-because-expired ticket is discarded, so even a same-instant
	 * retry cannot match it.
	 *
	 * @return void
	 */
	public function test_expiry_discards_the_ticket(): void {
		ApprovalTicket::arm( 'test/delete', [ 'id' => 1 ] );

		ApprovalTicket::consume( 'test/delete', [ 'id' => 1 ], time() + 11 );

		$this->assertFalse( ApprovalTicket::consume( 'test/delete', [ 'id' => 1 ] ) );
	}
}
