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
use Albert\Undo\Retention\Registrar;
use Albert\Undo\Retention\RetentionPolicy;
use Albert\Undo\Storage\Repository;

/**
 * Nightly WP-Cron job that holds the change journal to its retention policy.
 *
 * Prunes whole sessions by age, then those outside the per-item session count,
 * then by total size, oldest first. A pruned item
 * keeps its row and loses its before-image, so the entry can still say what
 * happened and that it can no longer be reverted. Entries are deleted
 * outright after {@see self::DELETE_AFTER_DAYS}, so the journal does not keep a
 * record of every call forever.
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
	 * Wire the prune to its store and policy.
	 *
	 * @param Repository|null      $repository The journal store.
	 * @param RetentionPolicy|null $policy     The policy; defaults to the registered one.
	 */
	public function __construct( private ?Repository $repository = null, private ?RetentionPolicy $policy = null ) {
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
			$policy     = $this->policy ?? Registrar::current();
			$repository = $this->repository ?? new Repository();
			$now        = time();

			$repository->close_abandoned( gmdate( 'Y-m-d H:i:s', $now - self::ABANDONED_SECONDS ) );
			$repository->prune_older_than( Repository::cutoff( $policy->max_age_days() ) );
			$repository->prune_beyond_runs( $policy->runs_per_item() );
			$repository->prune_to_bytes( $policy->max_bytes() );
			$repository->delete_older_than(
				gmdate( 'Y-m-d H:i:s', $now - ( max( 0, $policy->max_age_days() ) + self::DELETE_AFTER_DAYS ) * DAY_IN_SECONDS )
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never let a cron failure surface to the site.
		}
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
