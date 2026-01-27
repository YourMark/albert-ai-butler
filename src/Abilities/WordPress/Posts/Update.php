<?php
/**
 * Update Post Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Posts
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Posts;

use Alley\WP\Block_Converter\Block_Converter;
use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
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
		$this->id          = 'albert/update-post';
		$this->label       = __( 'Update Post', 'albert' );
		$this->description = __( 'Update an existing WordPress post with new title, content, and metadata.', 'albert' );
		$this->category    = 'content';
		$this->group       = 'posts';

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
	 * @return array<string, mixed> Output schema.
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
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type int    $id         Post ID (required).
	 *     @type string $title      Post title.
	 *     @type string $content    Post content.
	 *     @type string $status     Post status.
	 *     @type string $excerpt    Post excerpt.
	 *     @type array  $categories Category IDs.
	 *     @type array  $tags       Tag names.
	 * }
	 * @return array<string, mixed>|WP_Error Post data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Validate input.
		if ( empty( $args['id'] ) ) {
			return new WP_Error(
				'missing_id',
				__( 'Post ID is required.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		$post_id = absint( $args['id'] );

		// Check if post exists using REST API.
		$check_request  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$check_response = rest_do_request( $check_request );

		if ( $check_response->is_error() ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'albert' ),
				[ 'status' => 404 ]
			);
		}

		// Prepare REST API request data (only include provided fields).
		$request_data = [];

		if ( isset( $args['title'] ) ) {
			$request_data['title'] = sanitize_text_field( $args['title'] );
		}

		if ( isset( $args['content'] ) ) {
			$request_data['content'] = ( new Block_Converter( $args['content'] ) )->convert();
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

		// Add tags if provided (convert tag names to IDs using REST API).
		if ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) {
			$tag_ids = $this->get_or_create_tag_ids( $args['tags'] );
			if ( ! empty( $tag_ids ) ) {
				$request_data['tags'] = $tag_ids;
			}
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
				$data['message'] ?? __( 'An error occurred while updating the post.', 'albert' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Return formatted post data.
		$post_id = $data['id'];

		return [
			'id'        => $post_id,
			'title'     => $data['title']['rendered'] ?? '',
			'status'    => $data['status'],
			'permalink' => $data['link'] ?? '',
			'edit_url'  => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		];
	}

	/**
	 * Get or create tag IDs from tag names using REST API.
	 *
	 * @param array<int, string> $tag_names Array of tag names.
	 * @return array<int, int> Array of tag IDs.
	 * @since 1.0.0
	 */
	private function get_or_create_tag_ids( array $tag_names ): array {
		$tag_ids = [];

		foreach ( $tag_names as $tag_name ) {
			$tag_name = sanitize_text_field( $tag_name );

			// Search for existing tag using REST API.
			$search_request = new WP_REST_Request( 'GET', '/wp/v2/tags' );
			$search_request->set_param( 'search', $tag_name );
			$search_request->set_param( 'per_page', 1 );

			$search_response = rest_do_request( $search_request );
			$server          = rest_get_server();
			$search_data     = $server->response_to_data( $search_response, false );

			// Check if exact match exists.
			if ( ! is_wp_error( $search_data ) && ! empty( $search_data ) ) {
				foreach ( $search_data as $tag ) {
					if ( strtolower( $tag['name'] ) === strtolower( $tag_name ) ) {
						$tag_ids[] = $tag['id'];
						continue 2;
					}
				}
			}

			// Create new tag using REST API if not found.
			$create_request = new WP_REST_Request( 'POST', '/wp/v2/tags' );
			$create_request->set_param( 'name', $tag_name );

			$create_response = rest_do_request( $create_request );
			$create_data     = $server->response_to_data( $create_response, false );

			if ( ! is_wp_error( $create_data ) && ! empty( $create_data['id'] ) ) {
				$tag_ids[] = $create_data['id'];
			}
		}

		return $tag_ids;
	}
}
