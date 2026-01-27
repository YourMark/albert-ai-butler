<?php
/**
 * Delete Post Ability
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
 * Delete Post Ability class
 *
 * Allows AI assistants to delete WordPress posts via the abilities API.
 *
 * @since 1.0.0
 */
class Delete extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/delete-post';
		$this->label       = __( 'Delete Post', 'albert' );
		$this->description = __( 'Delete a WordPress post permanently or move it to trash.', 'albert' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::delete(),
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
				'id'    => [
					'type'        => 'integer',
					'description' => 'The post ID to delete',
				],
				'force' => [
					'type'        => 'boolean',
					'description' => 'Whether to bypass trash and force deletion',
					'default'     => false,
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
				'id'      => [ 'type' => 'integer' ],
				'deleted' => [ 'type' => 'boolean' ],
				'status'  => [ 'type' => 'string' ],
			],
			'required'   => [ 'id', 'deleted' ],
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

		// Get the route pattern for deleting a specific post.
		$route_pattern = '/wp/v2/posts/(?P<id>[\d]+)';

		// Find matching route.
		foreach ( $routes as $route => $endpoints ) {
			if ( preg_match( '#^' . $route_pattern . '$#', $route ) ) {
				foreach ( $endpoints as $endpoint ) {
					if ( isset( $endpoint['methods']['DELETE'] ) && isset( $endpoint['permission_callback'] ) ) {
						// Create a mock request for permission check.
						$request = new WP_REST_Request( 'DELETE', $route );

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
		return current_user_can( 'delete_posts' );
	}

	/**
	 * Execute the ability - delete a post using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type int  $id    Post ID (required).
	 *     @type bool $force Whether to bypass trash and force deletion.
	 * }
	 * @return array<string, mixed>|WP_Error Deletion result on success, WP_Error on failure.
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

		// Check if post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'albert' ),
				[ 'status' => 404 ]
			);
		}

		$force = ! empty( $args['force'] );

		// Create REST request.
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'force', $force );

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
				$data['message'] ?? __( 'An error occurred while deleting the post.', 'albert' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Return deletion result.
		return [
			'id'      => $post_id,
			'deleted' => true,
			'status'  => $force ? 'deleted' : 'trashed',
		];
	}
}
