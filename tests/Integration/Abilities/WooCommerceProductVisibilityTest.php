<?php
/**
 * Integration tests: WooCommerce products a user may not read stay hidden.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WooCommerce\FindProducts;
use Albert\Abilities\WooCommerce\ViewProduct;
use Albert\Tests\TestCase;

/**
 * Product visibility for woo-view-product and woo-find-products.
 *
 * @since 1.5.1
 */
class WooCommerceProductVisibilityTest extends TestCase {

	/**
	 * Products by status.
	 *
	 * @var array<string, int>
	 */
	private array $products = [];

	/**
	 * Create one product per status, owned by an administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}

		$owner = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $owner );

		foreach ( [ 'publish', 'draft', 'pending', 'private' ] as $status ) {
			$product = new \WC_Product_Simple();
			$product->set_name( 'Albert visibility ' . $status );
			$product->set_status( $status );
			$product->set_regular_price( '10' );
			$this->products[ $status ] = $product->save();
		}

		// A product editor who may edit only their own products.
		$editor = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_userdata( $editor )->add_cap( 'edit_products' );
		wp_set_current_user( $editor );
	}

	/**
	 * Only the published product is visible to somebody without read rights.
	 *
	 * @return void
	 */
	public function test_view_product_hides_unpublished_products(): void {
		$this->assertIsArray( ( new ViewProduct() )->execute( [ 'id' => $this->products['publish'] ] ) );

		foreach ( [ 'draft', 'pending', 'private' ] as $status ) {
			$result = ( new ViewProduct() )->execute( [ 'id' => $this->products[ $status ] ] );
			$this->assertWPError( $result, $status );
			$this->assertSame( 'product_not_found', $result->get_error_code() );
		}
	}

	/**
	 * A disabled variation of a published product stays hidden.
	 *
	 * @return void
	 */
	public function test_view_product_hides_a_disabled_variation(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Albert variable' );
		$parent->set_status( 'publish' );
		$parent_id = $parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_status( 'private' );
		$variation->set_regular_price( '5' );
		$variation_id = $variation->save();

		$reader = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $reader );

		$this->assertIsArray( ( new ViewProduct() )->execute( [ 'id' => $parent_id ] ) );
		$this->assertWPError( ( new ViewProduct() )->execute( [ 'id' => $variation_id ] ) );
	}

	/**
	 * Finding products lists only what the user may read, whatever status is asked for.
	 *
	 * @return void
	 */
	public function test_find_products_lists_only_readable_products(): void {
		$listed = ( new FindProducts() )->execute( [ 'per_page' => 50 ] );
		$ids    = array_column( $listed['products'], 'id' );

		$this->assertContains( $this->products['publish'], $ids );
		$this->assertNotContains( $this->products['draft'], $ids );
		$this->assertNotContains( $this->products['pending'], $ids );
		$this->assertNotContains( $this->products['private'], $ids );

		$drafts = ( new FindProducts() )->execute( [ 'status' => 'draft' ] );
		$this->assertSame( [], $drafts['products'] );
		$this->assertSame( 0, $drafts['total'] );
	}

	/**
	 * A shop manager still sees everything.
	 *
	 * @return void
	 */
	public function test_find_products_shows_a_shop_manager_every_status(): void {
		// A shop manager's product rights, granted directly: WC's role caps do
		// not reliably survive the test framework's transactions (see
		// PermissionFailureTest).
		$manager = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		$user    = get_userdata( $manager );
		foreach ( [ 'read_product', 'edit_products', 'edit_others_products', 'edit_published_products', 'edit_private_products', 'read_private_products' ] as $cap ) {
			$user->add_cap( $cap );
		}
		wp_set_current_user( $manager );

		$ids = array_column( ( new FindProducts() )->execute( [ 'per_page' => 50 ] )['products'], 'id' );

		foreach ( $this->products as $id ) {
			$this->assertContains( $id, $ids );
		}
	}
}
