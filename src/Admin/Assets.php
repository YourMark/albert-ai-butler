<?php
/**
 * Shared admin asset registration.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;

/**
 * Registers the assets every Albert admin screen shares.
 *
 * Today that is the design-token stylesheet: the single source of colour,
 * spacing, type, radius and motion for Albert's admin, in this plugin and in
 * every add-on.
 *
 * The tokens are **registered, never enqueued** here. Every screen that needs
 * them names {@see self::TOKENS_HANDLE} in its own stylesheet's dependency
 * array, and WordPress then enqueues the tokens first, automatically, wherever
 * a consumer appears. That is stronger than enqueueing them on every admin page:
 * a screen cannot forget the dependency and still render (its own custom
 * properties would be undefined), and Albert adds no stylesheet to admin pages
 * that are none of its business.
 *
 * Add-ons follow the same contract — declare `'albert-tokens'` as a dependency
 * rather than restating any value:
 *
 *     wp_enqueue_style( 'my-addon', $url, [ 'albert-tokens' ], $version );
 *
 * Registration runs at priority 1 so the handle exists before any screen's own
 * `admin_enqueue_scripts` callback runs at the default priority.
 *
 * @since 1.4.0
 */
class Assets implements Hookable {

	/**
	 * Stylesheet handle for the design tokens.
	 *
	 * Part of the public contract for add-ons. Treat as frozen.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const TOKENS_HANDLE = 'albert-tokens';

	/**
	 * Stylesheet handle for the shared primitives.
	 *
	 * Depends on {@see self::TOKENS_HANDLE}, so naming this one alone is enough
	 * — WordPress pulls the tokens in ahead of it. Part of the public contract
	 * for add-ons. Treat as frozen.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const PRIMITIVES_HANDLE = 'albert-primitives';

	/**
	 * The page navigation's script handle.
	 *
	 * Not part of the add-on contract the way the two stylesheet handles are:
	 * an add-on never enqueues this, because Albert renders the navigation on
	 * the add-on's screen itself. It is a constant so the handle is written
	 * once.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const NAV_HANDLE = 'albert-nav';

	/**
	 * The info-popover script handle.
	 *
	 * Drives `.albert-tip`, the server-rendered info control. Enqueued on every
	 * Albert screen because the field system that uses it is shared.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const POPOVER_HANDLE = 'albert-admin-popover';

	/**
	 * Script and stylesheet handle for the shared @wordpress/dataviews build.
	 *
	 * The script is a chunk of Albert's own webpack build, not a global
	 * library: it only works under {@see self::RUNTIME_HANDLE}, and an add-on
	 * cannot import from it. Part of the public contract for add-ons so the
	 * name stays stable. Treat as frozen.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public const DATAVIEWS_HANDLE = 'albert-dataviews';

	/**
	 * The webpack runtime script handle every React screen shares.
	 *
	 * Not part of the add-on contract.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public const RUNTIME_HANDLE = 'albert-runtime';

	/**
	 * Register the hook that registers the shared handles.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'register_shared_styles' ], 1 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_on_albert_screens' ], 2 );
	}

	/**
	 * Load the primitives on every Albert screen, including add-on pages.
	 *
	 * Registering alone is not enough for anything Albert renders *outside* a
	 * screen's own callback. {@see Menu::render_navigation()} runs on
	 * `in_admin_header` for every Albert screen — add-on pages included — so on
	 * a page owned by an add-on the navigation markup would appear with no
	 * stylesheet behind it and render as a run-together list of link text.
	 *
	 * Enqueueing here means any page under the Albert menu gets the shared
	 * layer whether or not its owner asked for it, which is the only way a
	 * cross-screen element can be styled reliably. Screens still name the handle
	 * in their own dependency array; a second enqueue of the same handle is a
	 * no-op.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function enqueue_on_albert_screens(): void {
		if ( ! Menu::is_albert_screen() ) {
			return;
		}

		wp_enqueue_style( self::PRIMITIVES_HANDLE );

		// Enqueued here for the same reason as the stylesheet above: the
		// navigation renders on every Albert screen, add-on pages included, so
		// the one script that belongs to it cannot live in any single screen's
		// own callback. It keeps the current entry in view when the strip
		// scrolls; see assets/js/albert-nav.js.
		wp_enqueue_script(
			self::NAV_HANDLE,
			ALBERT_PLUGIN_URL . 'assets/js/albert-nav.js',
			[],
			self::version( 'assets/js/albert-nav.js' ),
			true
		);

		// The info control ships with the field system, and the field system is
		// shared, so any Albert screen can now render a `.albert-tip`. Enqueued
		// here rather than per screen for that reason; a second enqueue of the
		// same handle elsewhere is a no-op.
		wp_enqueue_script(
			self::POPOVER_HANDLE,
			ALBERT_PLUGIN_URL . 'assets/js/admin-popover.js',
			[],
			self::version( 'assets/js/admin-popover.js' ),
			true
		);
	}

	/**
	 * The cache-busting version for one of Albert's own stylesheets.
	 *
	 * `ALBERT_VERSION` alone is wrong during development, and wrong in a way that
	 * wastes time rather than breaking anything. Version bumps happen only in
	 * release branches, so every CSS edit on `development` ships under the
	 * version already sitting in the browser cache. The stylesheet does not
	 * reload, the screen looks unchanged, and the natural conclusion is that the
	 * change did not work. That misdiagnosis cost an hour during the 1.4.0
	 * context build, chasing a positioning bug that was a stale cached file.
	 *
	 * So: the file's own modification time when it can be read, falling back to
	 * the plugin version. On a release that is stable per deploy, which is all a
	 * cache needs, and it additionally covers a patch that changes a stylesheet
	 * without touching the version constant.
	 *
	 * @param string $relative_path Path below the plugin directory, e.g. `assets/css/albert-tokens.css`.
	 *
	 * @return string A version string safe to hand to `wp_enqueue_style()`.
	 * @since 1.4.0
	 */
	public static function version( string $relative_path ): string {
		$file = ALBERT_PLUGIN_DIR . ltrim( $relative_path, '/' );

		if ( is_readable( $file ) ) {
			$modified = filemtime( $file );

			if ( $modified !== false ) {
				return (string) $modified;
			}
		}

		return (string) ALBERT_VERSION;
	}

	/**
	 * Register the design-token stylesheet.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_shared_styles(): void {
		wp_register_style(
			self::TOKENS_HANDLE,
			ALBERT_PLUGIN_URL . 'assets/css/albert-tokens.css',
			[],
			self::version( 'assets/css/albert-tokens.css' )
		);

		wp_register_style(
			self::PRIMITIVES_HANDLE,
			ALBERT_PLUGIN_URL . 'assets/css/albert-primitives.css',
			[ self::TOKENS_HANDLE ],
			self::version( 'assets/css/albert-primitives.css' )
		);
	}
}
