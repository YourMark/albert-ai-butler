<?php
/**
 * Allowed Users
 *
 * @package Albert
 * @subpackage OAuth
 * @since      1.4.0
 */

namespace Albert\OAuth;

defined( 'ABSPATH' ) || exit;

use Albert\Settings\Value;

/**
 * AllowedUsers class
 *
 * The single reader/writer of `albert_allowed_users`: the list of people who
 * may complete an OAuth authorisation, checked at
 * {@see \Albert\OAuth\Endpoints\AuthorizationPage::handle_authorization()}.
 *
 * Stored keyed by user id, each entry carrying `added_at` (when they were
 * granted the standing invitation), `authorised_at` (when they first
 * actually used it, null until then), and `expires_at`:
 *
 *     [ 42 => [ 'added_at' => 1755765600, 'authorised_at' => null, 'expires_at' => 1755852000 ], ... ]
 *
 * `expires_at` is computed once, at {@see self::add()}, from the expiry
 * window in force *at that moment* ({@see self::EXPIRY_OPTION}), and stored
 * as an absolute timestamp, not recomputed from the live setting on every
 * check. An invitation's deadline is a property of that invitation, decided
 * when it was granted: if the owner later tightens or loosens the window,
 * every invitation already waiting keeps the deadline it was given. Silently
 * moving someone's deadline because an unrelated setting changed would be a
 * surprise no security control should spring on someone.
 *
 * {@see self::reset_expiry_clock()} recalculates them deliberately, but is
 * programmatic only. It was briefly offered as a checkbox on the Settings
 * screen and removed in 1.4.0: "apply to invitations already waiting" asks the
 * owner to reason about the difference between a window and a deadline in
 * order to tick a box, which is a distinction the screen should simply make
 * for them.
 *
 * Before 1.4.0 this was a flat array of ints with no timestamps at all.
 * {@see \Albert\Database\Installer} migrates existing sites once, on
 * upgrade, before any of these methods can run in the same request (its
 * `maybe_upgrade()` fires first in {@see \Albert\Core\Plugin::init()}), by
 * leaving every field `null`: there is no record of when a legacy entry was
 * actually added or whether it was ever exercised, and inventing either
 * would be stating a fact nobody knows, displayed as one right on the row
 * ("Added 2 hours ago" for an account that predates this feature entirely
 * is not a rounding error, it is simply wrong). A migrated entry is exempt
 * from expiry the same way a fresh one with expiry turned off is: `null`
 * `expires_at` alone, {@see self::is_expired()}, needs no fabricated
 * `authorised_at` to back it up. Fields are still read defensively here in
 * case an entry somehow predates even that migration.
 *
 * `authorised_at` is the exemption for entries that *do* have a real one: an
 * invitation that was exercised at least once is allowed forever, no matter
 * what happens to that connection later or what its `expires_at` says,
 * checked nowhere but here.
 *
 * Invitation expiry is enforced live, at {@see self::is_allowed()}, not only
 * by the sweep. The same reasoning already applies to token expiry
 * (docs/features/31-connections.md §4): whether a stale row has been
 * physically removed yet is bookkeeping, not the security boundary. An
 * invitation past its stored `expires_at` is refused the moment somebody
 * tries to use it, whether or not {@see \Albert\Cron\AllowedUserExpiry} has
 * run since it expired. The cron exists to keep the stored list tidy, not to
 * be the thing standing between an expired invitation and a connection.
 *
 * @since 1.4.0
 */
class AllowedUsers {

	/**
	 * The option name.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const OPTION = 'albert_allowed_users';

	/**
	 * Option name for the invitation-expiry window, in days. 0 = never.
	 * Only consulted when an invitation's `expires_at` is computed: at
	 * {@see self::add()}, and at a programmatic {@see self::reset_expiry_clock()}.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	const EXPIRY_OPTION = 'albert_allowed_user_expiry_days';

	/**
	 * Default expiry window, in days. A never-authorised invitation is
	 * refused after this long: short by design, the same way a WordPress
	 * account-activation link is only good for a limited time. Somebody who
	 * misses it is not locked out forever, they are re-added with one click
	 * on the Connections screen.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	const DEFAULT_EXPIRY_DAYS = 1;

	/**
	 * Every allowed user, normalised.
	 *
	 * @return array<int, array{added_at: int|null, authorised_at: int|null, expires_at: int|null}>
	 * @since 1.4.0
	 */
	public static function all(): array {
		$raw = get_option( self::OPTION, [] );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$normalised = [];

		foreach ( $raw as $key => $value ) {
			$id = is_array( $value ) ? (int) $key : (int) $value;

			if ( $id <= 0 || isset( $normalised[ $id ] ) ) {
				continue;
			}

			$entry = is_array( $value ) ? $value : [];

			$normalised[ $id ] = [
				'added_at'      => self::to_timestamp( $entry['added_at'] ?? null ),
				'authorised_at' => self::to_timestamp( $entry['authorised_at'] ?? null ),
				'expires_at'    => self::to_timestamp( $entry['expires_at'] ?? null ),
			];
		}

		return $normalised;
	}

