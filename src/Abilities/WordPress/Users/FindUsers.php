<?php
/**
 * Find Users Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Users
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Users;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Find Users Ability class
 *
 * Allows AI assistants to find and search WordPress users via the abilities API.
 *
 * @since 1.0.0
 */
class FindUsers extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-users';
		$this->label       = __( 'Find Users', 'albert-ai-butler' );
		$this->description = __( 'Find and search WordPress users with optional filtering and pagination.', 'albert-ai-butler' );
		$this->category    = 'user';
		$this->group       = 'users';

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
	 * @return array<string, mixed> Input schema.
	 * @since 1.0.0
	 */
	protected function get_input_schema(): array {
		// Get all available WordPress roles dynamically.
		$wp_roles   = wp_roles();
		$role_names = array_keys( $wp_roles->roles );

		return [
			'type'       => 'object',
			'properties' => [
				'id'       => [
					'type'        => 'integer',
					'description' => 'User id for direct querying.',
				],
				'page'     => [
					'type'        => 'integer',
					'description' => 'Page number for pagination',
					'default'     => 1,
					'minimum'     => 1,
				],
				'per_page' => [
					'type'        => 'integer',
					'description' => 'Number of users per page',
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				],
				'search'   => [
					'type'        => 'string',
					'description' => 'Search users by name or email',
					'default'     => '',
				],
				'role'     => [
					'type'        => 'string',
					'description' => 'Filter users by role',
					'enum'        => $role_names,
				],
			],
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
				'users'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'          => [ 'type' => 'integer' ],
							'username'    => [ 'type' => 'string' ],
							'name'        => [ 'type' => 'string' ],
							'email'       => [ 'type' => 'string' ],
							'roles'       => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
							'url'         => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
						],
					],
				],
				'total'       => [ 'type' => 'integer' ],
				'total_pages' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'users', 'total' ],
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
		return $this->check_rest_permission( '/wp/v2/users', 'GET', 'list_users' );
	}

	/**
	 * Execute the ability - list users using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type int    $page     Page number for pagination.
	 *     @type int    $per_page Number of users per page.
	 *     @type string $search   Search query.
	 *     @type string $role     Filter by role.
	 * }
	 * @return array<string, mixed>|WP_Error Users list on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Create REST request.

		$route = '/wp/v2/users';

		if ( isset( $args['id'] ) && ! empty( $args['id'] ) ) {
			$route .= '/' . $args['id'];
		}

		$request = new WP_REST_Request( 'GET', $route );

		// Set pagination parameters.
		$request->set_param( 'page', absint( $args['page'] ?? 1 ) );
		$request->set_param( 'per_page', absint( $args['per_page'] ?? 10 ) );

		// Set search parameter if provided.
		if ( ! empty( $args['search'] ) ) {
			$request->set_param( 'search', sanitize_text_field( $args['search'] ) );
		}

		// Set role filter if provided.
		if ( ! empty( $args['role'] ) ) {
			$request->set_param( 'roles', sanitize_key( $args['role'] ) );
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
				$data['message'] ?? __( 'An error occurred while retrieving users.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Format users data.
		$users = [];
		foreach ( $data as $user_data ) {
			$users[] = [
				'id'          => $user_data['id'],
				'username'    => $user_data['slug'] ?? '',
				'name'        => $user_data['name'] ?? '',
				'email'       => $user_data['email'] ?? '',
				'roles'       => $user_data['roles'] ?? [],
				'url'         => $user_data['url'] ?? '',
				'description' => $user_data['description'] ?? '',
			];
		}

		// Get pagination headers.
		$headers     = $response->get_headers();
		$total       = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $users );
		$total_pages = isset( $headers['X-WP-TotalPages'] ) ? (int) $headers['X-WP-TotalPages'] : 1;

		return [
			'users'       => $users,
			'total'       => $total,
			'total_pages' => $total_pages,
		];
	}
}
