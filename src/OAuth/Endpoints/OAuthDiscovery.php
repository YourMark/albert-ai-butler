<?php
/**
 * OAuth Metadata Discovery Endpoint
 *
 * @package Albert
 * @subpackage OAuth\Endpoints
 * @since      1.0.0
 */

namespace Albert\OAuth\Endpoints;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\OAuth\ServerMetadata;
use WP;

/**
 * OAuthDiscovery class
 *
 * Provides OAuth 2.0 Authorization Server Metadata (RFC 8414).
 * This allows clients like Claude Desktop to auto-discover OAuth endpoints.
 *
 * @since 1.0.0
 */
class OAuthDiscovery implements Hookable {

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 20 );
		add_action( 'parse_request', [ $this, 'maybe_intercept_well_known' ] );
		add_action( 'template_redirect', [ $this, 'handle_discovery_request' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );

		// Prevent WordPress canonical redirect for our endpoints.
		add_filter( 'redirect_canonical', [ $this, 'prevent_canonical_redirect' ], 10, 2 );
	}

	/**
	 * Prevent WordPress canonical redirect for .well-known endpoints.
	 *
	 * Suppresses canonical redirects that would otherwise strip the trailing
	 * slash (or perform other URL rewrites) on Albert's OAuth discovery URLs.
	 * Some hosts — notably Kinsta — add a trailing slash at the edge, and the
	 * WordPress canonical redirect would bounce it back, producing a redirect
	 * loop or a 404. We check the requested URL directly rather than the
	 * `albert_oauth_discovery` query var, so the suppression works even when
	 * the rewrite rule has not matched yet (for example, with a stale stored
	 * rewrite-rules option).
	 *
	 * @param string $redirect_url  The redirect URL.
	 * @param string $requested_url The requested URL.
	 *
	 * @return string|false The redirect URL or false to prevent redirect.
	 * @since 1.0.0
	 */
	public function prevent_canonical_redirect( string $redirect_url, string $requested_url ): string|false {
		if ( $this->is_well_known_request( $requested_url ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Intercept .well-known requests at parse_request to set the discovery query var.
	 *
	 * Bypasses the rewrite rule layer, which can fail to match when the stored
	 * `rewrite_rules` option pre-dates the optional-trailing-slash pattern, or
	 * when the request reaches WordPress with a trailing slash added by an
	 * upstream proxy. By setting the query var here, the existing
	 * `handle_discovery_request()` callback on `template_redirect` runs as
	 * normal.
	 *
	 * @param WP $wp Current WordPress environment instance.
	 *
	 * @return void
	 * @since 1.1.1
	 */
	public function maybe_intercept_well_known( WP $wp ): void {
		// Read the raw request path. The value is passed through wp_parse_url()
		// and string-compared to two literal endpoint names; it is never echoed
		// or persisted, so per-character sanitization is not appropriate.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		$discovery = $this->classify_well_known( $request_uri );

		if ( $discovery !== '' ) {
			$wp->query_vars['albert_oauth_discovery'] = $discovery;
		}
	}

	/**
	 * Determine whether a URL targets one of Albert's .well-known endpoints.
	 *
	 * @param string $url The URL to inspect.
	 *
	 * @return bool True when the URL path is an Albert OAuth discovery endpoint.
	 * @since 1.1.1
	 */
	private function is_well_known_request( string $url ): bool {
		return $this->classify_well_known( $url ) !== '';
	}

	/**
	 * Classify a URL as one of Albert's discovery endpoints, or not one.
	 *
	 * Recognises both the empty-path root form (`oauth-authorization-server`)
	 * and the RFC 8414 §3.1 / RFC 9728 §3.1 path-insertion form, where a resource
	 * path follows the well-known type (`oauth-authorization-server/wp-json/...`).
	 * Prefix matching, so an upstream proxy's added trailing slash does not
	 * change the answer, the same resilience the rewrite layer needs.
	 *
	 * @param string $url The URL to inspect.
	 *
	 * @return string `authorization-server`, `protected-resource`, or `''`.
	 * @since 1.4.2
	 */
	private function classify_well_known( string $url ): string {
		$suffix = $this->extract_well_known_path( $url );

		if ( $suffix === 'oauth-authorization-server' || str_starts_with( $suffix, 'oauth-authorization-server/' ) ) {
			return 'authorization-server';
		}

		if ( $suffix === 'oauth-protected-resource' || str_starts_with( $suffix, 'oauth-protected-resource/' ) ) {
			return 'protected-resource';
		}

		return '';
	}

	/**
	 * Extract the discovery suffix from a .well-known URL path.
	 *
	 * Returns the segment after `.well-known/` with any trailing slash
	 * removed, or an empty string if the URL does not target a .well-known
	 * path. Example: `/.well-known/oauth-protected-resource/` returns
	 * `oauth-protected-resource`.
	 *
	 * @param string $url The URL to inspect.
	 *
	 * @return string The discovery suffix or an empty string.
	 * @since 1.1.1
	 */
	private function extract_well_known_path( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = trim( $path, '/' );

		if ( ! str_starts_with( $path, '.well-known/' ) ) {
			return '';
		}

		return substr( $path, strlen( '.well-known/' ) );
	}

	/**
	 * Flush rewrite rules if our rules are not registered.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function maybe_flush_rewrite_rules(): void {
		$rules_version = get_option( 'albert_rewrite_version', '' );

		// Flush rules if version doesn't match (new install or update).
		if ( ALBERT_VERSION !== $rules_version ) {
			flush_rewrite_rules();
			update_option( 'albert_rewrite_version', ALBERT_VERSION );
		}
	}

	/**
	 * Add rewrite rules for .well-known endpoints.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_rewrite_rules(): void {
		// OAuth Authorization Server Metadata (RFC 8414).
		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server/?$',
			'index.php?albert_oauth_discovery=authorization-server',
			'top'
		);

		// OAuth Protected Resource Metadata (RFC 9728 / MCP spec).
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/?$',
			'index.php?albert_oauth_discovery=protected-resource',
			'top'
		);

		// The RFC 8414 §3.1 / RFC 9728 §3.1 canonical form, where the issuer or
		// resource path is inserted *after* the well-known segment (for us,
		// `/.well-known/oauth-authorization-server/wp-json/albert/v1/oauth`). A
		// strict client builds exactly this and never tries the append form the
		// routes above cover. The single document is served for any resource
		// path under the type: this site has one authorization server and one
		// protected resource, and the document is public and identical either
		// way. These are root `.well-known` URLs, so on a host that intercepts a
		// root `/.well-known/` they never reach WordPress — the mid-path append
		// form remains the one that always works there (see ServerMetadata).
		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server/.+$',
			'index.php?albert_oauth_discovery=authorization-server',
			'top'
		);

		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/.+$',
			'index.php?albert_oauth_discovery=protected-resource',
			'top'
		);
	}

	/**
	 * Add custom query vars.
	 *
	 * @param array<int, string> $vars Existing query vars.
	 *
	 * @return array<int, string> Modified query vars.
	 * @since 1.0.0
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = 'albert_oauth_discovery';
		return $vars;
	}

	/**
	 * Handle discovery request.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_discovery_request(): void {
		$discovery = get_query_var( 'albert_oauth_discovery' );

		if ( ! $discovery ) {
			return;
		}

		// Send JSON response headers.
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Access-Control-Allow-Origin: *' );

		if ( $discovery === 'protected-resource' ) {
			$metadata = $this->get_protected_resource_metadata();
		} else {
			$metadata = $this->get_authorization_server_metadata();
		}

		echo wp_json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}


	/**
	 * Get OAuth Protected Resource Metadata (RFC 9728).
	 *
	 * This tells MCP clients where to find the authorization server.
	 *
	 * @return array<string, mixed> The metadata array.
	 * @since 1.0.0
	 */
	public function get_protected_resource_metadata(): array {
		return ServerMetadata::protected_resource();
	}

	/**
	 * Get OAuth Authorization Server Metadata (RFC 8414).
	 *
	 * @return array<string, mixed> The metadata array.
	 * @since 1.0.0
	 */
	public function get_authorization_server_metadata(): array {
		return ServerMetadata::authorization_server();
	}

	/**
	 * Flush rewrite rules on activation.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function activate(): void {
		$instance = new self();
		$instance->add_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Flush rewrite rules on deactivation.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function deactivate(): void {
		delete_option( 'albert_rewrite_version' );
		flush_rewrite_rules();
	}
}
