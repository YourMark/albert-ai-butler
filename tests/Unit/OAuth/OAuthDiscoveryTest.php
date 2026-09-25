<?php
/**
 * Unit tests for OAuthDiscovery .well-known routing.
 *
 * Covers the trailing-slash hardening: parse_request interception and
 * URL-based canonical redirect suppression. These two pieces make the
 * OAuth discovery endpoints reachable when the request arrives with a
 * trailing slash (as some hosts add at the edge) regardless of whether
 * the stored rewrite_rules option contains the optional-slash pattern.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\OAuth\Endpoints\OAuthDiscovery;
use PHPUnit\Framework\TestCase;
use WP;

/**
 * OAuthDiscovery routing tests.
 *
 * @covers \Albert\OAuth\Endpoints\OAuthDiscovery
 */
class OAuthDiscoveryTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var OAuthDiscovery
	 */
	private OAuthDiscovery $discovery;

	/**
	 * Reset transient state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->discovery        = new OAuthDiscovery();
		$_SERVER['REQUEST_URI'] = '/';
	}

	/**
	 * Clean up $_SERVER state.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	/**
	 * Run maybe_intercept_well_known against a request URI.
	 *
	 * @param string $request_uri Value to place in $_SERVER['REQUEST_URI'].
	 *
	 * @return WP The WP instance after the callback ran.
	 */
	private function intercept( string $request_uri ): WP {
		$_SERVER['REQUEST_URI'] = $request_uri;
		$wp                     = new WP();

		$this->discovery->maybe_intercept_well_known( $wp );

		return $wp;
	}

	// ─── maybe_intercept_well_known ───────────────────────────────────

	/**
	 * Path without a trailing slash sets the protected-resource query var.
	 *
	 * @return void
	 */
	public function test_intercepts_protected_resource_without_trailing_slash(): void {
		$wp = $this->intercept( '/.well-known/oauth-protected-resource' );

		$this->assertSame( 'protected-resource', $wp->query_vars['albert_oauth_discovery'] ?? null );
	}

	/**
	 * Path with a trailing slash also sets the query var (the bug fix).
	 *
	 * @return void
	 */
	public function test_intercepts_protected_resource_with_trailing_slash(): void {
		$wp = $this->intercept( '/.well-known/oauth-protected-resource/' );

		$this->assertSame( 'protected-resource', $wp->query_vars['albert_oauth_discovery'] ?? null );
	}

	/**
	 * Authorization-server path is intercepted with or without trailing slash.
	 *
	 * @return void
	 */
	public function test_intercepts_authorization_server_paths(): void {
		$without = $this->intercept( '/.well-known/oauth-authorization-server' );
		$with    = $this->intercept( '/.well-known/oauth-authorization-server/' );

		$this->assertSame( 'authorization-server', $without->query_vars['albert_oauth_discovery'] ?? null );
		$this->assertSame( 'authorization-server', $with->query_vars['albert_oauth_discovery'] ?? null );
	}

	/**
	 * The RFC 8414 §3.1 path-insertion form is intercepted.
	 *
	 * A strict client looks up `/.well-known/oauth-authorization-server/<issuer
	 * path>` rather than the append form Albert also serves. This is the row the
	 * user's conformance test measured as a 404.
	 *
	 * @return void
	 */
	public function test_intercepts_authorization_server_path_insertion_form(): void {
		$wp = $this->intercept( '/.well-known/oauth-authorization-server/wp-json/albert/v1/oauth' );

		$this->assertSame( 'authorization-server', $wp->query_vars['albert_oauth_discovery'] ?? null );
	}

	/**
	 * The RFC 9728 §3.1 path-insertion form is intercepted.
	 *
	 * @return void
	 */
	public function test_intercepts_protected_resource_path_insertion_form(): void {
		$wp = $this->intercept( '/.well-known/oauth-protected-resource/wp-json/albert/v1/mcp' );

		$this->assertSame( 'protected-resource', $wp->query_vars['albert_oauth_discovery'] ?? null );
	}

	/**
	 * A query string after the path does not block interception.
	 *
	 * @return void
	 */
	public function test_intercepts_when_query_string_present(): void {
		$wp = $this->intercept( '/.well-known/oauth-protected-resource/?foo=bar' );

		$this->assertSame( 'protected-resource', $wp->query_vars['albert_oauth_discovery'] ?? null );
	}

	/**
	 * Unrelated paths (including other .well-known/* endpoints) are left alone.
	 *
	 * @return void
	 */
	public function test_does_not_intercept_unrelated_paths(): void {
		$home         = $this->intercept( '/' );
		$post         = $this->intercept( '/sample-post/' );
		$other_well   = $this->intercept( '/.well-known/acme-challenge/abc' );
		$close_but_no = $this->intercept( '/.well-known/oauth-protected-resource-extra' );

		$this->assertArrayNotHasKey( 'albert_oauth_discovery', $home->query_vars );
		$this->assertArrayNotHasKey( 'albert_oauth_discovery', $post->query_vars );
		$this->assertArrayNotHasKey( 'albert_oauth_discovery', $other_well->query_vars );
		$this->assertArrayNotHasKey( 'albert_oauth_discovery', $close_but_no->query_vars );
	}

	/**
	 * Missing $_SERVER['REQUEST_URI'] (CLI etc.) does not raise a notice.
	 *
	 * @return void
	 */
	public function test_handles_missing_request_uri(): void {
		unset( $_SERVER['REQUEST_URI'] );
		$wp = new WP();

		$this->discovery->maybe_intercept_well_known( $wp );

		$this->assertArrayNotHasKey( 'albert_oauth_discovery', $wp->query_vars );
	}

	// ─── prevent_canonical_redirect ──────────────────────────────────

	/**
	 * Canonical redirect is suppressed for both endpoints, with or without slash.
	 *
	 * @return void
	 */
	public function test_canonical_redirect_suppressed_for_well_known_endpoints(): void {
		$urls = [
			'https://example.test/.well-known/oauth-protected-resource',
			'https://example.test/.well-known/oauth-protected-resource/',
			'https://example.test/.well-known/oauth-authorization-server',
			'https://example.test/.well-known/oauth-authorization-server/',
			'https://example.test/.well-known/oauth-authorization-server/wp-json/albert/v1/oauth',
			'https://example.test/.well-known/oauth-protected-resource/wp-json/albert/v1/mcp',
		];

		foreach ( $urls as $url ) {
			$result = $this->discovery->prevent_canonical_redirect(
				'https://example.test/redirected',
				$url
			);

			$this->assertFalse(
				$result,
				sprintf( 'Expected canonical redirect to be suppressed for %s', $url )
			);
		}
	}

	/**
	 * Unrelated URLs pass through the canonical redirect filter unchanged.
	 *
	 * @return void
	 */
	public function test_canonical_redirect_untouched_for_other_urls(): void {
		$redirect = 'https://example.test/redirected';

		$result = $this->discovery->prevent_canonical_redirect(
			$redirect,
			'https://example.test/some-post/'
		);

		$this->assertSame( $redirect, $result );
	}

	/**
	 * Canonical redirect is left alone for non-Albert .well-known paths.
	 *
	 * @return void
	 */
	public function test_canonical_redirect_untouched_for_other_well_known_paths(): void {
		$redirect = 'https://example.test/redirected';

		$result = $this->discovery->prevent_canonical_redirect(
			$redirect,
			'https://example.test/.well-known/acme-challenge/abc'
		);

		$this->assertSame( $redirect, $result );
	}
}
