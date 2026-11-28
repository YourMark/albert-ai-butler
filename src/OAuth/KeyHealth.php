<?php
/**
 * OAuth key health notice
 *
 * @package Albert
 * @subpackage OAuth
 * @since      1.7.0
 */

namespace Albert\OAuth;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\Menu;
use Albert\Contracts\Interfaces\Hookable;
use Albert\OAuth\Server\KeyManager;

/**
 * Tells an administrator when the keys that sign sign-ins could not be created.
 *
 * Without them no assistant can connect, and the only other trace is a
 * generic server error in the assistant's sign-in window.
 *
 * @since 1.7.0
 */
class KeyHealth implements Hookable {

	/**
	 * Register the notice.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
	}

	/**
	 * Show the last failure on Albert's screens and the Plugins screen.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function maybe_render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_notice_screen() ) {
			return;
		}

		$error = get_option( KeyManager::KEY_ERROR_OPTION );
		if ( ! is_string( $error ) || $error === '' ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s %s</p></div>',
			esc_html__( 'Albert:', 'albert-ai-butler' ),
			esc_html__( 'AI assistants cannot connect to this site yet.', 'albert-ai-butler' ),
			esc_html( $error )
		);
	}

	/**
	 * Whether the current admin screen is one the notice belongs on.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	private static function is_notice_screen(): bool {
		if ( Menu::is_albert_screen() ) {
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen !== null && $screen->id === 'plugins';
	}
}
