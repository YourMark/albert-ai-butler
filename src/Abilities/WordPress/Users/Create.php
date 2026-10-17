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
		$this->description = __( 'Create a new WordPress user. The password is generated on the site; a one-time link to set their own is returned.', 'albert-ai-butler' );
		$this->category    = 'user';
		$this->group       = 'users';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		// The link is a credential: whoever holds it can set this account's
		// password once. The caller needs it to pass on; no observer does.
		$this->sensitive_output_keys = [ 'password_reset_url' ];

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::create(
				'Do not supply a password; this ability does not accept one. The site generates it and returns '
				. '`password_reset_url`, a one-time link. Give that link to the new user so they set their own '
				. 'password. It is a credential, so hand it over directly rather than repeating it anywhere it '
				. 'would be stored. If the link is missing, `password_reset_note` says why: the account exists '
				. 'but nobody can sign in to it yet, so report that rather than saying the user is ready.'
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
				'id'                  => [ 'type' => 'integer' ],
				'username'            => [ 'type' => 'string' ],
				'email'               => [ 'type' => 'string' ],
				'roles'               => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'edit_url'            => [ 'type' => 'string' ],
				'password_reset_url'  => [
					'type'        => 'string',
					'description' => 'One-time link for the new user to set their own password. Absent when no key could be issued.',
				],
				'password_reset_note' => [
					'type'        => 'string',
					'description' => 'Why no link was issued, and what to do instead. Present only when password_reset_url is absent.',
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
		// makes it unusable rather than secret. The reset link below is the only
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

		$reset_url = $this->password_reset_url( (int) $data['id'] );

		if ( $reset_url !== null ) {
			$result['password_reset_url'] = $reset_url;
		} else {
			// The account exists and nobody can get into it. Deleting it again
			// would be worse than saying so, but staying silent would leave the
			// caller telling somebody their account is ready when it is not.
			$result['password_reset_note'] = __( 'No sign-in link could be issued, most likely because password resets are disabled on this site. Set this user a password in wp-admin.', 'albert-ai-butler' );
		}

		return $result;
	}

	/**
	 * A one-time link letting the new user set their own password.
	 *
	 * This is how the account becomes usable, and it is deliberately the only
	 * route: the generated password is never disclosed, so nothing the caller
	 * holds is a lasting credential. The caller passes this on, exactly as it
	 * passed on a password before.
	 *
	 * No notification email is sent. `wp_new_user_notification()` mints a reset
	 * key of its own, and a user holds one `user_activation_key` at a time, so
	 * whichever key is issued second invalidates the first: emailing as well as
	 * returning would either kill the returned link or send a dead one.
	 *
	 * Returns null when no key could be issued, which in practice means the site
	 * filters `allow_password_reset` off.
	 *
	 * @param int $user_id The newly created user.
	 *
	 * @return string|null The link, or null when a key could not be issued.
	 * @since 1.5.0
	 */
	private function password_reset_url( int $user_id ): ?string {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return null;
		}

		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			return null;
		}

		return network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);
	}
}
