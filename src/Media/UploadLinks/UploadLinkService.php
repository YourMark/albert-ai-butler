<?php
/**
 * Upload Link Service
 *
 * @package Albert
 * @subpackage Media\UploadLinks
 * @since      1.4.0
 */

namespace Albert\Media\UploadLinks;

defined( 'ABSPATH' ) || exit;

use Albert\Core\Plugin;
use Albert\Core\Tokens\TokenService;
use Albert\Media\AttachmentImporter;
use Albert\Media\MimeAllowlist;
use Albert\OAuth\Server\ConnectionContext;
use Albert\Settings\Value;
use WP_Error;

/**
 * Mints and redeems media upload links (doc 32, Path B), built on the
 * generic {@see TokenService} primitive.
 *
 * @since 1.4.0
 */
class UploadLinkService {

	/**
	 * The token purpose partitioning upload links from any other token consumer.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const PURPOSE = 'media_upload';

	/**
	 * The capability required to mint a link and to still hold one at redemption time.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const REQUIRED_CAPABILITY = 'upload_files';

	/**
	 * The header the redemption endpoint expects the token in — never the URL.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const TOKEN_HEADER = 'X-Albert-Upload-Token';

	/**
	 * HTTP methods the redemption endpoint accepts.
	 *
	 * Both, deliberately: PUT suits raw-body clients, POST suits multipart ones.
	 * Comma-separated because that is the shape `register_rest_route()` wants,
	 * the same one core's own `WP_REST_Server::EDITABLE` uses. Callers are told
	 * about it through {@see self::method_list()}, never this string.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const HTTP_METHODS = 'POST, PUT';

	/**
	 * The accepted methods as a list, for the assistant reading the response.
	 *
	 * Derived from {@see self::HTTP_METHODS} rather than written out again, so
	 * the route and the advertised methods cannot drift apart. A list, because
	 * the consumer is a machine: handing it "POST, PUT" invites it to send that
	 * whole string as the method.
	 *
	 * @return array<int, string>
	 * @since 1.4.0
	 */
	public static function method_list(): array {
		return array_map( 'trim', explode( ',', self::HTTP_METHODS ) );
	}

	/**
	 * How long a minted link stays valid.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const TTL_SECONDS = 600;

	/**
	 * Conservative default byte cap, used when a caller doesn't request one
	 * AND neither the filter nor the site owner's own setting apply.
	 * Always additionally capped to {@see wp_max_upload_size()}.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const DEFAULT_MAX_BYTES = self::DEFAULT_MAX_MB * self::BYTES_PER_MB;

	/**
	 * The above, expressed in MB — what the Settings screen field and its
	 * stored option actually deal in (bytes are not a friendly unit to type
	 * into a form).
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const DEFAULT_MAX_MB = 10;

	/**
	 * Option storing the site owner's own default byte cap, in MB. Set via
	 * Albert → Settings → Uploads.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const MAX_BYTES_OPTION = 'albert_upload_link_max_mb';

	/**
	 * Upper bound accepted for the Settings field, guarding against garbage
	 * input rather than expressing a real ceiling — {@see wp_max_upload_size()}
	 * is what actually limits an upload regardless of this setting.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const MAX_SETTABLE_MB = 2048; // 2 GB.

	/**
	 * Bytes per MB, for converting the MB-denominated option/setting to bytes.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const BYTES_PER_MB = 1048576;

	/**
	 * The token service.
	 *
	 * @since 1.4.0
	 * @var TokenService
	 */
	private TokenService $tokens;

	/**
	 * Constructor.
	 *
	 * @param TokenService|null $tokens Optional token service override (tests).
	 *
	 * @since 1.4.0
	 */
	public function __construct( ?TokenService $tokens = null ) {
		$this->tokens = $tokens ?? new TokenService();
	}

