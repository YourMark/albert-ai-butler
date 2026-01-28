<?php
/**
 * Create User Ability
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
 * Create User Ability class
 *
 * Allows AI assistants to create WordPress users via the abilities API.
 *
 * @since 1.0.0
 */
class Create extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/create-user';
		$this->label       = __( 'Create User', 'albert' );
		$this->description = __( 'Create a new WordPress user with specified username, email, and role.', 'albert' );
		$this->category    = 'user';
		$this->group       = 'users';

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
		// Get all available WordPress roles dynamically.
		$wp_roles   = wp_roles();
		$role_names = array_keys( $wp_roles->roles );

		return [
			'type'       => 'object',
			'properties' => [
				'username'    => [
					'type'        => 'string',
					'description' => 'The username for the user (required)',
				],
				'email'       => [
					'type'        => 'string',
					'format'      => 'email',
					'description' => 'The email address for the user (required)',
				],
				'password'    => [
					'type'        => 'string',
					'description' => 'The password for the user (required)',
				],
				'first_name'  => [
					'type'        => 'string',
					'description' => 'User first name',
					'default'     => '',
				],
				'last_name'   => [
					'type'        => 'string',
					'description' => 'User last name',
					'default'     => '',
				],
				'roles'       => [
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => $role_names,
					],
					'description' => 'User roles',
					'default'     => [ 'subscriber' ],
				],
				'url'         => [
					'type'        => 'string',
					'format'      => 'uri',
					'description' => 'User website URL',
					'default'     => '',
				],
				'description' => [
					'type'        => 'string',
					'description' => 'User biographical info',
					'default'     => '',
				],
			],
			'required'   => [ 'username', 'email' ],
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
		return $this->check_rest_permission( '/wp/v2/users', 'POST', 'create_users' );
	}

	/**
	 * Execute the ability - create a user using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $username    Username (required).
	 *     @type string $email       Email address (required).
	 *     @type string $password    Password (required).
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
		// Validate required fields.
		if ( empty( $args['username'] ) ) {
			return new WP_Error(
				'missing_username',
				__( 'Username is required.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $args['email'] ) ) {
			return new WP_Error(
				'missing_email',
				__( 'Email is required.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $args['password'] ) ) {
			return new WP_Error(
				'missing_password',
				__( 'Password is required.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		// Prepare REST API request data.
		$request_data = [
			'username'    => sanitize_user( $args['username'] ),
			'email'       => sanitize_email( $args['email'] ),
			'password'    => $args['password'],
			'first_name'  => sanitize_text_field( $args['first_name'] ?? '' ),
			'last_name'   => sanitize_text_field( $args['last_name'] ?? '' ),
			'roles'       => array_map( 'sanitize_key', $args['roles'] ?? [ 'subscriber' ] ),
			'url'         => esc_url_raw( $args['url'] ?? '' ),
			'description' => sanitize_textarea_field( $args['description'] ?? '' ),
		];

		// Create REST request.
		$request = new WP_REST_Request( 'POST', '/wp/v2/users' );
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
				$data['message'] ?? __( 'An error occurred while creating the user.', 'albert' ),
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
