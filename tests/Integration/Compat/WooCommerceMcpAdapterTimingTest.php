<?php
/**
 * Regression test for the documented mcp-adapter / WooCommerce timing bug.
 *
 * See the root CLAUDE.md "WooCommerce mcp-adapter timing bug" section for
 * the full story. Symptom: _doing_it_wrong notices on Albert admin pages
 * when WooCommerce is active, for mcp-adapter/discover-abilities,
 * mcp-adapter/get-ability-info and mcp-adapter/execute-ability.
 *
 * The fix lives in Plugin::init() — McpAdapter::instance() is only called
 * when ! is_admin(). This test exercises the path WC's REST preloading
 * takes (wp_get_abilities → rest_api_init → adapter init) and relies on
 * WP_UnitTestCase's tearDown to fail the test if any unexpected
 * _doing_it_wrong notice fires.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Compat;

use Albert\Tests\TestCase;

/**
 * WooCommerce mcp-adapter timing-bug regression.
 *
 * @covers \Albert\Core\Plugin::init
 */
class WooCommerceMcpAdapterTimingTest extends TestCase {

	/**
	 * Skip the entire class when WooCommerce is not loaded.
	 *
	 * The standard integration job has no WC; the with-WooCommerce job
	 * does. This guard keeps the standard job green without losing
	 * coverage where it matters.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active in this CI job.' );
		}
	}

	/**
	 * Listing abilities does not trigger _doing_it_wrong from mcp-adapter.
	 *
	 * Calling wp_get_abilities() fires wp_abilities_api_init. With the
	 * timing bug present, a follow-up rest_api_init causes the mcp-adapter
	 * to attempt registering its three default tools after the action
	 * already fired, producing _doing_it_wrong notices. WP_UnitTestCase's
	 * tearDown catches any unexpected notice and fails the test.
	 *
	 * @return void
	 */
	public function test_wp_get_abilities_does_not_trigger_mcp_adapter_warnings(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'wp_get_abilities not available.' );
		}

		$abilities = wp_get_abilities();

		$this->assertNotEmpty(
			$abilities,
			'wp_get_abilities should return Albert + WC abilities when both are active.'
		);

		// Force the rest_api_init phase that previously triggered the
		// adapter's late tool registration. With the fix in place this is
		// a no-op; without it, _doing_it_wrong fires and tearDown fails
		// the test.
		do_action( 'rest_api_init' );
	}

	/**
	 * The WooCommerce abilities are registered when WC is active.
	 *
	 * Confirms the WC-enabled CI job is genuinely exercising the Woo
	 * abilities — otherwise this test would silently skip and the WC
	 * coverage gap would persist invisibly.
	 *
	 * @return void
	 */
	public function test_woo_abilities_register_when_woocommerce_is_active(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'wp_get_ability not available.' );
		}

		$expected = [
			'albert/woo-find-products',
			'albert/woo-view-product',
			'albert/woo-find-orders',
			'albert/woo-view-order',
			'albert/woo-find-customers',
			'albert/woo-view-customer',
		];

		foreach ( $expected as $id ) {
			$this->assertNotNull(
				wp_get_ability( $id ),
				sprintf( 'WooCommerce ability %s should be registered when WC is active.', $id )
			);
		}
	}
}
