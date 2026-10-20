<?php
/**
 * Password change revokes connections
 *
 * @package Albert
 * @subpackage OAuth
 * @since      1.6.0
 */

namespace Albert\OAuth;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\Settings;
use Albert\Contracts\Interfaces\Hookable;
use WP_User;

/**
 * Revokes every token a person holds when their password is changed or reset.
 *
 * Changing a password is how people lock out whoever had their account, and a
 * token would otherwise outlive it by up to its refresh token's lifetime.
 * Deliberately not on `wp_set_password`: core calls that when it rehashes a
 * password at login, which would disconnect every assistant on each sign-in.
 * So only core's flows are covered (profile, `wp_update_user()`, password
 * reset); code that calls `wp_set_password()` directly is not.
 *
 * @since 1.6.0
 */
class PasswordChange implements Hookable {

	/**
	 * Register the password hooks.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function register_hooks(): void {
		add_action( 'after_password_reset', [ $this, 'on_password_reset' ] );
		add_action( 'profile_update', [ $this, 'on_profile_update' ], 10, 2 );
	}

	/**
	 * Revoke on a reset through the lost-password flow.
	 *
	 * @param WP_User $user The user whose password was reset.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function on_password_reset( $user ): void {
		if ( $user instanceof WP_User ) {
			Settings::revoke_user_tokens( $user->ID );
		}
	}

	/**
	 * Compares stored hashes, so a profile save that leaves the password alone keeps the connections.
	 *
	 * @param int     $user_id       The user.
	 * @param WP_User $old_user_data The user before the update.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function on_profile_update( $user_id, $old_user_data ): void {
		$user = get_userdata( (int) $user_id );

		if ( ! $user || ! $old_user_data instanceof WP_User || $user->user_pass === $old_user_data->user_pass ) {
			return;
		}

		Settings::revoke_user_tokens( $user->ID );
	}
}
