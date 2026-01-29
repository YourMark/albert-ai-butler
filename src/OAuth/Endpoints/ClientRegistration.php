<?php
/**
 * OAuth Dynamic Client Registration (RFC 7591)
 *
 * @package Albert
 * @subpackage OAuth\Endpoints
 * @since      1.0.0
 */

namespace Albert\OAuth\Endpoints;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\OAuth\Repositories\ClientRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * ClientRegistration class
 *
 * Handles OAuth 2.0 Dynamic Client Registration (RFC 7591).
 * This allows MCP clients like Claude to automatically register themselves.
 *
 * @since 1.0.0
 */
class ClientRegistration implements Hookable {

	/**
	 * REST API namespace.
	 *
	 * @since 1.0.0
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
		// Dynamic Client Registration endpoint.
		register_rest_route(
			self::NAMESPACE,
			'/oauth/register',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_registration' ],
				'permission_callback' => '__return_true', // Public endpoint per RFC 7591.
			]
		);
	}

	/**
	 * Handle client registration request.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error The response.
	 * @since 1.0.0
	 */
	public function handle_registration( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();

		// Get client metadata from request.
		$client_name   = isset( $body['client_name'] ) ? sanitize_text_field( $body['client_name'] ) : 'MCP Client';
		$redirect_uris = isset( $body['redirect_uris'] ) ? $this->sanitize_redirect_uris( $body['redirect_uris'] ) : [];

		// Validate redirect URIs if provided.
		if ( ! empty( $redirect_uris ) ) {
			foreach ( $redirect_uris as $uri ) {
				if ( ! $this->is_valid_redirect_uri( $uri ) ) {
					return new WP_Error(
						'invalid_redirect_uri',
						__( 'Invalid redirect URI provided.', 'albert' ),
						[ 'status' => 400 ]
					);
				}
			}
		}

		// Create the client.
		$client_repo  = new ClientRepository();
		$redirect_uri = ! empty( $redirect_uris ) ? wp_json_encode( $redirect_uris ) : '*';
		$result       = $client_repo->createClient(
			$client_name,
			$redirect_uri !== false ? $redirect_uri : '*',
			true, // Confidential client.
			null  // No associated WordPress user for DCR clients.
		);

		if ( ! $result ) {
			return new WP_Error(
				'registration_failed',
				__( 'Failed to register client.', 'albert' ),
				[ 'status' => 500 ]
			);
		}

		// Return client credentials per RFC 7591.
		$response_data = [
			'client_id'                  => $result['client_id'],
			'client_secret'              => $result['client_secret'],
			'client_name'                => $client_name,
			'token_endpoint_auth_method' => 'client_secret_post',
		];

		if ( ! empty( $redirect_uris ) ) {
			$response_data['redirect_uris'] = $redirect_uris;
		}

		// Return 201 Created with client credentials.
		return new WP_REST_Response( $response_data, 201 );
	}

	/**
	 * Sanitize redirect URIs array.
	 *
	 * @param array<int, string> $uris Array of redirect URIs.
	 *
	 * @return array<int, string> Sanitized URIs.
	 * @since 1.0.0
	 */
	private function sanitize_redirect_uris( array $uris ): array {
		return array_map( 'esc_url_raw', $uris );
	}

	/**
	 * Validate a redirect URI.
	 *
	 * Per RFC 7591 and MCP spec:
	 * - Must be HTTPS (except localhost for development)
	 * - Must not contain fragments
	 *
	 * @param string $uri The redirect URI to validate.
	 *
	 * @return bool Whether the URI is valid.
	 * @since 1.0.0
	 */
	private function is_valid_redirect_uri( string $uri ): bool {
		$parsed = wp_parse_url( $uri );

		if ( ! $parsed || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return false;
		}

		// Must be HTTPS or localhost.
		$is_https     = $parsed['scheme'] === 'https';
		$is_localhost = in_array( $parsed['host'], [ 'localhost', '127.0.0.1', '::1' ], true );

		if ( ! $is_https && ! $is_localhost ) {
			return false;
		}

		// Must not contain fragments.
		if ( ! empty( $parsed['fragment'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the registration endpoint URL.
	 *
	 * @return string The registration endpoint URL.
	 * @since 1.0.0
	 */
	public static function get_endpoint_url(): string {
		return rest_url( self::NAMESPACE . '/oauth/register' );
	}
}
