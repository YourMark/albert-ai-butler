<?php
/**
 * Unit tests for AbilitiesController — the REST endpoints that toggle abilities.
 *
 * Focuses on the guard that protects the MCP adapter's own tools: they can never
 * be disabled, so the single-ability route rejects them and the bulk route drops
 * them.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Admin\Rest;

require_once dirname( __DIR__, 2 ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/stubs/WP_Ability.php';

use Albert\Admin\Rest\AbilitiesController;
use Albert\Core\AbilitiesState;
use Albert\OAuth\Server\ConnectionContext;
use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;
use WP_REST_Request;

/**
 * AbilitiesController REST endpoint tests.
 *
 * @covers \Albert\Admin\Rest\AbilitiesController
 */
class AbilitiesControllerTest extends TestCase {

	/**
	 * Register a protected tool and a normal ability as test doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_options']   = [];
		$GLOBALS['albert_test_hooks']     = [];
		$GLOBALS['albert_test_abilities'] = [
			new WP_Ability( 'mcp-adapter/execute-ability' ),
			new WP_Ability( 'albert/find-posts' ),
		];
	}

	/**
	 * Clear the request state the management-context tests set.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'], $_GET['rest_route'], $_POST['rest_route'], $GLOBALS['wp'], $GLOBALS['albert_test_caps'] );
		ConnectionContext::reset();
		parent::tearDown();
	}

	/**
	 * Whether the controller opts a request to this URI into the management context.
	 *
	 * @param string $uri Request URI.
	 *
	 * @return bool
	 */
	private function is_management_context( string $uri ): bool {
		$_SERVER['REQUEST_URI'] = $uri;

		return ( new AbilitiesController() )->filter_management_context( false );
	}

	/**
	 * Requests to the abilities routes by an administrator are a management context.
	 *
	 * @return void
	 */
	public function test_abilities_route_is_a_management_context(): void {
		$GLOBALS['albert_test_caps'] = [ 'manage_options' ];

		$this->assertTrue( $this->is_management_context( '/wp-json/albert/v1/abilities' ) );
		$this->assertTrue( $this->is_management_context( '/wp-json/albert/v1/abilities/bulk?x=1' ) );
	}

	/**
	 * A rest_route parameter decides the route, whatever the path says.
	 *
	 * @return void
	 */
	public function test_rest_route_parameter_overrides_the_path(): void {
		$GLOBALS['albert_test_caps'] = [ 'manage_options' ];
		$_GET['rest_route']          = '/albert/v1/mcp';

		$this->assertFalse( $this->is_management_context( '/wp-json/albert/v1/abilities' ) );

		unset( $_GET['rest_route'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stands in for the parsed request.
		$GLOBALS['wp']                           = new \WP();
		$GLOBALS['wp']->query_vars['rest_route'] = '/albert/v1/mcp';

		$this->assertFalse( $this->is_management_context( '/wp-json/albert/v1/abilities' ) );
	}

	/**
	 * A path that merely contains the route is not a request for it.
	 *
	 * @return void
	 */
	public function test_path_containing_the_route_elsewhere_is_not_a_management_context(): void {
		$GLOBALS['albert_test_caps'] = [ 'manage_options' ];

		$this->assertFalse( $this->is_management_context( '/x/albert/v1/abilities' ) );
		$this->assertFalse( $this->is_management_context( '/wp-json/albert/v1/mcp/albert/v1/abilities' ) );
	}

	/**
	 * Without manage_options, or over a connection, it is never a management context.
	 *
	 * @return void
	 */
	public function test_requires_an_administrator_outside_a_connection(): void {
		$GLOBALS['albert_test_caps'] = [];
		$this->assertFalse( $this->is_management_context( '/wp-json/albert/v1/abilities' ) );

		$GLOBALS['albert_test_caps'] = [ 'manage_options' ];
		ConnectionContext::set( 'client-1' );
		$this->assertFalse( $this->is_management_context( '/wp-json/albert/v1/abilities' ) );
	}

	/**
	 * The disabled-abilities option after the request, as stored by the stubs.
	 *
	 * @return array<int, string>
	 */
	private function disabled_option(): array {
		return (array) ( $GLOBALS['albert_test_options'][ AbilitiesState::OPTION ] ?? [] );
	}

	/**
	 * Toggling a protected tool via the single-ability route is rejected (403).
	 *
	 * @return void
	 */
	public function test_update_ability_rejects_a_protected_tool(): void {
		$request              = new WP_REST_Request( 'POST', '/albert/v1/abilities/mcp-adapter/execute-ability' );
		$request['namespace'] = 'mcp-adapter';
		$request['name']      = 'execute-ability';
		$request['enabled']   = false;

		$result = ( new AbilitiesController() )->update_ability( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_ability_protected', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
		$this->assertNotContains( 'mcp-adapter/execute-ability', $this->disabled_option() );
	}

	/**
	 * A bulk disable drops protected tools and only acts on the rest.
	 *
	 * @return void
	 */
	public function test_bulk_update_excludes_protected_tools(): void {
		$request            = new WP_REST_Request( 'POST', '/albert/v1/abilities/bulk' );
		$request['ids']     = [ 'mcp-adapter/execute-ability', 'albert/find-posts' ];
		$request['enabled'] = false;

		$response = ( new AbilitiesController() )->bulk_update( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['updated'] );
		$this->assertNotContains( 'mcp-adapter/execute-ability', $this->disabled_option() );
	}
}
