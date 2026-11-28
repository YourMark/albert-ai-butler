<?php
/**
 * Upload Link Redemption Controller
 *
 * @package Albert
 * @subpackage Media\UploadLinks
 * @since      1.4.0
 */

namespace Albert\Media\UploadLinks;

defined( 'ABSPATH' ) || exit;

use Albert\Abstracts\BaseAbility;
use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\Plugin;
use Albert\Media\TempFile;
use Albert\Undo\Recorder;
use Albert\Undo\Window;
use Albert\Undo\WindowContext;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The redemption endpoint an assistant PUTs/POSTs bytes to after minting a
 * link via `albert/create-upload-link`. Deliberately public — the link
 * token, sent only in a header, is the credential.
 *
 * @since 1.4.0
 */
class UploadLinkController implements Hookable {

	/**
	 * Synthetic ability id this endpoint logs under. Not a registered WP_Ability.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const LOG_ABILITY_ID = 'albert/redeem-upload-link';

	/**
	 * Bytes read per chunk while streaming a raw request body to disk.
	 *
	 * Fixed, not derived from `max_bytes` — sizing to the cap would force
	 * buffering the whole allowed size before it could even be checked.
	 * 128 KiB, not the 1 MiB some competitors use: at this scale (local
	 * disk, tens-of-MB media files) it keeps iteration count low without
	 * costing much per-request memory under concurrent uploads.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const STREAM_CHUNK_BYTES = 131072;

	/**
	 * The upload link service.
	 *
	 * @since 1.4.0
	 * @var UploadLinkService
	 */
	private UploadLinkService $links;

