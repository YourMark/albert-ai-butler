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

use Albert\Admin\Settings;
use Albert\OAuth\AllowedUsers;
use WP_CLI;

/**
 * Manage who may connect an AI assistant to this site.
 *
 * The same list as the Connections screen. Removing someone signs out every
 * assistant they connected, as it does there.
 *
 * ## EXAMPLES
 *
 *     wp albert allowed-users list
 *     wp albert allowed-users add jan@example.com --expires=7
 *     wp albert allowed-users remove jan
 *
 * @since 1.6.0
 */
class AllowedUsersCommand {

	/**
	 * Columns `list` shows by default.
	 *
	 * @since 1.6.0
	 * @var array<int, string>
	 */
	private const FIELDS = [ 'ID', 'user_login', 'user_email', 'status', 'added', 'expires' ];

	/**
	 * Register the command. Only under WP-CLI.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'albert allowed-users', self::class );
	}

	/**
	 * List the people who may connect an assistant.
	 *
	 * Status is `authorised` (has connected at least once, never lapses),
	 * `invited` (not connected yet) or `expired` (the invitation lapsed unused;
	 * sending a setup link from the Connections screen restarts it).
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
	 * ## EXAMPLES
	 *
	 *     wp albert allowed-users list
	 *     wp albert allowed-users list --format=json
	 *     wp albert allowed-users list --fields=user_login,status
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>    $args       Positional arguments (none).
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function list_( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		$rows   = $this->rows();

		if ( $format === 'ids' ) {
			WP_CLI::log( implode( ' ', array_column( $rows, 'ID' ) ) );
			return;
		}

		if ( $format === 'count' ) {
			WP_CLI::log( (string) count( $rows ) );
			return;
		}

		$fields = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', $assoc_args['fields'] ) ) : self::FIELDS;

		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Allow one or more people to connect an assistant.
	 *
	 * Nobody is emailed. Send their setup link from the Connections screen.
	 *
	 * ## OPTIONS
	 *
	 * <user>...
	 * : User ID, login or email address.
	 *
	 * [--expires=<days>]
	 * : Days the invitation stays open if they do not connect, up to 3650. 0
	 * means it never lapses. Defaults to the site's setting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp albert allowed-users add 12
	 *     wp albert allowed-users add jan maria@example.com --expires=7
	 *
	 * @param array<int, string>    $args       User ids, logins or emails.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function add( array $args, array $assoc_args ): void {
		$expiry_days = null;

		if ( isset( $assoc_args['expires'] ) ) {
			$expires = (string) $assoc_args['expires'];

			// The Settings screen's limit; far beyond it the stored date no longer round-trips.
			if ( ! ctype_digit( $expires ) || (int) $expires > 3650 ) {
				WP_CLI::error( '--expires takes a whole number of days up to 3650, 0 for never.' );
			}

			$expiry_days = (int) $expires;
		}

		$users = $this->resolve( $args );
		$added = 0;

		foreach ( $users as $user ) {
			if ( AllowedUsers::add( $user->ID, $expiry_days ) ) {
				++$added;
				WP_CLI::log( sprintf( 'Added %s (ID %d).', $user->user_login, $user->ID ) );
			} else {
				WP_CLI::warning( sprintf( '%s (ID %d) is already on the list. Nothing changed.', $user->user_login, $user->ID ) );
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
	 * ## EXAMPLES
	 *
	 *     wp albert allowed-users remove 12
	 *     wp albert allowed-users remove jan@example.com
	 *
	 * @param array<int, string> $args User ids, logins or emails.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function remove( array $args ): void {
		$users   = $this->resolve( $args );
		$removed = 0;

		foreach ( $users as $user ) {
			$was_listed = in_array( $user->ID, AllowedUsers::ids(), true );

			AllowedUsers::remove( $user->ID );

			// Also when they were not listed: a token issued before an earlier
			// removal must not outlive this command.
			Settings::revoke_user_tokens( $user->ID );

			if ( $was_listed ) {
				++$removed;
				WP_CLI::log( sprintf( 'Removed %s (ID %d) and signed out their assistants.', $user->user_login, $user->ID ) );
			} else {
				WP_CLI::warning( sprintf( '%s (ID %d) was not on the list. Their tokens were revoked anyway.', $user->user_login, $user->ID ) );
			}
		}

		WP_CLI::success( sprintf( 'Removed %d of %d.', $removed, count( $users ) ) );
	}

	/**
	 * Find every user named, or stop before changing anything.
	 *
	 * @param array<int, string> $identifiers User ids, logins or emails.
	 *
	 * @return array<int, \WP_User>
	 * @since 1.6.0
	 */
	private function resolve( array $identifiers ): array {
		$users   = [];
		$missing = [];

		foreach ( $identifiers as $identifier ) {
			$user = $this->find_user( (string) $identifier );

			if ( $user === null ) {
				$missing[] = $identifier;
				continue;
			}

			$users[ $user->ID ] = $user;
		}

		if ( $missing !== [] ) {
			WP_CLI::error( sprintf( 'No user found for: %s. Nothing changed.', implode( ', ', $missing ) ) );
		}

		return array_values( $users );
	}

	/**
	 * A user by id, login or email, in that order.
	 *
	 * @param string $identifier The id, login or email.
	 *
	 * @return \WP_User|null
	 * @since 1.6.0
	 */
	private function find_user( string $identifier ): ?\WP_User {
		if ( ctype_digit( $identifier ) ) {
			$user = get_user_by( 'id', (int) $identifier );

			if ( $user instanceof \WP_User ) {
				return $user;
			}
		}

		$user = get_user_by( 'login', $identifier );

		if ( ! $user instanceof \WP_User && is_email( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
		}

		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * One row per person on the list.
	 *
	 * @return array<int, array<string, int|string>>
	 * @since 1.6.0
	 */
	private function rows(): array {
		$rows = [];

		foreach ( AllowedUsers::all() as $user_id => $entry ) {
			$user = get_user_by( 'id', $user_id );

			if ( $entry['authorised_at'] !== null ) {
				$status = 'authorised';
			} elseif ( AllowedUsers::has_expired_invitation( $user_id ) ) {
				$status = 'expired';
			} else {
				$status = 'invited';
			}

			$rows[] = [
				'ID'         => $user_id,
				'user_login' => $user instanceof \WP_User ? $user->user_login : '',
				'user_email' => $user instanceof \WP_User ? $user->user_email : '',
				'status'     => $status,
				'added'      => $this->date( $entry['added_at'] ),
				// An authorised invitation never lapses, whatever expires_at says.
				'expires'    => $entry['authorised_at'] !== null ? 'never' : $this->date( $entry['expires_at'], 'never' ),
			];
		}

		return $rows;
	}

	/**
	 * A timestamp in the site's timezone.
	 *
	 * @param int|null $timestamp Unix timestamp.
	 * @param string   $fallback  What to show for null.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	private function date( ?int $timestamp, string $fallback = '' ): string {
		if ( $timestamp === null ) {
			return $fallback;
		}

		return (string) wp_date( 'Y-m-d H:i', $timestamp );
	}
}
