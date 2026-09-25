<?php
/**
 * Unit tests for the RFC 8414 Authorization Server Metadata document.
 *
 * Two endpoints serve this document. Until 1.4.0 each built its own copy, and
 * the copies drifted: `none` was added to the REST route only, leaving the
 * `.well-known` path — the one RFC 8414 specifies and the one `mcp-remote`
 * fetches — advertising two auth methods while registration kept issuing a
 * third. These tests hold both routes to one document.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\OAuth\Endpoints\OAuthController;
use Albert\OAuth\Endpoints\OAuthDiscovery;
use Albert\OAuth\ServerMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Authorization Server Metadata tests.
 *
 * @covers \Albert\OAuth\ServerMetadata
 */
class ServerMetadataTest extends TestCase {

	/**
	 * Reset shared stub state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks'] = [];
	}

	/**
	 * Drop any permalink stub a test installed.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_rewrite'] );

		parent::tearDown();
	}

	/**
	 * `none` is declared, and the confidential-client methods are still there.
	 *
	 * Every loopback/native client — RFC 8252, which is every local MCP bridge
	 * — is registered as a public client using `none`. Metadata that omits it
	 * contradicts what registration issues, and a conforming client believes
	 * the metadata.
	 *
	 * @return void
	 */
	public function test_declares_none_alongside_the_confidential_methods(): void {
		$methods = ServerMetadata::authorization_server()['token_endpoint_auth_methods_supported'];

		$this->assertContains( 'none', $methods );
		$this->assertContains( 'client_secret_post', $methods );
		$this->assertContains( 'client_secret_basic', $methods );
	}

	/**
	 * The `.well-known` path serves `none` too.
	 *
	 * This is the assertion that was missing. The route already covered by
	 * OAuthControllerTest is `/wp-json/albert/v1/oauth/metadata`, which is not
	 * the path RFC 8414 specifies and not the one clients discover.
	 *
	 * @return void
	 */
	public function test_well_known_route_declares_none(): void {
		$metadata = ( new OAuthDiscovery() )->get_authorization_server_metadata();

		$this->assertContains( 'none', $metadata['token_endpoint_auth_methods_supported'] );
	}

	/**
	 * Both routes serve byte-identical documents.
	 *
	 * The regression guard proper. A future edit to one endpoint cannot make
	 * the two disagree without failing here, which is what happened when `none`
	 * was added to one of them.
	 *
	 * @return void
	 */
	public function test_both_routes_serve_the_same_document(): void {
		$well_known = ( new OAuthDiscovery() )->get_authorization_server_metadata();
		$rest       = ( new OAuthController() )->handle_authorization_server_metadata()->get_data();

		$this->assertSame( $well_known, $rest );
	}

	/**
	 * The document carries the fields RFC 8414 requires.
	 *
	 * @return void
	 */
	public function test_declares_the_required_rfc_8414_fields(): void {
		$metadata = ServerMetadata::authorization_server();

		foreach ( [ 'issuer', 'authorization_endpoint', 'token_endpoint', 'response_types_supported' ] as $field ) {
			$this->assertArrayHasKey( $field, $metadata );
			$this->assertNotEmpty( $metadata[ $field ] );
		}
	}

	/**
	 * REST URLs are built on the OAuth base URL, not on `rest_url()`.
	 *
	 * An MCP client told to use an external URL must be handed endpoints on
	 * that same host; resolving against `home_url()` would send it elsewhere.
	 *
	 * @return void
	 */
	public function test_rest_urls_are_built_on_the_oauth_base_url(): void {
		$base     = ServerMetadata::base_url();
		$metadata = ServerMetadata::authorization_server();

		$this->assertStringStartsWith( $base . '/wp-json/', $metadata['token_endpoint'] );
		$this->assertStringStartsWith( $base . '/wp-json/', $metadata['registration_endpoint'] );
		$this->assertSame( ServerMetadata::issuer_url(), $metadata['issuer'] );
		$this->assertSame( $base . '/oauth/authorize', $metadata['authorization_endpoint'] );
	}

	/**
	 * The issuer is a path, so a client's discovery falls through to a mid-path
	 * `.well-known` URL that hosts intercepting a root `/.well-known/` leave alone.
	 *
	 * @return void
	 */
	public function test_issuer_is_a_path_not_the_bare_domain(): void {
		$this->assertSame( ServerMetadata::base_url() . '/wp-json/albert/v1/oauth', ServerMetadata::issuer_url() );
	}

