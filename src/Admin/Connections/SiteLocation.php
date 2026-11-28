<?php
/**
 * Where this site appears to run: on the internet, or only on this computer.
 *
 * @package Albert
 * @subpackage Admin\Connections
 * @since      1.5.0
 */

namespace Albert\Admin\Connections;

defined( 'ABSPATH' ) || exit;

use Albert\MCP\Server as McpServer;

/**
 * Decides which apps the connect flow offers first, and which it marks as
 * needing a public site.
 *
 * Detection is a guess from the site's address and environment type, and is
 * shown as one. It recommends and orders, never hides: a tunnel, a staging
 * host or a LAN address can all fool it, so an administrator can set the
 * answer for the site.
 *
 * @since 1.5.0
 */
class SiteLocation {

	/**
	 * On the internet: apps that connect from their vendor's servers can reach it.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const PUBLIC = 'public';

	/**
	 * Only on this computer or network: only apps running here can reach it.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const LOCAL = 'local';

	/**
	 * The site-wide answer an administrator saved. Absent until somebody chooses.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const OPTION = 'albert_site_location';

	/**
	 * Host suffixes local development tools hand out. Never resolvable from
	 * the internet, so a site on one is local whatever else is true.
	 *
	 * @since 1.5.0
	 * @var array<int, string>
	 */
	private const LOCAL_SUFFIXES = [
		'.test',
		'.local',
		'.localhost',
		'.lan',
		'.internal',
		'.home.arpa',
		'.ddev.site',
		'.lndo.site',
	];

	/**
	 * The answer in force: the one an administrator saved, else the guess.
	 *
	 * @return string self::PUBLIC or self::LOCAL.
	 * @since 1.5.0
	 */
	public static function current(): string {
		$saved = self::saved();

		return $saved !== '' ? $saved : self::detect();
	}

	/**
	 * Whether the answer in force is still Albert's guess rather than a choice.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function is_guess(): bool {
		return self::saved() === '';
	}

	/**
	 * The site-wide answer an administrator saved, or '' when nobody has.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function saved(): string {
		$saved = (string) get_option( self::OPTION, '' );

		return self::is_valid( $saved ) ? $saved : '';
	}

	/**
	 * Save the site-wide answer.
	 *
	 * @param string $location self::PUBLIC or self::LOCAL.
	 *
	 * @return bool False when the value is not one of the two.
	 * @since 1.5.0
	 */
	public static function save( string $location ): bool {
		if ( ! self::is_valid( $location ) ) {
			return false;
		}

		update_option( self::OPTION, $location, false );

		return true;
	}

	/**
	 * Albert's guess, from the address assistants connect to.
	 *
	 * The MCP endpoint, not the site address: an external-URL override (a
	 * tunnel) makes a local site reachable, and the endpoint is what an app
	 * actually dials. The environment type only counts while the endpoint is
	 * still the site's own address.
	 *
	 * @return string self::PUBLIC or self::LOCAL.
	 * @since 1.5.0
	 */
	public static function detect(): string {
		$endpoint_host = (string) wp_parse_url( McpServer::get_endpoint_url(), PHP_URL_HOST );
		$site_host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		if ( self::is_local_host( $endpoint_host ) ) {
			return self::LOCAL;
		}

		if ( $endpoint_host === $site_host && wp_get_environment_type() === 'local' ) {
			return self::LOCAL;
		}

		return self::PUBLIC;
	}

	/**
	 * Whether a host can only be reached from this computer or network.
	 *
	 * @param string $host A host name or IP address, without port.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function is_local_host( string $host ): bool {
		$host = strtolower( trim( $host, '[]' ) );

		if ( $host === '' || $host === 'localhost' ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false ) {
			// Private, loopback and link-local addresses fail this check.
			return filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
		}

		// A single label ("mysite") only resolves on the local network.
		if ( ! str_contains( $host, '.' ) ) {
			return true;
		}

		foreach ( self::LOCAL_SUFFIXES as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a value is one of the two answers.
	 *
	 * @param string $location The value.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	private static function is_valid( string $location ): bool {
		return $location === self::PUBLIC || $location === self::LOCAL;
	}
}
