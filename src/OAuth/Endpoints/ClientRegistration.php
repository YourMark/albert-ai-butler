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
use Albert\Core\Plugin;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\ServerMetadata;
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
	 * @deprecated 1.0.1 Use {@see Plugin::rest_namespace()} instead.
	 * @since      1.0.0
	 * @var string
	 */
	const NAMESPACE = 'albert/v1';

	/**
	 * Schemes that must never appear in a stored, rendered redirect URI.
	 *
	 * Stable and client-independent — these never belong in a redirect
	 * regardless of who registers. Kept as a hard denylist rather than a
	 * curated allowlist so legitimate future clients are not rejected.
	 *
	 * @since 1.3.1
	 * @var array<int, string>
	 */
	const DANGEROUS_SCHEMES = [ 'javascript', 'data', 'vbscript', 'file', 'blob', 'about' ];

	/**
	 * Maximum number of redirect URIs accepted per client.
	 *
	 * @since 1.3.1
	 * @var int
	 */
	const MAX_REDIRECT_URIS = 5;

	/**
	 * Maximum length of a single redirect URI.
	 *
	 * @since 1.3.1
	 * @var int
	 */
	const MAX_REDIRECT_URI_LENGTH = 2048;

	/**
	 * Default per-site cap on the number of registered clients.
	 *
	 * @since 1.3.1
	 * @var int
	 */
	const DEFAULT_MAX_CLIENTS = 20;

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
		// Dynamic Client Registration endpoint (public per RFC 7591, rate limited in handler).
		register_rest_route(
			Plugin::rest_namespace(),
			'/oauth/register',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_registration' ],
				'permission_callback' => '__return_true',
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
		// Rate limiting: 10 registrations per client IP per hour.
		$ip            = $this->client_ip( $request );
		$transient_key = 'albert_dcr_' . md5( $ip );
		$attempts      = (int) get_transient( $transient_key );

		if ( $attempts >= 10 ) {
			return new WP_Error(
				'rate_limit_exceeded',
				__( 'Too many registration requests. Please try again later.', 'albert-ai-butler' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $transient_key, $attempts + 1, HOUR_IN_SECONDS );

		$client_repo = new ClientRepository();

		// Per-site client cap — bounds the stored-client table against abuse.
		$max_clients = (int) apply_filters( 'albert/oauth/max_clients', self::DEFAULT_MAX_CLIENTS );
		if ( $max_clients > 0 && $client_repo->count_clients() >= $max_clients ) {
			return new WP_Error(
				'client_limit_reached',
				__( 'This site has reached its maximum number of registered clients.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$body = $request->get_json_params();

		$client_name = isset( $body['client_name'] ) ? sanitize_text_field( $body['client_name'] ) : 'MCP Client';

		// redirect_uris is REQUIRED (RFC 7591). Absence is rejected — no wildcard fallback.
		$redirect_uris = isset( $body['redirect_uris'] ) ? $this->sanitize_redirect_uris( $body['redirect_uris'] ) : [];

		if ( empty( $redirect_uris ) ) {
			return new WP_Error(
				'invalid_redirect_uri',
				__( 'At least one redirect_uris entry is required.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		if ( count( $redirect_uris ) > self::MAX_REDIRECT_URIS ) {
			return new WP_Error(
				'too_many_redirect_uris',
				sprintf(
					/* translators: %d: maximum number of redirect URIs */
					__( 'A client may register at most %d redirect URIs.', 'albert-ai-butler' ),
					self::MAX_REDIRECT_URIS
				),
				[ 'status' => 400 ]
			);
		}

		// Validate every redirect URI. Each rejection is specific and logged.
		foreach ( $redirect_uris as $uri ) {
			$reason = $this->validate_redirect_uri( $uri );
			if ( $reason !== '' ) {
				$this->log_rejected_registration( $reason, $uri, $client_name );

				return new WP_Error(
					$reason,
					$this->rejection_message( $reason, $uri ),
					[ 'status' => 400 ]
				);
			}
		}

		// A native (private-use scheme) or loopback client is a public client per
		// RFC 8252: it cannot protect a secret, so PKCE — not a secret — guards it.
		$is_confidential = ! $this->requires_public_client( $redirect_uris );

		// Create the client.
		$result = $client_repo->createClient(
			$client_name,
			(string) wp_json_encode( $redirect_uris ),
			$is_confidential,
			null, // No associated WordPress user for DCR clients.
			null, // Let the repository generate the secret for confidential clients.
			'dcr'
		);

		if ( ! $result ) {
			return new WP_Error(
				'registration_failed',
				__( 'Failed to register client.', 'albert-ai-butler' ),
				[ 'status' => 500 ]
			);
		}

		// Return client credentials per RFC 7591.
		$response_data = [
			'client_id'                  => $result['client_id'],
			'client_id_issued_at'        => time(),
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'token_endpoint_auth_method' => $is_confidential ? 'client_secret_post' : 'none',
			'grant_types'                => [ 'authorization_code', 'refresh_token' ],
			'response_types'             => [ 'code' ],
			'scope'                      => 'default',
		];

		// Public clients receive no secret — they authenticate with PKCE only.
		if ( $is_confidential && ! empty( $result['client_secret'] ) ) {
			$response_data['client_secret'] = $result['client_secret'];

			// REQUIRED by RFC 7591 §3.2.1 whenever a client_secret is issued; 0
			// means it does not expire.
			$response_data['client_secret_expires_at'] = 0;
		}

		// Return 201 Created with client credentials.
		return new WP_REST_Response( $response_data, 201 );
	}

	/**
	 * Resolve the client IP for rate limiting.
	 *
	 * Keys on REMOTE_ADDR (not spoofable) by default. X-Forwarded-For is only
	 * trusted when a site explicitly opts in via the filter — behind a real proxy
	 * REMOTE_ADDR is the proxy, so the filter lets that site use the forwarded IP.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return string The client IP.
	 * @since 1.3.1
	 */
	private function client_ip( WP_REST_Request $request ): string {
		$remote_addr = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );

		/**
		 * Whether to trust the X-Forwarded-For header for rate-limit keying.
		 *
		 * Default false — XFF is client-spoofable. Enable only when a trusted
		 * reverse proxy sets it and strips any client-supplied value.
		 *
		 * @since 1.3.1
		 *
		 * @param bool $trust Whether to trust X-Forwarded-For.
		 */
		if ( apply_filters( 'albert/oauth/trust_forwarded_for', false ) ) {
			$forwarded = $request->get_header( 'X-Forwarded-For' );
			if ( $forwarded ) {
				return sanitize_text_field( explode( ',', $forwarded )[0] );
			}
		}

		return $remote_addr;
	}

	/**
	 * Sanitize redirect URIs array.
	 *
	 * @param mixed $uris Array of redirect URIs (or arbitrary input).
	 *
	 * @return array<int, string> Sanitized URIs.
	 * @since 1.0.0
	 */
	private function sanitize_redirect_uris( $uris ): array {
		if ( ! is_array( $uris ) ) {
			return [];
		}

		$clean = [];
		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || $uri === '' ) {
				continue;
			}
			// esc_url_raw() strips custom schemes it does not know, so preserve the
			// raw string for private-use schemes and only trim whitespace here.
			$clean[] = trim( $uri );
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Validate a redirect URI, returning a machine-readable rejection reason.
	 *
	 * The scheme check is deliberately permissive — the real anti-exfiltration
	 * controls are exact-match registered URIs, consent-screen transparency, and
	 * PKCE for public clients. This only keeps genuinely dangerous schemes out of
	 * a stored, rendered field.
	 *
	 * @param string $uri The redirect URI to validate.
	 *
	 * @return string Empty string when valid, otherwise a reason code.
	 * @since 1.3.1
	 */
	private function validate_redirect_uri( string $uri ): string {
		if ( $uri === '' ) {
			return 'redirect_uri_empty';
		}

		if ( strlen( $uri ) > self::MAX_REDIRECT_URI_LENGTH ) {
			return 'redirect_uri_too_long';
		}

		// Fragments are forbidden in redirect URIs (RFC 6749 §3.1.2).
		if ( str_contains( $uri, '#' ) ) {
			return 'redirect_uri_has_fragment';
		}

		$scheme = $this->extract_scheme( $uri );
		if ( $scheme === '' ) {
			return 'redirect_uri_malformed';
		}

		// Hard-deny dangerous schemes regardless of any other setting.
		if ( in_array( $scheme, self::DANGEROUS_SCHEMES, true ) ) {
			return 'redirect_scheme_not_permitted';
		}

		// Optional strict mode: restrict to an explicit scheme allowlist.
		/**
		 * Optional strict-mode allowlist of redirect URI schemes.
		 *
		 * Empty (default) = permissive-but-safe. Non-empty = restrict to exactly
		 * these schemes; a well-formed scheme outside the list is rejected.
		 *
		 * @since 1.3.1
		 *
		 * @param array<int, string> $schemes Allowed schemes (lowercase).
		 */
		$allowed_schemes = (array) apply_filters( 'albert/oauth/allowed_redirect_schemes', [] );
		if ( ! empty( $allowed_schemes ) && ! in_array( $scheme, array_map( 'strtolower', $allowed_schemes ), true ) ) {
			return 'redirect_scheme_not_allowed';
		}

		if ( $scheme === 'https' ) {
			$host = (string) wp_parse_url( $uri, PHP_URL_HOST );
			if ( $host === '' ) {
				return 'redirect_uri_malformed';
			}
			if ( ! $this->is_safe_https_host( $host ) ) {
				return 'redirect_host_not_allowed';
			}

			return '';
		}

		if ( $scheme === 'http' ) {
			// http is only acceptable for loopback (RFC 8252, native apps).
			$host = strtolower( (string) wp_parse_url( $uri, PHP_URL_HOST ) );
			if ( ! in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
				return 'redirect_scheme_not_permitted';
			}

			return '';
		}

		// Any other well-formed scheme is a private-use (custom) scheme — allow.
		// The scheme is the identity; there is no host to validate.
		return '';
	}

	/**
	 * Whether any of the redirect URIs forces the client to be public.
	 *
	 * Private-use schemes and loopback redirects cannot protect a client secret
	 * (RFC 8252), so such clients are registered as public and gated by PKCE.
	 *
	 * @param array<int, string> $uris Validated redirect URIs.
	 *
	 * @return bool Whether the client must be public.
	 * @since 1.3.1
	 */
	private function requires_public_client( array $uris ): bool {
		foreach ( $uris as $uri ) {
			// Every validated non-https redirect is either http-loopback or a
			// private-use scheme — both public client types per RFC 8252.
			if ( $this->extract_scheme( $uri ) !== 'https' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the lowercased URI scheme per the RFC 3986 grammar.
	 *
	 * `wp_parse_url()` does not reliably parse private-use schemes containing
	 * dots (e.g. `com.example.app:/cb`), so the scheme is read directly.
	 *
	 * @param string $uri The URI.
	 *
	 * @return string The lowercased scheme, or '' when malformed.
	 * @since 1.3.1
	 */
	private function extract_scheme( string $uri ): string {
		if ( preg_match( '#^([a-zA-Z][a-zA-Z0-9+\-.]*):#', $uri, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return '';
	}

	/**
	 * Whether an https redirect host is safe (not an internal/reserved target).
	 *
	 * @param string $host The host component.
	 *
	 * @return bool Whether the host is acceptable.
	 * @since 1.3.1
	 */
	private function is_safe_https_host( string $host ): bool {
		// Development convenience: allow loopback/internal https in local/dev.
		if ( in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
			return true;
		}

		$lower = strtolower( $host );
		if ( in_array( $lower, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
			return false;
		}

		// Resolve a hostname to an IPv4 address (best-effort); literal IPs pass through.
		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			// Not an IP and DNS did not resolve — allow (the browser, not the
			// server, follows this redirect; exact-match + PKCE remain the gate).
			return true;
		}

		// Reject RFC 1918 private and IANA reserved ranges (covers loopback,
		// link-local, and other reserved space).
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		// CGNAT 100.64.0.0/10 is not covered by the reserved-range filter.
		if ( $this->ip_in_cidr( $ip, '100.64.0.0', 10 ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether an IPv4 address falls within a CIDR range.
	 *
	 * @param string $ip     The IP address to test.
	 * @param string $subnet The subnet base address.
	 * @param int    $bits   The subnet mask bits.
	 *
	 * @return bool Whether the IP is within the range.
	 * @since 1.3.1
	 */
	private function ip_in_cidr( string $ip, string $subnet, int $bits ): bool {
		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );

		if ( $ip_long === false || $subnet_long === false ) {
			return false;
		}

		$mask = -1 << ( 32 - $bits );

		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}

	/**
	 * Build a human-readable message for a rejection reason.
	 *
	 * @param string $reason The reason code.
	 * @param string $uri    The rejected URI.
	 *
	 * @return string The message.
	 * @since 1.3.1
	 */
	private function rejection_message( string $reason, string $uri ): string {
		$scheme = $this->extract_scheme( $uri );

		switch ( $reason ) {
			case 'redirect_uri_has_fragment':
				return __( 'A redirect URI must not contain a fragment (#).', 'albert-ai-butler' );
			case 'redirect_uri_too_long':
				return __( 'A redirect URI is too long.', 'albert-ai-butler' );
			case 'redirect_scheme_not_permitted':
				return sprintf(
					/* translators: %s: URI scheme */
					__( 'The "%s" scheme is not permitted for redirect URIs.', 'albert-ai-butler' ),
					$scheme
				);
			case 'redirect_scheme_not_allowed':
				return sprintf(
					/* translators: %s: URI scheme */
					__( 'The "%s" scheme is not in this site\'s allowed redirect schemes.', 'albert-ai-butler' ),
					$scheme
				);
			case 'redirect_host_not_allowed':
				return __( 'The redirect URI host resolves to a private or reserved address.', 'albert-ai-butler' );
			default:
				return __( 'Invalid redirect URI provided.', 'albert-ai-butler' );
		}
	}

	/**
	 * Record a rejected registration so a wrongly-rejected client is diagnosable.
	 *
	 * @param string $reason      The reason code.
	 * @param string $uri         The rejected URI.
	 * @param string $client_name The client name from the request.
	 *
	 * @return void
	 * @since 1.3.1
	 */
	private function log_rejected_registration( string $reason, string $uri, string $client_name ): void {
		$scheme = $this->extract_scheme( $uri );

		/**
		 * Fires when a client registration is rejected during validation.
		 *
		 * @since 1.3.1
		 *
		 * @param string $reason      The machine-readable rejection reason.
		 * @param string $scheme      The rejected URI's scheme.
		 * @param string $client_name The client name from the request.
		 * @param string $uri         The full rejected URI.
		 */
		do_action( 'albert/oauth/registration_rejected', $reason, $scheme, $client_name, $uri );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic aid, gated on WP_DEBUG.
			error_log(
				sprintf(
					'[Albert] Rejected client registration (%s) scheme=%s client=%s',
					$reason,
					$scheme,
					$client_name
				)
			);
		}
	}

	/**
	 * Get the registration endpoint URL.
	 *
	 * @return string The registration endpoint URL.
	 * @since 1.0.0
	 */
	public static function get_endpoint_url(): string {
		return ServerMetadata::rest_url( Plugin::rest_namespace() . '/oauth/register' );
	}
}
