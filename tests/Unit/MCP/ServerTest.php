<?php
/**
 * Unit tests for the MCP Server.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\MCP;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\MCP\Server;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Server unit tests.
 *
 * @covers \Albert\MCP\Server
 */
class ServerTest extends TestCase {

	/**
	 * The create_server() method is bound to the global `mcp_adapter_init`
	 * action, which is fired by every loaded MCP-adapter copy — including the
	 * unscoped `WP\MCP\…` one WooCommerce bundles. Handed a foreign adapter, it
	 * must bail without a TypeError (regression: a foreign adapter previously
	 * fatal'd the strict type hint) and without trying to build a server
	 * against it.
	 *
	 * @return void
	 */
	public function test_create_server_ignores_a_foreign_adapter(): void {
		// stdClass stands in for WooCommerce's unscoped WP\MCP\Core\McpAdapter:
		// not our Mozart-scoped instance, and has no create_server() method — so if
		// the guard failed, we'd get a TypeError or a call-to-undefined-method.
		$foreign = new \stdClass();

		( new Server() )->create_server( $foreign );

		// Reaching here means it returned early instead of fataling.
		$this->expectNotToPerformAssertions();
	}

	/**
	 * Invoke the private response_status().
	 *
	 * @param mixed $response A response value.
	 *
	 * @return int
	 */
	private function response_status( $response ): int {
		$method = new ReflectionMethod( Server::class, 'response_status' );
		$method->setAccessible( true );

		return $method->invoke( new Server(), $response );
	}

	// ─── response_status() ─────────────────────────────────────────

	/**
	 * Reads the status carried by a WP_Error's error data.
	 *
	 * @return void
	 */
	public function test_response_status_reads_wp_error_status(): void {
		$error = new WP_Error( 'oauth_invalid_token', 'nope', [ 'status' => 401 ] );

		$this->assertSame( 401, $this->response_status( $error ) );
	}

	/**
	 * A WP_Error without a 'status' key in its data falls back to 500 rather
	 * than being mistaken for success.
	 *
	 * @return void
	 */
	public function test_response_status_defaults_wp_error_without_status_to_500(): void {
		$error = new WP_Error( 'oauth_error', 'nope', [] );

		$this->assertSame( 500, $this->response_status( $error ) );
	}

	/**
	 * Reads the status off a WP_REST_Response.
	 *
	 * @return void
	 */
	public function test_response_status_reads_rest_response_status(): void {
		$response = new WP_REST_Response( [], 200 );

		$this->assertSame( 200, $this->response_status( $response ) );
	}

	/**
	 * Anything else (e.g. null, the common "no response yet" value) is treated
	 * as success rather than as a 401 — so a header is never sent by mistake.
	 *
	 * @return void
	 */
	public function test_response_status_defaults_unrecognised_value_to_200(): void {
		$this->assertSame( 200, $this->response_status( null ) );
	}

	// ─── build_challenge() ──────────────────────────────────────────

	/**
	 * Invoke the private build_challenge().
	 *
	 * @param bool $token_sent Whether a Bearer token was present.
	 *
	 * @return string
	 */
	private function build_challenge( bool $token_sent ): string {
		$method = new ReflectionMethod( Server::class, 'build_challenge' );
		$method->setAccessible( true );

		return $method->invoke( new Server(), 'https://example.test/wp-json/albert/v1/oauth/resource', $token_sent );
	}

	/**
	 * No token present: no error code — this is a missing-credentials case, not
	 * a rejection.
	 *
	 * @return void
	 */
	public function test_challenge_omits_error_code_when_no_token_was_sent(): void {
		$this->assertSame(
			'Bearer realm="MCP", resource_metadata="https://example.test/wp-json/albert/v1/oauth/resource"',
			$this->build_challenge( false )
		);
	}

	/**
	 * A token was sent but rejected (expired/invalid): RFC 6750 §3's
	 * error="invalid_token" is what previously never appeared, leaving a client
	 * mid-session with a bare 401 indistinguishable from never having authorised.
	 *
	 * @return void
	 */
	public function test_challenge_adds_invalid_token_error_when_a_token_was_sent(): void {
		$this->assertStringContainsString( 'error="invalid_token"', $this->build_challenge( true ) );
	}

	// ─── add_oauth_discovery_headers() ─────────────────────────────

	/**
	 * Requests to a route outside the MCP endpoint are left untouched.
	 *
	 * @return void
	 */
	public function test_discovery_headers_ignore_unrelated_routes(): void {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$error   = new WP_Error( 'rest_forbidden', 'nope', [ 'status' => 401 ] );

		$result = ( new Server() )->add_oauth_discovery_headers( $error, [], $request );

		$this->assertSame( $error, $result );
	}

	/**
	 * A successful (non-401) MCP response passes through untouched — the header
	 * is a 401-only affordance per RFC 6750 §3.
	 *
	 * @return void
	 */
	public function test_discovery_headers_ignore_non_401_responses(): void {
		$request  = new WP_REST_Request( 'GET', '/albert/v1/mcp' );
		$response = new WP_REST_Response( [ 'ok' => true ], 200 );

		$result = ( new Server() )->add_oauth_discovery_headers( $response, [], $request );

		$this->assertSame( $response, $result );
	}
}
