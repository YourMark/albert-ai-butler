<?php
/**
 * Guard against the MCP Adapter's OAuth server
 *
 * @package Albert
 * @subpackage OAuth
 * @since      1.7.0
 */

namespace Albert\OAuth;

defined( 'ABSPATH' ) || exit;

use Albert\Core\Plugin;

/**
 * Keeps Albert's sign-in working when the MCP Adapter plugin ships its own OAuth server.
 *
 * WordPress/mcp-adapter#437 adds one, on by default. It claims every MCP route,
 * Albert's included, answers `/.well-known/oauth-authorization-server/…` ahead
 * of Albert, replaces Albert's `WWW-Authenticate` challenge, and issues tokens
 * Albert refuses, so a client that rediscovers loops on 401. Albert's copy in
 * `lib/mcp-adapter/` has no OAuth server; only the standalone plugin does, and
 * it updates without an Albert release. See issue #174.
 *
 * Registered from the main plugin file, not on `plugins_loaded`: the adapter
 * asks on `plugins_loaded`, and plugin load order must not decide the answer.
 * The hook names are from the unmerged PR and may change before it ships.
 *
 * @since 1.7.0
 */
class AdapterOAuthGuard {

	/**
	 * Add the filters.
	 *
	 * The switch runs early, so a site that turns the adapter's server on with
	 * its own `oauth_server_enabled` filter still can.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public static function register(): void {
		add_filter( 'oauth_server_enabled', [ self::class, 'keep_disabled' ], 1 );
		add_filter( 'oauth_server_protected_resources', [ self::class, 'drop_albert_routes' ], PHP_INT_MAX );
	}

	/**
	 * Switch the adapter's OAuth server off unless the site asks for it.
	 *
	 * Site-wide: other MCP servers on the site lose it too. Narrower guards
	 * cannot stop its discovery documents answering for Albert's issuer.
	 *
	 * @param mixed $enabled Whether the adapter's server runs.
	 *
	 * @return mixed False unless the site opted out of the guard.
	 * @since 1.7.0
	 */
	public static function keep_disabled( mixed $enabled ): mixed {
		/**
		 * Filters whether Albert switches off the MCP Adapter plugin's OAuth server.
		 *
		 * Return false to let it run next to Albert's. Albert's own MCP route is
		 * still kept out of it, but its discovery documents may then answer for
		 * Albert's, and assistants may fail to connect.
		 *
		 * @since 1.7.0
		 *
		 * @param bool $disable Whether to switch it off. Default true.
		 */
		return apply_filters( 'albert/oauth/disable_adapter_oauth_server', true ) ? false : $enabled;
	}

	/**
	 * Keep Albert's routes out of the adapter's protected resources.
	 *
	 * Albert answers its own 401s and checks its own tokens.
	 *
	 * @param mixed $routes REST routes the adapter protects.
	 *
	 * @return mixed The routes without Albert's.
	 * @since 1.7.0
	 */
	public static function drop_albert_routes( mixed $routes ): mixed {
		if ( ! is_array( $routes ) ) {
			return $routes;
		}

		$prefix = '/' . Plugin::rest_namespace() . '/';

		return array_values(
			array_filter(
				$routes,
				static fn( $route ): bool => ! is_string( $route ) || ! str_starts_with( '/' . ltrim( $route, '/' ), $prefix )
			)
		);
	}
}
