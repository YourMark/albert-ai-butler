<?php
/**
 * Unit tests for how the safe-mode setting reads its value.
 *
 * `define( 'ALBERT_SAFE_MODE', false )` is what a PHP developer writes, and a
 * string-only rule silently rejected it, leaving the gate on and the Settings
 * field editable with no sign the constant had been ignored.
 *
 * @package Albert\Tests\Unit\SafeMode
 */

namespace Albert\Tests\Unit\SafeMode;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\SafeMode\Gate;
use PHPUnit\Framework\TestCase;

/**
 * Gate value-coercion tests.
 *
 * @covers \Albert\SafeMode\Gate::means_off
 * @covers \Albert\SafeMode\Gate::is_valid
 * @covers \Albert\SafeMode\Gate::sanitize
 */
class GateValueTest extends TestCase {

	/**
	 * Values that mean off.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function offValues(): array {
		return [
			'boolean false' => [ false ],
			'integer zero'  => [ 0 ],
			'string off'    => [ 'off' ],
			'string false'  => [ 'false' ],
			'string no'     => [ 'no' ],
			'string zero'   => [ '0' ],
			'padded OFF'    => [ ' OFF ' ],
		];
	}

	/**
	 * Values that mean on.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function onValues(): array {
		return [
			'boolean true' => [ true ],
			'integer one'  => [ 1 ],
			'string on'    => [ 'on' ],
			'string true'  => [ 'true' ],
			'string yes'   => [ 'yes' ],
			'nonsense'     => [ 'wibble' ],
			'null'         => [ null ],
		];
	}

	/**
	 * Off is recognised however it is spelled.
	 *
	 * @dataProvider offValues
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return void
	 */
	public function test_off_is_recognised( $value ): void {
		$this->assertTrue( Gate::means_off( $value ) );
		$this->assertSame( 'off', Gate::sanitize( $value ) );
	}

	/**
	 * Anything else means on, so a typo fails towards the gate.
	 *
	 * @dataProvider onValues
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return void
	 */
	public function test_anything_else_means_on( $value ): void {
		$this->assertFalse( Gate::means_off( $value ) );
		$this->assertSame( 'on', Gate::sanitize( $value ) );
	}

	/**
	 * A boolean constant is a usable override, not one to skip.
	 *
	 * @return void
	 */
	public function test_booleans_are_valid_overrides(): void {
		$this->assertTrue( Gate::is_valid( false ) );
		$this->assertTrue( Gate::is_valid( true ) );
		$this->assertTrue( Gate::is_valid( 'off' ) );
	}

	/**
	 * Something Albert cannot read is skipped rather than pinning the site.
	 *
	 * @return void
	 */
	public function test_unreadable_values_are_not_valid_overrides(): void {
		$this->assertFalse( Gate::is_valid( 'wibble' ) );
		$this->assertFalse( Gate::is_valid( [ 'off' ] ) );
		$this->assertFalse( Gate::is_valid( null ) );
	}
}
