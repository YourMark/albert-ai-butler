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
 * Built for every mail client rather than for a browser: one table-based
 * layout with inline styles (Outlook on Windows renders with Word, Gmail
 * drops `<style>` in some views), a button that is a padded table cell so it
 * keeps its shape everywhere, the link also written out in full, and a
 * plain-text part for clients that show no HTML. The logo travels inside the
 * email as an embedded PNG: SVG is blocked by Gmail and Outlook, and an image
 * linked from a local site would never load for the recipient.
 *
 * Only for someone on the list: being on it is what lets them open the page
 * the link goes to. An administrator's link goes to Connections.
 *
 * @since 1.5.0
 */
class SetupInvite {

	/**
	 * The content id the HTML uses to show the embedded logo.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	private const LOGO_CID = 'albert-logo';

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

		// In the recipient's language, not the sending administrator's.
		$switched = switch_to_user_locale( $user->ID );

		try {
			$content         = self::content( $user, $from );
			$content['lang'] = str_replace( '_', '-', determine_locale() );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}

		$text = self::text( $content );
		$logo = ALBERT_PLUGIN_DIR . 'assets/images/albert-email-logo.png';

		// The plain-text part and the logo go on through PHPMailer, which
		// wp_mail() hands to this hook, for this one email only.
		$attach = static function ( $phpmailer ) use ( $text, $logo ): void {
			$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.

			if ( is_readable( $logo ) ) {
				$phpmailer->addEmbeddedImage( $logo, self::LOGO_CID, 'albert.png', 'base64', 'image/png' );
			}
		};

		add_action( 'phpmailer_init', $attach );

		try {
			return wp_mail(
				$user->user_email,
				$content['subject'],
				self::html( $content ),
				[ 'Content-Type: text/html; charset=UTF-8' ]
			);
		} finally {
			remove_action( 'phpmailer_init', $attach );
		}
	}

	/**
	 * Everything the email says, once, for both the HTML and the text part.
	 *
	 * @param \WP_User $user The recipient.
	 * @param \WP_User $from The administrator sending it.
	 *
	 * @return array<string, string>
	 * @since 1.5.0
	 */
	private static function content( \WP_User $user, \WP_User $from ): array {
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$expiry  = '';
		$expires = AllowedUsers::expires_at( $user->ID );

		if ( $expires !== null && ! AllowedUsers::has_authorised( $user->ID ) ) {
			$expiry = sprintf(
				/* translators: %s: date and time the invitation expires. */
				__( 'This invitation expires on %s if you have not connected an assistant by then.', 'albert-ai-butler' ),
				wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $expires )
			);
		}

