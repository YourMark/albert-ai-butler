<?php
/**
 * Unit tests for the approve-race guard.
 *
 * The one failure that hurts a real site in normal use is a destructive action
 * running twice from two concurrent approvals (two tabs, a double-click, a proxy
 * retry). This pins that losing the atomic claim runs nothing at all.
 *
 * @package Albert\Tests\Unit\SafeMode
 */

namespace Albert\Tests\Unit\SafeMode;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Error.php';

use Albert\SafeMode\Approver;
use Albert\SafeMode\PendingAction;
use Albert\SafeMode\Repository;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * A repository whose claim() always loses, recording whether an outcome was written.
 */
class LosingClaimRepository extends Repository {

	/**
	 * Whether record_outcome() was called.
	 *
	 * @var bool
	 */
	public bool $recorded = false;

	/**
	 * Always lose the claim.
	 *
	 * @param int $id         Ignored.
	 * @param int $decided_by Ignored.
	 *
	 * @return bool
	 */
	public function claim( int $id, int $decided_by ): bool {
		return false;
	}

	/**
	 * Note that an outcome was recorded.
	 *
	 * @param int                  $id         Ignored.
	 * @param int                  $decided_by Ignored.
	 * @param bool                 $succeeded  Ignored.
	 * @param array<string, mixed> $result     Ignored.
	 *
	 * @return void
	 */
	public function record_outcome( int $id, int $decided_by, bool $succeeded, array $result ): void {
		$this->recorded = true;
	}
}

/**
 * Approver race tests.
 *
 * @covers \Albert\SafeMode\Approver
 */
class ApproverTest extends TestCase {

	/**
	 * A staged action to approve.
	 *
	 * @return PendingAction
	 */
	private function action(): PendingAction {
		return new PendingAction(
			1,
			'ref-1',
			'albert/delete-post',
			[ 'id' => 1 ],
			PendingAction::STATUS_PENDING,
			1,
			null,
			null,
			gmdate( 'Y-m-d H:i:s' ),
			gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			null,
			null,
			null
		);
	}

	/**
	 * Losing the claim runs nothing and reports it was already handled.
	 *
	 * @return void
	 */
	public function test_losing_the_claim_runs_nothing(): void {
		$repository = new LosingClaimRepository();
		$approver   = new Approver( $repository );

		$result = $approver->approve( $this->action(), 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_already_handled', $result->get_error_code() );
		$this->assertFalse( $repository->recorded, 'A lost race must not run the ability or record an outcome.' );
	}
}
