<?php
/**
 * OAuth Server Metadata
 *
 * @package Albert
 * @subpackage OAuth
 * @since      1.4.0
 */

namespace Albert\OAuth;

defined( 'ABSPATH' ) || exit;

use Albert\Core\Plugin;

/**
 * ServerMetadata class
 *
 * The single source of the RFC 8414 Authorization Server Metadata document.
 *
 * It is a class rather than a method on an endpoint because two endpoints
 * serve this document, and until 1.4.0 each built its own copy:
 *
 * - `/.well-known/oauth-authorization-server`, handled by
 *   {@see \Albert\OAuth\Endpoints\OAuthDiscovery}. This is the path RFC 8414
 *   specifies, and the one `mcp-remote` — and so Claude Desktop — actually
 *   fetches.
 * - `/wp-json/albert/v1/oauth/metadata`, handled by
 *   {@see \Albert\OAuth\Endpoints\OAuthController}. A non-standard convenience
 *   route kept for clients that were pointed at it directly.
 *
 * They drifted, and the half that matters drifted wrong: 1.3.1 added `none` to
 * `token_endpoint_auth_methods_supported` on the REST route only, so the
 * standard path went on advertising two auth methods while client registration
 * kept issuing public clients that use a third. A loopback client that reads
 * discovery and believes it cannot authenticate is the interop failure that
 * release was meant to have fixed.
 *
 * One builder, two callers. There is no second copy left to drift.
 *
 * @since 1.4.0
 */
class ServerMetadata {

	/**
	 * The RFC 8414 Authorization Server Metadata document.
	 *
	 * @return array<string, mixed> The metadata.
	 * @since 1.4.0
	 */
	public static function authorization_server(): array {
		$base_url = self::base_url();

		return [
			// Required fields. The issuer is a path, not the bare domain
			// (see issuer_url()); the endpoints are their own absolute URLs.
			'issuer'                                => self::issuer_url(),
			'authorization_endpoint'                => $base_url . '/oauth/authorize',
			'token_endpoint'                        => self::rest_url( Plugin::rest_namespace() . '/oauth/token' ),
			'registration_endpoint'                 => self::rest_url( Plugin::rest_namespace() . '/oauth/register' ),

			/*
			 * `jwks_uri` is optional under RFC 8414, but a conforming client
			 * validates the metadata before it authenticates and some — Claude
			 * Code's MCP SDK among them — require it to be a string. Omitting it
			 * fails discovery outright. It is a mid-path REST URL, not a
			 * `.well-known` path, so hosts that intercept a root `/.well-known/`
			 * leave it alone (see issuer_url()).
			 */
			'jwks_uri'                              => self::rest_url( Plugin::rest_namespace() . '/oauth/jwks' ),

			// Recommended fields.
			'response_types_supported'              => [ 'code' ],
			'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],

			/*
			 * `none` is not padding for completeness. Every loopback/native
			 * client — RFC 8252, which is every local MCP bridge — is registered
			 * as a public client with exactly this auth method; see
			 * {@see \Albert\OAuth\Endpoints\ClientRegistration::register()}.
			 * Leaving it out states that the server will not accept what the
			 * server itself just issued, and a conforming client believes the
			 * metadata over its own credentials.
			 */
			'token_endpoint_auth_methods_supported' => [ 'client_secret_post', 'client_secret_basic', 'none' ],
			'code_challenge_methods_supported'      => [ 'S256' ],

			// Optional but useful fields.
			'scopes_supported'                      => [ 'default' ],
		];
	}

	/**
	 * The RFC 9728 Protected Resource Metadata document.
	 *
	 * One builder for both callers (the `.well-known` route and the REST route), so
	 * they cannot drift. `authorization_servers` must be an issuer identifier
	 * (RFC 9728 §7.6), matching the metadata's own `issuer`, or a client's RFC 8414
	 * lookup goes to the wrong URL and fails validation.
	 *
	 * @return array<string, mixed> The metadata.
	 * @since 1.4.1
	 */
	public static function protected_resource(): array {
		return [
			'resource'              => self::rest_url( Plugin::rest_namespace() . '/mcp' ),
			'authorization_servers' => [ self::issuer_url() ],
			'scopes_supported'      => [ 'default' ],
		];
	}

	/**
	 * The issuer identifier: a path, not the bare domain.
	 *
	 * A path issuer makes a client's RFC 8414 §3.1 discovery fall through to
	 * `<issuer>/.well-known/openid-configuration`, where `.well-known` sits mid-path.
	 * Hosts that intercept only a *root* `/.well-known/` (SiteGround, Servebolt) leave
	 * that alone, so it reaches WordPress and needs no files. Endpoints are their own
	 * URLs, not derived from this.
	 *
	 * @return string The issuer identifier.
	 * @since 1.4.1
	 */
	public static function issuer_url(): string {
		return self::rest_url( Plugin::rest_namespace() . '/oauth' );
	}

	/**
	 * The base URL for OAuth endpoints.
	 *
	 * Uses the external URL setting when one is configured and valid, otherwise
	 * `home_url()`.
	 *
	 * @return string The base URL.
	 * @since 1.4.0
	 */
	public static function base_url(): string {
		$external_url = (string) apply_filters( 'albert/mcp/external_url', '' );
		$external_url = rtrim( $external_url, '/' );

		if ( $external_url !== '' ) {
			$validated = wp_http_validate_url( $external_url );

			if ( $validated !== false ) {
				return $validated;
			}
		}

		return home_url();
	}

	/**
	 * A REST URL built on the current base URL.
	 *
	 * Deliberately not `rest_url()`: that resolves against `home_url()` and
	 * would ignore the external URL an MCP client was told to use.
	 *
	 * @param string $path The REST route path.
	 *
	 * @return string The full REST URL.
	 * @since 1.4.0
	 */
	public static function rest_url( string $path ): string {
		return self::base_url() . '/wp-json/' . ltrim( $path, '/' );
	}
}