	/**
	 * Constructor.
	 *
	 * @param UploadLinkService|null $links Optional service override (tests).
	 *
	 * @since 1.4.0
	 */
	public function __construct( ?UploadLinkService $links = null ) {
		$this->links = $links ?? new UploadLinkService();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the redemption REST route.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::rest_namespace(),
			'/media/uploads',
			[
				'methods'             => UploadLinkService::HTTP_METHODS,
				'callback'            => [ $this, 'handle_upload' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle a link redemption.
	 *
	 * Order matters: redeem (mark used) before any processing, then receive
	 * the file under the byte cap, then hand off to core.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.4.0
	 */
	public function handle_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token = trim( (string) $request->get_header( UploadLinkService::TOKEN_HEADER ) );

		if ( $token === '' ) {
			// Its own code, not the invalid/spent one. Nothing was presented,
			// so there is nothing to enumerate here, and telling a client its
			// link was already used when it simply forgot the header sends it
			// off to mint a replacement it does not need.
			return new WP_Error(
				'missing_token',
				sprintf(
					/* translators: %s: the HTTP header name the token belongs in */
					__( 'No upload token was provided. Send it in the %s header.', 'albert-ai-butler' ),
					UploadLinkService::TOKEN_HEADER
				),
				[ 'status' => 401 ]
			);
		}

		// Burns the token — single-use holds from this point on.
		$context = $this->links->redeem_link( $token );

		if ( is_wp_error( $context ) ) {
			// Only 'capability_revoked' carries a resolved user_id; other rejections never got that far.
			$error_data = $context->get_error_data();
			$user_id    = is_array( $error_data ) && isset( $error_data['user_id'] ) ? (int) $error_data['user_id'] : 0;

			$this->log( $user_id, [], $context );

			return $this->scrub_error( $context );
		}

		// Act as the issuing user for the rest of the request: core stamps
		// post_author from the current user, and its own MIME re-check inside
		// _wp_handle_upload() otherwise runs against the anonymous allowlist
		// and can reject a type this link legitimately advertised. Cannot
		// widen anything — finalize_upload() has already rejected everything
		// outside the link's own (narrower) allowlist by the time core looks.
		wp_set_current_user( $context['user_id'] );

		$received = $this->receive_file( $request, $context['max_bytes'] );

		if ( is_wp_error( $received ) ) {
			$this->log( $context['user_id'], [ 'post_id' => $context['post_id'] ], $received );

			return $received;
		}

		// The upload is the connection's call, made by another route: record it
		// in the change journal like any tool call.
		$recording = $context['client_id'] !== null && Window::open(
			new WindowContext( WindowContext::SOURCE_UPLOAD, self::LOG_ABILITY_ID, $context['user_id'], $context['client_id'], $context['client_name'] )
		);
		$status    = Recorder::STATUS_INTERRUPTED;

		try {
			$result = $this->links->finalize_upload( $received['tmp_path'], $received['filename'], $context );

			$this->log(
				$context['user_id'],
				[
					'post_id'  => $context['post_id'],
					'filename' => $received['filename'],
				],
				$result
			);
			$status = Recorder::STATUS_RECORDED;
		} finally {
			if ( $recording ) {
				Window::close( $status );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Receive the uploaded bytes onto disk, enforcing the link's byte cap.
	 *
	 * Supports a multipart `file` field, or a raw body streamed to disk in
	 * chunks so an oversized body is never buffered in memory.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request   The REST request.
	 * @param int                                   $max_bytes The link's byte cap.
	 *
	 * @return array{tmp_path: string, filename: string}|WP_Error
	 * @since 1.4.0
	 */
	private function receive_file( WP_REST_Request $request, int $max_bytes ): array|WP_Error {
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;

		// Checked before tmp_name, which PHP leaves empty on its own size
		// rejections — without this an oversized multipart body falls through
		// to the raw-body branch and answers "filename parameter required".
		if ( $file !== null && ! empty( $file['error'] ) ) {
			$code = (int) $file['error'];

			if ( UPLOAD_ERR_INI_SIZE === $code || UPLOAD_ERR_FORM_SIZE === $code ) {
				return $this->too_large_error( $max_bytes );
			}

			if ( UPLOAD_ERR_NO_FILE === $code ) {
				return $this->no_data_error();
			}

			return new WP_Error(
				'upload_error',
				__( 'The uploaded file could not be received.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		if ( $file !== null && ! empty( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ) {
			// PHP populates `size` for every entry it puts in $_FILES, including
			// its own rejections, where it is 0 — which the check below already
			// treats as "no data". There is nothing for a fallback to do.
			$size = (int) $file['size'];

			if ( $size === 0 ) {
				TempFile::delete( $file['tmp_name'] );

				return $this->no_data_error();
			}

			if ( $size > $max_bytes ) {
				TempFile::delete( $file['tmp_name'] );

				return $this->too_large_error( $max_bytes );
			}

			return [
				'tmp_path' => $file['tmp_name'],
				'filename' => $file['name'],
			];
		}

		$filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );

		if ( $filename === '' ) {
			return new WP_Error(
				'type_not_allowed',
				__( 'A "filename" parameter with a valid extension is required for a raw-body upload.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		// Only on this path is Content-Length the size of the file. A multipart
		// body also carries boundaries, part headers and the filename field, so
		// comparing that total against a file-size cap rejects a file that is
		// exactly at the limit; that branch checks the real size above instead.
		if ( $this->declared_body_size() > $max_bytes ) {
			return $this->too_large_error( $max_bytes );
		}

		// WP_REST_Server::serve_request() has already read the whole body into
		// memory (set_body( get_raw_data() )) before any route callback runs,
		// so neither this loop nor the check above bounds MEMORY; that is the
		// web server's own body limit. What the loop still buys is a bound on
		// what reaches DISK when a client understates Content-Length.
		$input = fopen( 'php://input', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming an HTTP request body, not a filesystem file.

		if ( ! $input ) {
			return new WP_Error(
				'upload_error',
				__( 'Could not read the request body.', 'albert-ai-butler' ),
				[ 'status' => 500 ]
			);
		}

		$tmp_path = $this->stream_to_temp_file( $input, $max_bytes );
		fclose( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fopen() above.

		if ( is_wp_error( $tmp_path ) ) {
			return $tmp_path;
		}

		return [
			'tmp_path' => $tmp_path,
			'filename' => $filename,
		];
	}

	/**
	 * Stream a readable resource to a new temp file, enforcing a byte cap
	 * while writing. Public so it's testable against an in-memory stream.
	 *
	 * @param resource $stream    A readable stream (e.g. `php://input`).
	 * @param int      $max_bytes The byte cap to enforce.
	 *
	 * @return string|WP_Error Path to the temp file, or a `too_large`/`upload_error` WP_Error.
	 * @since 1.4.0
	 */
	public function stream_to_temp_file( $stream, int $max_bytes ): string|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp_path = wp_tempnam();
		$out      = fopen( $tmp_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writing a controlled temp file, not arbitrary user input as a path.

		if ( ! $out ) {
			return new WP_Error(
				'upload_error',
				__( 'Could not create a temporary file.', 'albert-ai-butler' ),
				[ 'status' => 500 ]
			);
		}

		$total        = 0;
		$too_large    = false;
		$write_failed = false;

		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, self::STREAM_CHUNK_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading a stream resource in chunks, not a filesystem file by path.

			if ( $chunk === false || $chunk === '' ) {
				break;
			}

			$total += strlen( $chunk );

			if ( $total > $max_bytes ) {
				$too_large = true;
				break;
			}

			// A short/failed write must not be mistaken for success — $total tracks bytes read, not bytes landed.
			if ( fwrite( $out, $chunk ) !== strlen( $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to a controlled temp file.
				$write_failed = true;
				break;
			}
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fopen() above.

		if ( $write_failed ) {
			wp_delete_file( $tmp_path );

			return new WP_Error(
				'upload_error',
				__( 'Could not write the uploaded file to disk.', 'albert-ai-butler' ),
				[ 'status' => 500 ]
			);
		}

		if ( $too_large ) {
			wp_delete_file( $tmp_path );

			return $this->too_large_error( $max_bytes );
		}

		if ( $total === 0 ) {
			wp_delete_file( $tmp_path );

			return $this->no_data_error();
		}

		return $tmp_path;
	}

	/**
	 * The size the client says it is sending, or 0 when it says nothing.
	 *
	 * @return int
	 * @since 1.4.0
	 */
	private function declared_body_size(): int {
		if ( ! isset( $_SERVER['CONTENT_LENGTH'] ) ) {
			return 0;
		}

		return absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) );
	}

	/**
	 * The one `too_large` rejection, so both receive paths answer identically.
	 *
	 * @param int $max_bytes The link's byte cap.
	 *
	 * @return WP_Error
	 * @since 1.4.0
	 */
	private function too_large_error( int $max_bytes ): WP_Error {
		return new WP_Error(
			'too_large',
			__( 'The uploaded file exceeds the size allowed for this upload link.', 'albert-ai-butler' ),
			[
				'status'    => 413,
				'max_bytes' => $max_bytes,
			]
		);
	}

	/**
	 * The one empty-body rejection, so both receive paths answer identically.
	 *
	 * @return WP_Error
	 * @since 1.4.0
	 */
	private function no_data_error(): WP_Error {
		return new WP_Error(
			'upload_error',
			__( 'No file data was received.', 'albert-ai-butler' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Strip internal detail from an error before it reaches an unauthenticated caller.
	 *
	 * `capability_revoked` carries the issuing user's id so {@see self::log()}
	 * can attribute the failure; that id must not also be returned over the wire.
	 *
	 * @param WP_Error $error The error to scrub.
	 *
	 * @return WP_Error
	 * @since 1.4.0
	 */
	private function scrub_error( WP_Error $error ): WP_Error {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) || ! isset( $data['user_id'] ) ) {
			return $error;
		}

		unset( $data['user_id'] );

		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/**
	 * Log a redemption attempt through Albert's normal execution-log path.
	 *
	 * Not a WP_Ability, so `guarded_execute()` never runs for it — this fires
	 * the same hook pair {@see BaseAbility::fire_after_execute_hooks()} fires
	 * for every real ability, directly instead.
	 *
	 * @param int                           $user_id The issuing user (0 when the token itself was rejected).
	 * @param array<string, mixed>          $args    Non-sensitive context: post_id, filename. Never the token.
	 * @param array<string, mixed>|WP_Error $result  The outcome.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function log( int $user_id, array $args, array|WP_Error $result ): void {
		BaseAbility::fire_after_execute_hooks( self::LOG_ABILITY_ID, $args, $result, $user_id );
	}
}
