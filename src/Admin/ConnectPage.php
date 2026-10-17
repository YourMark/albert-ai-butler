<?php
/**
 * The Connect page, for people on the allowed list who are not administrators.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.5.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\Connections\ClientSetupGuides;
use Albert\Admin\Connections\ConnectCapability;
use Albert\Admin\Connections\ConnectDialog;
use Albert\Admin\Connections\SiteLocation;
use Albert\Contracts\Interfaces\Hookable;
use Albert\MCP\Server as McpServer;
use Albert\OAuth\Repositories\ClientRepository;

/**
 * How to connect, and your own assistants: nothing else.
 *
 * Someone on the allowed list may approve an assistant, whatever their role,
 * so they need to see how. Administrators have the Connections screen, which
 * does all of this and more, so the menu item is theirs only by link. This
 * page shows no logs, no settings and nobody else's connections.
 *
 * @since 1.5.0
 */
class ConnectPage implements Hookable {

	/**
	 * The page slug.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const PAGE_SLUG = 'albert-connect';

	/**
	 * The admin-post action that revokes one of your own connections.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const REVOKE_ACTION = 'albert_revoke_own_connection';

	/**
	 * Register hooks.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], Menu::POSITION_CONNECTIONS );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_' . self::REVOKE_ACTION, [ $this, 'handle_revoke' ] );
	}

	/**
	 * The link that takes someone straight into the connect dialog.
	 *
	 * An administrator connects from Connections and everyone else from
	 * this page; both open the dialog when the link carries
	 * `albert_connect`. After a login if they were signed out.
	 *
	 * @param \WP_User|null $user Who the link is for, or null for this page.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function setup_url( ?\WP_User $user = null ): string {
		$page = $user !== null && user_can( $user, 'manage_options' ) ? 'albert-connections' : self::PAGE_SLUG;

		return add_query_arg(
			[
				'page'           => $page,
				'albert_connect' => '1',
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Add the page; administrators keep using Connections.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Connect', 'albert-ai-butler' ),
			__( 'Connect', 'albert-ai-butler' ),
			ConnectCapability::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);

		// Out of an administrator's menu, still reachable by its link.
		if ( current_user_can( 'manage_options' ) ) {
			remove_submenu_page( Menu::PARENT_SLUG, self::PAGE_SLUG );
		}
	}

	/**
	 * Load the dialog on this page.
	 *
	 * @param string $hook The current admin page hook.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'albert-connections',
			ALBERT_PLUGIN_URL . 'assets/css/admin-connections.css',
			[ Assets::PRIMITIVES_HANDLE ],
			Assets::version( 'assets/css/admin-connections.css' )
		);

		wp_enqueue_script(
			'albert-admin',
			ALBERT_PLUGIN_URL . 'assets/js/admin-settings.js',
			[ 'albert-admin-utils' ],
			Assets::version( 'assets/js/admin-settings.js' ),
			true
		);

		wp_localize_script(
			'albert-admin',
			'albertAdmin',
			[
				'i18n' => [
					'copied'     => __( 'Copied!', 'albert-ai-butler' ),
					'copyFailed' => __( 'Copy failed', 'albert-ai-butler' ),
				],
			]
		);

		ConnectDialog::enqueue();
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( ConnectCapability::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'albert-ai-butler' ) );
		}

		$endpoint = McpServer::get_endpoint_url();
		$user_id  = get_current_user_id();
		$guides   = ClientSetupGuides::for_location( $endpoint, SiteLocation::current( $user_id ) );

		?>
		<div class="wrap albert-connections albert-connect-page">
			<div class="albert-page">
				<div class="albert-page__header">
					<div class="albert-page__text">
						<h1 class="albert-page__title"><?php esc_html_e( 'Connect', 'albert-ai-butler' ); ?></h1>
						<p class="albert-page__description"><?php esc_html_e( 'Connect your AI assistant to this site.', 'albert-ai-butler' ); ?></p>
					</div>
				</div>
				<hr class="wp-header-end">

				<?php Notices::render( 'albert_connect' ); ?>

				<div class="albert-page__body">
					<section class="albert-card albert-connections__connect">
						<div class="albert-card__header">
							<div class="albert-card__text">
								<h2 class="albert-card__title"><?php esc_html_e( 'Connect an assistant', 'albert-ai-butler' ); ?></h2>
								<?php ConnectDialog::render_location_line(); ?>
							</div>
						</div>
						<div class="albert-card__body albert-connections__connect-body">
							<p><?php ConnectDialog::render_button(); ?></p>

							<div class="albert-connections__url">
								<label class="albert-connections__url-label" for="albert-endpoint-url"><?php esc_html_e( 'MCP URL', 'albert-ai-butler' ); ?></label>
								<div class="albert-endpoint">
									<input type="text" id="albert-endpoint-url" class="albert-endpoint__field" value="<?php echo esc_url( $endpoint ); ?>" readonly />
									<button type="button" class="button button-secondary albert-copy-button" data-copy-target="albert-endpoint-url"><?php esc_html_e( 'Copy', 'albert-ai-butler' ); ?></button>
								</div>
							</div>

							<div class="albert-connections__apps" role="group" aria-label="<?php esc_attr_e( 'Instructions for', 'albert-ai-butler' ); ?>">
								<span class="albert-connections__apps-label" aria-hidden="true"><?php esc_html_e( 'Instructions for', 'albert-ai-butler' ); ?></span>
								<?php
								foreach ( $guides as $guide ) {
									if ( ! $guide['needs_public'] ) {
										ConnectDialog::render_button( 'button button-secondary', $guide['id'], $guide['label'], $guide['icon'] );
									}
								}
								?>
							</div>
						</div>
					</section>

					<?php $this->render_own_connections( $user_id ); ?>
				</div>
			</div>
		</div>
		<?php

		ConnectDialog::render();
	}

	/**
	 * The assistants this person approved, and nobody else's.
	 *
	 * @param int $user_id The current user.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_own_connections( int $user_id ): void {
		$mine = array_values(
			array_filter(
				( new ClientRepository() )->getLiveConnections(),
				static fn( array $connection ): bool => in_array( $user_id, $connection['user_ids'], true )
			)
		);

		?>
		<section class="albert-card">
			<div class="albert-card__header">
				<div class="albert-card__text">
					<h2 class="albert-card__title"><?php esc_html_e( 'Your assistants', 'albert-ai-butler' ); ?></h2>
				</div>
			</div>
			<?php if ( $mine === [] ) { ?>
				<div class="albert-card__body">
					<p class="albert-connections__empty"><?php esc_html_e( 'You haven’t connected an assistant yet.', 'albert-ai-butler' ); ?></p>
				</div>
			<?php } else { ?>
				<ul class="albert-card__body albert-card__body--flush albert-connlist__rows">
					<?php foreach ( $mine as $connection ) { ?>
						<?php
						$name    = $connection['label'] !== '' ? $connection['label'] : $connection['name'];
						$classes = 'albert-conn albert-conn--own';
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only highlight of one row.
						if ( isset( $_GET['albert_new'] ) && sanitize_text_field( wp_unslash( $_GET['albert_new'] ) ) === $connection['client_id'] ) {
							$classes .= ' albert-conn--new';
						}
						?>
						<li class="<?php echo esc_attr( $classes ); ?>">
							<div class="albert-conn__body">
								<div class="albert-conn__head">
									<strong class="albert-conn__title"><?php echo esc_html( $name ); ?></strong>
									<?php if ( $connection['label'] !== '' ) { ?>
										<span class="albert-conn__client"><?php echo esc_html( $connection['name'] ); ?></span>
									<?php } ?>
								</div>
								<p class="albert-conn__meta">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: how long ago, e.g. "2 hours". */
											__( 'Connected %s ago', 'albert-ai-butler' ),
											human_time_diff( (int) $connection['created_ts'] )
										)
									);
									?>
								</p>
							</div>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="albert-conn__actions">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::REVOKE_ACTION ); ?>" />
								<input type="hidden" name="client_id" value="<?php echo esc_attr( $connection['client_id'] ); ?>" />
								<?php wp_nonce_field( self::REVOKE_ACTION . '_' . $connection['client_id'] ); ?>
								<button type="submit" class="button-link">
									<?php esc_html_e( 'Revoke', 'albert-ai-butler' ); ?>
									<span class="screen-reader-text"><?php echo esc_html( $name ); ?></span>
								</button>
							</form>
						</li>
					<?php } ?>
				</ul>
			<?php } ?>
		</section>
		<?php
	}

	/**
	 * Revoke one of your own connections: your tokens only.
	 *
	 * Anyone else who approved the same app stays connected, and the app
	 * has to be approved again to come back.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function handle_revoke(): void {
		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';

		check_admin_referer( self::REVOKE_ACTION . '_' . $client_id );

		if ( ! current_user_can( ConnectCapability::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to do this.', 'albert-ai-butler' ), '', [ 'response' => 403 ] );
		}

		$revoked = ( new ClientRepository() )->revokeUserTokens( $client_id, get_current_user_id() );

		add_settings_error(
			'albert_connect',
			$revoked > 0 ? 'revoked' : 'not_found',
			$revoked > 0
				? __( 'That assistant is disconnected. It has to be approved again to come back.', 'albert-ai-butler' )
				: __( 'That assistant was not connected with your account.', 'albert-ai-butler' ),
			$revoked > 0 ? 'success' : 'warning'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'             => self::PAGE_SLUG,
					'settings-updated' => 'true',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
