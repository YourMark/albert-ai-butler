<?php
/**
 * Integration tests for running an approved action.
 *
 * The security-critical path: an approval runs the ability as the user the call
 * was going to run as, with the input captured at stage time, under a ticket
 * that exists for exactly that run and is gone afterwards.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\SafeMode;

use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\SafeMode\ApprovalTicket;
use Albert\SafeMode\Approver;
use Albert\SafeMode\PendingAction;
use Albert\SafeMode\Repository;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Approver integration tests.
 *
 * @covers \Albert\SafeMode\Approver
 */
class ApproverTest extends TestCase {

	/**
	 * Pending-actions store.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * What the registered test ability saw when it ran.
	 *
	 * @var array<string, mixed>
	 */
	private array $observed = [];

	/**
	 * Ability ids to unregister afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $registered_ids = [];

	/**
	 * Category the test abilities are registered under.
	 *
	 * Core requires one, and its own categories are not registered in the test
	 * environment, so this registers its own rather than borrowing a real one.
	 *
	 * @var string
	 */
	private const TEST_CATEGORY = 'albert-test';

	/**
	 * Reset the table and register a test ability that records its context.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		Installer::install();
		$this->repository     = new Repository();
		$this->observed       = [];
		$this->registered_ids = [];

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Tables::pending_actions() ) );

		ApprovalTicket::clear();
		$this->ensure_test_category_registered();
	}

	/**
	 * Register the test-only ability category, once.
	 *
	 * @return void
	 */
	private function ensure_test_category_registered(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || ! function_exists( 'wp_has_ability_category' ) ) {
			return;
		}

		if ( wp_has_ability_category( self::TEST_CATEGORY ) ) {
			return;
		}

		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP core's own abilities tests use this exact pattern to satisfy doing_action() in isolation.
		$wp_current_filter[] = 'wp_abilities_api_categories_init';

