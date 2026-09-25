<?php
/**
 * OAuth REST API Controller
 *
 * @package Albert
 * @subpackage OAuth\Endpoints
 * @since      1.0.0
 */

namespace Albert\OAuth\Endpoints;

defined( 'ABSPATH' ) || exit;

use Exception;
use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;
use Albert\OAuth\Jwks;
use Albert\OAuth\Server\AuthorizationServerFactory;
use Albert\OAuth\ServerMetadata;
use League\OAuth2\Server\Exception\OAuthServerException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * OAuthController class
 *
 * Handles OAuth 2.0 REST API endpoints.
 *
 * @since 1.0.0
 */
class OAuthController implements Hookable {

	/**
	 * REST API namespace.
	 *
	 * @deprecated 1.0.1 Use {@see Plugin::rest_namespace()} instead.
	 * @since      1.0.0
	 * @var string
	 */
	const NAMESPACE = 'albert/v1';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_routes(): void {
		// OAuth Authorization Server Metadata (alternative to .well-known).
		register_rest_route(
			Plugin::rest_namespace(),
			'/oauth/metadata',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_authorization_server_metadata' ],
				'permission_callback' => '__return_true',
			]
		);

		// The same metadata at the issuer's mid-path `.well-known`, which hosts that
		// intercept a root `/.well-known/` leave alone (see ServerMetadata::issuer_url()).
		// Both suffix names are served so a client reaches it whichever it appends.
		foreach ( [ 'openid-configuration', 'oauth-authorization-server' ] as $suffix ) {
			register_rest_route(
				Plugin::rest_namespace(),
				'/oauth/\.well-known/' . $suffix,
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'handle_authorization_server_metadata' ],
					'permission_callback' => '__return_true',
				]
			);
		}

		// OAuth Protected Resource Metadata (alternative to .well-known).
		register_rest_route(
			Plugin::rest_namespace(),
			'/oauth/resource',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_protected_resource_metadata' ],
				'permission_callback' => '__return_true',
			]
		);

		// JSON Web Key Set - the public half of the token signing key. Named as
		// `jwks_uri` in the authorization server metadata (see ServerMetadata).
		register_rest_route(
			Plugin::rest_namespace(),
			'/oauth/jwks',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_jwks' ],
				'permission_callback' => '__return_true',
			]
		);

		// Token endpoint - exchanges code for tokens.
		register_rest_route(
			Plugin::rest_namespace(),
			'/oauth/token',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_token' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle token request (POST).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return WP_REST_Response The response.
	 * @since 1.0.0
	 */
	public function handle_token( WP_REST_Request $request ): WP_REST_Response {
		try {
			$server       = AuthorizationServerFactory::create();
			$psr_request  = Psr7Bridge::to_psr7_request( $request );
			$psr_response = Psr7Bridge::create_response();

			$psr_response = $server->respondToAccessTokenRequest( $psr_request, $psr_response );

			return Psr7Bridge::to_wp_response( $psr_response );
		} catch ( OAuthServerException $e ) {
			$this->log_token_request_failure( $e );

			$response = Psr7Bridge::create_response();
			$response = $e->generateHttpResponse( $response );

			return Psr7Bridge::to_wp_response( $response );
		} catch ( Exception $e ) {
			return new WP_REST_Response(
				[
					'error'             => 'server_error',
					'error_description' => $e->getMessage(),
				],
				500
			);
		}
	}

	/**
	 * Log the specific reason a token request was rejected.
	 *
	 * The client only ever sees the generic OAuth error code (e.g. `invalid_grant`
	 * covers a decrypt failure, an expired code, a revoked code, a client ID
	 * mismatch, a redirect URI mismatch, and a PKCE verifier mismatch alike, per
	 * RFC 6749 §5.2's intentional collapsing of failure modes). Nothing server-side
	 * previously recorded which of those it was, which turns any real failure into
	 * a guessing game. This carries the library's specific message and hint out
	 * so the true cause is diagnosable without exposing it to the client.
	 *
	 * @param OAuthServerException $e The rejection.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function log_token_request_failure( OAuthServerException $e ): void {
		/**
		 * Fires when the token endpoint rejects a request.
		 *
		 * @since 1.4.0
		 *
		 * @param string               $error_type The OAuth error type (e.g. `invalid_grant`).
		 * @param string               $message    The library's specific failure message.
		 * @param string|null          $hint       An optional hint from the library.
		 * @param OAuthServerException $exception  The underlying exception.
		 */
		do_action( 'albert/oauth/token_request_failed', $e->getErrorType(), $e->getMessage(), $e->getHint(), $e );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic aid, gated on WP_DEBUG; the client only ever sees the generic OAuth error code.
			error_log(
				sprintf(
					'[Albert] Token request rejected (%s): %s%s',
					$e->getErrorType(),
					$e->getMessage(),
					$e->getHint() !== null ? ' — ' . $e->getHint() : ''
				)
			);
		}
	}

	/**
	 * Handle OAuth Authorization Server Metadata request.
	 *
	 * Returns RFC 8414 metadata for OAuth clients to discover endpoints.
	 *
	 * @return WP_REST_Response The metadata response.
	 * @since 1.0.0
	 */
	public function handle_authorization_server_metadata(): WP_REST_Response {
		$response = new WP_REST_Response( ServerMetadata::authorization_server(), 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600' );

		return $response;
	}

	/**
	 * Handle OAuth Protected Resource Metadata request.
	 *
	 * Returns RFC 9728 metadata that tells MCP clients where to find the authorization server.
	 *
	 * @return WP_REST_Response The metadata response.
	 * @since 1.0.0
	 */
	public function handle_protected_resource_metadata(): WP_REST_Response {
		$response = new WP_REST_Response( ServerMetadata::protected_resource(), 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600' );

		return $response;
	}

	/**
	 * Handle JSON Web Key Set request.
	 *
	 * Returns the RFC 7517 key set published as `jwks_uri` in the metadata.
	 *
	 * @return WP_REST_Response The key set response.
	 * @since 1.4.2
	 */
	public function handle_jwks(): WP_REST_Response {
		$response = new WP_REST_Response( Jwks::document(), 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600' );

		return $response;
	}
}
