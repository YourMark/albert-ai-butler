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
	 * Default per-site cap on the number of connected clients.
	 *
	 * @since 1.3.1
	 * @var int
	 */
	const DEFAULT_MAX_CLIENTS = 20;

	/**
	 * Most registrations kept that never got a token, before the oldest goes.
	 *
	 * Registration is anonymous, so these bound storage rather than refuse
	 * anyone: a flood only pushes out other unfinished registrations.
	 *
	 * @since 1.5.1
	 * @var int
	 */
	const MAX_PENDING_CLIENTS = 100;

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
		$transient_key = 'albert_dcr_' . md5( self::rate_limit_key( $ip ) );
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

		$body = $request->get_json_params();

		$client_name = isset( $body['client_name'] ) ? sanitize_text_field( $body['client_name'] ) : 'MCP Client';

		// Per-site cap on connected clients (see count_live_clients()).
		if ( self::limit_reached( $client_repo ) ) {
			$this->log_rejected_registration( 'client_limit_reached', '', $client_name );

			return new WP_Error(
				'client_limit_reached',
				__( 'This site has reached its maximum number of registered clients.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

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

		// Only now, with the request valid: make room among unfinished registrations.
		while ( $client_repo->count_pending_clients() >= self::max_pending_clients() ) {
			if ( ! $client_repo->evict_oldest_pending_client() ) {
				break;
			}
		}

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
	 * The rate-limit bucket for an address: IPv6 by its /64, since one host
	 * commonly holds the whole /64 and could otherwise rotate through it.
	 *
	 * @param string $ip The client IP.
	 *
	 * @return string
	 * @since 1.5.1
	 */
	public static function rate_limit_key( string $ip ): string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) === false ) {
			return $ip;
		}

		$packed = inet_pton( $ip );

		return $packed === false ? $ip : bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
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
		return self::redirect_uri_problem( $uri );
	}

	/**
	 * Why a redirect URI is refused, as a reason code, or '' when it is fine.
	 *
	 * @param string $uri         The redirect URI.
	 * @param bool   $resolve_dns Whether to resolve a host name to check its
	 *                            address. Off at authorization: DNS may have
	 *                            changed, and it would slow every sign-in.
	 *
	 * @return string
	 * @since 1.5.1
	 */
	public static function redirect_uri_problem( string $uri, bool $resolve_dns = true ): string {
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

		// Whitespace or a control character anywhere: no valid URI has one, and
		// keeping them out means every later use can trust the stored string.
		if ( preg_match( '/[\x00-\x20\x7f]/', $uri ) === 1 ) {
			return 'redirect_uri_malformed';
		}

		$scheme = self::extract_scheme( $uri );
		if ( $scheme === '' ) {
			return 'redirect_uri_malformed';
		}

		// Hard-deny dangerous schemes regardless of any other setting.
		if ( in_array( $scheme, self::DANGEROUS_SCHEMES, true ) ) {
			return 'redirect_scheme_not_permitted';
		}

		if ( self::has_ambiguous_authority( $uri ) ) {
			return 'redirect_uri_malformed';
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
			if ( ! self::is_safe_https_host( $host, $resolve_dns ) ) {
				return 'redirect_host_not_allowed';
			}

			return '';
		}

		if ( $scheme === 'http' ) {
			// http is only acceptable for loopback (RFC 8252, native apps).
			$host = self::bare_host( (string) wp_parse_url( $uri, PHP_URL_HOST ) );
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
	 * Whether a URI could reach a different host than the one PHP reads from it.
	 *
	 * PHP's parse_url() and browsers (WHATWG URL) disagree on backslashes,
	 * userinfo, percent-encoded and non-ASCII hosts: `https://evil.example\@claude.ai/cb`
	 * is host claude.ai to PHP and evil.example to a browser. Any of those makes
	 * the consent screen and the host checks untrustworthy, so they are refused.
	 *
	 * @param string $uri The redirect URI.
	 *
	 * @return bool Whether the URI must be refused.
	 * @since 1.5.1
	 */
	public static function has_ambiguous_authority( string $uri ): bool {
		if ( str_contains( $uri, '\\' ) || preg_match( '/[^\x21-\x7e]/', $uri ) === 1 ) {
			return true;
		}

		if ( preg_match( '#^([A-Za-z][A-Za-z0-9+.-]*)://#', $uri, $prefix ) !== 1 ) {
			// No authority, as in a private-use `com.example.app:/cb`.
			return false;
		}

		$rest      = substr( $uri, strlen( $prefix[0] ) );
		$authority = substr( $rest, 0, strcspn( $rest, '/?#' ) );

		// A private-use scheme is opened by the operating system, not a browser
		// URL parser; only userinfo could pass for a different destination.
		if ( ! in_array( strtolower( $prefix[1] ), [ 'http', 'https' ], true ) ) {
			return str_contains( $authority, '@' );
		}

		// A host name, or a bracketed IPv6 literal, with an optional port. No
		// userinfo, no percent-encoding.
		if ( preg_match( '/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])(?::[0-9]{1,5})?$/', $authority ) !== 1 ) {
			return true;
		}

		$host = wp_parse_url( $uri, PHP_URL_HOST );

		return ! is_string( $host ) || strtolower( $host ) !== strtolower( (string) preg_replace( '/:[0-9]{1,5}$/', '', $authority ) );
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
			if ( self::extract_scheme( $uri ) !== 'https' ) {
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
	private static function extract_scheme( string $uri ): string {
		if ( preg_match( '#^([a-zA-Z][a-zA-Z0-9+\-.]*):#', $uri, $matches ) ) {
			return strtolower( $matches[1] );
		}

		return '';
	}

	/**
	 * Whether an https redirect host is safe (not an internal/reserved target).
	 *
	 * @param string $host        The host component.
	 * @param bool   $resolve_dns Whether to resolve a host name to its address.
	 *
	 * @return bool Whether the host is acceptable.
	 * @since 1.3.1
	 * @since 1.5.1 Checks bracketed IPv6 literals; DNS lookup optional.
	 */
	private static function is_safe_https_host( string $host, bool $resolve_dns = true ): bool {
		// Development convenience: allow loopback/internal https in local/dev.
		if ( in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
			return true;
		}

		$host = self::bare_host( $host );
		if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
			return false;
		}

		// Resolve a hostname to an IPv4 address (best-effort); literal IPs pass through.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ip = $host;
		} elseif ( $resolve_dns ) {
			$ip = gethostbyname( $host );
		} else {
			return true;
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			// Not an IP and DNS did not resolve — allow (the browser, not the
			// server, follows this redirect; exact-match + PKCE remain the gate).
			return true;
		}

		// An IPv4-mapped IPv6 address (::ffff:127.0.0.1) is judged as the IPv4
		// address it carries: PHP before 8.2 does not count the mapped range as
		// reserved, so the range filter below would let it through.
		$ip = self::unmap_ipv4( $ip );

		// Reject RFC 1918 private and IANA reserved ranges (covers loopback,
		// link-local, and other reserved space).
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		// CGNAT 100.64.0.0/10 is not covered by the reserved-range filter.
		if ( self::ip_in_cidr( $ip, '100.64.0.0', 10 ) ) {
			return false;
		}

		return true;
	}

	/**
	 * A host lowercased, without the brackets PHP keeps around an IPv6 literal.
	 *
	 * @param string $host The host from wp_parse_url().
	 *
	 * @return string
	 * @since 1.5.1
	 */
	private static function bare_host( string $host ): string {
		return strtolower( trim( $host, '[]' ) );
	}

	/**
	 * The IPv4 address inside an IPv4-mapped IPv6 address, or the address unchanged.
	 *
	 * @param string $ip A valid IP address.
	 *
	 * @return string
	 * @since 1.5.1
	 */
	private static function unmap_ipv4( string $ip ): string {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed address returns false; that is the answer.

		if ( is_string( $packed ) && strlen( $packed ) === 16 && substr( $packed, 0, 12 ) === str_repeat( "\0", 10 ) . "\xff\xff" ) {
			return (string) inet_ntop( substr( $packed, 12 ) );
		}

		return $ip;
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
	private static function ip_in_cidr( string $ip, string $subnet, int $bits ): bool {
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
		$scheme = self::extract_scheme( $uri );

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
		$scheme = self::extract_scheme( $uri );

		/**
		 * Fires when a client registration is rejected.
		 *
		 * @since 1.3.1
		 * @since 1.5.0 Also fires for `client_limit_reached`, with an empty scheme and URI.
		 *
		 * @param string $reason      The machine-readable rejection reason.
		 * @param string $scheme      The rejected URI's scheme, or '' when no URI was at fault.
		 * @param string $client_name The client name from the request.
		 * @param string $uri         The full rejected URI, or ''.
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
	 * The registration cap, after the `albert/oauth/max_clients` filter. 0 means no cap.
	 *
	 * @return int
	 * @since 1.5.0
	 */
	public static function max_clients(): int {
		return (int) apply_filters( 'albert/oauth/max_clients', self::DEFAULT_MAX_CLIENTS );
	}

	/**
	 * How many unfinished registrations are kept, after the
	 * `albert/oauth/max_pending_clients` filter. At least 1.
	 *
	 * @return int
	 * @since 1.5.1
	 */
	public static function max_pending_clients(): int {
		/**
		 * Filters how many registrations that never got a token are kept.
		 *
		 * When reached, the oldest one not mid sign-in is deleted to make room.
		 *
		 * @since 1.5.1
		 *
		 * @param int $max Default 100.
		 */
		return max( 1, (int) apply_filters( 'albert/oauth/max_pending_clients', self::MAX_PENDING_CLIENTS ) );
	}

	/**
	 * Whether the registration cap is reached, so a new client would be refused.
	 *
	 * Counts connected clients only, so anonymous registrations cannot fill it.
	 *
	 * @param ClientRepository|null $repository Repository to count with.
	 *
	 * @return bool
	 * @phpstan-impure
	 * @since 1.5.0
	 */
	public static function limit_reached( ?ClientRepository $repository = null ): bool {
		$max = self::max_clients();

		return $max > 0 && ( $repository ?? new ClientRepository() )->count_live_clients() >= $max;
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
