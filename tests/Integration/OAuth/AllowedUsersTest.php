<?php
/**
 * Integration tests for AllowedUsers.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth;

use Albert\OAuth\AllowedUsers;
use Albert\Tests\TestCase;

/**
 * AllowedUsers integration tests.
 *
 * @covers \Albert\OAuth\AllowedUsers
 */
class AllowedUsersTest extends TestCase {

	/**
	 * A clean option before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( AllowedUsers::OPTION );
		delete_option( AllowedUsers::EXPIRY_OPTION );
	}

	/**
	 * Seed an entry with exact timestamps, bypassing `add()` (which always
	 * stamps "now" and computes `expires_at` from the live setting).
	 *
	 * @param int      $user_id       The user id.
	 * @param int|null $added_at      Unix timestamp, or null.
	 * @param int|null $authorised_at Unix timestamp, or null.
	 * @param int|null $expires_at    Unix timestamp, or null (never expires).
	 *
	 * @return void
	 */
	private function seed( int $user_id, ?int $added_at, ?int $authorised_at, ?int $expires_at ): void {
		$all             = get_option( AllowedUsers::OPTION, [] );
		$all[ $user_id ] = [
			'added_at'      => $added_at !== null ? gmdate( 'Y-m-d H:i:s', $added_at ) : null,
			'authorised_at' => $authorised_at !== null ? gmdate( 'Y-m-d H:i:s', $authorised_at ) : null,
			'expires_at'    => $expires_at !== null ? gmdate( 'Y-m-d H:i:s', $expires_at ) : null,
		];
		update_option( AllowedUsers::OPTION, $all );
	}

