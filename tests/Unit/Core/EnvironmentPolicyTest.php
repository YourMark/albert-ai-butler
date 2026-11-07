<?php
/**
 * Unit tests for the environment policy.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Ability.php';

use Albert\Core\AbilitiesState;
use Albert\Core\EnvironmentPolicy;
use PHPUnit\Framework\TestCase;
use WP_Ability;

/**
 * EnvironmentPolicy tests.
 *
 * @covers \Albert\Core\EnvironmentPolicy
 * @covers \Albert\Core\AbilitiesState::effective
 */
class EnvironmentPolicyTest extends TestCase {

	/**
	 * Toggles saved with a read, a write, a delete and an undeclared ability switched off.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']            = [];
		$GLOBALS['albert_test_filter_returns']   = [];
		$GLOBALS['albert_test_filter_callbacks'] = [];
		$GLOBALS['albert_test_environment_type'] = 'staging';
		$GLOBALS['albert_test_doing_it_wrong']   = [];
		$GLOBALS['albert_test_options']          = [
			AbilitiesState::OPTION       => [ 'albert/find-posts', 'albert/create-post', 'albert/delete-post', 'other/undeclared' ],
			AbilitiesState::SAVED_OPTION => true,
		];
		$GLOBALS['albert_test_abilities']        = [
			new WP_Ability( 'albert/find-posts', [ 'annotations' => [ 'readonly' => true ] ] ),
			new WP_Ability(
				'albert/create-post',
				[
					'annotations' => [
						'readonly'    => false,
						'destructive' => false,
					],
				]
			),
			new WP_Ability(
				'albert/delete-post',
				[
					'annotations' => [
						'readonly'    => false,
						'destructive' => true,
					],
				]
			),
			new WP_Ability( 'other/undeclared' ),
		];
	}

	/**
	 * Clear the environment so other tests see production.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['albert_test_environment_type'], $GLOBALS['albert_test_abilities'] );
		parent::tearDown();
	}

	/**
	 * Set the policy through the filter.
	 *
	 * @param mixed $policy Policy the filter returns.
	 *
	 * @return void
	 */
	private function filter_policy( mixed $policy ): void {
		$GLOBALS['albert_test_filter_returns']['albert/abilities/environment_policy'] = $policy;
	}

	/**
	 * With nothing configured the toggles decide, and the screen sees no policy.
	 *
	 * @return void
	 */
	public function test_default_uses_the_toggles(): void {
		$this->assertFalse( EnvironmentPolicy::is_active() );
		$this->assertSame( AbilitiesState::disabled(), AbilitiesState::effective() );
	}

	/**
	 * The filter is told which environment it is deciding for.
	 *
	 * @return void
	 */
	public function test_filter_receives_the_environment(): void {
		EnvironmentPolicy::current();

		$calls = array_values(
			array_filter(
				$GLOBALS['albert_test_hooks'],
				static fn( array $hook ): bool => $hook['hook'] === 'albert/abilities/environment_policy'
			)
		);

		$this->assertSame( [ EnvironmentPolicy::TOGGLES, 'staging' ], $calls[0]['args'] );
	}

	/**
	 * `all` switches every ability on without writing the toggles.
	 *
	 * @return void
	 */
	public function test_all_empties_the_effective_list_and_leaves_the_toggles(): void {
		$saved = $GLOBALS['albert_test_options'];
		$this->filter_policy( EnvironmentPolicy::ALL );

		$this->assertSame( [], AbilitiesState::effective() );
		$this->assertTrue( AbilitiesState::is_enabled( 'albert/delete-post' ) );
		$this->assertSame( $saved, $GLOBALS['albert_test_options'] );
		$this->assertSame( $saved[ AbilitiesState::OPTION ], AbilitiesState::disabled() );
	}

	/**
	 * `read-write` switches on what declares it deletes nothing; deletes and undeclared keep their toggle.
	 *
	 * @return void
	 */
	public function test_read_write_keeps_deletes_and_undeclared_off(): void {
		$this->filter_policy( EnvironmentPolicy::READ_WRITE );

		$this->assertSame( [ 'albert/delete-post', 'other/undeclared' ], AbilitiesState::effective() );
	}

	/**
	 * A write that leaves `destructive` unset may delete, so `read-write` leaves its toggle.
	 *
	 * @return void
	 */
	public function test_read_write_keeps_a_write_without_destructive_off(): void {
		$GLOBALS['albert_test_options'][ AbilitiesState::OPTION ] = [ 'other/update-things' ];
		$GLOBALS['albert_test_abilities']                         = [
			new WP_Ability( 'other/update-things', [ 'annotations' => [ 'readonly' => false ] ] ),
		];
		$this->filter_policy( EnvironmentPolicy::READ_WRITE );

		$this->assertSame( [ 'other/update-things' ], AbilitiesState::effective() );
	}

