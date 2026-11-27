<?php
/**
 * WP-CLI: registers Albert's commands.
 *
 * @package Albert
 * @subpackage CLI
 * @since      1.6.0
 */

namespace Albert\CLI;

defined( 'ABSPATH' ) || exit;

use WP_CLI;

/**
 * Registers `wp albert` and Free's commands under it.
 *
 * WP-CLI only adds `albert <name>` once `albert` itself exists, so add-ons'
 * commands (Premium's `albert activity-log`) hang off the namespace registered here.
 *
 * @since 1.6.0
 */
class Commands {

	/**
	 * Register the commands. Only under WP-CLI.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			self::add_commands();
		}
	}

	/**
	 * Add the namespace and the commands.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public static function add_commands(): void {
		WP_CLI::add_command( 'albert', AlbertNamespace::class );
		WP_CLI::add_command( 'albert allowed-users', AllowedUsersCommand::class );
	}
}
