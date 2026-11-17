<?php
/**
 * Integration tests for ID inputs that are not a positive whole number.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WooCommerce\ViewCustomer as WooViewCustomer;
use Albert\Abilities\WooCommerce\ViewOrder as WooViewOrder;
use Albert\Abilities\WooCommerce\ViewProduct as WooViewProduct;
use Albert\Abilities\WordPress\Media\DeleteMedia;
use Albert\Abilities\WordPress\Media\SetFeaturedImage;
use Albert\Abilities\WordPress\Media\UpdateMedia;
use Albert\Abilities\WordPress\Media\ViewMedia;
use Albert\Abilities\WordPress\Pages\Delete as DeletePage;
use Albert\Abilities\WordPress\Pages\EditBlock as EditPageBlock;
use Albert\Abilities\WordPress\Pages\Update as UpdatePage;
use Albert\Abilities\WordPress\Pages\ViewPage;
use Albert\Abilities\WordPress\Posts\Delete as DeletePost;
use Albert\Abilities\WordPress\Posts\EditBlock as EditPostBlock;
use Albert\Abilities\WordPress\Posts\RemoveBlock as RemovePostBlock;
use Albert\Abilities\WordPress\Posts\Update as UpdatePost;
use Albert\Abilities\WordPress\Posts\ViewPost;
use Albert\Abilities\WordPress\Taxonomies\DeleteTerm;
use Albert\Abilities\WordPress\Taxonomies\UpdateTerm;
use Albert\Abilities\WordPress\Taxonomies\ViewTerm;
use Albert\Abilities\WordPress\Users\Delete as DeleteUser;
use Albert\Abilities\WordPress\Users\Update as UpdateUser;
use Albert\Abilities\WordPress\Users\ViewUser;
use Albert\Abstracts\BaseAbility;
use Albert\Tests\TestCase;
use Albert\Tests\Traits\ProvidesAbilities;
use WP_Error;

/**
 * `0`, `"0"` and `-5` are refused as invalid, never read as missing or as ID 5.
 *
 * @since 1.6.0
 */
class InvalidIdTest extends TestCase {

	use ProvidesAbilities;

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
	 * @return array<string, array{0: class-string<BaseAbility>, 1: string, 2: array<string, mixed>}>
	 */
	public static function provideIdInputs(): array {
		return [
			'update-post'        => [ UpdatePost::class, 'id', [ 'title' => 'x' ] ],
			'delete-post'        => [ DeletePost::class, 'id', [] ],
			'view-post'          => [ ViewPost::class, 'id', [] ],
			'update-page'        => [ UpdatePage::class, 'id', [ 'title' => 'x' ] ],
			'delete-page'        => [ DeletePage::class, 'id', [] ],
			'view-page'          => [ ViewPage::class, 'id', [] ],
			'edit-post-block'    => [ EditPostBlock::class, 'id', [] ],
			'edit-page-block'    => [ EditPageBlock::class, 'id', [] ],
			'remove-post-block'  => [ RemovePostBlock::class, 'id', [ 'path' => [ 0 ] ] ],
			'view-media'         => [ ViewMedia::class, 'id', [] ],
			'update-media'       => [ UpdateMedia::class, 'id', [ 'title' => 'x' ] ],
			'delete-media'       => [ DeleteMedia::class, 'id', [] ],
			'set-featured-image' => [ SetFeaturedImage::class, 'post_id', [ 'attachment_id' => 1 ] ],
			'view-user'          => [ ViewUser::class, 'id', [] ],
			'update-user'        => [ UpdateUser::class, 'id', [ 'first_name' => 'x' ] ],
			'delete-user'        => [ DeleteUser::class, 'id', [ 'reassign' => 1 ] ],
			'view-term'          => [ ViewTerm::class, 'id', [ 'taxonomy' => 'category' ] ],
			'update-term'        => [
				UpdateTerm::class,
				'id',
				[
					'taxonomy' => 'category',
					'name'     => 'x',
				],
			],
			'delete-term'        => [ DeleteTerm::class, 'id', [ 'taxonomy' => 'category' ] ],
			'woo-view-product'   => [ WooViewProduct::class, 'id', [] ],
			'woo-view-order'     => [ WooViewOrder::class, 'id', [] ],
			'woo-view-customer'  => [ WooViewCustomer::class, 'id', [] ],
		];
	}

	/**
	 * The ability itself refuses each bad value with `albert_invalid_{field}`.
	 *
	 * @dataProvider provideIdInputs
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 * @param string                    $field         ID input name.
	 * @param array<string, mixed>      $extra         Other input the ability needs.
	 * @return void
	 */
	public function test_execute_refuses_non_positive_ids( string $ability_class, string $field, array $extra ): void {
		$this->skip_if_woocommerce_required( $ability_class );

		$ability = new $ability_class();

		foreach ( [ 0, '0', -5, '-5' ] as $value ) {
			$result = $ability->execute( array_merge( $extra, [ $field => $value ] ) );

			$this->assertInstanceOf( WP_Error::class, $result, sprintf( '%s accepted %s.', $ability->get_id(), var_export( $value, true ) ) );
			$this->assertSame( 'albert_invalid_' . $field, $result->get_error_code(), sprintf( '%s with %s.', $ability->get_id(), var_export( $value, true ) ) );
		}
	}

	/**
	 * The registered schema refuses them before the ability runs.
	 *
	 * @dataProvider provideIdInputs
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 * @param string                    $field         ID input name.
	 * @return void
	 */
	public function test_schema_requires_a_positive_id( string $ability_class, string $field ): void {
		$this->skip_if_woocommerce_required( $ability_class );

		$instance = new $ability_class();
		$ability  = wp_get_ability( $instance->get_id() );

		if ( ! $ability ) {
			$this->markTestSkipped( $instance->get_id() . ' is not registered.' );
		}

		$schema = $ability->get_input_schema();
		$this->assertSame( 1, $schema['properties'][ $field ]['minimum'] ?? null, $instance->get_id() );
	}

	/**
	 * `-5` no longer acts on post 5.
	 *
	 * @return void
	 */
	public function test_negative_id_does_not_delete_the_positive_post(): void {
		$post_id = self::factory()->post->create();

		$result = ( new DeletePost() )->execute( [ 'id' => -$post_id ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * A digit string is still read as that ID.
	 *
	 * @return void
	 */
	public function test_digit_string_is_accepted(): void {
		$post_id = self::factory()->post->create();

		$result = ( new ViewPost() )->execute( [ 'id' => (string) $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post']['id'] );
	}
}
