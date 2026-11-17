<?php
/**
 * Integration tests for who may read a product through albert/woo-view-product.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WooCommerce\ViewProduct;
use Albert\Tests\TestCase;
use WC_Product_Simple;
use WP_Error;

/**
 * A subscriber must not read draft or private products.
 *
 * @since 1.6.0
 */
class WooViewProductPermissionTest extends TestCase {

	/**
	 * Skips without WooCommerce.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( WC_Product_Simple::class ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}
	}

	/**
	 * A subscriber is refused.
	 *
	 * @return void
	 */
	public function test_subscriber_is_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = ( new ViewProduct() )->check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_permission_denied', $result->get_error_code() );
	}

	/**
	 * A shop manager reads a draft product.
	 *
	 * @return void
	 */
	public function test_shop_manager_reads_a_draft_product(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$product = new WC_Product_Simple();
		$product->set_name( 'Unreleased' );
		$product->set_status( 'draft' );
		$product->save();

		$ability = new ViewProduct();

		$this->assertTrue( $ability->check_permission() );

		$result = $ability->execute( [ 'id' => $product->get_id() ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'Unreleased', $result['product']['name'] );
	}
}
