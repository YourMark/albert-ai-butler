<?php
/**
 * View User Ability
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
use WP_User;

/**
 * View User Ability class
 *
 * Allows AI assistants to view a single WordPress user by ID.
 *
 * @since 1.0.0
 */
class ViewUser extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/view-user';
		$this->label       = __( 'View User', 'albert' );
		$this->description = __( 'Retrieve a single WordPress user by ID.', 'albert' );
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
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.0.0
	 */
	private function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'description' => 'The user ID to retrieve.',
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
				'user' => [
					'type'        => 'object',
					'description' => 'The requested user object.',
				],
			],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * @return bool True if user can execute, false otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): bool {
		return current_user_can( 'list_users' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		$user_id = absint( $args['id'] ?? 0 );

		if ( ! $user_id ) {
			return new WP_Error( 'missing_user_id', __( 'User ID is required.', 'albert' ) );
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error(
				'user_not_found',
				sprintf(
					/* translators: %d: User ID */
					__( 'User with ID %d not found.', 'albert' ),
					$user_id
				)
			);
		}

		return [
			'user' => [
				'id'           => $user->ID,
				'username'     => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'first_name'   => $user->first_name,
				'last_name'    => $user->last_name,
				'roles'        => $user->roles,
				'registered'   => $user->user_registered,
				'url'          => $user->user_url,
				'description'  => $user->description,
			],
		];
	}
}
