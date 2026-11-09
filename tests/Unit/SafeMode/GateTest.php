<?php
/**
 * Unit tests for the safe-mode gate rule.
 *
 * Pins the two things a misedit could quietly break: the setting toggles the
 * gate, and the annotation rule is `destructive !== false` (fail-safe), never
 * `=== true` (fail-open).
 *
 * @package Albert\Tests\Unit\SafeMode
 */

namespace Albert\Tests\Unit\SafeMode;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Ability.php';

use Albert\SafeMode\Gate;
use PHPUnit\Framework\TestCase;
use WP_Ability;

/**
 * Gate tests.
 *
 * @covers \Albert\SafeMode\Gate
 */
class GateTest extends TestCase {

	/**
	 * The gate under test.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Reset option/hook globals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_options']          = [];
		$GLOBALS['albert_test_hooks']            = [];
		$GLOBALS['albert_test_filter_returns']   = [];
		$GLOBALS['albert_test_filter_callbacks'] = [];
		unset( $GLOBALS['albert_test_user_id'] );
		$this->gate = new Gate();
	}

	/**
	 * An ability carrying the given annotations, or none.
	 *
	 * @param array<string, bool|null>|null $annotations Annotations, or null for an unannotated ability.
	 *
	 * @return WP_Ability
	 */
	private function ability( ?array $annotations ): WP_Ability {
		$meta = $annotations === null ? [] : [ 'annotations' => $annotations ];

		return new WP_Ability( 'test/ability', $meta );
	}

	/**
	 * With safe mode at its default, a destructive ability is gated.
	 *
	 * @return void
	 */
	public function test_default_on_gates_a_destructive_ability(): void {
		$this->assertTrue( $this->gate->must_gate( $this->ability( [ 'destructive' => true ] ) ) );
	}

	/**
	 * An exempted ability is let through, even when it is destructive.
	 *
	 * The escape hatch exists so an owner with one noisy ability they trust
	 * excuses that ability instead of switching safe mode off entirely.
	 *
	 * @return void
	 */
	public function test_an_exempt_ability_is_not_gated(): void {
		$GLOBALS['albert_test_filter_returns']['albert/safe_mode/exempt_abilities'] = [ 'test/ability' ];

		$this->assertFalse( $this->gate->must_gate( $this->ability( [ 'destructive' => true ] ) ) );
	}

	/**
	 * Exemption beats the high-risk list, which is the general default.
	 *
	 * @return void
	 */
	public function test_exemption_wins_over_the_high_risk_list(): void {
		$GLOBALS['albert_test_filter_returns']['albert/safe_mode/high_risk_abilities'] = [ 'test/ability' ];
		$GLOBALS['albert_test_filter_returns']['albert/safe_mode/exempt_abilities']    = [ 'test/ability' ];

		$this->assertFalse( $this->gate->must_gate( $this->ability( [ 'destructive' => false ] ) ) );
	}

	/**
	 * An exemption naming a different ability changes nothing.
	 *
	 * @return void
	 */
	public function test_an_unrelated_exemption_does_not_open_the_gate(): void {
		$GLOBALS['albert_test_filter_returns']['albert/safe_mode/exempt_abilities'] = [ 'other/ability' ];

		$this->assertTrue( $this->gate->must_gate( $this->ability( [ 'destructive' => true ] ) ) );
	}

	/**
	 * A non-destructive ability (every read/create/update) is never gated.
	 *
	 * @return void
	 */
	public function test_allows_a_non_destructive_ability(): void {
		$this->assertFalse( $this->gate->must_gate( $this->ability( [ 'destructive' => false ] ) ) );
	}

	/**
	 * An unannotated ability is gated: fail-safe, not fail-open.
	 *
	 * @return void
	 */
	public function test_gates_an_unannotated_ability(): void {
		$this->assertTrue( $this->gate->must_gate( $this->ability( null ) ) );
		$this->assertTrue( $this->gate->is_gated_ability( $this->ability( [ 'readonly' => true ] ) ) );
	}

	/**
	 * A null destructive flag gates; only an explicit false is exempt.
	 *
	 * @return void
	 */
	public function test_null_destructive_is_gated(): void {
		$this->assertTrue( $this->gate->is_gated_ability( $this->ability( [ 'destructive' => null ] ) ) );
	}

	/**
	 * Switched off, even a destructive ability runs unattended.
	 *
	 * @return void
	 */
	public function test_off_allows_a_destructive_ability(): void {
		$GLOBALS['albert_test_options']['albert_safe_mode'] = 'off';

		$this->assertFalse( $this->gate->is_enabled() );
		$this->assertFalse( $this->gate->must_gate( $this->ability( [ 'destructive' => true ] ) ) );
	}

	/**
	 * The default, with nothing stored, is on.
	 *
	 * @return void
	 */
	public function test_enabled_by_default(): void {
		$this->assertTrue( $this->gate->is_enabled() );
	}

	/**
	 * An add-on can hold a call that Albert's policy lets through, on its input.
	 *
	 * @return void
	 */
	public function test_an_add_on_can_hold_a_call_on_its_input(): void {
		$GLOBALS['albert_test_filter_callbacks']['albert/safe_mode/should_hold'] = static function ( bool $hold, string $ability, array $input ): bool {
			return $hold || ( $input['status'] ?? '' ) === 'publish';
		};

		$ability = $this->ability( [ 'destructive' => false ] );

		$this->assertTrue( $this->gate->must_gate( $ability, [ 'status' => 'publish' ] ) );
		$this->assertFalse( $this->gate->must_gate( $ability, [ 'status' => 'draft' ] ) );
	}

	/**
	 * A filter returning false cannot release a hold Albert's policy made.
	 *
	 * @return void
	 */
	public function test_a_filter_cannot_release_a_policy_hold(): void {
		$GLOBALS['albert_test_filter_returns']['albert/safe_mode/should_hold'] = false;

		$this->assertTrue( $this->gate->must_gate( $this->ability( [ 'destructive' => true ] ) ) );
	}

	/**
	 * The filter receives the policy decision, ability id, input and user id.
	 *
	 * @return void
	 */
	public function test_the_filter_receives_the_call(): void {
		$GLOBALS['albert_test_user_id'] = 42;
		$input                          = [ 'id' => 7 ];

		$this->gate->must_gate( $this->ability( [ 'destructive' => true ] ), $input );

		$calls = array_values(
			array_filter(
				$GLOBALS['albert_test_hooks'],
				static fn( array $call ): bool => $call['hook'] === 'albert/safe_mode/should_hold'
			)
		);

		$this->assertCount( 1, $calls );
		$this->assertSame( [ true, 'test/ability', $input, 42 ], $calls[0]['args'] );
	}

	/**
	 * The filter is not asked when safe mode is off or the ability is exempt.
	 *
	 * Otherwise an add-on hold would override the owner's off switch or exemption.
	 *
	 * @return void
	 */
	public function test_the_filter_is_skipped_when_off_or_exempt(): void {
		$GLOBALS['albert_test_filter_returns']['albert/safe_mode/should_hold']      = true;
		$GLOBALS['albert_test_filter_returns']['albert/safe_mode/exempt_abilities'] = [ 'test/ability' ];

		$this->assertFalse( $this->gate->must_gate( $this->ability( [ 'destructive' => false ] ) ) );

		$GLOBALS['albert_test_filter_returns']['albert/safe_mode/exempt_abilities'] = [];
		$GLOBALS['albert_test_options']['albert_safe_mode']                         = 'off';

		$this->assertFalse( $this->gate->must_gate( $this->ability( [ 'destructive' => false ] ) ) );
	}
}