	/**
	 * A fresh add records `added_at`, leaves `authorised_at` unset, and
	 * computes `expires_at` from the default (1-day) window.
	 *
	 * @return void
	 */
	public function test_add_records_added_at_and_computes_expires_at(): void {
		$user_id = self::factory()->user->create();

		$this->assertTrue( AllowedUsers::add( $user_id ) );

		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
		$this->assertEqualsWithDelta( time(), AllowedUsers::added_at( $user_id ), 5 );
		$this->assertNull( AllowedUsers::authorised_at( $user_id ) );
		$this->assertFalse( AllowedUsers::has_authorised( $user_id ) );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, AllowedUsers::expires_at( $user_id ), 5 );
	}

	/**
	 * Adding somebody already on the list is a no-op and does not reset
	 * `added_at`.
	 *
	 * @return void
	 */
	public function test_add_is_a_noop_for_an_existing_entry(): void {
		$user_id = self::factory()->user->create();

		AllowedUsers::add( $user_id );
		$first_added_at = AllowedUsers::added_at( $user_id );

		$this->assertFalse( AllowedUsers::add( $user_id ) );
		$this->assertSame( $first_added_at, AllowedUsers::added_at( $user_id ) );
	}

	/**
	 * Removing drops the entry entirely.
	 *
	 * @return void
	 */
	public function test_remove_drops_the_entry(): void {
		$user_id = self::factory()->user->create();

		AllowedUsers::add( $user_id );
		AllowedUsers::remove( $user_id );

		$this->assertFalse( AllowedUsers::is_allowed( $user_id ) );
		$this->assertSame( [], AllowedUsers::ids() );
	}

	/**
	 * Records the first-use timestamp for somebody on the list.
	 *
	 * @return void
	 */
	public function test_mark_authorised_records_the_timestamp(): void {
		$user_id = self::factory()->user->create();

		AllowedUsers::add( $user_id );
		AllowedUsers::mark_authorised( $user_id );

		$this->assertTrue( AllowedUsers::has_authorised( $user_id ) );
		$this->assertEqualsWithDelta( time(), AllowedUsers::authorised_at( $user_id ), 5 );
	}

	/**
	 * Only ever records the *first* time: a distinction the invitation-expiry
	 * sweep depends on to exempt somebody for good the moment they exercise
	 * the invitation, not just the most recent time.
	 *
	 * @return void
	 */
	public function test_mark_authorised_keeps_the_first_timestamp(): void {
		$user_id = self::factory()->user->create();

		AllowedUsers::add( $user_id );
		AllowedUsers::mark_authorised( $user_id );
		$first = AllowedUsers::authorised_at( $user_id );

		// A later authorisation (e.g. a second connection) must not move it.
		AllowedUsers::mark_authorised( $user_id );

		$this->assertSame( $first, AllowedUsers::authorised_at( $user_id ) );
	}

	/**
	 * A no-op for somebody not on the list at all (e.g. removed between being
	 * granted access and completing the OAuth flow).
	 *
	 * @return void
	 */
	public function test_mark_authorised_is_a_noop_when_not_allowed(): void {
		$user_id = self::factory()->user->create();

		AllowedUsers::mark_authorised( $user_id );

		$this->assertFalse( AllowedUsers::is_allowed( $user_id ) );
	}

	/**
	 * Reflects whether the list is empty.
	 *
	 * @return void
	 */
	public function test_has_any(): void {
		$this->assertFalse( AllowedUsers::has_any() );

		AllowedUsers::add( self::factory()->user->create() );

		$this->assertTrue( AllowedUsers::has_any() );
	}

	/**
	 * A legacy flat array of ids (pre-1.4.0 shape) is read defensively: ids
	 * resolve, but with no timestamps to report, and expiry never applies.
	 *
	 * @return void
	 */
	public function test_reads_a_legacy_flat_array_defensively(): void {
		$user_id = self::factory()->user->create();

		update_option( AllowedUsers::OPTION, [ $user_id ] );

		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
		$this->assertSame( [ $user_id ], AllowedUsers::ids() );
		$this->assertNull( AllowedUsers::added_at( $user_id ) );
		$this->assertNull( AllowedUsers::expires_at( $user_id ) );
	}

	/**
	 * A never-authorised invitation past its stored `expires_at` is refused
	 * live, whether or not any cron has run.
	 *
	 * @return void
	 */
	public function test_is_allowed_false_once_expires_at_passes(): void {
		$user_id = self::factory()->user->create();
		$this->seed( $user_id, time() - ( 2 * DAY_IN_SECONDS ), null, time() - DAY_IN_SECONDS );

		$this->assertFalse( AllowedUsers::is_allowed( $user_id ) );
		$this->assertTrue( AllowedUsers::has_expired_invitation( $user_id ) );
	}

	/**
	 * A fresh invitation is still allowed well inside its window.
	 *
	 * @return void
	 */
	public function test_is_allowed_true_within_the_default_window(): void {
		$user_id = self::factory()->user->create();

		AllowedUsers::add( $user_id );

		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
		$this->assertFalse( AllowedUsers::has_expired_invitation( $user_id ) );
	}

	/**
	 * Expiry disabled (0) at the moment of adding stores no `expires_at`, so
	 * the invitation never expires however old it gets.
	 *
	 * @return void
	 */
	public function test_is_allowed_true_when_expiry_was_disabled_at_add_time(): void {
		$user_id = self::factory()->user->create();

		update_option( AllowedUsers::EXPIRY_OPTION, 0 );
		AllowedUsers::add( $user_id );

		$this->assertNull( AllowedUsers::expires_at( $user_id ) );
		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
		$this->assertFalse( AllowedUsers::has_expired_invitation( $user_id ) );
	}

	/**
	 * Changing the global expiry window after an invitation was granted does
	 * not move that invitation's own `expires_at`: the deadline is decided
	 * once, at the moment it is granted, not re-derived from whatever the
	 * setting currently says.
	 *
	 * @return void
	 */
	public function test_changing_the_setting_does_not_move_an_existing_expires_at(): void {
		$user_id = self::factory()->user->create();

		update_option( AllowedUsers::EXPIRY_OPTION, 1 );
		AllowedUsers::add( $user_id );
		$original_expiry = AllowedUsers::expires_at( $user_id );

		update_option( AllowedUsers::EXPIRY_OPTION, 30 );

		$this->assertSame( $original_expiry, AllowedUsers::expires_at( $user_id ) );
	}

	/**
	 * Somebody who authorised at least once is allowed forever, however old
	 * or far in the past their `expires_at` is: the invitation was
	 * exercised, that is what it was for.
	 *
	 * @return void
	 */
	public function test_is_allowed_true_for_an_old_but_authorised_invitation(): void {
		$user_id = self::factory()->user->create();
		$this->seed(
			$user_id,
			time() - ( 365 * DAY_IN_SECONDS ),
			time() - ( 364 * DAY_IN_SECONDS ),
			time() - ( 364 * DAY_IN_SECONDS )
		);

		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
		$this->assertFalse( AllowedUsers::has_expired_invitation( $user_id ) );
	}

	/**
	 * False for somebody never invited at all: that is a different denial
	 * (never invited) from an expired one, and the access-denied page tells
	 * them apart.
	 *
	 * @return void
	 */
	public function test_has_expired_invitation_is_false_when_never_invited(): void {
		$user_id = self::factory()->user->create();

		$this->assertFalse( AllowedUsers::is_allowed( $user_id ) );
		$this->assertFalse( AllowedUsers::has_expired_invitation( $user_id ) );
	}

	/**
	 * Recalculates `expires_at` for every still-pending invitation from its
	 * own `added_at`, only when explicitly asked.
	 *
	 * @return void
	 */
	public function test_reset_expiry_clock_recalculates_pending_entries(): void {
		$user_id = self::factory()->user->create();
		$this->seed( $user_id, time() - ( 10 * DAY_IN_SECONDS ), null, time() - ( 9 * DAY_IN_SECONDS ) );

		$touched = AllowedUsers::reset_expiry_clock( 30 );

		$this->assertSame( 1, $touched );
		$this->assertEqualsWithDelta(
			time() - ( 10 * DAY_IN_SECONDS ) + ( 30 * DAY_IN_SECONDS ),
			AllowedUsers::expires_at( $user_id ),
			5
		);
		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
	}

	/**
	 * Leaves already-authorised entries alone: their exemption makes
	 * `expires_at` moot, and recalculating it would be pointless churn.
	 *
	 * @return void
	 */
	public function test_reset_expiry_clock_skips_authorised_entries(): void {
		$user_id = self::factory()->user->create();
		$this->seed(
			$user_id,
			time() - ( 365 * DAY_IN_SECONDS ),
			time() - ( 364 * DAY_IN_SECONDS ),
			time() - ( 364 * DAY_IN_SECONDS )
		);
		$original_expiry = AllowedUsers::expires_at( $user_id );

		$touched = AllowedUsers::reset_expiry_clock( 30 );

		$this->assertSame( 0, $touched );
		$this->assertSame( $original_expiry, AllowedUsers::expires_at( $user_id ) );
	}

	/**
	 * 0 clears `expires_at` for every pending entry: they stop expiring.
	 *
	 * @return void
	 */
	public function test_reset_expiry_clock_with_zero_clears_expiry(): void {
		$user_id = self::factory()->user->create();
		$this->seed( $user_id, time() - DAY_IN_SECONDS, null, time() - HOUR_IN_SECONDS );

		AllowedUsers::reset_expiry_clock( 0 );

		$this->assertNull( AllowedUsers::expires_at( $user_id ) );
		$this->assertTrue( AllowedUsers::is_allowed( $user_id ) );
	}

	/**
	 * Sending the link restarts a pending invitation, an expired one too,
	 * and leaves someone who connected or never expires alone.
	 *
	 * @return void
	 */
	public function test_renew_invitation(): void {
		$expired   = self::factory()->user->create();
		$connected = self::factory()->user->create();
		$forever   = self::factory()->user->create();
		$this->seed( $expired, time() - 3 * DAY_IN_SECONDS, null, time() - DAY_IN_SECONDS );
		$this->seed( $connected, time() - 3 * DAY_IN_SECONDS, time() - DAY_IN_SECONDS, time() - DAY_IN_SECONDS );
		$this->seed( $forever, time() - 3 * DAY_IN_SECONDS, null, null );

		$this->assertTrue( AllowedUsers::renew_invitation( $expired ) );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, AllowedUsers::expires_at( $expired ), 5 );
		$this->assertTrue( AllowedUsers::is_allowed( $expired ) );

		$this->assertFalse( AllowedUsers::renew_invitation( $connected ) );
		$this->assertFalse( AllowedUsers::renew_invitation( $forever ) );
		$this->assertNull( AllowedUsers::expires_at( $forever ) );
		$this->assertFalse( AllowedUsers::renew_invitation( self::factory()->user->create() ) );
	}
}
