<?php
/**
 * Delete User Ability
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
 * Delete User Ability class
 *
 * Allows AI assistants to delete WordPress users via the abilities API.
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
		$this->id          = 'albert/delete-user';
		$this->label       = __( 'Delete User', 'albert-ai-butler' );
		$this->description = __( 'Delete a WordPress user and optionally reassign their content.', 'albert-ai-butler' );
		$this->category    = 'user';
		$this->group       = 'users';

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
				'id'       => [
					'type'        => 'integer',
					'description' => 'The user ID to delete',
				],
				'reassign' => [
					'type'        => 'integer',
					'description' => 'Reassign posts and links to this user ID',
					'default'     => 0,
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
			],
			'required'   => [ 'id', 'deleted' ],
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
		return $this->check_rest_permission( '/wp/v2/users/(?P<id>[\\d]+)', 'DELETE', 'delete_users' );
	}

	/**
	 * Execute the ability - delete a user using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type int $id       User ID (required).
	 *     @type int $reassign Reassign posts to this user ID.
	 * }
	 * @return array<string, mixed>|WP_Error Deletion result on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Validate input.
		if ( empty( $args['id'] ) ) {
			return new WP_Error(
				'missing_id',
				__( 'User ID is required.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$user_id = absint( $args['id'] );

		// Check if user exists.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		// Create REST request.
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/users/' . $user_id );
		$request->set_param( 'force', true );

		// Set reassign parameter if provided.
		if ( ! empty( $args['reassign'] ) ) {
			$request->set_param( 'reassign', absint( $args['reassign'] ) );
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
				$data['message'] ?? __( 'An error occurred while deleting the user.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Return deletion result.
		return [
			'id'      => $user_id,
			'deleted' => true,
		];
	}
}