	/**
	 * Every allowed user id.
	 *
	 * @return array<int, int>
	 * @since 1.4.0
	 */
	public static function ids(): array {
		return array_keys( self::all() );
	}

	/**
	 * Whether anybody is allowed at all.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	public static function has_any(): bool {
		return ! empty( self::all() );
	}

	/**
	 * Whether a user may complete an OAuth authorisation right now.
	 *
	 * Not just membership: a never-authorised entry past its stored
	 * `expires_at` is refused here too, live, rather than waiting for
	 * {@see \Albert\Cron\AllowedUserExpiry} to have physically removed it.
	 * Checked at {@see \Albert\OAuth\Endpoints\AuthorizationPage::handle_authorization()}.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	public static function is_allowed( int $user_id ): bool {
		$entry = self::all()[ $user_id ] ?? null;

		return $entry !== null && ! self::is_expired( $entry );
	}

	/**
	 * Whether a user is still on the list, but their invitation has expired:
	 * never authorised, and past its stored `expires_at`. Distinct from
	 * `! is_allowed()`, which is also true for somebody never invited at all;
	 * this is what {@see self::is_allowed()} internally checks before
	 * refusing, and what the sweep and the access-denied page use to tell
	 * "you were never invited" apart from "your invitation expired".
	 *
	 * @param int $user_id The user id.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	public static function has_expired_invitation( int $user_id ): bool {
		$entry = self::all()[ $user_id ] ?? null;

		return $entry !== null && self::is_expired( $entry );
	}

	/**
	 * Whether an entry is a never-authorised invitation past its stored
	 * `expires_at`. The single rule both the live gate
	 * ({@see self::is_allowed()}) and the sweep ({@see \Albert\Cron\AllowedUserExpiry})
	 * apply, so the two can never disagree about who has expired.
	 *
	 * @param array{added_at: int|null, authorised_at: int|null, expires_at: int|null} $entry A normalised entry.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	private static function is_expired( array $entry ): bool {
		if ( $entry['authorised_at'] !== null || $entry['expires_at'] === null ) {
			return false;
		}

		return $entry['expires_at'] <= time();
	}

	/**
	 * When a user was added to the list, or null if they are not on it or
	 * predate the 1.4.0 migration.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return int|null Unix timestamp.
	 * @since 1.4.0
	 */
	public static function added_at( int $user_id ): ?int {
		return self::all()[ $user_id ]['added_at'] ?? null;
	}

	/**
	 * When a user first completed an authorisation, or null if they never have.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return int|null Unix timestamp.
	 * @since 1.4.0
	 */
	public static function authorised_at( int $user_id ): ?int {
		return self::all()[ $user_id ]['authorised_at'] ?? null;
	}

	/**
	 * When a user's invitation expires (or expired), or null if it does not
	 * expire: expiry was off when it was granted, they are not on the list,
	 * or the entry predates the 1.4.0 migration.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return int|null Unix timestamp.
	 * @since 1.4.0
	 */
	public static function expires_at( int $user_id ): ?int {
		return self::all()[ $user_id ]['expires_at'] ?? null;
	}

	/**
	 * Whether a user has ever completed an authorisation.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return bool
	 * @since 1.4.0
	 */
	public static function has_authorised( int $user_id ): bool {
		return self::authorised_at( $user_id ) !== null;
	}

	/**
	 * Add a user to the allowed list, if they are not already on it.
	 *
	 * `expires_at` is computed now, from whatever the expiry window is at
	 * this moment, and stored: it does not move if the window changes later
	 * (see the class docblock).
	 *
	 * @param int $user_id The user id.
	 *
	 * @return bool True if the user was newly added, false if already allowed.
	 * @since 1.4.0
	 */
	public static function add( int $user_id ): bool {
		$all = self::all();

		if ( isset( $all[ $user_id ] ) ) {
			return false;
		}

		$now = time();

		$all[ $user_id ] = [
			'added_at'      => $now,
			'authorised_at' => null,
			'expires_at'    => self::compute_expiry( $now ),
		];

		self::save( $all );

		return true;
	}

