<?php
/**
 * Change-journal prune
 *
 * @package Albert
 * @subpackage Cron
 * @since      1.5.0
 */

namespace Albert\Cron;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Undo\FailureCounter;
use Albert\Undo\Retention\Registrar;
use Albert\Undo\Retention\RetentionPolicy;
use Albert\Undo\Storage\Repository;

/**
 * Nightly WP-Cron job that holds the change journal to its retention policy.
 *
 * Prunes whole sessions by age, then beyond the per-item session count, then
 * by size, least recently active first. A pruned item keeps its row, so the
 * entry can still say what happened; entries go {@see self::DELETE_AFTER_DAYS}
 * later.
 *
 * @since 1.5.0
 */
class ChangeJournalPrune implements Hookable {

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	const HOOK = 'albert_prune_change_journal';

	/**
	 * Days after which an entry, already pruned, is deleted.
	 *
	 * @var int
	 */
	const DELETE_AFTER_DAYS = 30;

	/**
	 * How long an entry may stay `recording` before it counts as abandoned, in seconds.
	 *
	 * @var int
	 */
	const ABANDONED_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Least time between two prunes run at the end of a request.
	 *
	 * @var int
	 */
	const SOON_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * Transient that throttles {@see self::soon()}.
	 *
	 * @var string
	 */
	const SOON_LOCK = 'albert_undo_prune_lock';

	/**
	 * The journal store.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Wire the prune to its store and policy.
	 *
	 * @param Repository|null      $repository The journal store.
	 * @param RetentionPolicy|null $policy     The policy; defaults to the registered one.
	 */
	public function __construct( ?Repository $repository = null, private ?RetentionPolicy $policy = null ) {
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
	 * Apply the retention policy.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function run(): void {
		try {
			$policy = $this->policy ?? Registrar::current();
			$now    = time();

			$this->repository->close_abandoned( gmdate( 'Y-m-d H:i:s', $now - self::ABANDONED_SECONDS ) );
			$this->repository->prune_older_than( Repository::cutoff( $policy->max_age_days() ) );
			$this->repository->prune_beyond_runs( $policy->runs_per_item() );
			$this->repository->prune_to_bytes( $policy->max_bytes() );
			$this->repository->delete_older_than(
				gmdate( 'Y-m-d H:i:s', $now - ( max( 0, $policy->max_age_days() ) + self::DELETE_AFTER_DAYS ) * DAY_IN_SECONDS )
			);
		} catch ( \Throwable $e ) {
			// Counted, so retention that stopped working shows on the Dashboard instead of nowhere.
			FailureCounter::record( 1 );
		}
	}

	/**
	 * Also prune at the end of a request that recorded something, at most once
	 * per {@see self::SOON_INTERVAL}: WP-Cron only runs on traffic and can be
	 * switched off, and before-images are personal data that must not outlive
	 * the window because a cron never ran.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function soon(): void {
		if ( get_transient( self::SOON_LOCK ) !== false ) {
			return;
		}

		set_transient( self::SOON_LOCK, 1, self::SOON_INTERVAL );
		add_action( 'shutdown', [ new self(), 'run' ], PHP_INT_MAX );
	}

	/**
	 * Schedule the nightly prune if not already scheduled.
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
	 * Unschedule the nightly prune.
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
