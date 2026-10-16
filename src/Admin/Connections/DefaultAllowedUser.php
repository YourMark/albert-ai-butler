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
 * Two moments add them: activation, for the person who clicked Activate, and
 * the first Albert screen an administrator opens, for sites that were
 * activated without one (WP-CLI, a host's installer) or upgraded from a
 * version that started empty. Activating is a deliberate act, so it adds
 * whenever the list is empty; the screen check runs once per site, so
 * somebody who removed themselves on purpose is not put back.
 *
 * Never from cron or REST. Adding a person is something an administrator
 * should have been present for.
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
	 * Add the administrator who is activating Albert.
	 *
	 * Activation from WP-CLI has no current user; the decision is then left
	 * to the first Albert screen an administrator opens.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function on_activation(): void {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
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
		if ( get_option( self::DONE_OPTION ) || ! Menu::is_albert_screen() ) {
			return;
		}

		if ( ( new ClientRepository() )->getLiveConnections() !== [] ) {
			update_option( self::DONE_OPTION, true, false );
			return;
		}

		self::decide( get_current_user_id() );
	}

	/**
	 * Add the user when the list is empty and they are an administrator.
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

		if ( ! AllowedUsers::has_any() ) {
			AllowedUsers::add_automatically( $user_id );
		}

		update_option( self::DONE_OPTION, true, false );
	}
}
