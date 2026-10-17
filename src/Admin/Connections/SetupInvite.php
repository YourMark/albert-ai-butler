<?php
/**
 * Emails someone the link to connect their assistant.
 *
 * @package Albert
 * @subpackage Admin\Connections
 * @since      1.5.0
 */

namespace Albert\Admin\Connections;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\ConnectPage;
use Albert\OAuth\AllowedUsers;

/**
 * A short email with the setup link, sent through WordPress's own mail.
 *
 * The link, not the instructions: steps change, the site's address can
 * change, and the page the link opens shows the right steps for the app
 * they pick, today. The email only says who sent it, where it goes, and
 * when an unused invitation runs out.
 *
 * Only for someone on the list: being on it is what lets them open the page
 * the link goes to. An administrator's link goes to Connections.
 *
 * @since 1.5.0
 */
class SetupInvite {

	/**
	 * Whether this user can be sent a setup link.
	 *
	 * @param \WP_User $user The user.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function can_send_to( \WP_User $user ): bool {
		return $user->user_email !== '' && AllowedUsers::is_allowed( $user->ID );
	}

	/**
	 * Send the email.
	 *
	 * @param \WP_User $user The person to send it to.
	 * @param \WP_User $from The administrator sending it.
	 *
	 * @return bool Whether WordPress accepted the email for sending.
	 * @since 1.5.0
	 */
	public static function send( \WP_User $user, \WP_User $from ): bool {
		if ( ! self::can_send_to( $user ) ) {
			return false;
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		/* translators: %s: the site title. */
		$subject = sprintf( __( 'Connect your AI assistant to %s', 'albert-ai-butler' ), $site );

		$lines = [
			/* translators: %s: the recipient's display name. */
			sprintf( __( 'Hi %s,', 'albert-ai-butler' ), $user->display_name ),
			'',
			/* translators: 1: the administrator's display name, 2: the site title. */
			sprintf( __( '%1$s has given you access to connect an AI assistant, like Claude, Codex or Cursor, to %2$s.', 'albert-ai-butler' ), $from->display_name, $site ),
			'',
			__( 'Open this link and pick the app you use. Albert shows you the steps for it, one at a time:', 'albert-ai-butler' ),
			ConnectPage::setup_url( $user ),
			'',
			__( 'You sign in to the site first if you are not signed in already.', 'albert-ai-butler' ),
		];

		$expires = AllowedUsers::expires_at( $user->ID );
		if ( $expires !== null && ! AllowedUsers::has_authorised( $user->ID ) ) {
			$lines[] = sprintf(
				/* translators: %s: date and time the invitation expires. */
				__( 'This invitation expires on %s if you have not connected an assistant by then.', 'albert-ai-butler' ),
				wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $expires )
			);
		}

		return wp_mail( $user->user_email, $subject, implode( "\n", $lines ) );
	}
}
