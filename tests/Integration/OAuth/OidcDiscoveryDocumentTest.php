<?php
/**
 * Integration tests for the `openid-configuration` copy of the server metadata.
 *
 * On hosts that answer the root `/.well-known/` themselves (SiteGround), Claude
 * Code falls back to this document and validates it as OpenID Connect metadata.
 * It refused the document while two OIDC-required fields were missing, so the
 * connection stopped before the approval screen.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth;

use Albert\Tests\TestCase;
use WP_REST_Request;

/**
 * OIDC discovery document tests.
 *
 * @covers \Albert\OAuth\ServerMetadata
 */
class OidcDiscoveryDocumentTest extends TestCase {

	/**
	 * Fields OpenID Connect Discovery 1.0 §3 marks REQUIRED.
	 *
	 * @var string[]
	 */
	private const OIDC_REQUIRED = [
		'issuer',
		'authorization_endpoint',
		'token_endpoint',
		'jwks_uri',
		'response_types_supported',
		'subject_types_supported',
		'id_token_signing_alg_values_supported',
	];

	/**
	 * Boot the REST server so the routes are registered.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Fetch a discovery document through the REST server.
	 *
	 * @param string $suffix The `.well-known` suffix.
	 *
	 * @return array<string, mixed>
	 */
	private function fetch( string $suffix ): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/albert/v1/oauth/.well-known/' . $suffix ) );

		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	/**
	 * Every OIDC-required field is present and non-empty.
	 *
	 * @return void
	 */
	public function test_openid_configuration_carries_every_oidc_required_field(): void {
		$document = $this->fetch( 'openid-configuration' );

		foreach ( self::OIDC_REQUIRED as $field ) {
			$this->assertArrayHasKey( $field, $document, $field );
			$this->assertNotEmpty( $document[ $field ], $field );
		}
	}

	/**
	 * The advertised algorithm is the one the published key carries.
	 *
	 * @return void
	 */
	public function test_signing_algorithm_matches_the_published_key(): void {
		$document = $this->fetch( 'openid-configuration' );
		$jwks     = rest_do_request( new WP_REST_Request( 'GET', '/albert/v1/oauth/jwks' ) )->get_data();

		foreach ( $jwks['keys'] as $key ) {
			$this->assertContains( $key['alg'], $document['id_token_signing_alg_values_supported'] );
		}
	}

	/**
	 * Both suffixes serve the same document.
	 *
	 * @return void
	 */
	public function test_both_suffixes_serve_the_same_document(): void {
		$this->assertSame( $this->fetch( 'openid-configuration' ), $this->fetch( 'oauth-authorization-server' ) );
	}
}
