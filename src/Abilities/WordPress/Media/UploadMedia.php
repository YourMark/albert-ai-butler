<?php
/**
 * Upload Media Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Media
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Media;

defined( 'ABSPATH' ) || exit;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use Albert\Media\AttachmentImporter;
use Albert\Media\MimeAllowlist;
use WP_Error;

/**
 * Upload Media Ability class
 *
 * Allows AI assistants to upload media files to the WordPress media library
 * by sideloading from a URL. This is the only supported method since AI
 * assistants cannot access local files or send binary data through MCP.
 *
 * @since 1.0.0
 */
class UploadMedia extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/upload-media';
		$this->label       = __( 'Upload Media', 'albert-ai-butler' );
		$this->description = __( 'Upload media to WordPress by sideloading from a URL.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'media';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::create(
				'Check with `albert/find-media` before uploading: re-uploading a file the library already '
				. 'holds leaves two copies and the wrong one gets used. This sideloads from a URL the site '
				. 'must be able to reach, a local path or a link behind a login will fail. Always send '
				. '`alt` unless the image is decorative.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> Input schema.
	 * @since 1.0.0
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'url'      => [
					'type'        => 'string',
					'description' => 'URL of the image to sideload into WordPress',
				],
				'filename' => [
					'type'        => 'string',
					'description' => 'Optional filename to use (defaults to filename from URL)',
				],
				'title'    => [
					'type'        => 'string',
					'description' => 'Title for the media item in the media library',
					'default'     => '',
				],
				'alt_text' => [
					'type'        => 'string',
					'description' => 'Alternative text for images',
					'default'     => '',
				],
				'caption'  => [
					'type'        => 'string',
					'description' => 'Caption for the media item',
					'default'     => '',
				],
				'post_id'  => [
					'type'        => 'integer',
					'description' => 'Optional post ID to attach the media to',
					'default'     => 0,
				],
			],
			'required'   => [ 'url' ],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> Output schema.
	 * @since 1.0.0
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'attachment_id' => [
					'type'        => 'integer',
					'description' => 'WordPress attachment ID',
				],
				'url'           => [
					'type'        => 'string',
					'description' => 'URL of the uploaded attachment',
				],
				'mime_type'     => [
					'type'        => 'string',
					'description' => 'MIME type of the file',
				],
				'file_size'     => [
					'type'        => 'integer',
					'description' => 'File size in bytes',
				],
			],
		];
	}

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'upload_files' );
	}

	/**
	 * Execute the ability - sideload media from URL.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $url      URL to sideload from.
	 *     @type string $filename Optional filename.
	 *     @type string $title    Media title (optional).
	 *     @type string $alt_text Image alt text (optional).
	 *     @type string $caption  Media caption (optional).
	 *     @type int    $post_id  Post ID to attach to (optional).
	 * }
	 * @return array<string, mixed>|WP_Error Attachment data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		$url = esc_url_raw( $args['url'] );

		// Validate URL format.
		if ( ! preg_match( '/^https?:\/\//i', $url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'Invalid URL format. Must be a valid HTTP or HTTPS URL.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		// (int) cast, not absint(): absint(-12) returns 12, which would
		// silently attach to a post the caller never asked for.
		$post_id = max( 0, (int) ( $args['post_id'] ?? 0 ) );
		// Core's media endpoint asks the same before attaching to a parent. A
		// missing post fails it too, with the same answer, so the error does not
		// tell anyone which post IDs exist.
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'cannot_attach',
				__( 'That post does not exist, or you may not attach media to it.', 'albert-ai-butler' ),
				[ 'status' => 403 ]
			);
		}

		// download_url() needs this one; AttachmentImporter requires the rest itself.
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$temp_file = download_url( $url );

		if ( is_wp_error( $temp_file ) ) {
			return new WP_Error(
				'download_failed',
				__( 'Failed to download file from URL.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		// Determine filename from URL or provided filename.
		$filename = ! empty( $args['filename'] )
			? sanitize_file_name( $args['filename'] )
			: basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		// Sniffed against the current user's own allowlist, never a blanket
		// default, and never widened by an unfiltered_upload capability. The
		// importer consumes the temp file whichever way this goes.
		$result = AttachmentImporter::import(
			$temp_file,
			$filename,
			MimeAllowlist::for_user( get_current_user_id() ),
			$post_id
		);

		if ( is_wp_error( $result ) ) {
			return self::translate_import_error( $result );
		}

		// Title, alt text and caption only; none of them appear in the response,
		// so what the importer already built stays accurate.
		$this->set_attachment_metadata( (int) $result['attachment_id'], $args );

		return $result;
	}

	/**
	 * Keep this ability's long-standing error codes over the importer's.
	 *
	 * The shared importer speaks the upload-link endpoint's vocabulary, which
	 * is HTTP-shaped. This ability has answered `invalid_file_type` since
	 * 1.0.0 and that is part of what callers see, so translate rather than
	 * quietly renaming it.
	 *
	 * @param WP_Error $error The importer's error.
	 *
	 * @return WP_Error
	 * @since 1.4.0
	 */
	private static function translate_import_error( WP_Error $error ): WP_Error {
		if ( $error->get_error_code() !== 'type_not_allowed' ) {
			return $error;
		}

		return new WP_Error(
			'invalid_file_type',
			__( 'Invalid or unsupported file type.', 'albert-ai-butler' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Set attachment metadata from args.
	 *
	 * @param int                  $attachment_id The attachment ID.
	 * @param array<string, mixed> $args          The input arguments.
	 * @return void
	 * @since 1.0.0
	 */
	private function set_attachment_metadata( int $attachment_id, array $args ): void {
		// Set title if provided.
		if ( ! empty( $args['title'] ) ) {
			wp_update_post(
				[
					'ID'         => $attachment_id,
					'post_title' => sanitize_text_field( $args['title'] ),
				]
			);
		}

		// Set alt text if provided.
		if ( ! empty( $args['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt_text'] ) );
		}

		// Set caption if provided.
		if ( ! empty( $args['caption'] ) ) {
			wp_update_post(
				[
					'ID'           => $attachment_id,
					'post_excerpt' => sanitize_text_field( $args['caption'] ),
				]
			);
		}
	}
}
