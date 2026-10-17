<?php
/**
 * The guided connect dialog.
 *
 * @package Albert
 * @subpackage Admin\Connections
 * @since      1.5.0
 */

namespace Albert\Admin\Connections;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\Assets;
use Albert\Admin\Dashboard\Suggestions;
use Albert\Admin\InfoTip;
use Albert\Core\Plugin;
use Albert\MCP\Server as McpServer;

/**
 * One question at a time: where is this site, which app, do this, then wait
 * for the connection to arrive.
 *
 * The same dialog opens from the Dashboard and from Connections, so a user
 * who does not know what to do is walked through it wherever they start. It
 * says "connected" only once Albert has seen the connection, by polling for a
 * new one from this user ({@see \Albert\Admin\Rest\ConnectController}); the
 * steps before that happen in other apps, which Albert cannot see, so they are
 * instructions and never ticked off.
 *
 * Every app's steps are rendered here, for both locations, by
 * {@see GuidePanel}, and the script only shows and hides them. Nothing about a
 * guide is built in JavaScript, so it is escaped in one place.
 *
 * @phpstan-import-type AlbertClientGuide from ClientSetupGuides
 *
 * @since 1.5.0
 */
class ConnectDialog {

	/**
	 * The dialog element's id.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const DIALOG_ID = 'albert-connect';

	/**
	 * Render the button that opens the dialog.
	 *
	 * @param string $classes Button classes.
	 * @param string $app     Open straight at this app's steps, or '' to start at the first question.
	 * @param string $label   The button text, or '' for the default.
	 * @param string $icon    An app logo to show before the label, or ''.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function render_button( string $classes = 'button button-primary', string $app = '', string $label = '', string $icon = '' ): void {
		?>
		<button type="button" class="<?php echo esc_attr( $classes . ( $icon !== '' ? ' albert-app-button' : '' ) ); ?>" data-albert-open-connect<?php echo $app !== '' ? ' data-app="' . esc_attr( $app ) . '"' : ''; ?> aria-haspopup="dialog">
			<?php
			if ( $icon !== '' ) {
				AppIcon::render( $icon );
			}
			echo esc_html( $label !== '' ? $label : __( 'Connect an assistant', 'albert-ai-butler' ) );
			?>
		</button>
		<?php
	}

	/**
	 * The one-line answer to "where is this site", with a way to change it.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function render_location_line(): void {
		?>
		<p class="albert-connect-location" data-albert-location-line>
			<span class="dashicons <?php echo esc_attr( self::location_icon( SiteLocation::current( get_current_user_id() ) ) ); ?>" aria-hidden="true" data-albert-location-icon></span>
			<span data-albert-location-text><?php echo esc_html( self::location_sentence( SiteLocation::current( get_current_user_id() ), SiteLocation::is_guess( get_current_user_id() ) ) ); ?></span>
			<button type="button" class="button-link" data-albert-open-connect data-step="location"><?php esc_html_e( 'Change', 'albert-ai-butler' ); ?></button>
			<?php
			InfoTip::render(
				__( 'A local site runs only on your computer, for example in Local, Herd, Studio or Docker, and the internet cannot reach it. Apps on your computer, like Claude Desktop, Claude Code, Codex and Cursor, can still connect. Apps that connect from the cloud, like Claude on the web and ChatGPT, need a public site: one anyone can open at its address. Albert detects this from the site’s address, so change it if it is wrong.', 'albert-ai-butler' ),
				__( 'local and public sites', 'albert-ai-butler' )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the dialog.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function render(): void {
		$endpoint = McpServer::get_endpoint_url();
		$user_id  = get_current_user_id();
		$location = SiteLocation::current( $user_id );
		$guides   = [
			SiteLocation::PUBLIC => ClientSetupGuides::for_location( $endpoint, SiteLocation::PUBLIC ),
			SiteLocation::LOCAL  => ClientSetupGuides::for_location( $endpoint, SiteLocation::LOCAL ),
		];

		?>
		<dialog
			id="<?php echo esc_attr( self::DIALOG_ID ); ?>"
			class="albert-dialog albert-connect"
			aria-labelledby="albert-connect-title"
			data-albert-connect
			data-location="<?php echo esc_attr( $location ); ?>"
			data-guess="<?php echo SiteLocation::is_guess( $user_id ) ? '1' : '0'; ?>"
			data-server-time="<?php echo esc_attr( (string) time() ); ?>">
			<div class="albert-dialog__header">
				<div class="albert-dialog__heading">
					<p class="albert-connect__progress" data-albert-connect-progress></p>
					<h2 class="albert-dialog__title" id="albert-connect-title" data-albert-connect-title tabindex="-1"></h2>
				</div>
				<button type="button" class="albert-dialog__close" data-albert-connect-close aria-label="<?php esc_attr_e( 'Close', 'albert-ai-butler' ); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
				</button>
			</div>

			<div class="albert-dialog__body">
				<?php self::render_location_step(); ?>
				<?php self::render_app_step( $guides ); ?>
				<?php self::render_steps_step( $guides ); ?>
				<?php self::render_done_step(); ?>
			</div>

			<div class="albert-dialog__footer albert-connect__footer">
				<button type="button" class="button albert-connect__back" data-albert-connect-back hidden>
					<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Back', 'albert-ai-butler' ); ?></span>
				</button>
				<button type="button" class="button button-primary" data-albert-connect-next><?php esc_html_e( 'Continue', 'albert-ai-butler' ); ?></button>
				<button type="button" class="button button-primary" data-albert-connect-done hidden><?php esc_html_e( 'Done', 'albert-ai-butler' ); ?></button>
			</div>
		</dialog>
		<?php
	}

	/**
	 * Step 1: where is this site.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function render_location_step(): void {
		$options = [
			SiteLocation::PUBLIC => [
				__( 'On the internet', 'albert-ai-butler' ),
				__( 'People reach it at its address. Every app can connect.', 'albert-ai-butler' ),
			],
			SiteLocation::LOCAL  => [
				__( 'Only on this computer', 'albert-ai-butler' ),
				__( 'For example Local, Herd, Studio or a Docker setup. Apps that run on this computer can connect; Claude on the web and phone and ChatGPT cannot.', 'albert-ai-butler' ),
			],
		];

		?>
		<section class="albert-connect__step" data-albert-connect-step="location" data-title="<?php esc_attr_e( 'Where is this site?', 'albert-ai-butler' ); ?>" hidden>
			<fieldset class="albert-connect__choices">
				<legend class="albert-connect__lead" data-albert-connect-guess>
					<?php echo esc_html( self::guess_sentence( SiteLocation::detect() ) ); ?>
				</legend>
				<?php foreach ( $options as $value => [ $label, $description ] ) { ?>
					<label class="albert-choice">
						<input type="radio" name="albert-connect-location" value="<?php echo esc_attr( $value ); ?>" <?php checked( SiteLocation::current( get_current_user_id() ), $value ); ?> aria-describedby="albert-connect-location-<?php echo esc_attr( $value ); ?>">
						<span class="dashicons <?php echo esc_attr( self::location_icon( $value ) ); ?> albert-choice__icon" aria-hidden="true"></span>
						<span class="albert-choice__text">
							<span class="albert-choice__label"><?php echo esc_html( $label ); ?></span>
							<span class="albert-choice__description" id="albert-connect-location-<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $description ); ?></span>
						</span>
					</label>
				<?php } ?>
			</fieldset>
			<?php if ( ! current_user_can( 'manage_options' ) ) { ?>
				<p class="albert-connect__context"><?php esc_html_e( 'This changes it for you only. An administrator can change it for the site.', 'albert-ai-butler' ); ?></p>
			<?php } ?>
		</section>
		<?php
	}

	/**
	 * Step 2: which app, one list per location.
	 *
	 * @param array<string, array<int, AlbertClientGuide>> $guides Guides per location.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function render_app_step( array $guides ): void {
		?>
		<section class="albert-connect__step" data-albert-connect-step="app" data-title="<?php esc_attr_e( 'Which app do you use?', 'albert-ai-butler' ); ?>" hidden>
			<?php self::render_context_line(); ?>
			<?php foreach ( $guides as $location => $list ) { ?>
				<fieldset class="albert-connect__apps" data-albert-connect-apps="<?php echo esc_attr( $location ); ?>" hidden>
					<legend class="screen-reader-text"><?php esc_html_e( 'Which app do you use?', 'albert-ai-butler' ); ?></legend>
					<?php
					self::render_app_choices( $location, array_filter( $list, static fn( array $guide ): bool => ! $guide['needs_public'] ) );

					$needs_public = array_filter( $list, static fn( array $guide ): bool => (bool) $guide['needs_public'] );
					if ( $needs_public !== [] ) {
						?>
						<p class="albert-connect__group"><?php esc_html_e( 'Needs a public site', 'albert-ai-butler' ); ?></p>
						<?php
						self::render_app_choices( $location, $needs_public );
					}
					?>
				</fieldset>
			<?php } ?>
		</section>
		<?php
	}

	/**
	 * A grid of app choices.
	 *
	 * @param string                        $location The location the list is for.
	 * @param array<int, AlbertClientGuide> $guides   The guides.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function render_app_choices( string $location, array $guides ): void {
		?>
		<div class="albert-connect__grid">
			<?php foreach ( $guides as $guide ) { ?>
				<label class="albert-choice albert-choice--app">
					<input type="radio" name="albert-connect-app-<?php echo esc_attr( $location ); ?>" value="<?php echo esc_attr( (string) $guide['id'] ); ?>">
					<?php AppIcon::render( (string) $guide['icon'] ); ?>
					<span class="albert-choice__label"><?php echo esc_html( (string) $guide['label'] ); ?></span>
				</label>
			<?php } ?>
		</div>
		<?php
	}

	/**
	 * Step 3: the chosen app's steps, then the wait.
	 *
	 * @param array<string, array<int, AlbertClientGuide>> $guides Guides per location.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function render_steps_step( array $guides ): void {
		?>
		<section class="albert-connect__step" data-albert-connect-step="steps" hidden>
			<?php self::render_context_line(); ?>
			<?php
			foreach ( $guides as $location => $list ) {
				foreach ( $list as $guide ) {
					$waits = ! $guide['needs_public'] && $guide['blocked'] === '';
					$title = $guide['needs_public']
						/* translators: %s: an app's name, e.g. "ChatGPT". */
						? sprintf( __( '%s needs a public site', 'albert-ai-butler' ), $guide['label'] )
						/* translators: %s: an app's name, e.g. "Cursor". */
						: sprintf( __( 'Connect %s', 'albert-ai-butler' ), $guide['id'] === 'other' ? __( 'another app', 'albert-ai-butler' ) : $guide['label'] );
					?>
					<div
						class="albert-connect__panel"
						data-albert-connect-panel="<?php echo esc_attr( $location . ':' . $guide['id'] ); ?>"
						data-title="<?php echo esc_attr( $title ); ?>"
						data-app-name="<?php echo esc_attr( $guide['id'] === 'other' ? __( 'your app', 'albert-ai-butler' ) : (string) $guide['label'] ); ?>"
						data-waits="<?php echo $waits ? '1' : '0'; ?>"
						hidden>
						<?php GuidePanel::render( $guide, 'albert-connect-' . $location ); ?>
					</div>
					<?php
				}
			}
			?>

			<div class="albert-connect__wait" data-albert-connect-wait hidden>
				<p class="albert-connect__waiting" role="status">
					<span class="albert-connect__spinner" aria-hidden="true"></span>
					<span data-albert-connect-wait-text></span>
				</p>
				<div class="albert-connect__stuck" data-albert-connect-stuck hidden>
					<p class="albert-connect__stuck-title"><?php esc_html_e( 'Not connected yet?', 'albert-ai-butler' ); ?></p>
					<ul>
						<li><?php esc_html_e( 'You signed in as someone who isn’t listed under Who may connect.', 'albert-ai-butler' ); ?></li>
						<li><?php esc_html_e( 'The app couldn’t reach this site. Check that the address it uses matches the MCP URL.', 'albert-ai-butler' ); ?></li>
						<li><?php esc_html_e( 'On a site that only runs on this computer, apps that need a public site can’t connect.', 'albert-ai-butler' ); ?></li>
					</ul>
					<button type="button" class="button-link" data-albert-connect-manual><?php esc_html_e( 'Set it up by hand instead', 'albert-ai-butler' ); ?></button>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Step 4: what Albert saw, and what to try first.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function render_done_step(): void {
		$prompts = array_slice( ( new Suggestions() )->all(), 0, 3 );
		?>
		<section class="albert-connect__step" data-albert-connect-step="done" hidden>
			<p class="albert-connect__success">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<span data-albert-connect-signed-in></span>
			</p>
			<?php if ( $prompts !== [] ) { ?>
				<p class="albert-connect__group"><?php esc_html_e( 'Try asking', 'albert-ai-butler' ); ?></p>
				<ul class="albert-connect__prompts">
					<?php foreach ( $prompts as $prompt ) { ?>
						<li>&ldquo;<?php echo esc_html( (string) $prompt['text'] ); ?>&rdquo;</li>
					<?php } ?>
				</ul>
			<?php } ?>
		</section>
		<?php
	}

	/**
	 * "This site: on the internet. Change", above steps 2 and 3.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function render_context_line(): void {
		?>
		<p class="albert-connect__context albert-connect-location">
			<span class="dashicons" aria-hidden="true" data-albert-location-icon></span>
			<span data-albert-connect-where></span>
			<button type="button" class="button-link" data-albert-connect-change><?php esc_html_e( 'Change', 'albert-ai-butler' ); ?></button>
		</p>
		<?php
	}

	/**
	 * The sentence for the location line.
	 *
	 * Says plainly when it is Albert's detection rather than somebody's
	 * choice, because detection can be wrong and then the Change link is
	 * what the reader needs.
	 *
	 * @param string $location The location.
	 * @param bool   $is_guess Whether it is still detected rather than chosen.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private static function location_sentence( string $location, bool $is_guess ): string {
		if ( $is_guess ) {
			return $location === SiteLocation::LOCAL
				? __( 'Detected: a local site, only on this computer.', 'albert-ai-butler' )
				: __( 'Detected: a public site, on the internet.', 'albert-ai-butler' );
		}

		return $location === SiteLocation::LOCAL
			? __( 'Local site, only on this computer.', 'albert-ai-butler' )
			: __( 'Public site, on the internet.', 'albert-ai-butler' );
	}

	/**
	 * Albert's detection, said as one, above the first question.
	 *
	 * @param string $location The detected location.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private static function guess_sentence( string $location ): string {
		return $location === SiteLocation::LOCAL
			? __( 'Albert detected a local site, only on this computer. Change it if that’s wrong.', 'albert-ai-butler' )
			: __( 'Albert detected a public site, on the internet. Change it if that’s wrong.', 'albert-ai-butler' );
	}

	/**
	 * The icon for a location: a computer or a globe.
	 *
	 * @param string $location The location.
	 *
	 * @return string A dashicons class.
	 * @since 1.5.0
	 */
	public static function location_icon( string $location ): string {
		return $location === SiteLocation::LOCAL ? 'dashicons-desktop' : 'dashicons-admin-site-alt3';
	}

	/**
	 * Enqueue the dialog's script and styles.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function enqueue(): void {
		wp_enqueue_style(
			'albert-connect',
			ALBERT_PLUGIN_URL . 'assets/css/admin-connect.css',
			[ Assets::PRIMITIVES_HANDLE ],
			Assets::version( 'assets/css/admin-connect.css' )
		);

		wp_enqueue_script(
			'albert-admin-utils',
			ALBERT_PLUGIN_URL . 'assets/js/albert-admin-utils.js',
			[],
			Assets::version( 'assets/js/albert-admin-utils.js' ),
			true
		);

		wp_enqueue_script(
			'albert-connect',
			ALBERT_PLUGIN_URL . 'assets/js/admin-connect.js',
			[ 'albert-admin-utils' ],
			Assets::version( 'assets/js/admin-connect.js' ),
			true
		);

		wp_localize_script(
			'albert-connect',
			'albertConnect',
			[
				'statusUrl'   => rest_url( Plugin::rest_namespace() . '/connect/status' ),
				'locationUrl' => rest_url( Plugin::rest_namespace() . '/connect/location' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'i18n'        => [
					/* translators: 1: current step number, 2: number of steps. */
					'progress'  => __( 'Step %1$d of %2$d', 'albert-ai-butler' ),
					'public'    => __( 'Public site, on the internet.', 'albert-ai-butler' ),
					'local'     => __( 'Local site, only on this computer.', 'albert-ai-butler' ),
					/* translators: %s: an app's name. */
					'waiting'   => __( 'Waiting for %s to connect. Keep this window open.', 'albert-ai-butler' ),
					/* translators: %s: an app's name. */
					'stillWait' => __( 'Still waiting for %s. This updates by itself.', 'albert-ai-butler' ),
					/* translators: %s: the connected app's name. */
					'connected' => __( '%s is connected', 'albert-ai-butler' ),
					/* translators: %s: the person who approved it. */
					'signedIn'  => __( 'It signed in as %s.', 'albert-ai-butler' ),
					'saveError' => __( 'Could not save where this site runs. It is used for now; reload the page to try saving again.', 'albert-ai-butler' ),
				],
			]
		);
	}
}
