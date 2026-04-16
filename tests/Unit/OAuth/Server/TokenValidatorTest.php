<?php
/**
 * Unit tests for TokenValidator — bearer-token header parsing.
 *
 * The full validate_request / permission_callback / require_scopes flows
 * live in tests/Integration/OAuth/Server/TokenValidatorTest.php because
 * they require the real league/oauth2-server resource server (RSA keys,
 * PSR-7 bridge, WP_User lookup). Here we cover only the purely syntactic
 * Bearer-token extractor, which has no WP or OAuth dependencies.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth\Server;

require_once dirname( __DIR__, 2 ) . '/stubs/wordpress.php';

use Albert\OAuth\Server\TokenValidator;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * TokenValidator header-parsing tests.
 *
 * @covers \Albert\OAuth\Server\TokenValidator::get_bearer_token
 */
class TokenValidatorTest extends TestCase {

	/**
	 * Build a request with an optional Authorization header.
	 *
	 * @param string|null $header Authorization header value, or null to omit.
	 *
	 * @return WP_REST_Request
	 */
	private function request_with_auth( ?string $header ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/albert/v1/anything' );
		if ( $header !== null ) {
			$request->set_header( 'authorization', $header );
		}
		return $request;
	}

	/**
	 * A valid `Bearer <token>` header yields just the token.
	 *
	 * @return void
	 */
	public function test_extracts_token_from_bearer_header(): void {
		$request = $this->request_with_auth( 'Bearer abc.def.ghi' );

		$this->assertSame( 'abc.def.ghi', TokenValidator::get_bearer_token( $request ) );
	}

	/**
	 * Scheme matching is case-insensitive per RFC 6750.
	 *
	 * @return void
	 */
	public function test_scheme_match_is_case_insensitive(): void {
		$this->assertSame(
			'token',
			TokenValidator::get_bearer_token( $this->request_with_auth( 'bearer token' ) )
		);
		$this->assertSame(
			'token',
			TokenValidator::get_bearer_token( $this->request_with_auth( 'BEARER token' ) )
		);
		$this->assertSame(
			'token',
			TokenValidator::get_bearer_token( $this->request_with_auth( 'BeArEr token' ) )
		);
	}

	/**
	 * Multiple whitespace characters between scheme and token are tolerated.
	 *
	 * @return void
	 */
	public function test_accepts_extra_whitespace_between_scheme_and_token(): void {
		$this->assertSame(
			'token',
			TokenValidator::get_bearer_token( $this->request_with_auth( "Bearer\ttoken" ) )
		);
		$this->assertSame(
			'token',
			TokenValidator::get_bearer_token( $this->request_with_auth( 'Bearer   token' ) )
		);
	}

	/**
	 * No Authorization header at all returns null.
	 *
	 * @return void
	 */
	public function test_returns_null_when_header_missing(): void {
		$this->assertNull( TokenValidator::get_bearer_token( $this->request_with_auth( null ) ) );
	}

	/**
	 * An empty Authorization header returns null.
	 *
	 * @return void
	 */
	public function test_returns_null_when_header_empty(): void {
		$this->assertNull( TokenValidator::get_bearer_token( $this->request_with_auth( '' ) ) );
	}

	/**
	 * A non-Bearer scheme (Basic, Digest, etc.) returns null.
	 *
	 * @return void
	 */
	public function test_returns_null_for_non_bearer_scheme(): void {
		$this->assertNull(
			TokenValidator::get_bearer_token( $this->request_with_auth( 'Basic dXNlcjpwYXNz' ) )
		);
		$this->assertNull(
			TokenValidator::get_bearer_token( $this->request_with_auth( 'Digest username="x"' ) )
		);
	}

	/**
	 * A naked token without the Bearer scheme is not extracted.
	 *
	 * @return void
	 */
	public function test_returns_null_for_naked_token(): void {
		$this->assertNull(
			TokenValidator::get_bearer_token( $this->request_with_auth( 'abc.def.ghi' ) )
		);
	}
}
