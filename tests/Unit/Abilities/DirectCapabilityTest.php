<?php
/**
 * Unit tests for abilities that use require_capability() directly.
 *
 * These 16 abilities delegate permission checks to BaseAbility::require_capability()
 * instead of check_rest_permission(). Because they don't need the REST API server,
 * they can be unit-tested against the current_user_can() stub.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Abilities;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Abilities\WordPress\Media\FindMedia;
use Albert\Abilities\WordPress\Media\UploadMedia;
use Albert\Abilities\WordPress\Media\ViewMedia;
use Albert\Abilities\WordPress\Pages\ViewPage;
use Albert\Abilities\WordPress\Posts\ViewPost;
use Albert\Abilities\WordPress\Taxonomies\CreateTerm;
use Albert\Abilities\WordPress\Taxonomies\DeleteTerm;
use Albert\Abilities\WordPress\Taxonomies\FindTaxonomies;
use Albert\Abilities\WordPress\Taxonomies\FindTerms;
use Albert\Abilities\WordPress\Taxonomies\UpdateTerm;
use Albert\Abilities\WordPress\Taxonomies\ViewTerm;
use Albert\Abilities\WordPress\Users\ViewUser;
use Albert\Abilities\WooCommerce\FindCustomers;
use Albert\Abilities\WooCommerce\FindOrders;
use Albert\Abilities\WooCommerce\FindProducts;
use Albert\Abilities\WooCommerce\ViewCustomer;
use Albert\Abilities\WooCommerce\ViewOrder;
use Albert\Abilities\WooCommerce\ViewProduct;
use Albert\Abstracts\BaseAbility;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Direct capability permission tests.
 *
 * For each ability using require_capability(), verifies:
 * 1. check_permission() returns true when the user has the cap
 * 2. check_permission() returns WP_Error with correct code/status when denied
 *
 * @covers \Albert\Abstracts\BaseAbility::require_capability
 */
class DirectCapabilityTest extends TestCase {

	/**
	 * Reset globals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']   = [];
		$GLOBALS['albert_test_user_id'] = 1;
		$GLOBALS['albert_test_options'] = [
			'albert_abilities_saved'    => true,
			'albert_disabled_abilities' => [],
		];
		unset( $GLOBALS['albert_test_caps'], $GLOBALS['albert_test_abilities'] );
	}

	/**
	 * Abilities and the capability they require.
	 *
	 * @return array<string, array{0: class-string<BaseAbility>, 1: string}>
	 */
	public static function provideAbilitiesWithCaps(): array {
		return [
			// Media.
			'find-media'         => [ FindMedia::class, 'upload_files' ],
			'view-media'         => [ ViewMedia::class, 'upload_files' ],
			'upload-media'       => [ UploadMedia::class, 'upload_files' ],

			// Posts/Pages (read-only).
			'view-post'          => [ ViewPost::class, 'read' ],
			'view-page'          => [ ViewPage::class, 'read' ],

			// Users.
			'view-user'          => [ ViewUser::class, 'list_users' ],

			// Taxonomies.
			'find-taxonomies'    => [ FindTaxonomies::class, 'manage_categories' ],
			'find-terms'         => [ FindTerms::class, 'manage_categories' ],
			'view-term'          => [ ViewTerm::class, 'manage_categories' ],
			'create-term'        => [ CreateTerm::class, 'manage_categories' ],
			'update-term'        => [ UpdateTerm::class, 'manage_categories' ],
			'delete-term'        => [ DeleteTerm::class, 'manage_categories' ],

			// WooCommerce.
			'woo-find-products'  => [ FindProducts::class, 'edit_products' ],
			'woo-view-product'   => [ ViewProduct::class, 'read' ],
			'woo-find-orders'    => [ FindOrders::class, 'edit_shop_orders' ],
			'woo-view-order'     => [ ViewOrder::class, 'edit_shop_orders' ],
			'woo-find-customers' => [ FindCustomers::class, 'list_users' ],
			'woo-view-customer'  => [ ViewCustomer::class, 'list_users' ],
		];
	}

	/**
	 * Permission granted when user has the required capability.
	 *
	 * @dataProvider provideAbilitiesWithCaps
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 * @param string                    $cap   Required capability.
	 *
	 * @return void
	 */
	public function test_permission_granted_with_required_cap( string $ability_class, string $cap ): void {
		$GLOBALS['albert_test_caps'] = [ $cap ];

		$ability = new $ability_class();

		$this->assertTrue(
			$ability->check_permission(),
			sprintf( '%s should return true when user has "%s".', $ability_class, $cap )
		);
	}

	/**
	 * Permission denied when user lacks the required capability.
	 *
	 * @dataProvider provideAbilitiesWithCaps
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 * @param string                    $cap   Required capability.
	 *
	 * @return void
	 */
	public function test_permission_denied_without_required_cap( string $ability_class, string $cap ): void {
		// Grant a harmless cap that is NOT the required one.
		$GLOBALS['albert_test_caps'] = [ 'some_unrelated_cap' ];

		$ability = new $ability_class();
		$result  = $ability->check_permission();

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			sprintf( '%s should return WP_Error when user lacks "%s".', $ability_class, $cap )
		);
		$this->assertSame( 'ability_permission_denied', $result->get_error_code() );
	}

	/**
	 * Denied WP_Error carries a 403 status.
	 *
	 * @dataProvider provideAbilitiesWithCaps
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 * @param string                    $cap   Required capability.
	 *
	 * @return void
	 */
	public function test_permission_denied_error_has_403_status( string $ability_class, string $cap ): void {
		$GLOBALS['albert_test_caps'] = [];

		$ability = new $ability_class();
		$result  = $ability->check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 403, $data['status'] ?? null );
	}

	/**
	 * Denied WP_Error message mentions the capability name.
	 *
	 * @dataProvider provideAbilitiesWithCaps
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class.
	 * @param string                    $cap   Required capability.
	 *
	 * @return void
	 */
	public function test_permission_denied_message_mentions_capability( string $ability_class, string $cap ): void {
		$GLOBALS['albert_test_caps'] = [];

		$ability = new $ability_class();
		$result  = $ability->check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString(
			$cap,
			$result->get_error_message(),
			sprintf( 'Error message should mention the required capability "%s".', $cap )
		);
	}
}
