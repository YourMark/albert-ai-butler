<?php
/**
 * View Post Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Posts
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Posts;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

/**
 * View Post Ability class
 *
 * Allows AI assistants to view a single WordPress post by ID.
 *
 * @since 1.0.0
 */
class ViewPost extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/view-post';
		$this->label       = __( 'View Post', 'albert' );
		$this->description = __( 'Retrieve a single WordPress post by ID.', 'albert' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.0.0
	 */
	private function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'description' => 'The post ID to retrieve.',
					'minimum'     => 1,
				],
			],
			'required'   => [ 'id' ],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.0.0
	 */
	private function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post' => [
					'type'        => 'object',
					'description' => 'The requested post object.',
				],
			],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * @return true|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): true|WP_Error {
		return $this->require_capability( 'read' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		$post_id = absint( $args['id'] ?? 0 );

		if ( ! $post_id ) {
			return new WP_Error( 'missing_post_id', __( 'Post ID is required.', 'albert' ) );
		}

		$post = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'Post with ID %d not found.', 'albert' ),
					$post_id
				)
			);
		}

		// Check if user can read this specific post.
		if ( ! current_user_can( 'read_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to view this post.', 'albert' ) );
		}

		return [
			'post' => [
				'id'             => $post->ID,
				'title'          => $post->post_title,
				'content'        => $post->post_content,
				'excerpt'        => $post->post_excerpt,
				'status'         => $post->post_status,
				'author_id'      => (int) $post->post_author,
				'date'           => $post->post_date,
				'modified'       => $post->post_modified,
				'slug'           => $post->post_name,
				'permalink'      => get_permalink( $post ),
				'featured_image' => get_post_thumbnail_id( $post ) ? get_post_thumbnail_id( $post ) : null,
			],
		];
	}
}