	/**
	 * Mint a new upload link.
	 *
	 * @param int                  $user_id The issuing user — re-checked at redemption.
	 * @param array<string, mixed> $args    {
	 *     Input parameters.
	 *
	 *     @type array<int, string> $accepted_types Optional MIME types to narrow the allowlist to.
	 *     @type int                $max_bytes      Optional byte cap request (capped by wp_max_upload_size()).
	 *     @type int                $post_id        Optional post to parent the resulting attachment to.
	 * }
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.4.0
	 */
	public function mint( int $user_id, array $args ): array|WP_Error {
		if ( ! user_can( $user_id, self::REQUIRED_CAPABILITY ) ) {
			return new WP_Error(
				'ability_permission_denied',
				__( 'This user does not have permission to upload media.', 'albert-ai-butler' ),
				[ 'status' => 403 ]
			);
		}

		$requested_types = array_values(
			array_filter( array_map( 'strval', (array) ( $args['accepted_types'] ?? [] ) ) )
		);
		$allowlist       = MimeAllowlist::for_user( $user_id, $requested_types );

		if ( empty( $allowlist ) ) {
			return new WP_Error(
				'no_accepted_types',
				__( 'None of the requested file types are allowed for this user.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		// (int) cast, not absint(): absint(-12) returns 12, which would
		// silently parent against a post the caller never asked for.
		$post_id = max( 0, (int) ( $args['post_id'] ?? 0 ) );
		if ( $post_id > 0 && ! get_post( $post_id ) ) {
			return new WP_Error(
				'invalid_post',
				__( 'The specified post does not exist.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		$max_bytes = $this->resolve_max_bytes( (int) ( $args['max_bytes'] ?? 0 ) );

		$issued = $this->tokens->issue(
			self::PURPOSE,
			$user_id,
			[
				'mime_allowlist' => $allowlist,
				'max_bytes'      => $max_bytes,
				'post_id'        => $post_id,
				// So the upload is recorded in the change journal as the
				// connection's own call, though it arrives without a token.
				'client_id'      => ConnectionContext::client_id(),
				'client_name'    => ConnectionContext::client_name(),
			],
			self::TTL_SECONDS
		);

		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$upload_url = rest_url( Plugin::rest_namespace() . '/media/uploads' );

		return [
			'upload_url'     => $upload_url,
			'upload_token'   => $issued['token'],
			'token_header'   => self::TOKEN_HEADER,
			'methods'        => self::method_list(),
			'expires_at'     => $issued['expires_at'],
			'max_bytes'      => $max_bytes,
			'accepted_types' => MimeAllowlist::mime_list( $allowlist ),
			'post_id'        => $post_id,
			'curl_example'   => $this->curl_example( $upload_url, $issued['token'] ),
		];
	}

	/**
	 * Redeem a link token, re-checking the issuing user's capabilities.
	 *
	 * The MIME allowlist is re-narrowed against the user's *current*
	 * `get_allowed_mime_types()`, so a role downgrade since mint can only
	 * shrink what's accepted, never widen it.
	 *
	 * @param string $token The raw link token.
	 *
	 * @return array{user_id: int, mime_allowlist: array<string, string>, max_bytes: int, post_id: int, client_id: string|null, client_name: string|null}|WP_Error
	 * @since 1.4.0
	 */
	public function redeem_link( string $token ): array|WP_Error {
		$redeemed = $this->tokens->redeem( $token, self::PURPOSE );

		if ( is_wp_error( $redeemed ) ) {
			return $this->translate_token_error( $redeemed );
		}

		$user_id = $redeemed['user_id'];
		$payload = $redeemed['payload'];

		if ( ! user_can( $user_id, self::REQUIRED_CAPABILITY ) ) {
			return new WP_Error(
				'capability_revoked',
				__( 'The user who created this upload link no longer has permission to upload media.', 'albert-ai-butler' ),
				// user_id rides in the error data so the controller can still log the real actor.
				[
					'status'  => 403,
					'user_id' => $user_id,
				]
			);
		}

		$stored_allowlist    = is_array( $payload['mime_allowlist'] ?? null ) ? $payload['mime_allowlist'] : [];
		$current_allowlist   = get_allowed_mime_types( $user_id );
		$effective_allowlist = MimeAllowlist::intersect( $current_allowlist, MimeAllowlist::mime_list( $stored_allowlist ) );

		if ( empty( $effective_allowlist ) ) {
			return new WP_Error(
				'capability_revoked',
				__( 'The user who created this upload link no longer has permission to upload any of the accepted file types.', 'albert-ai-butler' ),
				[
					'status'  => 403,
					'user_id' => $user_id,
				]
			);
		}

		return [
			'user_id'        => $user_id,
			'mime_allowlist' => $effective_allowlist,
			// Re-ceilinged, not just read back: the server's own limit can have
			// been lowered since this link was minted, and the stored number
			// would then promise more than core will accept.
			'max_bytes'      => $this->apply_server_ceiling( (int) ( $payload['max_bytes'] ?? self::DEFAULT_MAX_BYTES ) ),
			'post_id'        => (int) ( $payload['post_id'] ?? 0 ),
			'client_id'      => is_string( $payload['client_id'] ?? null ) ? $payload['client_id'] : null,
			'client_name'    => is_string( $payload['client_name'] ?? null ) ? $payload['client_name'] : null,
		];
	}

	/**
	 * Turn a received, on-disk file into a media library attachment.
	 *
	 * Thin wrapper over {@see AttachmentImporter::import()}, which both upload
	 * paths share; this keeps the link domain's own vocabulary at the seam the
	 * controller calls. Content sniffing runs against the link's own allowlist,
	 * never `unfiltered_upload`. The temp file is always consumed.
	 *
	 * @param string                                                     $tmp_path          Path to the already-received file.
	 * @param string                                                     $original_filename Client-supplied filename (untrusted).
	 * @param array{mime_allowlist: array<string, string>, post_id: int} $context           From {@see self::redeem_link()}.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.4.0
	 */
	public function finalize_upload( string $tmp_path, string $original_filename, array $context ): array|WP_Error {
		return AttachmentImporter::import(
			$tmp_path,
			$original_filename,
			$context['mime_allowlist'],
			$context['post_id']
		);
	}

	/**
	 * Resolve the max_bytes cap: a caller's request, or the site's own
	 * default, always ceilinged at the server's own upload limit.
	 *
	 * @param int $requested Caller-requested byte cap, 0 for "use the default".
	 *
	 * @return int
	 * @since 1.4.0
	 */
	private function resolve_max_bytes( int $requested ): int {
		return $this->apply_server_ceiling( $requested > 0 ? $requested : $this->default_max_bytes() );
	}

	/**
	 * Clamp a byte cap to what this server will actually accept.
	 *
	 * Applied both when minting and when redeeming, so a link cannot outlive a
	 * tightening of the server's own limit. `wp_max_upload_size()` is
	 * filterable and can return 0 or less on a misconfigured host; treat that
	 * as "no usable ceiling" rather than clamping every upload to nothing.
	 *
	 * @param int $bytes The cap to clamp.
	 *
	 * @return int At least 1.
	 * @since 1.4.0
	 */
	private function apply_server_ceiling( int $bytes ): int {
		$ceiling = (int) wp_max_upload_size();

		return max( 1, $ceiling > 0 ? min( $bytes, $ceiling ) : $bytes );
	}

	/**
	 * Resolve the default byte cap: filter overrides, else the Settings
	 * value, else the built-in default. Still ceilinged by
	 * {@see self::resolve_max_bytes()} against `wp_max_upload_size()`.
	 *
	 * @return int
	 * @since 1.4.0
	 */
	private function default_max_bytes(): int {
		$state = self::get_default_max_bytes_filter_state();
		if ( $state['state'] === 'active' ) {
			return $state['value'];
		}

		// Constant -> albert/settings/value/{option} -> stored option, so a
		// wp-config.php constant pins this the same way it pins any other
		// setting. The byte filter above is checked first and returns exact
		// bytes, because it is the only override that can express a size
		// finer than a megabyte.
		//
		// The default stays explicit: this runs on an MCP request, not in
		// admin, so the default registered by Settings\Storage on `admin_init`
		// does not apply and get_option() would otherwise return false.
		$option_mb = (int) Value::get( self::MAX_BYTES_OPTION, self::DEFAULT_MAX_MB );

		// An override arrives unsanitised, so clamp here rather than trusting it.
		$option_mb = min( $option_mb, self::MAX_SETTABLE_MB );

		return $option_mb > 0 ? $option_mb * self::BYTES_PER_MB : self::DEFAULT_MAX_BYTES;
	}

	/**
	 * Get the current state of the `albert/media/upload_link_max_bytes` filter.
	 *
	 * Used by both the resolver above and the Settings screen. Deliberately
	 * not memoized (unlike {@see \Albert\MCP\Server::get_external_url_state()})
	 * so a filter that starts or stops applying mid-test doesn't read stale.
	 *
	 * @since 1.4.0
	 *
	 * @return array{state: 'inactive'|'active', value: int, requested: int}
	 */
	public static function get_default_max_bytes_filter_state(): array {
		/**
		 * Filters the default byte cap for a media upload link.
		 *
		 * Applies only when a caller doesn't request `max_bytes` explicitly.
		 * Accepts an int (bytes) or a php.ini-style shorthand string
		 * (`"10M"`, `"2G"`) via {@see wp_convert_hr_to_bytes()}; null defers
		 * to the site's own setting. Clamped to {@see self::MAX_SETTABLE_MB};
		 * `wp_max_upload_size()` still applies on top at redemption time.
		 *
		 * @since 1.4.0
		 *
		 * @param int|string|null $max_bytes The overriding default, or null to defer.
		 */
		$filtered = apply_filters( 'albert/media/upload_link_max_bytes', null );

		$bytes = self::parse_filtered_bytes( $filtered );

		if ( $bytes > 0 ) {
			return [
				'state'     => 'active',
				'value'     => min( $bytes, self::MAX_SETTABLE_MB * self::BYTES_PER_MB ),
				// Unclamped, so SettingsBootstrap::render_max_mb_field() can tell "clamped" from "not".
				'requested' => $bytes,
			];
		}

		return [
			'state'     => 'inactive',
			'value'     => 0,
			'requested' => 0,
		];
	}

	/**
	 * Parse the `albert/media/upload_link_max_bytes` filter's return value
	 * into a positive byte count, or 0 when it isn't a usable value (null,
	 * empty, zero, negative, or a string wp_convert_hr_to_bytes() can't
	 * make sense of).
	 *
	 * @param mixed $value Raw filter return value.
	 *
	 * @return int Bytes, or 0 when not usable.
	 * @since 1.4.0
	 */
	private static function parse_filtered_bytes( $value ): int {
		// is_float too: a filter computing e.g. 1.5 * MB is otherwise dropped
		// silently, and the screen then shows no override at all.
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value > 0 ? (int) $value : 0;
		}

		if ( is_string( $value ) && trim( $value ) !== '' ) {
			$bytes = (int) wp_convert_hr_to_bytes( $value );

			return $bytes > 0 ? $bytes : 0;
		}

		return 0;
	}

	/**
	 * Translate a generic {@see TokenService} error into this domain's vocabulary.
	 *
	 * @param WP_Error $error The generic token error.
	 *
	 * @return WP_Error
	 * @since 1.4.0
	 */
	private function translate_token_error( WP_Error $error ): WP_Error {
		if ( $error->get_error_code() === 'token_expired' ) {
			return new WP_Error(
				'link_expired',
				__( 'This upload link has expired.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		return new WP_Error(
			'link_already_used',
			__( 'This upload link is invalid or has already been used.', 'albert-ai-butler' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Build a ready-to-run curl example for the assistant to act on.
	 *
	 * @param string $upload_url The redemption endpoint.
	 * @param string $token      The raw link token.
	 *
	 * @return string
	 * @since 1.4.0
	 */
	private function curl_example( string $upload_url, string $token ): string {
		return sprintf(
			"curl -X POST '%s' -H '%s: %s' -F 'file=@/path/to/file.jpg'",
			$upload_url,
			self::TOKEN_HEADER,
			$token
		);
	}
}
