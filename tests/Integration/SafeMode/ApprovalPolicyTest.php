<?php
/**
 * Integration tests for who may decide a held action.
 *
 * Needs real roles, real users and a real registered ability, because the rule
 * is "you may approve what you could have done yourself" and only the ability
 * can answer the second half.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\SafeMode;

use Albert\SafeMode\ApprovalPolicy;
use Albert\SafeMode\PendingAction;
use Albert\Tests\TestCase;

/**
 * ApprovalPolicy integration tests.
 *
 * @covers \Albert\SafeMode\ApprovalPolicy
 */
class ApprovalPolicyTest extends TestCase {

	/**
	 * Test-only ability category.
	 *
	 * @var string
	 */
	private const TEST_CATEGORY = 'albert-test';

	/**
	 * Ability ids to unregister afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $registered_ids = [];

	/**
	 * The policy under test.
	 *
	 * @var ApprovalPolicy
	 */
	private ApprovalPolicy $policy;

	/**
	 * Whether the registered ability grants permission.
	 *
	 * @var bool
	 */
	private bool $permitted = true;

	/**
	 * Build a policy and register the test category.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		$this->policy         = new ApprovalPolicy();
		$this->registered_ids = [];
		$this->permitted      = true;

		if ( function_exists( 'wp_has_ability_category' ) && ! wp_has_ability_category( self::TEST_CATEGORY ) ) {
			$this->during(
				'wp_abilities_api_categories_init',
				static function (): void {
					wp_register_ability_category(
						self::TEST_CATEGORY,
						[
							'label'       => 'Albert Test',
							'description' => 'Test-only category.',
						]
					);
				}
			);
		}
	}

	/**
	 * Unregister anything this test registered.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->registered_ids as $id ) {
			if ( function_exists( 'wp_unregister_ability' ) ) {
				wp_unregister_ability( $id );
			}
		}

		parent::tear_down();
	}

	/**
	 * Run a callback while the given one-shot action reports as in progress.
	 *
	 * @param string   $action   Action name.
	 * @param callable $callback What to run.
	 *
	 * @return void
	 */
	private function during( string $action, callable $callback ): void {
		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP core's own abilities tests use this pattern.
		$wp_current_filter[] = $action;

		try {
			$callback();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Register an ability whose permission callback follows $this->permitted.
	 *
	 * @param string $name Ability id.
	 *
	 * @return void
	 */
	private function register_ability( string $name ): void {
		$this->during(
			'wp_abilities_api_init',
			function () use ( $name ): void {
				wp_register_ability(
					$name,
					[
						'label'               => 'Test',
						'description'         => 'Permission follows the test.',
						'category'            => self::TEST_CATEGORY,
						'input_schema'        => [
							'type'       => 'object',
							'properties' => [ 'id' => [ 'type' => 'integer' ] ],
						],
						'output_schema'       => [ 'type' => 'object' ],
						'execute_callback'    => static fn(): array => [ 'ok' => true ],
						'permission_callback' => fn(): bool => $this->permitted,
					]
				);
			}
		);

		$this->registered_ids[] = $name;
	}

	/**
	 * A staged action owned by a given user.
	 *
	 * @param int    $user_id The owner.
	 * @param string $ability The ability id.
	 *
	 * @return PendingAction
	 */
	private function action( int $user_id, string $ability = 'albert-test/policy' ): PendingAction {
		return new PendingAction(
			1,
			'ref-1',
			$ability,
			[ 'id' => 1 ],
			PendingAction::STATUS_PENDING,
			$user_id,
			null,
			null,
			gmdate( 'Y-m-d H:i:s' ),
			gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			null,
			null,
			null
		);
	}

	/**
	 * An administrator decides any row, including somebody else's.
	 *
	 * @return void
	 */
	public function test_an_administrator_decides_any_row(): void {
		$this->register_ability( 'albert-test/policy' );

		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $admin );

		$this->assertTrue( $this->policy->can_decide( $this->action( $editor ) ) );
		$this->assertNull( $this->policy->decide_blocked_reason( $this->action( $editor ) ) );
	}

	/**
	 * An editor approves their own row when the ability permits it.
	 *
	 * @return void
	 */
	public function test_an_editor_approves_their_own_permitted_row(): void {
		$this->register_ability( 'albert-test/policy' );

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$this->assertTrue( $this->policy->can_decide( $this->action( $editor ) ) );
		$this->assertNull( $this->policy->decide_blocked_reason( $this->action( $editor ) ) );
	}

	/**
	 * An editor never decides somebody else's row.
	 *
	 * @return void
	 */
	public function test_an_editor_cannot_decide_another_users_row(): void {
		$this->register_ability( 'albert-test/policy' );

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$other  = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$this->assertFalse( $this->policy->can_decide( $this->action( $other ) ) );
		$this->assertNotNull( $this->policy->decide_blocked_reason( $this->action( $other ) ) );
	}

	/**
	 * Losing the capability blocks the row entirely, both ways.
	 *
	 * One rule, not two: the row then expires on its own, or an administrator
	 * decides it.
	 *
	 * @return void
	 */
	public function test_losing_permission_blocks_the_row_entirely(): void {
		$this->register_ability( 'albert-test/policy' );
		$this->permitted = false;

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$this->assertFalse( $this->policy->can_decide( $this->action( $editor ) ) );
		$this->assertNotNull( $this->policy->decide_blocked_reason( $this->action( $editor ) ) );
	}

	/**
	 * An administrator can still decide a row its owner no longer can.
	 *
	 * This is what stops a blocked row being stuck: somebody can always clear it.
	 *
	 * @return void
	 */
	public function test_an_administrator_can_clear_a_blocked_row(): void {
		$this->register_ability( 'albert-test/policy' );
		$this->permitted = false;

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$this->assertTrue( $this->policy->can_decide( $this->action( $editor ) ) );
	}

	/**
	 * An unregistered ability is decidable by nobody but an administrator.
	 *
	 * @return void
	 */
	public function test_a_missing_ability_is_not_decidable_by_its_owner(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$action = $this->action( $editor, 'albert-test/never-registered' );

		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$this->assertFalse( $this->policy->can_decide( $action ) );
		$this->assertNotNull( $this->policy->decide_blocked_reason( $action ) );
	}

	/**
	 * A subscriber cannot reach the screen; an editor can.
	 *
	 * @return void
	 */
	public function test_the_view_gate_keeps_subscribers_out(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$editor     = self::factory()->user->create( [ 'role' => 'editor' ] );

		$this->assertFalse( $this->policy->can_view( $subscriber ) );
		$this->assertTrue( $this->policy->can_view( $editor ) );
	}

	/**
	 * The view gate is filterable without affecting who decides what.
	 *
	 * @return void
	 */
	public function test_the_view_gate_is_filterable(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		add_filter( 'albert/approvals/view_capability', static fn(): string => 'read' );

		$this->assertTrue( $this->policy->can_view( $subscriber ) );
		$this->assertFalse( $this->policy->can_decide( $this->action( 999 ), $subscriber ) );
	}
}
