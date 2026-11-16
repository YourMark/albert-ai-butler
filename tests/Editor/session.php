<?php
/**
 * Creates a throwaway administrator and prints auth cookies for it as JSON.
 *
 * Run through `wp eval-file`. block-validity.mjs deletes the user afterwards,
 * which also deletes the auto-draft the editor creates for it.
 *
 * @package Albert
 */

// phpcs:disable

require_once ABSPATH . WPINC . '/class-wp-session-tokens.php';

$albert_login = 'albert-editor-check-' . wp_generate_password( 8, false );
$albert_user  = wp_insert_user(
	[
		'user_login' => $albert_login,
		'user_pass'  => wp_generate_password( 32 ),
		'user_email' => $albert_login . '@example.invalid',
		'role'       => 'administrator',
	]
);

if ( is_wp_error( $albert_user ) ) {
	fwrite( STDERR, $albert_user->get_error_message() );
	exit( 1 );
}

$albert_expires = time() + HOUR_IN_SECONDS;
$albert_token   = WP_Session_Tokens::get_instance( $albert_user )->create( $albert_expires );
$albert_secure  = is_ssl();

echo wp_json_encode(
	[
		'user'    => $albert_user,
		'secure'  => $albert_secure,
		'cookies' => [
			[
				'name'  => $albert_secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE,
				'value' => wp_generate_auth_cookie( $albert_user, $albert_expires, $albert_secure ? 'secure_auth' : 'auth', $albert_token ),
				'path'  => ADMIN_COOKIE_PATH,
			],
			[
				'name'  => LOGGED_IN_COOKIE,
				'value' => wp_generate_auth_cookie( $albert_user, $albert_expires, 'logged_in', $albert_token ),
				'path'  => COOKIEPATH,
			],
		],
	]
);
