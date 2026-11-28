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
	 * The current user fixes it here with one button.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const ROUTE_BUTTON = 'button';

	/**
	 * Plugin installs are switched off, so an administrator has to add it
	 * outside WordPress.
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
	 * How the current user can get it installed and active.
	 *
	 * Activating is a database setting, so it is offered even where plugin files
	 * are deployed. `DISALLOW_FILE_MODS` removes `install_plugins` from everyone,
	 * so that case is told apart from a user who merely lacks the capability.
	 * Where Albert runs network-wide the adapter is activated network-wide too,
	 * which a site administrator cannot do.
	 *
	 * @return string One of the ROUTE_* constants.
	 * @since 1.5.0
	 */
	public static function route(): string {
		if ( self::is_installed() ) {
			$can_activate = self::network_wide() ? current_user_can( 'manage_network_plugins' ) : current_user_can( 'activate_plugins' );

			return $can_activate ? self::ROUTE_BUTTON : self::ROUTE_ASK;
		}

		if ( ! wp_is_file_mod_allowed( 'albert_install_mcp_adapter' ) ) {
			return current_user_can( 'manage_options' ) ? self::ROUTE_DEPLOY : self::ROUTE_ASK;
		}

		// The button installs and then activates, so it needs both.
		return current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' ) ? self::ROUTE_BUTTON : self::ROUTE_ASK;
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
			// On multisite plugins are installed from the network admin.
			$installer = is_multisite() ? network_admin_url( 'update.php' ) : admin_url( 'update.php' );

			return wp_nonce_url(
				add_query_arg(
					[
						'action' => 'install-plugin',
						'plugin' => self::SLUG,
					],
					$installer
				),
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
	 * What to do when there is no button: who has to act.
	 *
	 * @return string Plain text.
	 * @since 1.5.0
	 */
	public static function guidance(): string {
		if ( self::route() === self::ROUTE_DEPLOY ) {
			return is_multisite()
				? __( 'Plugin installs are switched off on this site, so a network administrator needs to look at it.', 'albert-ai-butler' )
				: __( 'Plugin installs are switched off on this site, so a site administrator needs to look at it.', 'albert-ai-butler' );
		}

		if ( self::is_installed() ) {
			return __( 'Only a site administrator can activate it, so please let them know.', 'albert-ai-butler' );
		}

		return is_multisite()
			? __( 'Only a network administrator can install it, so please let them know.', 'albert-ai-butler' )
			: __( 'Only a site administrator can install it, so please let them know.', 'albert-ai-butler' );
	}

	/**
	 * What Albert tells a connected assistant, for it to pass on.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function assistant_notice(): string {
		if ( self::route() === self::ROUTE_DEPLOY ) {
			return __( 'Albert, the plugin connecting you to this site, needs the MCP Adapter plugin from WordPress.org. Albert still works for now, but a coming update will require it. Plugin installs are switched off on this site, so tell the user a site administrator needs to look at it.', 'albert-ai-butler' );
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
