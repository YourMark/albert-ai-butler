<?php
/**
 * Where the MCP adapter comes from.
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.5.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the MCP Adapter plugin supply the adapter, with Albert's copy as fallback.
 *
 * The adapter ships as a WordPress.org plugin, and bundling it is deprecated.
 * Albert still carries a copy in `lib/mcp-adapter/` for sites without the plugin,
 * but offers it to Jetpack Autoloader only when the plugin is not active. Offered
 * alongside it, Jetpack settles an equal version in favour of the plugin read
 * first, `albert-ai-butler` sorts before `mcp-adapter`, and the plugin then flags
 * Albert on every admin screen as an outdated plugin bundling its own copy.
 *
 * Required directly by the plugin file, before any autoloader exists, so it
 * uses nothing but WordPress core.
 *
 * @since 1.5.0
 */
final class AdapterSource {

	/**
	 * The MCP Adapter plugin's basename.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';

	/**
	 * Albert's fallback copy, relative to the plugin directory.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const FALLBACK_DIR = 'lib/mcp-adapter';

	/**
	 * Jetpack Autoloader's cache of the plugin paths it reads manifests from.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	private const JETPACK_PATHS_CACHE = 'jetpack_autoloader_plugin_paths';

	/**
	 * Whether the MCP Adapter plugin supplies the adapter on this request.
	 *
	 * `WP_MCP_DIR` is defined by the plugin's own bootstrap, which covers it
	 * being loaded as a must-use plugin. A listed plugin whose folder is gone is
	 * not counted, or the site would be left with no adapter at all.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function plugin_active(): bool {
		if ( defined( 'WP_MCP_DIR' ) ) {
			return true;
		}

		if ( ! file_exists( WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE ) ) {
			return false;
		}

		$active = (array) get_option( 'active_plugins', [] );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}

		return in_array( self::PLUGIN_FILE, $active, true );
	}

	/**
	 * Offer Albert's copy to Jetpack Autoloader, unless the plugin supplies one.
	 *
	 * Must run before `vendor/autoload_packages.php` is required. Uses Jetpack's
	 * own list of plugins activating this request, which it reads alongside the
	 * active ones and resolves by newest version, so a plugin bundling an older
	 * copy (WooCommerce does) still loses to ours.
	 *
	 * @param string $plugin_dir Albert's plugin directory.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function offer_fallback( string $plugin_dir ): void {
		if ( self::plugin_active() ) {
			return;
		}

		global $jetpack_autoloader_activating_plugins_paths;

		if ( ! is_array( $jetpack_autoloader_activating_plugins_paths ) ) {
			$jetpack_autoloader_activating_plugins_paths = [];
		}

		$jetpack_autoloader_activating_plugins_paths[] = rtrim( $plugin_dir, '/\\' ) . '/' . self::FALLBACK_DIR;
	}

	/**
	 * Forget Jetpack's cached plugin paths once the MCP Adapter plugin activates.
	 *
	 * Jetpack keeps reading cached paths for a while, and our fallback is one of
	 * them. An equal version still goes to the plugin, but a newer fallback would
	 * win until the cache refreshed.
	 *
	 * @param string $plugin The plugin that was activated.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function forget_cached_paths( string $plugin ): void {
		if ( self::PLUGIN_FILE === $plugin ) {
			delete_transient( self::JETPACK_PATHS_CACHE );
		}
	}
}
