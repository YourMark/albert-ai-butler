<?php
/**
 * REST request facts, known before WordPress dispatches the request.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.5.1
 */

namespace Albert\Core;

use Albert\MCP\Server;

defined( 'ABSPATH' ) || exit;

/**
 * What the current request is, read from the raw request.
 *
 * The abilities registry is built before the REST server authenticates the
 * request, so decisions made while building it cannot wait for the token to
 * be validated. Add-ons use these to recognise their own routes the same way.
 *
 * @since 1.5.1
 */
final class RestRequest {

	/**
	 * The REST route this request is dispatched to, or '' when it is not a REST request.
	 *
	 * Mirrors WP::parse_request(): a `rest_route` from POST or GET overrides the
	 * route in the path, so `/wp-json/albert/v1/abilities?rest_route=/other`
	 * is a request for `/other`. The path fallback covers the time before
	 * parse_request runs.
	 *
	 * @return string The route, starting with `/`, or ''.
	 * @since 1.5.1
	 */
	public static function route(): string {
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return (string) $GLOBALS['wp']->query_vars['rest_route'];
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Read-only routing decision.
		foreach ( [ $_POST, $_GET ] as $source ) {
			if ( isset( $source['rest_route'] ) ) {
				return is_string( $source['rest_route'] ) ? sanitize_text_field( wp_unslash( $source['rest_route'] ) ) : '';
			}
		}
		// phpcs:enable

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) strtok( $uri, '?' );

		if ( preg_match( '#/' . preg_quote( rest_get_url_prefix(), '#' ) . '(/.*)$#', $path, $matches ) !== 1 ) {
			return '';
		}

		return $matches[1];
	}

	/**
	 * Whether the dispatched route is `$route` or below it.
	 *
	 * @param string $route A route such as `/albert/v1/abilities`.
	 *
	 * @return bool
	 * @since 1.5.1
	 */
	public static function targets( string $route ): bool {
		return preg_match( '#^' . preg_quote( rtrim( $route, '/' ), '#' ) . '(?:/|$)#', self::route() ) === 1;
	}

	/**
	 * Whether the request carries a Bearer token, valid or not.
	 *
	 * @return bool
	 * @since 1.5.1
	 */
	public static function has_bearer_token(): bool {
		$headers = [];
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $key ) {
			if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
				$headers[] = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			}
		}

		if ( function_exists( 'apache_request_headers' ) ) {
			foreach ( (array) apache_request_headers() as $name => $value ) {
				if ( is_string( $name ) && strtolower( $name ) === 'authorization' && is_string( $value ) ) {
					$headers[] = $value;
				}
			}
		}

		foreach ( $headers as $header ) {
			if ( preg_match( '/^\s*Bearer\s/i', $header ) === 1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether this is an assistant's request: a Bearer token, or Albert's MCP route.
	 *
	 * @return bool
	 * @since 1.5.1
	 */
	public static function is_assistant_request(): bool {
		return self::has_bearer_token() || self::targets( '/' . Plugin::rest_namespace() . '/' . Server::ROUTE );
	}
}
