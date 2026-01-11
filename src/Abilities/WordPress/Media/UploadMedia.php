<?php
/**
 * Upload Media Ability
 *
 * @package    ExtendedAbilities
 * @subpackage Abilities\WordPress\Media
 * @since      1.0.0
 */

namespace ExtendedAbilities\Abilities\WordPress\Media;

use ExtendedAbilities\Abstracts\BaseAbility;
use WP_Error;
use WP_REST_Request;

/**
 * Upload Media Ability class
 *
 * Allows AI assistants to upload media files to the WordPress media library.
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
		$this->id          = 'wordpress/upload-media';
		$this->label       = __( 'Upload Media', 'extended-abilities' );
		$this->description = __( 'Upload media files (images, videos, documents) to the WordPress media library.', 'extended-abilities' );
		$this->category    = 'wp-extended-abilities-wp-core';
		$this->group       = 'media';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp' => [
				'public' => true,
			],
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
				'file'        => [
					'type'        => 'string',
					'description' => 'File source: can be a URL, local file path, or base64 encoded file data (required)',
				],
				'filename'    => [
					'type'        => 'string',
					'description' => 'The filename for the uploaded file (optional, auto-detected for URLs/paths, required for base64)',
					'default'     => '',
				],
				'title'       => [
					'type'        => 'string',
					'description' => 'Title for the media item in the media library',
					'default'     => '',
				],
				'alt_text'    => [
					'type'        => 'string',
					'description' => 'Alternative text for images',
					'default'     => '',
				],
				'caption'     => [
					'type'        => 'string',
					'description' => 'Caption for the media item',
					'default'     => '',
				],
				'description' => [
					'type'        => 'string',
					'description' => 'Description for the media item',
					'default'     => '',
				],
				'post_id'     => [
					'type'        => 'integer',
					'description' => 'Optional post ID to attach the media to',
					'default'     => 0,
				],
			],
			'required'   => [ 'file' ],
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
				'attachment_id' => [ 'type' => 'integer' ],
				'url'           => [ 'type' => 'string' ],
				'width'         => [ 'type' => 'integer' ],
				'height'        => [ 'type' => 'integer' ],
				'mime_type'     => [ 'type' => 'string' ],
				'file_size'     => [ 'type' => 'integer' ],
			],
			'required'   => [ 'attachment_id', 'url' ],
		];
	}

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * Uses the permission callback from the WordPress REST API endpoint.
	 *
	 * @return bool Whether user has permission.
	 * @since 1.0.0
	 */
	public function check_permission(): bool {
		$server = rest_get_server();
		$routes = $server->get_routes();

		// Get the route for creating media.
		$route = '/wp/v2/media';

		if ( ! isset( $routes[ $route ] ) ) {
			return false;
		}

		// Find the POST method endpoint.
		foreach ( $routes[ $route ] as $endpoint ) {
			if ( isset( $endpoint['methods']['POST'] ) && isset( $endpoint['permission_callback'] ) ) {
				// Create a mock request for permission check.
				$request = new WP_REST_Request( 'POST', $route );

				// Call the permission callback.
				$permission_callback = $endpoint['permission_callback'];

				if ( is_callable( $permission_callback ) ) {
					$result = call_user_func( $permission_callback, $request );

					// Handle WP_Error or boolean response.
					if ( is_wp_error( $result ) ) {
						return false;
					}

					return (bool) $result;
				}
			}
		}

		// Fallback to basic capability check.
		return current_user_can( 'upload_files' );
	}

	/**
	 * Execute the ability - upload media file using REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $file        File source (URL, path, or base64).
	 *     @type string $filename    Filename for the file (optional).
	 *     @type string $title       Media title (optional).
	 *     @type string $alt_text    Image alt text (optional).
	 *     @type string $caption     Media caption (optional).
	 *     @type string $description Media description (optional).
	 *     @type int    $post_id     Post ID to attach to (optional).
	 * }
	 * @return array<string, mixed>|WP_Error Media data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Validate required fields.
		if ( empty( $args['file'] ) ) {
			return new WP_Error(
				'missing_file',
				__( 'File data is required.', 'extended-abilities' ),
				[ 'status' => 400 ]
			);
		}

		$file = $args['file'];

		// If post_id is provided, verify it exists.
		if ( ! empty( $args['post_id'] ) ) {
			$post_id = absint( $args['post_id'] );

			$post_request  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
			$post_response = rest_do_request( $post_request );

			if ( $post_response->is_error() ) {
				// Try pages endpoint.
				$page_request  = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $post_id );
				$page_response = rest_do_request( $page_request );

				if ( $page_response->is_error() ) {
					return new WP_Error(
						'invalid_post',
						__( 'The specified post or page does not exist.', 'extended-abilities' ),
						[ 'status' => 404 ]
					);
				}
			}
		}

		// Determine file source type and get file data.
		$result = $this->process_file_source( $file, $args['filename'] ?? '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$file_data = $result['data'];
		$filename  = $result['filename'];
		$file_type = $result['file_type'];

		// Create REST request for media upload.
		$media_request = new WP_REST_Request( 'POST', '/wp/v2/media' );

		// Set file data as body.
		$media_request->set_body( $file_data );

		// Set headers.
		$media_request->set_header( 'Content-Type', $file_type['type'] );
		$media_request->set_header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );

		// Set parameters.
		if ( ! empty( $args['post_id'] ) ) {
			$media_request->set_param( 'post', absint( $args['post_id'] ) );
		}

		if ( ! empty( $args['title'] ) ) {
			$media_request->set_param( 'title', sanitize_text_field( $args['title'] ) );
		}

		if ( ! empty( $args['alt_text'] ) ) {
			$media_request->set_param( 'alt_text', sanitize_text_field( $args['alt_text'] ) );
		}

		if ( ! empty( $args['caption'] ) ) {
			$media_request->set_param( 'caption', sanitize_text_field( $args['caption'] ) );
		}

		if ( ! empty( $args['description'] ) ) {
			$media_request->set_param( 'description', sanitize_text_field( $args['description'] ) );
		}

		// Execute the media upload request.
		$media_response = rest_do_request( $media_request );
		$server         = rest_get_server();
		$media_data     = $server->response_to_data( $media_response, false );

		// Check for errors.
		if ( is_wp_error( $media_data ) ) {
			return $media_data;
		}

		if ( $media_response->is_error() ) {
			return new WP_Error(
				$media_data['code'] ?? 'rest_error',
				$media_data['message'] ?? __( 'An error occurred while uploading the file.', 'extended-abilities' ),
				[ 'status' => $media_response->get_status() ]
			);
		}

		// Return formatted data.
		return [
			'attachment_id' => $media_data['id'],
			'url'           => $media_data['source_url'] ?? '',
			'width'         => $media_data['media_details']['width'] ?? 0,
			'height'        => $media_data['media_details']['height'] ?? 0,
			'mime_type'     => $media_data['mime_type'] ?? '',
			'file_size'     => $media_data['media_details']['filesize'] ?? 0,
		];
	}

	/**
	 * Process file source - detect and handle URL, local path, or base64 data.
	 *
	 * @param string $file     File source (URL, path, or base64).
	 * @param string $filename Optional filename override.
	 * @return array<string, mixed>|WP_Error Array with 'data', 'filename', 'file_type' on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	private function process_file_source( string $file, string $filename = '' ): array|WP_Error {
		// Check if it's a URL.
		if ( preg_match( '/^https?:\/\//i', $file ) ) {
			return $this->process_url( $file, $filename );
		}

		// Check if it's a local file path.
		if ( file_exists( $file ) ) {
			return $this->process_local_file( $file, $filename );
		}

		// Otherwise, treat as base64 encoded data.
		return $this->process_base64( $file, $filename );
	}

	/**
	 * Process file from URL.
	 *
	 * @param string $url      File URL.
	 * @param string $filename Optional filename override.
	 * @return array<string, mixed>|WP_Error Array with 'data', 'filename', 'file_type' on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	private function process_url( string $url, string $filename = '' ): array|WP_Error {
		// Download the file.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$temp_file = download_url( $url );

		if ( is_wp_error( $temp_file ) ) {
			return new WP_Error(
				'download_failed',
				__( 'Failed to download file from URL.', 'extended-abilities' ),
				[ 'status' => 400 ]
			);
		}

		// Determine filename from URL if not provided.
		if ( empty( $filename ) ) {
			$parsed_url = wp_parse_url( $url );
			$filename   = basename( $parsed_url['path'] ?? 'file' );
		}

		// Check file type.
		$file_type = wp_check_filetype_and_ext( $temp_file, $filename );

		if ( ! $file_type['type'] || ! $file_type['ext'] ) {
			wp_delete_file( $temp_file );
			return new WP_Error(
				'invalid_file_type',
				__( 'Invalid file type.', 'extended-abilities' ),
				[ 'status' => 400 ]
			);
		}

		// Read the file data.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local temp file.
		$file_data = file_get_contents( $temp_file );
		wp_delete_file( $temp_file );

		if ( $file_data === false ) {
			return new WP_Error(
				'read_failed',
				__( 'Failed to read downloaded file.', 'extended-abilities' ),
				[ 'status' => 500 ]
			);
		}

		return [
			'data'      => $file_data,
			'filename'  => $filename,
			'file_type' => $file_type,
		];
	}

	/**
	 * Process file from local file path.
	 *
	 * @param string $path     Local file path.
	 * @param string $filename Optional filename override.
	 * @return array<string, mixed>|WP_Error Array with 'data', 'filename', 'file_type' on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	private function process_local_file( string $path, string $filename = '' ): array|WP_Error {
		// Determine filename from path if not provided.
		if ( empty( $filename ) ) {
			$filename = basename( $path );
		}

		// Check file type.
		$file_type = wp_check_filetype_and_ext( $path, $filename );

		if ( ! $file_type['type'] || ! $file_type['ext'] ) {
			return new WP_Error(
				'invalid_file_type',
				__( 'Invalid file type.', 'extended-abilities' ),
				[ 'status' => 400 ]
			);
		}

		// Read the file data.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file path provided by user.
		$file_data = file_get_contents( $path );

		if ( $file_data === false ) {
			return new WP_Error(
				'read_failed',
				__( 'Failed to read file.', 'extended-abilities' ),
				[ 'status' => 500 ]
			);
		}

		return [
			'data'      => $file_data,
			'filename'  => $filename,
			'file_type' => $file_type,
		];
	}

	/**
	 * Process base64 encoded file data.
	 *
	 * @param string $base64   Base64 encoded file data.
	 * @param string $filename Filename (required for base64).
	 * @return array<string, mixed>|WP_Error Array with 'data', 'filename', 'file_type' on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	private function process_base64( string $base64, string $filename = '' ): array|WP_Error {
		// Filename is required for base64 data.
		if ( empty( $filename ) ) {
			return new WP_Error(
				'missing_filename',
				__( 'Filename is required when using base64 encoded file data.', 'extended-abilities' ),
				[ 'status' => 400 ]
			);
		}

		// Decode base64 data.
		$file_data = base64_decode( $base64, true );

		if ( $file_data === false ) {
			return new WP_Error(
				'invalid_base64',
				__( 'Invalid base64 encoded file data.', 'extended-abilities' ),
				[ 'status' => 400 ]
			);
		}

		// Check file type based on filename.
		$file_type = wp_check_filetype( $filename );

		if ( ! $file_type['type'] || ! $file_type['ext'] ) {
			return new WP_Error(
				'invalid_file_type',
				__( 'Invalid file type. Cannot determine type from filename.', 'extended-abilities' ),
				[ 'status' => 400 ]
			);
		}

		return [
			'data'      => $file_data,
			'filename'  => $filename,
			'file_type' => $file_type,
		];
	}
}
