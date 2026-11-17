<?php
/**
 * Unit tests for the annotation presets.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

use Albert\Core\Annotations;
use PHPUnit\Framework\TestCase;

/**
 * Every preset sets all three behaviour hints.
 *
 * @covers \Albert\Core\Annotations
 */
class AnnotationsTest extends TestCase {

	/**
	 * Each preset and the hints it must set.
	 *
	 * @return array<string, array{0: string, 1: array{readonly: bool, destructive: bool, idempotent: bool}}>
	 */
	public static function providePresets(): array {
		return [
			'read'        => [
				'read',
				[
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
			'create'      => [
				'create',
				[
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				],
			],
			'update'      => [
				'update',
				[
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
			'delete'      => [
				'delete',
				[
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				],
			],
			'destructive' => [
				'destructive',
				[
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				],
			],
			'action'      => [
				'action',
				[
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				],
			],
		];
	}

	/**
	 * The preset sets exactly these hints.
	 *
	 * @dataProvider providePresets
	 *
	 * @param string                                                     $preset   Preset method.
	 * @param array{readonly: bool, destructive: bool, idempotent: bool} $expected Expected hints.
	 * @return void
	 */
	public function test_preset_sets_all_three_hints( string $preset, array $expected ): void {
		$this->assertSame( $expected, Annotations::$preset() );
	}

	/**
	 * Instructions ride along without changing the hints.
	 *
	 * @dataProvider providePresets
	 *
	 * @param string                                                     $preset   Preset method.
	 * @param array{readonly: bool, destructive: bool, idempotent: bool} $expected Expected hints.
	 * @return void
	 */
	public function test_instructions_keep_the_hints( string $preset, array $expected ): void {
		$this->assertSame( $expected + [ 'instructions' => 'Read it first.' ], Annotations::$preset( 'Read it first.' ) );
	}
}
