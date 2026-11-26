<?php
/**
 * Deleting a user takes them off the allowed list
 *
 * @package Albert
 * @subpackage OAuth
 * @since      1.6.0
 */

namespace Albert\OAuth;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;

/**
 * Removes a deleted user from the allowed list, so no row is left that names nobody.
 *
 * @since 1.6.0
 */
class UserDeletion implements Hookable {

	/**
	 * Register the hook.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function register_hooks(): void {
		add_action( 'deleted_user', [ $this, 'on_deleted_user' ] );
	}

	/**
	 * Take the deleted user off the list.
	 *
	 * @param int $user_id The deleted user.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function on_deleted_user( $user_id ): void {
		AllowedUsers::remove( (int) $user_id );
	}
}
