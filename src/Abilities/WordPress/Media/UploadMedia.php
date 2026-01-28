<?php
/**
 * Upload Media Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Media
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Media;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
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
		$this->label       = __( 'Upload Media', 'albert' );
		$this->description = __( 'Upload media to WordPress by sideloading from a URL.', 'albert' );
		$this->category    = 'content';
		$this->group       = 'media';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::create(),
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
	 * @return true|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): true|WP_Error {
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
		// Validate required URL.
		if ( empty( $args['url'] ) ) {
			return new WP_Error(
				'missing_url',
				__( 'URL is required.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		$url = esc_url_raw( $args['url'] );

		// Validate URL format.
		if ( ! preg_match( '/^https?:\/\//i', $url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'Invalid URL format. Must be a valid HTTP or HTTPS URL.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		// Validate post_id if provided.
		$post_id = absint( $args['post_id'] ?? 0 );
		if ( ! empty( $post_id ) ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error(
					'invalid_post',
					__( 'The specified post does not exist.', 'albert' ),
					[ 'status' => 404 ]
				);
			}
		}

		// Download the file.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$temp_file = download_url( $url );

		if ( is_wp_error( $temp_file ) ) {
			return new WP_Error(
				'download_failed',
				__( 'Failed to download file from URL.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		// Determine filename from URL or provided filename.
		$filename = ! empty( $args['filename'] )
			? sanitize_file_name( $args['filename'] )
			: basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		// Validate file type.
		$file_type = wp_check_filetype_and_ext( $temp_file, $filename );

		if ( ! $file_type['type'] || ! $file_type['ext'] ) {
			wp_delete_file( $temp_file );
			return new WP_Error(
				'invalid_file_type',
				__( 'Invalid or unsupported file type.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		// Prepare file array for sideloading.
		$file_array = [
			'name'     => $filename,
			'tmp_name' => $temp_file,
		];

		// Sideload the file.
		$attachment_id = media_handle_sideload( $file_array, $post_id );

		// Clean up temp file if still exists.
		if ( file_exists( $temp_file ) ) {
			wp_delete_file( $temp_file );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Set metadata.
		$this->set_attachment_metadata( $attachment_id, $args );

		// Return formatted response.
		return $this->format_attachment_response( $attachment_id );
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

	/**
	 * Format attachment data for response.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return array<string, mixed> Formatted attachment data.
	 * @since 1.0.0
	 */
	private function format_attachment_response( int $attachment_id ): array {
		$metadata  = wp_get_attachment_metadata( $attachment_id );
		$file_size = $metadata['filesize'] ?? 0;

		if ( empty( $file_size ) ) {
			$attached_file = get_attached_file( $attachment_id );
			$file_size     = $attached_file ? filesize( $attached_file ) : 0;
		}

		return [
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'width'         => $metadata['width'] ?? 0,
			'height'        => $metadata['height'] ?? 0,
			'mime_type'     => get_post_mime_type( $attachment_id ),
			'file_size'     => $file_size ? $file_size : 0,
		];
	}
}