		return [
			/* translators: %s: the site title. */
			'subject'  => sprintf( __( 'Connect your AI assistant to %s', 'albert-ai-butler' ), $site ),
			/* translators: %s: the recipient's display name. */
			'greeting' => sprintf( __( 'Hi %s,', 'albert-ai-butler' ), $user->display_name ),
			/* translators: 1: the administrator's display name, 2: the site title. */
			'intro'    => sprintf( __( '%1$s has given you access to connect an AI assistant, like Claude, Codex or Cursor, to %2$s.', 'albert-ai-butler' ), $from->display_name, $site ),
			'action'   => __( 'Open the link and pick the app you use. Albert shows you the steps for it, one at a time.', 'albert-ai-butler' ),
			'button'   => __( 'Connect your assistant', 'albert-ai-butler' ),
			'url'      => ConnectPage::setup_url( $user ),
			'fallback' => __( 'If the button does not work, copy this link into your browser:', 'albert-ai-butler' ),
			'signin'   => __( 'You sign in to the site first if you are not signed in already.', 'albert-ai-butler' ),
			'expiry'   => $expiry,
			/* translators: 1: the administrator's display name, 2: the site title. */
			'footer'   => sprintf( __( 'You received this because %1$s added you to the people who may connect an AI assistant to %2$s.', 'albert-ai-butler' ), $from->display_name, $site ),
		];
	}

	/**
	 * The plain-text part.
	 *
	 * @param array<string, string> $content The email's content.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private static function text( array $content ): string {
		$lines = [
			$content['greeting'],
			'',
			$content['intro'],
			'',
			$content['action'],
			$content['url'],
			'',
			$content['signin'],
		];

		if ( $content['expiry'] !== '' ) {
			$lines[] = $content['expiry'];
		}

		$lines[] = '';
		$lines[] = '--';
		$lines[] = $content['footer'];

		return implode( "\n", $lines );
	}

	/**
	 * The HTML part: one centred card on a grey page.
	 *
	 * Colours are written out, not tokens: no mail client reads custom
	 * properties. The accent is Albert's default, and the text greys are the
	 * ones the admin uses, so the email reads as the same product.
	 *
	 * @param array<string, string> $content The email's content.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private static function html( array $content ): string {
		$font  = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
		$text  = $font . 'font-size:15px;line-height:24px;color:#1d2327;margin:0 0 16px;';
		$muted = $font . 'font-size:13px;line-height:20px;color:#646970;margin:0 0 8px;';

		ob_start();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $content['lang'] ?? get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title><?php echo esc_html( $content['subject'] ); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f0f0f1;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;"><?php echo esc_html( $content['intro'] ); ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f0f1;">
	<tr>
		<td align="center" style="padding:32px 16px;">
			<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:560px;">
				<tr>
					<td style="padding:0 0 16px;">
						<table role="presentation" cellpadding="0" cellspacing="0" border="0">
							<tr>
								<td style="padding:0 10px 0 0;vertical-align:middle;">
									<img src="cid:<?php echo esc_attr( self::LOGO_CID ); ?>" width="32" height="32" alt="" style="display:block;border:0;outline:none;text-decoration:none;">
								</td>
								<td style="<?php echo esc_attr( $font ); ?>font-size:17px;font-weight:600;color:#1d2327;vertical-align:middle;">Albert</td>
							</tr>
						</table>
					</td>
				</tr>
				<tr>
					<td style="background-color:#ffffff;border:1px solid #dcdcde;border-radius:8px;padding:32px;">
						<h1 style="<?php echo esc_attr( $font ); ?>font-size:22px;line-height:30px;font-weight:600;color:#1d2327;margin:0 0 20px;"><?php echo esc_html( $content['subject'] ); ?></h1>
						<p style="<?php echo esc_attr( $text ); ?>"><?php echo esc_html( $content['greeting'] ); ?></p>
						<p style="<?php echo esc_attr( $text ); ?>"><?php echo esc_html( $content['intro'] ); ?></p>
						<p style="<?php echo esc_attr( $text ); ?>"><?php echo esc_html( $content['action'] ); ?></p>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;">
							<tr>
								<td align="center" bgcolor="#3858e9" style="border-radius:4px;background-color:#3858e9;">
									<a href="<?php echo esc_url( $content['url'] ); ?>" target="_blank" style="<?php echo esc_attr( $font ); ?>display:inline-block;padding:12px 24px;font-size:15px;font-weight:600;line-height:20px;color:#ffffff;text-decoration:none;border-radius:4px;"><?php echo esc_html( $content['button'] ); ?></a>
								</td>
							</tr>
						</table>
						<p style="<?php echo esc_attr( $muted ); ?>"><?php echo esc_html( $content['fallback'] ); ?></p>
						<p style="<?php echo esc_attr( $muted ); ?>word-break:break-all;"><a href="<?php echo esc_url( $content['url'] ); ?>" style="color:#2271b1;"><?php echo esc_html( $content['url'] ); ?></a></p>
						<p style="<?php echo esc_attr( $muted ); ?>margin-top:16px;"><?php echo esc_html( $content['signin'] ); ?></p>
						<?php if ( $content['expiry'] !== '' ) { ?>
							<p style="<?php echo esc_attr( $muted ); ?>"><?php echo esc_html( $content['expiry'] ); ?></p>
						<?php } ?>
					</td>
				</tr>
				<tr>
					<td style="padding:16px 8px 0;">
						<p style="<?php echo esc_attr( $muted ); ?>font-size:12px;line-height:18px;"><?php echo esc_html( $content['footer'] ); ?></p>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
