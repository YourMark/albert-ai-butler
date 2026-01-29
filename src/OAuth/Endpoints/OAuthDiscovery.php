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
		add_action( 'template_redirect', [ $this, 'handle_discovery_request' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );

		// Prevent WordPress canonical redirect for our endpoints.
		add_filter( 'redirect_canonical', [ $this, 'prevent_canonical_redirect' ], 10, 2 );
	}

	/**
	 * Prevent WordPress canonical redirect for .well-known endpoints.
	 *
	 * This is needed when accessing the site through a tunnel/proxy,
	 * as WordPress would otherwise redirect to the canonical (local) URL.
	 *
	 * @param string $redirect_url  The redirect URL.
	 * @param string $requested_url The requested URL.
	 *
	 * @return string|false The redirect URL or false to prevent redirect.
	 * @since 1.0.0
	 */
	public function prevent_canonical_redirect( string $redirect_url, string $requested_url ): string|false {
		$discovery = get_query_var( 'albert_oauth_discovery' );

		if ( $discovery ) {
			return false;
		}

		return $redirect_url;
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
	 * Get the base URL for OAuth endpoints.
	 *
	 * Uses the external URL setting if configured and developer settings are enabled,
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

		// Only use external URL if developer settings are enabled.
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

	/**
	 * Get OAuth Protected Resource Metadata (RFC 9728).
	 *
	 * This tells MCP clients where to find the authorization server.
	 *
	 * @return array<string, mixed> The metadata array.
	 * @since 1.0.0
	 */
	public function get_protected_resource_metadata(): array {
		$base_url = $this->get_base_url();

		return [
			'resource'              => $this->get_rest_url( 'albert/v1/mcp' ),
			'authorization_servers' => [ $base_url ],
			'scopes_supported'      => [ 'default' ],
		];
	}

	/**
	 * Get OAuth Authorization Server Metadata (RFC 8414).
	 *
	 * @return array<string, mixed> The metadata array.
	 * @since 1.0.0
	 */
	public function get_authorization_server_metadata(): array {
		$base_url = $this->get_base_url();

		return [
			// Required fields.
			'issuer'                                => $base_url,
			'authorization_endpoint'                => $base_url . '/oauth/authorize',
			'token_endpoint'                        => $this->get_rest_url( 'albert/v1/oauth/token' ),
			'registration_endpoint'                 => $this->get_rest_url( 'albert/v1/oauth/register' ),

			// Recommended fields.
			'response_types_supported'              => [ 'code' ],
			'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
			'token_endpoint_auth_methods_supported' => [ 'client_secret_post', 'client_secret_basic' ],
			'code_challenge_methods_supported'      => [ 'S256' ],

			// Optional but useful fields.
			'scopes_supported'                      => [ 'default' ],
		];
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
