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
use Albert\OAuth\Server\ConnectionContext;

/**
 * Removes a user who leaves the site from the allowed list, so no row is left that names nobody.
 *
 * @since 1.6.0
 */
class UserDeletion implements Hookable {

	/**
	 * Cron hook for a removal deferred out of an assistant's request.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public const DEFERRED_HOOK = 'albert_remove_departed_allowed_user';

	/**
	 * Register the hooks.
	 *
	 * On multisite the list is per site, and `remove_user_from_blog` fires on each
	 * site the user leaves, a network-wide delete included, with that site switched in.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function register_hooks(): void {
		if ( is_multisite() ) {
			add_action( 'remove_user_from_blog', [ $this, 'on_user_left' ] );
		} else {
			add_action( 'deleted_user', [ $this, 'on_user_left' ] );
		}

		add_action( self::DEFERRED_HOOK, [ $this, 'remove_if_departed' ] );
	}

	/**
	 * Take the user off this site's list.
	 *
	 * An assistant may delete users, but `ConnectionGuard` refuses every write to
	 * the list during its request, so the removal waits for cron.
	 *
	 * @param int $user_id The departing user.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function on_user_left( $user_id ): void {
		$user_id = (int) $user_id;

		if ( ! in_array( $user_id, AllowedUsers::ids(), true ) ) {
			return;
		}

		if ( ConnectionContext::client_id() !== null ) {
			wp_schedule_single_event( time(), self::DEFERRED_HOOK, [ $user_id ] );

			return;
		}

		AllowedUsers::remove( $user_id );
	}

	/**
	 * Remove a user whose removal was deferred, if they really left this site.
	 *
	 * @param int $user_id The departed user.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function remove_if_departed( $user_id ): void {
		$user_id = (int) $user_id;

		if ( is_user_member_of_blog( $user_id ) ) {
			return;
		}

		AllowedUsers::remove( $user_id );
	}

	/**
	 * Drop pending deferred removals.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public static function unschedule(): void {
		wp_unschedule_hook( self::DEFERRED_HOOK );
	}
}
