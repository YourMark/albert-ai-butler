<?php
/**
 * Puts the first administrator on the allowed list.
 *
 * @package Albert
 * @subpackage Admin\Connections
 * @since      1.5.0
 */

namespace Albert\Admin\Connections;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\Menu;
use Albert\Contracts\Interfaces\Hookable;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Repositories\ClientRepository;

/**
 * An empty allowed list refuses everyone, the administrator who installed
 * Albert included, and that was the first wall a new owner hit.
 *
 * Decided once per site, at whichever comes first: activation by an
 * administrator, or the first Albert screen one opens (WP-CLI activation,
 * a host's installer, an upgrade). Once decided it stays decided, so
 * somebody who removed themselves is not put back, not even by
 * reactivating. Never from cron or REST: adding a person is something an
 * administrator should have been present for.
 *
 * @since 1.5.0
 */
class DefaultAllowedUser implements Hookable {

	/**
	 * Set once the default has been decided, added or not.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const DONE_OPTION = 'albert_default_allowed_user_done';

	/**
	 * Register hooks.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		add_action( 'current_screen', [ $this, 'maybe_add_on_albert_screen' ] );
	}

	/**
	 * Add the administrator activating Albert, unless already decided.
	 *
	 * WP-CLI activation has no current user, so it leaves the decision to
	 * the first Albert screen.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function on_activation(): void {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}

		self::decide( $user_id );
	}

	/**
	 * Add the administrator opening an Albert screen, once per site.
	 *
	 * A site that already has connections is in use, and its owner already
	 * manages the list, so it is left alone.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function maybe_add_on_albert_screen(): void {
		if ( get_option( self::DONE_OPTION ) || ! Menu::is_albert_screen() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ( new ClientRepository() )->getLiveConnections() !== [] ) {
			add_option( self::DONE_OPTION, true, '', false );
			return;
		}

		self::decide( get_current_user_id() );
	}

	/**
	 * Add the user when the list is empty and they are an administrator.
	 *
	 * Claiming the decision with add_option() first, which fails when the
	 * row already exists, keeps two first requests from both adding.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function decide( int $user_id ): void {
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return;
		}

		if ( ! add_option( self::DONE_OPTION, true, '', false ) ) {
			return;
		}

		if ( ! AllowedUsers::has_any() ) {
			AllowedUsers::add_automatically( $user_id );
		}
	}
}
