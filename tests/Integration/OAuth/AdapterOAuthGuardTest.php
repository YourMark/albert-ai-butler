<?php
/**
 * Integration tests for the guard against the MCP Adapter's OAuth server.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth;

use Albert\Tests\TestCase;

/**
 * The adapter's OAuth server stays off, and never claims Albert's route.
 *
 * Runs against the hook names of WordPress/mcp-adapter#437 as the adapter
 * would call them.
 *
 * @covers \Albert\OAuth\AdapterOAuthGuard
 */
class AdapterOAuthGuardTest extends TestCase {

	/**
	 * The main plugin file registers the guard, so it is in place before `plugins_loaded`.
	 *
	 * @return void
	 */
	public function test_the_adapter_server_is_off_by_default(): void {
		$this->assertFalse( apply_filters( 'oauth_server_enabled', true ) );
	}

	/**
	 * A site can let it run, through Albert's filter or the adapter's own.
	 *
	 * @return void
	 */
	public function test_a_site_can_turn_it_back_on(): void {
		add_filter( 'albert/oauth/disable_adapter_oauth_server', '__return_false' );
		$this->assertTrue( apply_filters( 'oauth_server_enabled', true ) );
		remove_filter( 'albert/oauth/disable_adapter_oauth_server', '__return_false' );

		add_filter( 'oauth_server_enabled', '__return_true' );
		$this->assertTrue( apply_filters( 'oauth_server_enabled', true ) );
	}

	/**
	 * Albert's MCP route is never one the adapter protects; others stay.
	 *
	 * @return void
	 */
	public function test_albert_routes_are_not_protected_by_the_adapter(): void {
		$routes = apply_filters( 'oauth_server_protected_resources', [ '/albert/v1/mcp', 'albert/v1/mcp/', '/mcp/mcp-adapter-default-server', '/albert-other/route' ] );

		$this->assertSame( [ '/mcp/mcp-adapter-default-server', '/albert-other/route' ], $routes );
	}
}
