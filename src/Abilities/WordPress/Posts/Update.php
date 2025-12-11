<?php
/**
 * Update Post Ability
 *
 * @package    ExtendedAbilities
 * @subpackage Abilities\WordPress\Posts
 * @since      1.0.0
 */

namespace ExtendedAbilities\Abilities\WordPress\Posts;

use Alley\WP\Block_Converter\Block_Converter;
use ExtendedAbilities\Abstracts\BaseAbility;
use WP_Error;
use WP_REST_Request;

/**
 * Update Post Ability class
 *
 * Allows AI assistants to update WordPress posts via the abilities API.
 *
 * @since 1.0.0
 */
class Update extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'wordpress/update-post';
		$this->label       = __( 'Update Post', 'extended-abilities' );
		$this->description = __( 'Update an existing WordPress post with new title, content, and metadata.', 'extended-abilities' );
		$this->category    = 'wp-extended-abilities-wp-core';
		$this->group       = 'posts';

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
	 * @return array Input schema.
	 * @since 1.0.0
	 */
	protected function get_input_schema(): array {
		// Get all available post statuses dynamically.
		$post_statuses = array_keys( get_post_statuses() );

		return [
			'type'       => 'object',
			'properties' => [
				'id'         => [
					'type'        => 'integer',
					'description' => 'The post ID to update',
				],
				'title'      => [
					'type'        => 'string',
					'description' => 'The post title',
				],
				'content'    => [
					'type'        => 'string',
					'description' => 'The post content (HTML allowed)',
				],
				'status'     => [
					'type'        => 'string',
					'enum'        => $post_statuses,
					'description' => 'Post status',
				],
				'excerpt'    => [
					'type'        => 'string',
					'description' => 'Optional post excerpt',
				],
				'categories' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => 'Array of category IDs',
				],
				'tags'       => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Array of tag names',
				],
			],
			'required'   => [ 'id' ],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array Output schema.
	 * @since 1.0.0
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'        => [ 'type' => 'integer' ],
				'title'     => [ 'type' => 'string' ],
				'status'    => [ 'type' => 'string' ],
				'permalink' => [ 'type' => 'string' ],
				'edit_url'  => [ 'type' => 'string' ],
			],
			'required'   => [ 'id', 'title', 'status' ],
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

		// Get the route pattern for updating a specific post.
		$route_pattern = '/wp/v2/posts/(?P<id>[\d]+)';

		// Find matching route.
		foreach ( $routes as $route => $endpoints ) {
			if ( preg_match( '#^' . $route_pattern . '$#', $route ) ) {
				foreach ( $endpoints as $endpoint ) {
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
			}
		}

		// Fallback to basic capability check.
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Execute the ability - update a post using WordPress REST API.
	 *
	 * @param array $args {
	 *     Input parameters.
	 *
	 * @type int $id Post ID (required).
	 * @type string $title Post title.
	 * @type string $content Post content.
	 * @type string $status Post status.
	 * @type string $excerpt Post excerpt.
	 * @type array $categories Category IDs.
	 * @type array $tags Tag names.
	 * }
	 * @return array|WP_Error Post data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Validate input.
		if ( empty( $args['id'] ) ) {
			return new WP_Error(
				'missing_id',
				__( 'Post ID is required.', 'extended-abilities' ),
				[ 'status' => 400 ]
			);
		}

		$post_id = absint( $args['id'] );

		// Check if post exists.
		if ( ! get_post( $post_id ) ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'extended-abilities' ),
				[ 'status' => 404 ]
			);
		}

		// Prepare REST API request data (only include provided fields).
		$request_data = [];

		if ( isset( $args['title'] ) ) {
			$request_data['title'] = sanitize_text_field( $args['title'] );
		}

		if ( isset( $args['content'] ) ) {
			$request_data['content'] = ( new Block_Converter( $args['content'] ) )->convert();;
		}

		if ( isset( $args['status'] ) ) {
			$request_data['status'] = sanitize_key( $args['status'] );
		}

		if ( isset( $args['excerpt'] ) ) {
			$request_data['excerpt'] = sanitize_textarea_field( $args['excerpt'] );
		}

		// Add categories if provided.
		if ( ! empty( $args['categories'] ) && is_array( $args['categories'] ) ) {
			$request_data['categories'] = array_map( 'absint', $args['categories'] );
		}

		// Add tags if provided (REST API expects tag IDs, so convert tag names to IDs).
		if ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) {
			$tag_ids = [];
			foreach ( $args['tags'] as $tag_name ) {
				$tag = get_term_by( 'name', $tag_name, 'post_tag' );
				if ( ! $tag ) {
					// Create tag if it doesn't exist.
					$tag = wp_insert_term( $tag_name, 'post_tag' );
					if ( is_wp_error( $tag ) ) {
						continue;
					}
					$tag_ids[] = $tag['term_id'];
				} else {
					$tag_ids[] = $tag->term_id;
				}
			}
			$request_data['tags'] = $tag_ids;
		}

		// Create REST request.
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		foreach ( $request_data as $key => $value ) {
			$request->set_param( $key, $value );
		}

		// Execute the request.
		$response = rest_do_request( $request );
		$server   = rest_get_server();
		$data     = $server->response_to_data( $response, false );

		// Check for errors.
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $response->is_error() ) {
			return new WP_Error(
				$data['code'] ?? 'rest_error',
				$data['message'] ?? __( 'An error occurred while updating the post.', 'extended-abilities' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Return formatted post data.
		return [
			'id'        => $data['id'],
			'title'     => $data['title']['rendered'] ?? '',
			'status'    => $data['status'],
			'permalink' => $data['link'] ?? get_permalink( $data['id'] ),
			'edit_url'  => admin_url( 'post.php?post=' . $data['id'] . '&action=edit' ),
		];
	}
}
