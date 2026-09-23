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
		$GLOBALS['albert_test_options'] = [];
		$GLOBALS['albert_test_hooks']   = [];
		$this->gate                     = new Gate();
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
}