		try {
			wp_register_ability_category(
				self::TEST_CATEGORY,
				[
					'label'       => 'Albert Test',
					'description' => 'Test-only category for safe-mode approval tests.',
				]
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Drop any armed ticket so one test cannot leak into the next.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		ApprovalTicket::clear();

		foreach ( $this->registered_ids as $id ) {
			if ( function_exists( 'wp_unregister_ability' ) ) {
				wp_unregister_ability( $id );
			}
		}

		parent::tear_down();
	}

	/**
	 * Register an ability whose callback records what it saw.
	 *
	 * `wp_register_ability()` requires `doing_action( 'wp_abilities_api_init' )`,
	 * and that action fires once at boot. Push it onto `$wp_current_filter`,
	 * register, pop: the same pattern WP core's own abilities tests use, and
	 * {@see \Albert\Tests\Integration\EnforceDisabledTest}.
	 *
	 * @param string $name  Ability id.
	 * @param bool   $should_throw Whether the callback should throw.
	 *
	 * @return void
	 */
	private function register_recording_ability( string $name, bool $should_throw = false ): void {
		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP core's own abilities tests use this exact pattern to satisfy doing_action() in isolation.
		$wp_current_filter[] = 'wp_abilities_api_init';

		try {
			wp_register_ability(
				$name,
				[
					'label'               => 'Test',
					'description'         => 'Records the context it ran in.',
					'category'            => self::TEST_CATEGORY,
					'input_schema'        => [
						'type'       => 'object',
						'properties' => [ 'id' => [ 'type' => 'integer' ] ],
					],
					'output_schema'       => [ 'type' => 'object' ],
					'execute_callback'    => function ( $input ) use ( $should_throw ) {
						$this->observed = [
							'user_id'      => get_current_user_id(),
							'input'        => $input,
							'ticket_armed' => ApprovalTicket::is_armed(),
						];

						if ( $should_throw ) {
							throw new \RuntimeException( 'boom' );
						}

						return [ 'ok' => true ];
					},
					'permission_callback' => '__return_true',
				]
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->registered_ids[] = $name;
	}

	/**
	 * Approving runs the ability as the staged user, with the staged input,
	 * under a ticket that is armed during the run and cleared after it.
	 *
	 * @return void
	 */
	public function test_approval_runs_as_the_staged_user_with_the_staged_input(): void {
		$this->register_recording_ability( 'albert-test/record' );

		$caller   = self::factory()->user->create( [ 'role' => 'editor' ] );
		$approver = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $approver );

		// Priority 1, ahead of the Interceptor's 10, because the gate is where
		// the ticket has to be live: the Interceptor consumes it there, so by the
		// time the ability's own callback runs it is already spent.
		$armed_at_gate = null;
		$watch         = static function ( $pre ) use ( &$armed_at_gate ) {
			$armed_at_gate = ApprovalTicket::is_armed();

			return $pre;
		};
		add_filter( 'wp_pre_execute_ability', $watch, 1 );

		$action = $this->repository->stage( 'albert-test/record', [ 'id' => 99 ], $caller, 'client-a', 'Claude', DAY_IN_SECONDS );

		try {
			$result = ( new Approver( $this->repository ) )->approve( $action, $approver );
		} finally {
			remove_filter( 'wp_pre_execute_ability', $watch, 1 );
		}

		$this->assertIsArray( $result );
		$this->assertSame( $caller, $this->observed['user_id'], 'The call must run as the user it was staged for, not the approver.' );
		$this->assertSame( [ 'id' => 99 ], $this->observed['input'] );

		if ( $armed_at_gate !== null ) {
			$this->assertTrue( $armed_at_gate, 'The ticket must be live when the gate checks it.' );
			$this->assertFalse( $this->observed['ticket_armed'], 'The gate consumes the ticket, so it is spent by the time the ability runs.' );
		}

		$this->assertFalse( ApprovalTicket::is_armed(), 'The ticket must not outlive the run.' );
		$this->assertSame( $approver, get_current_user_id(), 'The approving user must be restored afterwards.' );
	}

	/**
	 * A throwing ability still clears the ticket.
	 *
	 * The `finally` is the whole guard: a ticket that survived a fatal would let
	 * a later call with the same ability and input through the gate unattended,
	 * for as long as its TTL allows.
	 *
	 * @return void
	 */
	public function test_a_throwing_ability_still_clears_the_ticket(): void {
		$this->register_recording_ability( 'albert-test/throws', true );

		$caller   = self::factory()->user->create( [ 'role' => 'editor' ] );
		$approver = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $approver );

		$action = $this->repository->stage( 'albert-test/throws', [ 'id' => 5 ], $caller, null, null, DAY_IN_SECONDS );

		try {
			( new Approver( $this->repository ) )->approve( $action, $approver );
		} catch ( \Throwable $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertFalse( ApprovalTicket::is_armed(), 'A throw must not leave a ticket armed.' );
		$this->assertSame( $approver, get_current_user_id(), 'A throw must not leave the caller impersonated.' );
	}

	/**
	 * An ability that no longer exists is recorded as failed, not run.
	 *
	 * @return void
	 */
	public function test_a_missing_ability_is_recorded_as_failed(): void {
		$approver = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $approver );

		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$action = $this->repository->stage( 'albert-test/gone', [ 'id' => 1 ], $approver, null, null, DAY_IN_SECONDS );

		$result = ( new Approver( $this->repository ) )->approve( $action, $approver );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_pending_ability_missing', $result->get_error_code() );

		$stored = $this->repository->find( $action->action_id );
		$this->assertInstanceOf( PendingAction::class, $stored );
		$this->assertSame( PendingAction::STATUS_FAILED, $stored->status );
	}

	/**
	 * A second approval of the same row runs nothing.
	 *
	 * @return void
	 */
	public function test_a_second_approval_runs_nothing(): void {
		$this->register_recording_ability( 'albert-test/once' );

		$caller   = self::factory()->user->create( [ 'role' => 'editor' ] );
		$approver = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $approver );

		$action           = $this->repository->stage( 'albert-test/once', [ 'id' => 1 ], $caller, null, null, DAY_IN_SECONDS );
		$approver_service = new Approver( $this->repository );

		$approver_service->approve( $action, $approver );
		$this->observed = [];

		$second = $approver_service->approve( $action, $approver );

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'albert_already_handled', $second->get_error_code() );
		$this->assertSame( [], $this->observed, 'The ability must not run a second time.' );
	}
}
