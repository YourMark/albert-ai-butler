<?php
/**
 * Create User Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Users
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Users;

defined( 'ABSPATH' ) || exit;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;
use WP_User;

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
		$this->label       = __( 'Create User', 'albert-ai-butler' );
		$this->description = __( 'Create a new WordPress user. The password is generated on the site, and WordPress emails the new user a link to set their own.', 'albert-ai-butler' );
		$this->category    = 'user';
		$this->group       = 'users';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::create(
				'Do not supply a password; this ability does not accept one. The site generates it, and WordPress '
				. 'emails the new user a link to set their own. When `invitation_sent` is false, '
				. '`invitation_note` says why: the account exists but nobody can sign in to it yet, so report '
				. 'that rather than saying the user is ready.'
			),
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
				'id'              => [ 'type' => 'integer' ],
				'username'        => [ 'type' => 'string' ],
				'email'           => [ 'type' => 'string' ],
				'roles'           => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'edit_url'        => [ 'type' => 'string' ],
				'invitation_sent' => [
					'type'        => 'boolean',
					'description' => 'Whether WordPress emailed the new user a link to set their password.',
				],
				'invitation_note' => [
					'type'        => 'string',
					'description' => 'Why no invitation was sent, and what to do instead. Present only when invitation_sent is false.',
				],
			],
			'required'   => [ 'id', 'username', 'email' ],
		];
	}

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * Delegates to the REST API endpoint's own permission callback.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): bool|WP_Error {
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
		// The caller never chooses this. `/wp/v2/users` requires a password, so
		// one is generated and then never disclosed to anybody, which is what
		// makes it unusable rather than secret. The emailed link is the only
		// route in. `wp_generate_password()` cannot emit a backslash, which
		// core's own `check_user_password()` rejects.
		$generated_password = wp_generate_password( 32, true, true );

		// Prepare REST API request data.
		$request_data = [
			'username'    => sanitize_user( $args['username'] ),
			'email'       => sanitize_email( $args['email'] ),
			'password'    => $generated_password,
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
				$data['message'] ?? __( 'An error occurred while creating the user.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Return formatted user data.
		$result = [
			'id'       => $data['id'],
			'username' => $data['slug'] ?? '',
			'email'    => $data['email'] ?? '',
			'roles'    => $data['roles'] ?? [],
			'edit_url' => admin_url( 'user-edit.php?user_id=' . $data['id'] ),
		];

		$result['invitation_sent'] = $this->send_invitation( (int) $data['id'] );

		if ( ! $result['invitation_sent'] ) {
			// The account exists and nobody can get into it. Deleting it again
			// would be worse than saying so, but staying silent would leave the
			// caller telling somebody their account is ready when it is not.
			$result['invitation_note'] = __( 'No invitation could be sent, most likely because password resets are disabled on this site. Set this user a password in wp-admin.', 'albert-ai-butler' );
		}

		return $result;
	}

	/**
	 * Email the new user WordPress's own link to set their password.
	 *
	 * The link is a credential, so it goes to the user's own inbox and never
	 * through the assistant, whose transcript the AI provider keeps.
	 *
	 * @param int $user_id The newly created user.
	 *
	 * @return bool False when the site does not allow this user a password reset,
	 *              in which case WordPress sends nothing.
	 * @since 1.5.1
	 */
	private function send_invitation( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || ! wp_is_password_reset_allowed_for_user( $user ) ) {
			return false;
		}

		wp_new_user_notification( $user_id, null, 'user' );

		return true;
	}
}
