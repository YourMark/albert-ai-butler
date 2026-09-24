<?php
/**
 * Unit tests for the safe-mode risk policy.
 *
 * The annotation axis misses privilege changes and foundational-option writes;
 * these pin the second axis that catches them, including for third-party
 * abilities the name list has never heard of.
 *
 * @package Albert\Tests\Unit\SafeMode
 */

namespace Albert\Tests\Unit\SafeMode;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Ability.php';

use Albert\SafeMode\RiskPolicy;
use PHPUnit\Framework\TestCase;
use WP_Ability;

/**
 * RiskPolicy tests.
 *
 * @covers \Albert\SafeMode\RiskPolicy
 */
class RiskPolicyTest extends TestCase {

	/**
	 * The policy under test.
	 *
	 * @var RiskPolicy
	 */
	private RiskPolicy $risk;

	/**
	 * Reset hooks/roles and build a fresh policy.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks'] = [];
		$GLOBALS['albert_test_roles'] = [
			'administrator' => [ 'manage_options' => true ],
			'editor'        => [ 'manage_options' => false ],
		];
		$this->risk                   = new RiskPolicy();
	}

	/**
	 * An ability with no name.
	 *
	 * @param string $name Ability id.
	 *
	 * @return WP_Ability
	 */
	private function ability( string $name ): WP_Ability {
		return new WP_Ability( $name, [ 'annotations' => [ 'destructive' => false ] ] );
	}

	/**
	 * Albert's own privilege abilities are held by name, despite destructive:false.
	 *
	 * @return void
	 */
	public function test_holds_named_high_risk_abilities(): void {
		$this->assertTrue( $this->risk->must_hold( $this->ability( 'albert/update-user' ), [] ) );
		$this->assertTrue( $this->risk->must_hold( $this->ability( 'albert/create-user' ), [] ) );
	}

	/**
	 * An ordinary write is not held by the risk policy.
	 *
	 * @return void
	 */
	public function test_does_not_hold_an_ordinary_write(): void {
		$this->assertFalse(
			$this->risk->must_hold(
				$this->ability( 'albert/update-post' ),
				[
					'id'    => 5,
					'title' => 'Hi',
				]
			)
		);
	}

	/**
	 * Any ability granting an admin-capability role is held, by input.
	 *
	 * @return void
	 */
	public function test_holds_input_that_grants_an_admin_role(): void {
		$this->assertTrue( $this->risk->must_hold( $this->ability( 'thirdparty/set-role' ), [ 'role' => 'administrator' ] ) );
		$this->assertTrue( $this->risk->must_hold( $this->ability( 'thirdparty/set-role' ), [ 'roles' => [ 'administrator' ] ] ) );
	}

	/**
	 * A non-admin role is not held on the role axis.
	 *
	 * @return void
	 */
	public function test_does_not_hold_a_non_admin_role(): void {
		$this->assertFalse( $this->risk->must_hold( $this->ability( 'thirdparty/set-role' ), [ 'role' => 'editor' ] ) );
	}

	/**
	 * A third-party option writer targeting a foundational option is held.
	 *
	 * @return void
	 */
	public function test_holds_a_high_risk_option_write(): void {
		$this->assertTrue(
			$this->risk->must_hold(
				$this->ability( 'thirdparty/update-option' ),
				[
					'option' => 'siteurl',
					'value'  => 'https://evil',
				]
			)
		);
		$this->assertTrue( $this->risk->must_hold( $this->ability( 'thirdparty/update-option' ), [ 'option_name' => 'admin_email' ] ) );
	}

	/**
	 * A benign option is not held.
	 *
	 * @return void
	 */
	public function test_does_not_hold_a_benign_option(): void {
		$this->assertFalse( $this->risk->must_hold( $this->ability( 'thirdparty/update-option' ), [ 'option' => 'blogdescription' ] ) );
	}

	/**
	 * A term named after a high-risk option is not an option write.
	 *
	 * `name` and `key` were in the lookup list, so creating a category called
	 * "home" matched and got held. A gate that stops ordinary content work
	 * teaches people to stop reading the queue.
	 *
	 * @return void
	 */
	public function test_a_term_named_home_is_not_held(): void {
		$this->assertFalse(
			$this->risk->must_hold( $this->ability( 'albert/create-term' ), [ 'name' => 'home' ] )
		);
	}

	/**
	 * A real option write is still caught by the keys that do name options.
	 *
	 * @return void
	 */
	public function test_an_option_write_is_still_held(): void {
		$this->assertTrue(
			$this->risk->must_hold( $this->ability( 'acme/update-option' ), [ 'option' => 'siteurl' ] )
		);
	}

	/**
	 * The options added after review are held too.
	 *
	 * @dataProvider addedHighRiskOptions
	 *
	 * @param string $option The option name.
	 *
	 * @return void
	 */
	public function test_newly_listed_options_are_held( string $option ): void {
		$this->assertTrue(
			$this->risk->must_hold( $this->ability( 'acme/update-option' ), [ 'option_name' => $option ] )
		);
	}

	/**
	 * Options added after the first review pass.
	 *
	 * @return array<string, array{string}>
	 */
	public static function addedHighRiskOptions(): array {
		return [
			'active_plugins'      => [ 'active_plugins' ],
			'blog_public'         => [ 'blog_public' ],
			'permalink_structure' => [ 'permalink_structure' ],
		];
	}
}
