<?php
/**
 * PSR-7 Bridge for WordPress
 *
 * @package Albert
 * @subpackage OAuth\Endpoints
 * @since      1.0.0
 */

namespace Albert\OAuth\Endpoints;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Psr7Bridge class
 *
 * Bridges WordPress REST API request/response to PSR-7.
 *
 * @since 1.0.0
 */
class Psr7Bridge {

	/**
	 * Convert WP_REST_Request to PSR-7 ServerRequest.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $wp_request The WordPress REST request.
	 *
	 * @return ServerRequestInterface The PSR-7 request.
	 * @since 1.0.0
	 */
	public static function to_psr7_request( WP_REST_Request $wp_request ): ServerRequestInterface {
		$method = $wp_request->get_method();
		$uri    = home_url( $wp_request->get_route() );

		// Add query string for GET requests.
		$query_params = $wp_request->get_query_params();
		if ( ! empty( $query_params ) ) {
			$uri .= '?' . http_build_query( $query_params );
		}

		// Build headers array.
		$headers = [];
		foreach ( $wp_request->get_headers() as $key => $value ) {
			// Convert header key format (e.g., 'content_type' to 'Content-Type').
			$header_key             = str_replace( '_', '-', ucwords( strtolower( $key ), '_' ) );
			$headers[ $header_key ] = is_array( $value ) ? implode( ', ', $value ) : $value;
		}

		// Get body.
		$body = $wp_request->get_body();

		// Create PSR-7 request.
		$psr_request = new ServerRequest( $method, $uri, $headers, $body );

		// Add parsed body for POST requests.
		$body_params = $wp_request->get_body_params();
		if ( ! empty( $body_params ) ) {
			$psr_request = $psr_request->withParsedBody( $body_params );
		}

		// Add query params.
		if ( ! empty( $query_params ) ) {
			$psr_request = $psr_request->withQueryParams( $query_params );
		}

		return $psr_request;
	}

	/**
	 * Convert PSR-7 Response to WP_REST_Response.
	 *
	 * @param ResponseInterface $psr_response The PSR-7 response.
	 *
	 * @return WP_REST_Response The WordPress REST response.
	 * @since 1.0.0
	 */
	public static function to_wp_response( ResponseInterface $psr_response ): WP_REST_Response {
		$body        = (string) $psr_response->getBody();
		$status_code = $psr_response->getStatusCode();

		// Try to decode JSON body.
		$data = json_decode( $body, true );
		if ( $data === null && ! empty( $body ) ) {
			$data = $body;
		}

		$wp_response = new WP_REST_Response( $data, $status_code );

		// Copy headers.
		foreach ( $psr_response->getHeaders() as $name => $values ) {
			foreach ( $values as $value ) {
				$wp_response->header( $name, $value );
			}
		}

		return $wp_response;
	}

	/**
	 * Create a new PSR-7 Response.
	 *
	 * @param int                   $status  HTTP status code.
	 * @param array<string, string> $headers Response headers.
	 * @param string                $body    Response body.
	 *
	 * @return ResponseInterface The PSR-7 response.
	 * @since 1.0.0
	 */
	public static function create_response( int $status = 200, array $headers = [], string $body = '' ): ResponseInterface {
		return new Response( $status, $headers, $body );
	}

	/**
	 * Create a new PSR-7 ServerRequest.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $uri     Request URI.
	 * @param array<string, string> $headers Request headers.
	 *
	 * @return ServerRequestInterface The PSR-7 server request.
	 * @since 1.0.0
	 */
	public static function create_server_request( string $method, string $uri, array $headers = [] ): ServerRequestInterface {
		return new ServerRequest( $method, $uri, $headers );
	}
}
