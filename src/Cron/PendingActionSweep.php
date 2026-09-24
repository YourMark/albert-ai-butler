<?php
/**
 * Safe-mode pending-actions sweep
 *
 * @package Albert
 * @subpackage Cron
 * @since      1.5.0
 */

namespace Albert\Cron;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\SafeMode\AuditTrail;
use Albert\SafeMode\Repository;

/**
 * Daily WP-Cron job that keeps the safe-mode queue honest and bounded.
 *
 * Three jobs, in order:
 *
 * 1. **Lapsed rows become `expired`.** Without this a row nobody decided keeps
 *    `status = 'pending'` forever, which both lists exclude: the open list
 *    filters on `expires_at`, the decided list filters on `status <> pending`.
 *    The request then disappears from the screen with no record it was ever
 *    asked, which is an audit hole in a feature whose whole claim is auditable
 *    approval.
 * 2. **Claims that never finished become `failed`.** An approval flips a row to
 *    `executing` and then runs the ability; a fatal or a timeout in between
 *    leaves it there permanently, invisible to the open list and shown as
 *    "Approved, running" in perpetuity.
 * 3. **Decided rows are deleted once they are old.** The queue stores the input
 *    a call was about to run with, so it is the one Albert table holding request
 *    payloads at rest. Retention is what keeps them out of next year's backups.
 *
 * @since 1.5.0
 */
class PendingActionSweep implements Hookable {

	/**
	 * Cron hook name.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const HOOK = 'albert_sweep_pending_actions';

	/**
	 * How long a claim may sit unfinished before it is called failed, in seconds.
	 *
	 * Generous on purpose. A slow ability holding a claim for minutes is normal;
	 * one holding it for an hour is not running any more, whatever the row says.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const STALE_CLAIM_SECONDS = HOUR_IN_SECONDS;

	/**
	 * How long a decided row is kept before deletion, in days.
	 *
	 * Long enough to answer "what did I approve last month", short enough that
	 * captured input is not an indefinite liability.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	const RETENTION_DAYS = 30;

	/**
	 * Wire the sweep to its store.
	 *
	 * @param Repository|null $repository The pending-actions store; defaults to a fresh one.
	 */
	public function __construct( private ?Repository $repository = null ) {
		$this->repository = $repository ?? new Repository();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * Expire what lapsed, fail what stalled, delete what is old.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function run(): void {
		try {
			// Read before writing, so each row can be named in the audit trail.
			// A bulk UPDATE knows how many it touched and nothing about which,
			// and "three requests lapsed" is not the sentence somebody looking
			// for trouble needs.
			$lapsed = $this->repository->list_lapsed();
			$stale  = $this->repository->list_stale_claims( self::STALE_CLAIM_SECONDS );

			$this->repository->expire_lapsed();
			$this->repository->fail_stale_claims( self::STALE_CLAIM_SECONDS );

			foreach ( $lapsed as $action ) {
				AuditTrail::expired( $action );
			}

			foreach ( $stale as $action ) {
				AuditTrail::abandoned( $action );
			}

			$this->repository->purge_decided( self::retention_days() );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never let a cron failure surface to the site.
		}
	}

	/**
	 * How many days a decided row is kept.
	 *
	 * @return int Days; 0 or less disables deletion.
	 * @since 1.5.0
	 */
	private static function retention_days(): int {
		/**
		 * Filters how long decided safe-mode actions are kept before deletion.
		 *
		 * Return 0 or less to keep them indefinitely. Worth knowing before you
		 * do: these rows hold the input each call was about to run with, so
		 * keeping them forever keeps request payloads in every backup.
		 *
		 * @since 1.5.0
		 *
		 * @param int $days Days to keep a decided action. Default 30.
		 */
		return (int) apply_filters( 'albert/safe_mode/retention_days', self::RETENTION_DAYS );
	}

	/**
	 * Schedule the daily sweep if not already scheduled.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Unschedule the daily sweep.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		while ( $timestamp !== false ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}
}
