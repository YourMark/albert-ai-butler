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

/**
 * Reading a product, drafts included, needs edit_products. The refusal is covered by DirectCapabilityTest.
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
	 * A user who can edit products reads a draft product.
	 *
	 * @return void
	 */
	public function test_product_editor_reads_a_draft_product(): void {
		// The test framework resets roles, so shop_manager lacks edit_products; grant it directly.
		$user = get_userdata( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user->ID );

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