	/**
	 * Remove a user from the allowed list.
	 *
	 * Does not touch their tokens; callers that mean "and disconnect them"
	 * (e.g. {@see \Albert\Admin\Connections::handle_remove_allowed_user()})
	 * revoke separately via {@see \Albert\Admin\Settings::revoke_user_tokens()}.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function remove( int $user_id ): void {
		$all = self::all();

		unset( $all[ $user_id ] );

		self::save( $all );
	}

	/**
	 * Record that a user has completed an authorisation, the first time only.
	 *
	 * A no-op for a user not on the list (removed between granting and
	 * completing the OAuth flow) or who has already authorised before: the
	 * invitation only needs to be exercised once to be exempt from expiry
	 * forever, so the first timestamp is the one that matters.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function mark_authorised( int $user_id ): void {
		$all = self::all();

		if ( ! isset( $all[ $user_id ] ) || $all[ $user_id ]['authorised_at'] !== null ) {
			return;
		}

		$all[ $user_id ]['authorised_at'] = time();

		self::save( $all );
	}

	/**
	 * Recalculate `expires_at` for every still-pending (never-authorised)
	 * invitation, using each one's own `added_at` plus the given window.
	 *
	 * Not run automatically when the expiry setting changes: an invitation's
	 * deadline is a property of that invitation, not of the current window (see
	 * the class docblock). Nothing in Albert calls this today — it is here for
	 * a caller that genuinely means "move every pending deadline", such as a
	 * WP-CLI command or an add-on. Already exercised invitations are untouched:
	 * their `authorised_at` exemption makes `expires_at` moot for them
	 * regardless.
	 *
	 * @param int $days The new expiry window, in days. 0 clears expiry (never).
	 *
	 * @return int How many entries were recalculated.
	 * @since 1.4.0
	 */
	public static function reset_expiry_clock( int $days ): int {
		$all     = self::all();
		$touched = 0;

		foreach ( $all as $user_id => $entry ) {
			if ( $entry['authorised_at'] !== null || $entry['added_at'] === null ) {
				continue;
			}

			$all[ $user_id ]['expires_at'] = $days > 0 ? $entry['added_at'] + ( $days * DAY_IN_SECONDS ) : null;
			++$touched;
		}

		if ( $touched > 0 ) {
			self::save( $all );
		}

		return $touched;
	}

	/**
	 * The expiry timestamp for an invitation granted right now, from the
	 * currently configured window. Null when expiry is off (0 days).
	 *
	 * @param int $from Unix timestamp the invitation is granted at.
	 *
	 * @return int|null
	 * @since 1.4.0
	 */
	private static function compute_expiry( int $from ): ?int {
		// Read through the settings chain so a constant or filter pinning this
		// window is the window actually used, matching what the Settings screen
		// reports while one is in force.
		//
		// Explicit default: reachable from cron and WP-CLI as well as admin, and
		// Settings\Storage only registers its default on `admin_init`.
		$days = (int) Value::get( self::EXPIRY_OPTION, self::DEFAULT_EXPIRY_DAYS );

		return $days > 0 ? $from + ( $days * DAY_IN_SECONDS ) : null;
	}

	/**
	 * Persist the normalised structure as MySQL-friendly datetime strings.
	 *
	 * @param array<int, array{added_at: int|null, authorised_at: int|null, expires_at: int|null}> $all Normalised entries.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private static function save( array $all ): void {
		$stored = [];

		foreach ( $all as $id => $entry ) {
			$stored[ $id ] = [
				'added_at'      => $entry['added_at'] !== null ? gmdate( 'Y-m-d H:i:s', $entry['added_at'] ) : null,
				'authorised_at' => $entry['authorised_at'] !== null ? gmdate( 'Y-m-d H:i:s', $entry['authorised_at'] ) : null,
				'expires_at'    => $entry['expires_at'] !== null ? gmdate( 'Y-m-d H:i:s', $entry['expires_at'] ) : null,
			];
		}

		update_option( self::OPTION, $stored );
	}

	/**
	 * A stored datetime string (or already-int timestamp) to a Unix timestamp.
	 *
	 * @param mixed $value A `Y-m-d H:i:s` string, an int, or null.
	 *
	 * @return int|null
	 * @since 1.4.0
	 */
	private static function to_timestamp( $value ): ?int {
		if ( $value === null || $value === '' ) {
			return null;
		}

		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		$timestamp = strtotime( (string) $value . ' UTC' );

		return $timestamp !== false ? $timestamp : null;
	}
}
