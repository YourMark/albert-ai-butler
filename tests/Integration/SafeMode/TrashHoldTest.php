<?php
/**
 * Integration tests for which delete calls safe mode holds.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\SafeMode;

use Albert\SafeMode\Gate;
use Albert\Tests\TestCase;
use WP_Ability;

/**
 * Trash is let through, the permanent call is held.
 *
 * @since 1.6.0
 */
class TrashHoldTest extends TestCase {

	/**
	 * Albert's delete abilities that have a trash.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function provideTrashAbilities(): array {
		return [
			'delete-post'    => [ 'albert/delete-post', [ 'id' => 5 ] ],
			'delete-page'    => [ 'albert/delete-page', [ 'id' => 5 ] ],
			'delete-pattern' => [ 'albert/delete-pattern', [ 'name' => 'core/x' ] ],
			'delete-comment' => [ 'albert/delete-comment', [ 'comment_ids' => [ 5, 6 ] ] ],
		];
	}

	/**
	 * Without `force`, or with `force: false`, the call runs.
	 *
	 * @dataProvider provideTrashAbilities
	 *
	 * @param string               $id    Ability id.
	 * @param array<string, mixed> $input Other input.
	 * @return void
	 */
	public function test_trash_is_not_held( string $id, array $input ): void {
		$ability = wp_get_ability( $id );
		$gate    = new Gate();

		$this->assertFalse( $gate->must_gate( $ability, $input ) );
		$this->assertFalse( $gate->must_gate( $ability, $input + [ 'force' => false ] ) );
	}

	/**
	 * With `force: true` the call is held.
	 *
	 * @dataProvider provideTrashAbilities
	 *
	 * @param string               $id    Ability id.
	 * @param array<string, mixed> $input Other input.
	 * @return void
	 */
	public function test_force_is_held( string $id, array $input ): void {
		$this->assertTrue( ( new Gate() )->must_gate( wp_get_ability( $id ), $input + [ 'force' => true ] ) );
	}

	/**
	 * Deletes without a trash stay held whatever `force` says.
	 *
	 * @return void
	 */
	public function test_deletes_without_a_trash_stay_held(): void {
		$gate = new Gate();

		$this->assertTrue(
			$gate->must_gate(
				wp_get_ability( 'albert/delete-term' ),
				[
					'id'    => 5,
					'force' => false,
				]
			)
		);
		$this->assertTrue( $gate->must_gate( wp_get_ability( 'albert/delete-media' ), [ 'id' => 5 ] ) );
	}

	/**
	 * A third-party delete with the same input is still held.
	 *
	 * @return void
	 */
	public function test_a_third_party_delete_with_force_false_is_held(): void {
		$ability = new WP_Ability(
			'vendor/delete-thing',
			[
				'label'               => 'Delete Thing',
				'description'         => 'Deletes a thing.',
				'category'            => 'site',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [ 'force' => [ 'type' => 'boolean' ] ],
				],
				'execute_callback'    => '__return_true',
				'permission_callback' => '__return_true',
				'meta'                => [
					'annotations' => [ 'destructive' => true ],
				],
			]
		);

		$this->assertTrue( ( new Gate() )->must_gate( $ability, [ 'force' => false ] ) );
	}
}
