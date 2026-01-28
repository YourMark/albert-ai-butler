<?php
/**
 * Set Featured Image Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Media
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Media;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

/**
 * Set Featured Image Ability class
 *
 * Allows AI assistants to set a featured image for posts and pages.
 *
 * @since 1.0.0
 */
class SetFeaturedImage extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/set-featured-image';
		$this->label       = __( 'Set Featured Image', 'albert' );
		$this->description = __( 'Set an existing media attachment as the featured image for a post or page.', 'albert' );
		$this->category    = 'content';
		$this->group       = 'media';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(),
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
				'post_id'       => [
					'type'        => 'integer',
					'description' => 'The ID of the post or page to set the featured image for (required)',
				],
				'attachment_id' => [
					'type'        => 'integer',
					'description' => 'The ID of the media attachment to set as featured image (required)',
				],
			],
			'required'   => [ 'post_id', 'attachment_id' ],
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
				'post_id'       => [ 'type' => 'integer' ],
				'attachment_id' => [ 'type' => 'integer' ],
				'success'       => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'post_id', 'attachment_id', 'success' ],
		];
	}

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * Delegates to the REST API endpoint's own permission callback.
	 *
	 * @return true|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): true|WP_Error {
		return $this->check_rest_permission( '/wp/v2/posts/(?P<id>[\\d]+)', 'POST', 'edit_posts' );
	}

	/**
	 * Execute the ability - set featured image using REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type int $post_id       Post ID to set featured image for.
	 *     @type int $attachment_id Attachment ID to use as featured image.
	 * }
	 * @return array<string, mixed>|WP_Error Result data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Validate required fields.
		if ( empty( $args['post_id'] ) ) {
			return new WP_Error(
				'missing_post_id',
				__( 'Post ID is required.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $args['attachment_id'] ) ) {
			return new WP_Error(
				'missing_attachment_id',
				__( 'Attachment ID is required.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		$post_id       = absint( $args['post_id'] );
		$attachment_id = absint( $args['attachment_id'] );

		// Verify the post exists using REST API.
		$post_request  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$post_response = rest_do_request( $post_request );

		if ( $post_response->is_error() ) {
			// Try pages endpoint if post doesn't exist.
			$page_request  = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $post_id );
			$page_response = rest_do_request( $page_request );

			if ( $page_response->is_error() ) {
				return new WP_Error(
					'invalid_post',
					__( 'The specified post or page does not exist.', 'albert' ),
					[ 'status' => 404 ]
				);
			}
		}

		// Verify the attachment exists.
		$media_request  = new WP_REST_Request( 'GET', '/wp/v2/media/' . $attachment_id );
		$media_response = rest_do_request( $media_request );

		if ( $media_response->is_error() ) {
			return new WP_Error(
				'invalid_attachment',
				__( 'The specified attachment does not exist.', 'albert' ),
				[ 'status' => 404 ]
			);
		}

		// Set as featured image using REST API.
		$update_request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$update_request->set_param( 'featured_media', $attachment_id );

		$update_response = rest_do_request( $update_request );
		$server          = rest_get_server();
		$update_data     = $server->response_to_data( $update_response, false );

		// Check for errors.
		if ( is_wp_error( $update_data ) ) {
			return $update_data;
		}

		if ( $update_response->is_error() ) {
			// Try pages endpoint if posts doesn't work.
			$page_update_request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $post_id );
			$page_update_request->set_param( 'featured_media', $attachment_id );

			$page_update_response = rest_do_request( $page_update_request );

			if ( $page_update_response->is_error() ) {
				return new WP_Error(
					'thumbnail_error',
					__( 'Failed to set the featured image.', 'albert' ),
					[ 'status' => 500 ]
				);
			}
		}

		// Return success data.
		return [
			'post_id'       => $post_id,
			'attachment_id' => $attachment_id,
			'success'       => true,
		];
	}
}
