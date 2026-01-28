<?php
/**
 * Update User Ability
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

/**
 * Update User Ability class
 *
 * Allows AI assistants to update WordPress users via the abilities API.
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
		$this->id          = 'albert/update-user';
		$this->label       = __( 'Update User', 'albert' );
		$this->description = __( 'Update an existing WordPress user with new information.', 'albert' );
		$this->category    = 'user';
		$this->group       = 'users';

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
		// Get all available WordPress roles dynamically.
		$wp_roles   = wp_roles();
		$role_names = array_keys( $wp_roles->roles );

		return [
			'type'       => 'object',
			'properties' => [
				'id'          => [
					'type'        => 'integer',
					'description' => 'The user ID to update',
				],
				'email'       => [
					'type'        => 'string',
					'format'      => 'email',
					'description' => 'The email address for the user',
				],
				'password'    => [
					'type'        => 'string',
					'description' => 'New password for the user',
				],
				'first_name'  => [
					'type'        => 'string',
					'description' => 'User first name',
				],
				'last_name'   => [
					'type'        => 'string',
					'description' => 'User last name',
				],
				'roles'       => [
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => $role_names,
					],
					'description' => 'User roles',
				],
				'url'         => [
					'type'        => 'string',
					'format'      => 'uri',
					'description' => 'User website URL',
				],
				'description' => [
					'type'        => 'string',
					'description' => 'User biographical info',
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
				'id'       => [ 'type' => 'integer' ],
				'username' => [ 'type' => 'string' ],
				'email'    => [ 'type' => 'string' ],
				'roles'    => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'edit_url' => [ 'type' => 'string' ],
			],
			'required'   => [ 'id', 'username', 'email' ],
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
		return $this->check_rest_permission( '/wp/v2/users/(?P<id>[\\d]+)', 'POST', 'edit_users' );
	}

	/**
	 * Execute the ability - update a user using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type int    $id          User ID (required).
	 *     @type string $email       Email address.
	 *     @type string $password    New password.
	 *     @type string $first_name  First name.
	 *     @type string $last_name   Last name.
	 *     @type array  $roles       User roles.
	 *     @type string $url         Website URL.
	 *     @type string $description Biographical info.
	 * }
	 * @return array<string, mixed>|WP_Error User data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Validate input.
		if ( empty( $args['id'] ) ) {
			return new WP_Error(
				'missing_id',
				__( 'User ID is required.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		$user_id = absint( $args['id'] );

		// Check if user exists.
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'albert' ),
				[ 'status' => 404 ]
			);
		}

		// Prepare REST API request data (only include provided fields).
		$request_data = [];

		if ( isset( $args['email'] ) ) {
			$request_data['email'] = sanitize_email( $args['email'] );
		}

		if ( isset( $args['password'] ) ) {
			$request_data['password'] = $args['password'];
		}

		if ( isset( $args['first_name'] ) ) {
			$request_data['first_name'] = sanitize_text_field( $args['first_name'] );
		}

		if ( isset( $args['last_name'] ) ) {
			$request_data['last_name'] = sanitize_text_field( $args['last_name'] );
		}

		if ( isset( $args['roles'] ) && is_array( $args['roles'] ) ) {
			$request_data['roles'] = array_map( 'sanitize_key', $args['roles'] );
		}

		if ( isset( $args['url'] ) ) {
			$request_data['url'] = esc_url_raw( $args['url'] );
		}

		if ( isset( $args['description'] ) ) {
			$request_data['description'] = sanitize_textarea_field( $args['description'] );
		}

		// Create REST request.
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $user_id );
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
				$data['message'] ?? __( 'An error occurred while updating the user.', 'albert' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Return formatted user data.
		return [
			'id'       => $data['id'],
			'username' => $data['slug'] ?? '',
			'email'    => $data['email'] ?? '',
			'roles'    => $data['roles'] ?? [],
			'edit_url' => admin_url( 'user-edit.php?user_id=' . $data['id'] ),
		];
	}
}
