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
 * Albert's copy is offered to Jetpack Autoloader only when the plugin is not
 * active: on an equal version Jetpack picks Albert's, and the plugin then flags
 * Albert as an outdated plugin bundling its own copy. Required before any
 * autoloader exists, so it uses nothing but WordPress core.
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
	 * Whether the MCP Adapter plugin supplies the adapter on this request.
	 *
	 * `WP_MCP_DIR` is defined by the plugin's own bootstrap, which only runs
	 * before Albert when it is loaded as a must-use plugin. A listed plugin
	 * whose folder is gone is not counted, or the site would have no adapter.
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

		return in_array( self::PLUGIN_FILE, $active, true ) || self::activating_now();
	}

	/**
	 * Whether this request is the one activating the MCP Adapter plugin.
	 *
	 * WordPress adds it to `active_plugins` only after loading it, later in the
	 * same request. Mirrors Jetpack's own check: the nonce cannot be verified
	 * this early, so only its presence is required, and the activation fails
	 * later if it is wrong.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	private static function activating_now(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Pluggable functions are not loaded yet; values are only compared.
		if ( empty( $_REQUEST['_wpnonce'] ) || ! isset( $_REQUEST['action'] ) ) {
			return false;
		}

		$action = wp_unslash( $_REQUEST['action'] );

		if ( $action === 'activate' && isset( $_REQUEST['plugin'] ) ) {
			return wp_unslash( $_REQUEST['plugin'] ) === self::PLUGIN_FILE;
		}

		if ( $action === 'activate-selected' && isset( $_REQUEST['checked'] ) && is_array( $_REQUEST['checked'] ) ) {
			return in_array( self::PLUGIN_FILE, wp_unslash( $_REQUEST['checked'] ), true );
		}
		// phpcs:enable

		return false;
	}

	/**
	 * Offer Albert's copy to Jetpack Autoloader, unless the plugin supplies one.
	 *
	 * Must run before `vendor/autoload_packages.php` is required. Uses Jetpack's
	 * list of plugins activating this request, which it reads alongside the
	 * active ones and resolves by newest version, so an older bundled copy
	 * (WooCommerce ships one) still loses to ours. Relies on Jetpack Autoloader 6.
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
}
