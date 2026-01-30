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
use Albert\OAuth\Entities\UserEntity;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\Server\AuthorizationServerFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use WP_Error;
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
	 * @since 1.0.0
	 * @var string
	 */
	const NAMESPACE = 'albert-ai-butler/v1';

	/**
	 * Transient prefix for authorization requests.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const AUTH_REQUEST_TRANSIENT_PREFIX = 'albert_oauth_auth_request_';

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
			self::NAMESPACE,
			'/oauth/metadata',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_authorization_server_metadata' ],
				'permission_callback' => '__return_true',
			]
		);

		// OAuth Protected Resource Metadata (alternative to .well-known).
		register_rest_route(
			self::NAMESPACE,
			'/oauth/resource',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_protected_resource_metadata' ],
				'permission_callback' => '__return_true',
			]
		);

		// Authorization endpoint - initiates the OAuth flow.
		register_rest_route(
			self::NAMESPACE,
			'/oauth/authorize',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'handle_authorize' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'response_type' => [
							'required'    => true,
							'type'        => 'string',
							'enum'        => [ 'code' ],
							'description' => 'Must be "code" for authorization code flow.',
						],
						'client_id'     => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'The client identifier.',
						],
						'redirect_uri'  => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
							'description'       => 'The redirect URI.',
						],
						'state'         => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'State parameter for CSRF protection.',
						],
						'scope'         => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => 'Requested scopes.',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'handle_authorize_submit' ],
					'permission_callback' => [ $this, 'authorize_submit_permission' ],
					'args'                => [
						'auth_request_id' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'approve'         => [
							'required' => true,
							'type'     => 'string',
							'enum'     => [ 'yes', 'no' ],
						],
					],
				],
			]
		);

		// Token endpoint - exchanges code for tokens.
		register_rest_route(
			self::NAMESPACE,
			'/oauth/token',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_token' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle authorization request (GET).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error The response.
	 * @since 1.0.0
	 */
	public function handle_authorize( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// User must be logged in.
		if ( ! is_user_logged_in() ) {
			// Redirect to login with return URL.
			$login_url = wp_login_url( $this->get_current_url( $request ) );

			return new WP_REST_Response(
				[
					'error'       => 'login_required',
					'message'     => __( 'Please log in to authorize this application.', 'albert-ai-butler' ),
					'redirect_to' => $login_url,
				],
				401
			);
		}

		// Validate client.
		$client_id    = $request->get_param( 'client_id' );
		$redirect_uri = $request->get_param( 'redirect_uri' );

		$client_repo = new ClientRepository();
		$client      = $client_repo->getClientEntity( $client_id );

		if ( ! $client ) {
			return new WP_Error(
				'invalid_client',
				__( 'Unknown client.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		// Validate redirect URI.
		// Wildcard '*' allows any redirect URI (for AI assistants like Claude, ChatGPT, Gemini).
		$allowed_uris = $client->getRedirectUri();
		if ( is_string( $allowed_uris ) ) {
			$allowed_uris = [ $allowed_uris ];
		}

		$is_wildcard = in_array( '*', $allowed_uris, true );
		if ( ! $is_wildcard && ! in_array( $redirect_uri, $allowed_uris, true ) ) {
			return new WP_Error(
				'invalid_redirect_uri',
				__( 'Invalid redirect URI.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		// Store authorization request in transient.
		$auth_request_id   = wp_generate_uuid4();
		$auth_request_data = [
			'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'state'         => $request->get_param( 'state' ),
			'scope'         => $request->get_param( 'scope' ) ?? 'default',
			'response_type' => $request->get_param( 'response_type' ),
			'user_id'       => get_current_user_id(),
			'created_at'    => time(),
		];

		set_transient(
			self::AUTH_REQUEST_TRANSIENT_PREFIX . $auth_request_id,
			$auth_request_data,
			600 // 10 minutes.
		);

		// Return authorization form data.
		return new WP_REST_Response(
			[
				'auth_request_id' => $auth_request_id,
				'client'          => [
					'name' => $client->getName(),
				],
				'user'            => [
					'display_name' => wp_get_current_user()->display_name,
				],
				'scope'           => $auth_request_data['scope'],
				'approve_url'     => rest_url( self::NAMESPACE . '/oauth/authorize' ),
			],
			200
		);
	}

	/**
	 * Handle authorization form submission (POST).
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error The response.
	 * @since 1.0.0
	 */
	public function handle_authorize_submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$auth_request_id = $request->get_param( 'auth_request_id' );
		$approve         = $request->get_param( 'approve' );

		// Get stored auth request.
		$auth_request_data = get_transient( self::AUTH_REQUEST_TRANSIENT_PREFIX . $auth_request_id );

		if ( ! $auth_request_data ) {
			return new WP_Error(
				'invalid_request',
				__( 'Authorization request expired or invalid.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		// Delete transient.
		delete_transient( self::AUTH_REQUEST_TRANSIENT_PREFIX . $auth_request_id );

		// Verify user.
		if ( get_current_user_id() !== (int) $auth_request_data['user_id'] ) {
			return new WP_Error(
				'user_mismatch',
				__( 'User mismatch.', 'albert-ai-butler' ),
				[ 'status' => 403 ]
			);
		}

		$redirect_uri = $auth_request_data['redirect_uri'];
		$state        = $auth_request_data['state'];

		// User denied.
		if ( $approve !== 'yes' ) {
			$redirect_params = [
				'error'             => 'access_denied',
				'error_description' => 'The user denied the request.',
			];
			if ( $state ) {
				$redirect_params['state'] = $state;
			}

			return new WP_REST_Response(
				[
					'redirect_to' => add_query_arg( $redirect_params, $redirect_uri ),
				],
				200
			);
		}

		// User approved - generate authorization code.
		try {
			$server      = AuthorizationServerFactory::create();
			$psr_request = Psr7Bridge::to_psr7_request( $request );

			// Build a proper authorization request.
			$query_params = [
				'response_type' => 'code',
				'client_id'     => $auth_request_data['client_id'],
				'redirect_uri'  => $auth_request_data['redirect_uri'],
				'scope'         => $auth_request_data['scope'],
				'state'         => $auth_request_data['state'] ?? '',
			];

			$psr_request = $psr_request->withQueryParams( $query_params );

			// Validate the authorization request.
			$auth_request = $server->validateAuthorizationRequest( $psr_request );
			$auth_request->setUser( new UserEntity( get_current_user_id() ) );
			$auth_request->setAuthorizationApproved( true );

			// Complete the authorization request.
			$psr_response = $server->completeAuthorizationRequest(
				$auth_request,
				Psr7Bridge::create_response()
			);

			// Get redirect location from response.
			$location = $psr_response->getHeader( 'Location' );

			return new WP_REST_Response(
				[
					'redirect_to' => $location[0] ?? $redirect_uri,
				],
				200
			);
		} catch ( OAuthServerException $e ) {
			$redirect_params = [
				'error'             => $e->getErrorType(),
				'error_description' => $e->getMessage(),
			];
			if ( $state ) {
				$redirect_params['state'] = $state;
			}

			return new WP_REST_Response(
				[
					'redirect_to' => add_query_arg( $redirect_params, $redirect_uri ),
				],
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'server_error',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
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
	 * Permission callback for authorize submit.
	 *
	 * @return bool|WP_Error Whether the request is permitted.
	 * @since 1.0.0
	 */
	public function authorize_submit_permission(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in.', 'albert-ai-butler' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Get the current request URL.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return string The current URL.
	 * @since 1.0.0
	 */
	private function get_current_url( WP_REST_Request $request ): string {
		$url = rest_url( $request->get_route() );

		$query_params = $request->get_query_params();
		if ( ! empty( $query_params ) ) {
			$url = add_query_arg( $query_params, $url );
		}

		return $url;
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
		$base_url = $this->get_base_url();

		$metadata = [
			'issuer'                                => $base_url,
			'authorization_endpoint'                => $base_url . '/oauth/authorize',
			'token_endpoint'                        => $this->get_rest_url( 'albert/v1/oauth/token' ),
			'registration_endpoint'                 => $this->get_rest_url( 'albert/v1/oauth/register' ),
			'response_types_supported'              => [ 'code' ],
			'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
			'token_endpoint_auth_methods_supported' => [ 'client_secret_post', 'client_secret_basic' ],
			'code_challenge_methods_supported'      => [ 'S256' ],
			'scopes_supported'                      => [ 'default' ],
		];

		$response = new WP_REST_Response( $metadata, 200 );
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
		$base_url = $this->get_base_url();

		$metadata = [
			'resource'              => $this->get_rest_url( 'albert/v1/mcp' ),
			'authorization_servers' => [ $this->get_rest_url( 'albert/v1/oauth/metadata' ) ],
			'scopes_supported'      => [ 'default' ],
		];

		$response = new WP_REST_Response( $metadata, 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600' );

		return $response;
	}

	/**
	 * Get the base URL for OAuth endpoints.
	 *
	 * Uses the external URL setting if configured and developer mode is enabled,
	 * otherwise falls back to home_url().
	 *
	 * @return string The base URL.
	 * @since 1.0.0
	 */
	private function get_base_url(): string {
		/**
		 * Filter to enable developer settings like External URL.
		 *
		 * @param bool $show Whether to show developer settings. Default false.
		 *
		 * @since 1.0.0
		 */
		$show_developer_settings = apply_filters( 'albert/developer_mode', false );

		if ( $show_developer_settings ) {
			$external_url = get_option( 'albert_external_url', '' );

			if ( ! empty( $external_url ) ) {
				return $external_url;
			}
		}

		return home_url();
	}

	/**
	 * Get a REST URL using the current base URL.
	 *
	 * @param string $path The REST route path.
	 *
	 * @return string The full REST URL.
	 * @since 1.0.0
	 */
	private function get_rest_url( string $path ): string {
		$base_url = $this->get_base_url();
		return $base_url . '/wp-json/' . ltrim( $path, '/' );
	}
}