	// ─── Protected Resource Metadata (RFC 9728) ─────────────────────

	/**
	 * `authorization_servers` lists the issuer, not the metadata document URL.
	 *
	 * The regression this guards: the REST route once listed `…/oauth/metadata`
	 * here, where RFC 9728 §7.6 requires an issuer identifier. A conforming client
	 * applies the RFC 8414 transformation to the entry, so a document URL sent it
	 * somewhere that does not resolve and whose `issuer` would not match. The value
	 * must be exactly the metadata document's own `issuer`.
	 *
	 * @return void
	 */
	public function test_protected_resource_lists_the_issuer_as_authorization_server(): void {
		$issuer = ServerMetadata::authorization_server()['issuer'];

		$this->assertSame( [ $issuer ], ServerMetadata::protected_resource()['authorization_servers'] );
	}

	/**
	 * The protected resource is the MCP endpoint itself.
	 *
	 * @return void
	 */
	public function test_protected_resource_points_at_the_mcp_endpoint(): void {
		$metadata = ServerMetadata::protected_resource();

		$this->assertSame( ServerMetadata::base_url() . '/wp-json/albert/v1/mcp', $metadata['resource'] );
	}

	/**
	 * Both protected-resource routes serve one document.
	 *
	 * The same regression guard the authorization-server document has: the
	 * `.well-known` path and the convenience REST route drifted on
	 * `authorization_servers`, and a future edit to one cannot make them
	 * disagree again without failing here.
	 *
	 * @return void
	 */
	public function test_both_protected_resource_routes_serve_the_same_document(): void {
		$well_known = ( new OAuthDiscovery() )->get_protected_resource_metadata();
		$rest       = ( new OAuthController() )->handle_protected_resource_metadata()->get_data();

		$this->assertSame( $well_known, $rest );
		$this->assertSame( ServerMetadata::protected_resource(), $well_known );
	}

	// ─── Permalink structure ────────────────────────────────────────

	/**
	 * Pretty permalinks produce exactly the URLs 1.4.1 advertised.
	 *
	 * A client already connected re-runs discovery against these; any change
	 * here re-authorises every existing connection.
	 *
	 * @return void
	 */
	public function test_pretty_permalinks_keep_the_existing_urls(): void {
		$this->use_permalinks( '/%postname%/' );

		$metadata = ServerMetadata::authorization_server();

		$this->assertSame( 'https://example.test/wp-json/albert/v1/oauth', $metadata['issuer'] );
		$this->assertSame( 'https://example.test/oauth/authorize', $metadata['authorization_endpoint'] );
		$this->assertSame( 'https://example.test/wp-json/albert/v1/oauth/token', $metadata['token_endpoint'] );
		$this->assertSame( 'https://example.test/wp-json/albert/v1/mcp', ServerMetadata::protected_resource()['resource'] );
	}

	/**
	 * Index permalinks route every advertised URL through `index.php`.
	 *
	 * Such sites usually have no rewrite rules, so a bare `/wp-json/` is the web
	 * server's 404 and discovery fails before it reaches WordPress.
	 *
	 * @return void
	 */
	public function test_index_permalinks_route_through_index_php(): void {
		$this->use_permalinks( '/index.php/%postname%/' );

		$metadata = ServerMetadata::authorization_server();
		$base     = 'https://example.test/index.php/';

		$this->assertSame( $base . 'wp-json/albert/v1/oauth', $metadata['issuer'] );
		$this->assertSame( $base . 'oauth/authorize', $metadata['authorization_endpoint'] );
		$this->assertSame( $base . 'wp-json/albert/v1/oauth/token', $metadata['token_endpoint'] );
		$this->assertSame( $base . 'wp-json/albert/v1/oauth/register', $metadata['registration_endpoint'] );
		$this->assertSame( $base . 'wp-json/albert/v1/mcp', ServerMetadata::protected_resource()['resource'] );
	}

	/**
	 * Install a `$wp_rewrite` for the given permalink structure.
	 *
	 * @param string $structure The permalink structure.
	 *
	 * @return void
	 */
	private function use_permalinks( string $structure ): void {
		$rewrite                      = new \WP_Rewrite();
		$rewrite->permalink_structure = $structure;

		$GLOBALS['wp_rewrite'] = $rewrite; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture for $wp_rewrite.
	}
}
