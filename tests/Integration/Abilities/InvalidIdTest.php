<?php
/**
 * Integration tests for ID inputs below 1.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Tests\TestCase;
use WP_Error;

/**
 * The schema's `minimum: 1` refuses `-5` before execute() could `absint()` it into 5.
 *
 * @since 1.6.0
 */
class InvalidIdTest extends TestCase {

	/**
	 * Runs as an administrator with every ability switched on.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * Abilities that act on one object by ID, with the ID field and the other input they need.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	public static function provideIdInputs(): array {
		return [
			'update-post'        => [ 'albert/update-post', 'id', [ 'title' => 'x' ] ],
			'delete-post'        => [ 'albert/delete-post', 'id', [] ],
			'view-post'          => [ 'albert/view-post', 'id', [] ],
			'update-page'        => [ 'albert/update-page', 'id', [ 'title' => 'x' ] ],
			'delete-page'        => [ 'albert/delete-page', 'id', [] ],
			'view-page'          => [ 'albert/view-page', 'id', [] ],
			'edit-post-block'    => [ 'albert/edit-post-block', 'id', [] ],
			'edit-page-block'    => [ 'albert/edit-page-block', 'id', [] ],
			'remove-post-block'  => [ 'albert/remove-post-block', 'id', [ 'path' => [ 0 ] ] ],
			'view-media'         => [ 'albert/view-media', 'id', [] ],
			'update-media'       => [ 'albert/update-media', 'id', [ 'title' => 'x' ] ],
			'delete-media'       => [ 'albert/delete-media', 'id', [] ],
			'set-featured-image' => [ 'albert/set-featured-image', 'post_id', [ 'attachment_id' => 1 ] ],
			'view-user'          => [ 'albert/view-user', 'id', [] ],
			'update-user'        => [ 'albert/update-user', 'id', [ 'first_name' => 'x' ] ],
			'delete-user'        => [ 'albert/delete-user', 'id', [ 'reassign' => 1 ] ],
			'view-term'          => [ 'albert/view-term', 'id', [ 'taxonomy' => 'category' ] ],
			'update-term'        => [
				'albert/update-term',
				'id',
				[
					'taxonomy' => 'category',
					'name'     => 'x',
				],
			],
			'delete-term'        => [ 'albert/delete-term', 'id', [ 'taxonomy' => 'category' ] ],
			'woo-view-product'   => [ 'albert/woo-view-product', 'id', [] ],
			'woo-view-order'     => [ 'albert/woo-view-order', 'id', [] ],
			'woo-view-customer'  => [ 'albert/woo-view-customer', 'id', [] ],
		];
	}

	/**
	 * Each ability refuses a negative ID as invalid input.
	 *
	 * @dataProvider provideIdInputs
	 *
	 * @param string               $ability_id Ability ID.
	 * @param string               $field      ID input name.
	 * @param array<string, mixed> $extra      Other input the ability needs.
	 * @return void
	 */
	public function test_negative_id_is_refused( string $ability_id, string $field, array $extra ): void {
		$ability = wp_get_ability( $ability_id );

		if ( ! $ability ) {
			$this->markTestSkipped( $ability_id . ' is not registered.' );
		}

		$result = $ability->execute( array_merge( $extra, [ $field => -5 ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * `-5` leaves post 5 alone.
	 *
	 * @return void
	 */
	public function test_negative_id_does_not_touch_the_positive_one(): void {
		$post_id = self::factory()->post->create();

		$ability = wp_get_ability( 'albert/delete-post' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( [ 'id' => -$post_id ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}
}
