<?php
/**
 * The MCP Adapter plugin, as something a site owner can install.
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.5.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the MCP Adapter plugin still needs installing or activating, and how.
 *
 * Every surface that asks the owner to do it (the Dashboard, the notices, Site
 * Health, the assistant) reads from here, so they agree on the state, the
 * wording and the link.
 *
 * @since 1.5.0
 */
final class AdapterPlugin {

	/**
	 * The plugin's WordPress.org slug.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const SLUG = 'mcp-adapter';

	/**
	 * Whether the plugin is missing or inactive.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function needs_attention(): bool {
		return ! AdapterSource::plugin_active();
	}

	/**
	 * Whether the plugin's files are on this site.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function is_installed(): bool {
		return file_exists( WP_PLUGIN_DIR . '/' . AdapterSource::PLUGIN_FILE );
	}

	/**
	 * The current user fixes it here with one button.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const ROUTE_BUTTON = 'button';

	/**
	 * Plugin installs are switched off, so an administrator adds it the way the
	 * site's plugins are deployed.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const ROUTE_DEPLOY = 'deploy';

	/**
	 * The current user cannot do either, so they tell an administrator.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const ROUTE_ASK = 'ask';

	/**
	 * The Composer command that adds it, through WordPress Packagist. Shown as
	 * an example where plugin installs are switched off.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const COMPOSER_COMMAND = 'composer require wpackagist-plugin/mcp-adapter';

	/**
	 * How the current user can get it installed and active.
	 *
	 * Activating is a database setting, so it is offered even where plugin files
	 * are deployed. `DISALLOW_FILE_MODS` removes `install_plugins` from everyone,
	 * so that case is told apart from a user who merely lacks the capability.
	 *
	 * @return string One of the ROUTE_* constants.
	 * @since 1.5.0
	 */
	public static function route(): string {
		if ( self::is_installed() ) {
			return current_user_can( 'activate_plugins' ) ? self::ROUTE_BUTTON : self::ROUTE_ASK;
		}

		if ( ! self::file_mods_allowed() ) {
			return current_user_can( 'manage_options' ) ? self::ROUTE_DEPLOY : self::ROUTE_ASK;
		}

		return current_user_can( 'install_plugins' ) ? self::ROUTE_BUTTON : self::ROUTE_ASK;
	}

	/**
	 * Whether the current user gets the button.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function current_user_can_fix(): bool {
		return self::route() === self::ROUTE_BUTTON;
	}

	/**
	 * Whether this site allows installing plugins at all.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function file_mods_allowed(): bool {
		return wp_is_file_mod_allowed( 'albert_install_mcp_adapter' );
	}

	/**
	 * Core's own screen for the fix: the installer, or the activation link.
	 *
	 * The one-click button falls back to this. Core's installer asks for
	 * filesystem credentials when the host needs them, which a REST call cannot.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function fix_url(): string {
		if ( ! self::is_installed() ) {
			return wp_nonce_url(
				self_admin_url( 'update.php?action=install-plugin&plugin=' . self::SLUG ),
				'install-plugin_' . self::SLUG
			);
		}

		$plugins_screen = self::network_wide() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
		$args           = [
			'action' => 'activate',
			'plugin' => rawurlencode( AdapterSource::PLUGIN_FILE ),
		];

		if ( self::network_wide() ) {
			$args['networkwide'] = '1';
		}

		return wp_nonce_url( add_query_arg( $args, $plugins_screen ), 'activate-plugin_' . AdapterSource::PLUGIN_FILE );
	}

	/**
	 * Whether the one-click button can do the job in place.
	 *
	 * Not on multisite, where installing belongs to the network admin and core's
	 * own screens handle it.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function supports_one_click(): bool {
		return ! is_multisite() && self::current_user_can_fix();
	}

	/**
	 * The button's label.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function action_label(): string {
		return self::is_installed()
			? __( 'Activate MCP Adapter', 'albert-ai-butler' )
			: __( 'Install and activate MCP Adapter', 'albert-ai-butler' );
	}

	/**
	 * A short title for the situation.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function title(): string {
		return self::is_installed()
			? __( 'Activate the MCP Adapter plugin', 'albert-ai-butler' )
			: __( 'Install the MCP Adapter plugin', 'albert-ai-butler' );
	}

	/**
	 * Why it matters, in one or two sentences.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function explanation(): string {
		$why = __( 'Albert talks to AI assistants through the MCP Adapter, which now comes as its own plugin from WordPress.org. Albert still includes a copy, but a coming update will rely on the plugin.', 'albert-ai-butler' );

		return self::is_installed()
			? $why . ' ' . __( 'It is installed on this site but not active.', 'albert-ai-butler' )
			: $why;
	}

	/**
	 * What to do when there is no button: how to add it, or who to ask.
	 *
	 * @return string Plain text. {@see self::guidance_html()} marks up the command.
	 * @since 1.5.0
	 */
	public static function guidance(): string {
		if ( self::route() === self::ROUTE_DEPLOY ) {
			return __( 'Plugin installs are switched off on this site, so add it the way your other plugins are deployed, then activate it on the Plugins screen. With Composer:', 'albert-ai-butler' )
				. ' ' . self::COMPOSER_COMMAND;
		}

		if ( self::is_installed() ) {
			return __( 'Only a site administrator can activate it, so please let them know.', 'albert-ai-butler' );
		}

		return is_multisite()
			? __( 'Only a network administrator can install it, so please let them know.', 'albert-ai-butler' )
			: __( 'Only a site administrator can install it, so please let them know.', 'albert-ai-butler' );
	}

	/**
	 * The guidance as escaped HTML, with the Composer command as code.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function guidance_html(): string {
		return str_replace(
			esc_html( self::COMPOSER_COMMAND ),
			'<code>' . esc_html( self::COMPOSER_COMMAND ) . '</code>',
			esc_html( self::guidance() )
		);
	}

	/**
	 * What Albert tells a connected assistant, for it to pass on.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function assistant_notice(): string {
		if ( self::route() === self::ROUTE_DEPLOY ) {
			return sprintf(
				/* translators: %s: the Composer command that installs the plugin */
				__( 'Albert, the plugin connecting you to this site, needs the MCP Adapter plugin from WordPress.org. Albert still works for now, but a coming update will require it. Plugin installs are switched off on this site, so tell the user to add it the way their plugins are deployed (with Composer: "%s") and activate it on the Plugins screen.', 'albert-ai-butler' ),
				self::COMPOSER_COMMAND
			);
		}

		return self::is_installed()
			? __( 'Albert, the plugin connecting you to this site, needs the MCP Adapter plugin to be active. It is installed but switched off. Albert still works for now, but a coming update will require it. Tell the user to open Albert > Dashboard in their WordPress admin and choose "Activate MCP Adapter".', 'albert-ai-butler' )
			: __( 'Albert, the plugin connecting you to this site, needs the MCP Adapter plugin from WordPress.org. Albert still works for now, but a coming update will require it. Tell the user to open Albert > Dashboard in their WordPress admin and choose "Install and activate MCP Adapter".', 'albert-ai-butler' );
	}

	/**
	 * Whether Albert runs network-wide, so the adapter should too.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	private static function network_wide(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( ALBERT_PLUGIN_BASENAME );
	}
}
