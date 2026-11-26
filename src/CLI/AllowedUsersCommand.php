<?php
/**
 * WP-CLI: the allowed-users list.
 *
 * @package Albert
 * @subpackage CLI
 * @since      1.6.0
 */

namespace Albert\CLI;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\Connections;
use Albert\Admin\Settings;
use Albert\OAuth\AllowedUsers;
use WP_CLI;
use WP_CLI\Fetchers\User as UserFetcher;
use WP_CLI\Formatter;

/**
 * Manage who may connect an AI assistant to this site: the Connections screen's list.
 *
 * ## EXAMPLES
 *
 *     wp albert allowed-users list
 *     wp albert allowed-users add jan@example.com
 *     wp albert allowed-users remove jan
 *
 * @since 1.6.0
 */
class AllowedUsersCommand {

	/**
	 * Register the command. Only under WP-CLI.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'albert allowed-users', self::class );
		}
	}

	/**
	 * List the people who may connect an assistant.
	 *
	 * Status is `authorised` (has connected, never lapses), `invited` (not
	 * connected yet) or `expired` (the invitation lapsed unused; sending a setup
	 * link from the Connections screen restarts it).
	 *
	 * ## OPTIONS
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns. Default: ID, user_login, user_email, status, added, expires.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>    $args       Unused.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows    = [];
		$entries = AllowedUsers::all();

		cache_users( array_keys( $entries ) );

		foreach ( $entries as $user_id => $entry ) {
			$user = get_user_by( 'id', $user_id );

			if ( $entry['authorised_at'] !== null ) {
				$status = 'authorised';
			} elseif ( AllowedUsers::is_expired( $entry ) ) {
				$status = 'expired';
			} else {
				$status = 'invited';
			}

			$rows[] = [
				'ID'         => $user_id,
				'user_login' => $user instanceof \WP_User ? $user->user_login : '',
				'user_email' => $user instanceof \WP_User ? $user->user_email : '',
				'status'     => $status,
				'added'      => $entry['added_at'] !== null ? (string) wp_date( 'Y-m-d H:i', $entry['added_at'] ) : '',
				// An authorised invitation never lapses, whatever expires_at says.
				'expires'    => $entry['authorised_at'] === null && $entry['expires_at'] !== null
					? (string) wp_date( 'Y-m-d H:i', $entry['expires_at'] )
					: 'never',
			];
		}

		$formatter = new Formatter( $assoc_args, [ 'ID', 'user_login', 'user_email', 'status', 'added', 'expires' ] );

		// Formatter's `ids` prints the items themselves, so it needs bare IDs.
		$formatter->display_items( ( $assoc_args['format'] ?? '' ) === 'ids' ? array_column( $rows, 'ID' ) : $rows );
	}

	/**
	 * Allow one or more people to connect an assistant. Nobody is emailed.
	 *
	 * The invitation lasts as long as the site's setting, as on the Connections
	 * screen. Only people the screen's picker would offer can be added.
	 *
	 * ## OPTIONS
	 *
	 * <user>...
	 * : User ID, login or email address.
	 *
	 * @param array<int, string> $args Users.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function add( array $args ): void {
		$users = $this->users( $args );

		foreach ( $users as $user ) {
			if ( ! Connections::can_be_allowed( $user ) ) {
				WP_CLI::error(
					sprintf(
						'%s (ID %d) lacks the "%s" capability needed to approve an assistant. Nothing changed.',
						$user->user_login,
						$user->ID,
						Connections::allowed_user_capability()
					)
				);
			}
		}

		$added = 0;
		$ids   = AllowedUsers::ids();

		foreach ( $users as $user ) {
			if ( in_array( $user->ID, $ids, true ) ) {
				WP_CLI::warning( sprintf( '%s (ID %d) is already on the list.', $user->user_login, $user->ID ) );
			} elseif ( AllowedUsers::add( $user->ID ) ) {
				++$added;
				WP_CLI::log( sprintf( 'Added %s (ID %d).', $user->user_login, $user->ID ) );
			} else {
				WP_CLI::warning( sprintf( '%s (ID %d) could not be saved to the list.', $user->user_login, $user->ID ) );
			}
		}

		WP_CLI::success( sprintf( 'Added %d of %d.', $added, count( $users ) ) );
	}

	/**
	 * Remove people from the list and sign out every assistant they connected.
	 *
	 * ## OPTIONS
	 *
	 * <user>...
	 * : User ID, login or email address.
	 *
	 * @param array<int, string> $args Users.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function remove( array $args ): void {
		$users   = $this->users( $args );
		$removed = 0;

		foreach ( $users as $user ) {
			if ( ! AllowedUsers::remove( $user->ID ) ) {
				WP_CLI::warning( sprintf( '%s (ID %d) is not on the list.', $user->user_login, $user->ID ) );
				continue;
			}

			Settings::revoke_user_tokens( $user->ID );
			++$removed;
			WP_CLI::log( sprintf( 'Removed %s (ID %d) and signed out their assistants.', $user->user_login, $user->ID ) );
		}

		WP_CLI::success( sprintf( 'Removed %d of %d.', $removed, count( $users ) ) );
	}

	/**
	 * The users named, once each; stops before any change if one is not found.
	 *
	 * @param array<int, string> $args User IDs, logins or emails.
	 *
	 * @return array<int, \WP_User>
	 * @since 1.6.0
	 */
	private function users( array $args ): array {
		$found = ( new UserFetcher() )->get_many( $args );

		// get_many() warns about each one it could not find and skips it.
		if ( count( $found ) !== count( $args ) ) {
			WP_CLI::error( 'Not every user was found. Nothing changed.' );
		}

		$users = [];

		foreach ( $found as $user ) {
			$users[ $user->ID ] = $user;
		}

		return array_values( $users );
	}
}
