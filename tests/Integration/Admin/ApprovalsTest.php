<?php
/**
 * Integration tests for the Approvals screen's result notice.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\Approvals;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\SafeMode\Approver;
use Albert\SafeMode\Repository;
use Albert\Tests\TestCase;

/**
 * Approvals screen tests.
 *
 * @covers \Albert\Admin\Approvals
 */
class ApprovalsTest extends TestCase {

	/**
	 * The pending-actions store.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Reset the queue before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
		$this->repository = new Repository();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::pending_actions() ) );
	}

	/**
	 * Clear the query args a test set.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['albert_notice'], $_GET['decided'] );

		parent::tear_down();
	}

	/**
	 * The notice names the rejected request, and its row is highlighted.
	 *
	 * @return void
	 */
	public function test_rejection_notice_names_the_request_and_highlights_it(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		// Whether the ability is registered depends on which tests ran first; when
		// it is not, the label falls back to the id and core flags the lookup.
		if ( ! wp_has_ability( 'albert/delete-post' ) ) {
			$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		}

		$action = $this->stage_and_reject( $admin, $admin );
		$html   = $this->render( 'rejected', $action->action_id );

		$this->assertMatchesRegularExpression( '/Rejected: [^<]+: Pricing\. It never ran\./', $html );
		$this->assertStringContainsString( 'albert-approvals__decided-item--just', $html );
	}

	/**
	 * A crafted `decided` id for somebody else's action names nothing.
	 *
	 * The lookup is limited to the viewer's own decided list, so the generic
	 * notice shows and the other person's request stays out of the page.
	 *
	 * @return void
	 */
	public function test_another_users_action_is_not_named(): void {
		$owner  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$viewer = self::factory()->user->create( [ 'role' => 'editor' ] );

		$action = $this->stage_and_reject( $owner, $owner );

		wp_set_current_user( $viewer );
		$html = $this->render( 'rejected', $action->action_id );

		$this->assertStringContainsString( 'Rejected. The request was discarded and never ran.', $html );
		$this->assertStringNotContainsString( 'Pricing', $html );
	}

	/**
	 * Stage a delete with a staged target label, then reject it.
	 *
	 * @param int $requester Who the call was staged for.
	 * @param int $decider   Who rejected it.
	 *
	 * @return \Albert\SafeMode\PendingAction
	 */
	private function stage_and_reject( int $requester, int $decider ): \Albert\SafeMode\PendingAction {
		$action = $this->repository->stage(
			'albert/delete-post',
			[ 'id' => 42 ],
			$requester,
			'client-a',
			'Claude',
			HOUR_IN_SECONDS,
			[
				'label'  => 'Pricing',
				'status' => 'publish',
			]
		);

		$this->repository->reject( $action->id, $decider );

		return $action;
	}

	/**
	 * Render the screen as the redirect after a decision would.
	 *
	 * @param string $notice    The outcome.
	 * @param string $action_id The action just decided.
	 *
	 * @return string
	 */
	private function render( string $notice, string $action_id ): string {
		$_GET['albert_notice'] = $notice;
		$_GET['decided']       = $action_id;

		ob_start();
		( new Approvals( $this->repository, new Approver( $this->repository ) ) )->render_page();

		return (string) ob_get_clean();
	}
}