	/**
	 * An ability that is not registered cannot be classified, so it keeps its toggle.
	 *
	 * @return void
	 */
	public function test_read_write_keeps_unregistered_abilities_off(): void {
		$GLOBALS['albert_test_abilities'] = [];
		$this->filter_policy( EnvironmentPolicy::READ_WRITE );

		$this->assertSame( AbilitiesState::disabled(), AbilitiesState::effective() );
	}

	/**
	 * `albert/abilities/disabled_list` still has the last word after the policy.
	 *
	 * @return void
	 */
	public function test_disabled_list_filter_runs_after_the_policy(): void {
		$this->filter_policy( EnvironmentPolicy::ALL );
		$GLOBALS['albert_test_filter_callbacks']['albert/abilities/disabled_list'] = static function ( array $disabled ): array {
			$disabled[] = 'albert/delete-post';
			return $disabled;
		};

		$this->assertSame( [ 'albert/delete-post' ], AbilitiesState::effective() );
	}

	/**
	 * An unknown policy name falls back to the toggles.
	 *
	 * @return void
	 */
	public function test_unknown_policy_uses_the_toggles(): void {
		$this->filter_policy( 'everything' );

		$this->assertFalse( EnvironmentPolicy::is_active() );
		$this->assertSame( AbilitiesState::disabled(), AbilitiesState::effective() );
	}

	/**
	 * The current policy names its environment and source for the screen.
	 *
	 * @return void
	 */
	public function test_current_reports_environment_and_source(): void {
		$this->filter_policy( EnvironmentPolicy::ALL );

		$this->assertSame(
			[
				'environment' => 'staging',
				'policy'      => EnvironmentPolicy::ALL,
				'source'      => 'filter',
			],
			EnvironmentPolicy::current()
		);
	}

	/**
	 * The constant sets the policy for the environments it names and wins over the filter.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_constant_wins_over_the_filter(): void {
		define(
			'ALBERT_ENVIRONMENT_ABILITIES',
			[
				'staging' => EnvironmentPolicy::ALL,
				'local'   => EnvironmentPolicy::READ_WRITE,
			]
		);
		$this->filter_policy( EnvironmentPolicy::TOGGLES );

		$this->assertSame( 'constant', EnvironmentPolicy::current()['source'] );
		$this->assertSame( [], AbilitiesState::effective() );
	}

	/**
	 * A constant without a production entry leaves production on its toggles.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_production_is_unchanged_unless_named(): void {
		define( 'ALBERT_ENVIRONMENT_ABILITIES', [ 'staging' => EnvironmentPolicy::ALL ] );
		$GLOBALS['albert_test_environment_type'] = 'production';

		$this->assertFalse( EnvironmentPolicy::is_active() );
		$this->assertSame( AbilitiesState::disabled(), AbilitiesState::effective() );
	}

	/**
	 * A constant entry with an unknown policy is skipped, so the filter decides.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_unknown_constant_entry_falls_through_to_the_filter(): void {
		define( 'ALBERT_ENVIRONMENT_ABILITIES', [ 'staging' => 'everything' ] );
		$this->filter_policy( EnvironmentPolicy::READ_WRITE );

		$this->assertSame( 'filter', EnvironmentPolicy::current()['source'] );
	}

	/**
	 * A typo in the constant is reported once, however often the policy is read.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_unknown_constant_entry_is_reported_once(): void {
		define( 'ALBERT_ENVIRONMENT_ABILITIES', [ 'staging' => 'read_write' ] );

		EnvironmentPolicy::current();
		EnvironmentPolicy::current();

		$this->assertCount( 1, $GLOBALS['albert_test_doing_it_wrong'] );
		$this->assertStringContainsString( 'ALBERT_ENVIRONMENT_ABILITIES', $GLOBALS['albert_test_doing_it_wrong'][0] );
	}

	/**
	 * An unknown policy from the filter is reported.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_unknown_filter_policy_is_reported(): void {
		$this->filter_policy( 'everything' );

		EnvironmentPolicy::current();

		$this->assertCount( 1, $GLOBALS['albert_test_doing_it_wrong'] );
		$this->assertStringContainsString( 'albert/abilities/environment_policy', $GLOBALS['albert_test_doing_it_wrong'][0] );
	}

	/**
	 * A valid setup reports nothing, and an environment the constant leaves out is no typo.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_unnamed_environment_is_not_reported(): void {
		define( 'ALBERT_ENVIRONMENT_ABILITIES', [ 'local' => EnvironmentPolicy::ALL ] );

		EnvironmentPolicy::current();

		$this->assertSame( [], $GLOBALS['albert_test_doing_it_wrong'] );
	}

	/**
	 * An array entry keeps the toggles, which leaves that shape free for a per-group config.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_array_entry_keeps_the_toggles(): void {
		define( 'ALBERT_ENVIRONMENT_ABILITIES', [ 'staging' => [ 'posts' => EnvironmentPolicy::ALL ] ] );

		$this->assertFalse( EnvironmentPolicy::is_active() );
		$this->assertSame( AbilitiesState::disabled(), AbilitiesState::effective() );
	}
}
