<?php
/**
 * Safe-mode audit events.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

use Albert\Logging\Outcome;
use Albert\Logging\Repository as LogRepository;

/**
 * Records what safe mode did, where somebody looking for trouble would look.
 *
 * Every event here already leaves a trace *somewhere*: a decision sets
 * `decided_by` on the queue row, the sweep flips a status, the connection guard
 * quietly keeps an option's old value. None of that is anywhere near the
 * activity log, so a burst of rejected deletions or an assistant repeatedly
 * trying to switch safe mode off reads as silence next to everything else that
 * happened on the site.
 *
 * **Two destinations, on purpose.** Each event fires its own action *and*
 * writes an activity-log row. The action is the durable half: Free's log keeps
 * only two rows per ability-and-status partition, so it is a recent-activity
 * sample rather than an audit trail, and anything that must survive belongs to
 * whoever is listening (Premium, or a site's own logger). The row is the
 * visible half, so the Dashboard shows a rejection next to the call it refers
 * to instead of in a different screen entirely.
 *
 * Write failures are swallowed. Recording that something happened must never be
 * the reason something else breaks.
 *
 * @since 1.5.0
 */
class AuditTrail {

	/**
	 * A person approved a held call.
	 *
	 * Logged even though the approved run logs itself: that row says the ability
	 * ran, this one says who let it.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const CODE_APPROVED = 'albert_approval_granted';

	/**
	 * A person rejected a held call. The ability never ran.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const CODE_REJECTED = 'albert_approval_rejected';

	/**
	 * Nobody decided in time.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const CODE_EXPIRED = 'albert_approval_expired';

	/**
	 * An approved run never reported back.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const CODE_ABANDONED = 'albert_run_abandoned';

	/**
	 * An assistant tried to write one of Albert's own control options.
	 *
	 * The clearest "somebody is testing the fence" signal the feature has.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const CODE_OPTION_BLOCKED = 'albert_protected_option_write_blocked';

	/**
	 * An assistant deleted one of Albert's control options.
	 *
	 * An `error`, where a refused write is only a `warning`: this one got
	 * through. No core filter can short-circuit `delete_option()`.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const CODE_OPTION_DELETED = 'albert_protected_option_deleted';

	/**
	 * Record an approval.
	 *
	 * @param PendingAction $action     The action that was approved.
	 * @param int           $decided_by The approving user.
	 * @param bool          $ran        Whether the ability then ran without error.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function approved( PendingAction $action, int $decided_by, bool $ran = true ): void {
		/**
		 * Fires when a person approves a held action.
		 *
		 * @since 1.5.0
		 *
		 * @param PendingAction $action     The approved action.
		 * @param int           $decided_by The approving user.
		 */
		do_action( 'albert/safe_mode/approved', $action, $decided_by );

		// The outcome travels with the decision. Recording every approval as a
		// success meant the audit row said the action ran while the queue row said
		// it failed, and two stores disagreeing about one event is worse than
		// either of them being terse.
		self::record(
			$action->ability_name,
			$decided_by,
			$ran ? Outcome::SUCCESS : Outcome::ERROR,
			self::CODE_APPROVED,
			$ran ? 'Approved by a person and run.' : 'Approved by a person, but the action failed.'
		);
	}

	/**
	 * Record a rejection.
	 *
	 * A `warning`, not an `error`: nothing broke, a person said no. That is the
	 * same statement the gate's own hold makes.
	 *
	 * @param PendingAction $action     The action that was rejected.
	 * @param int           $decided_by The rejecting user.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function rejected( PendingAction $action, int $decided_by ): void {
		/**
		 * Fires when a person rejects a held action.
		 *
		 * @since 1.5.0
		 *
		 * @param PendingAction $action     The rejected action.
		 * @param int           $decided_by The rejecting user.
		 */
		do_action( 'albert/safe_mode/rejected', $action, $decided_by );

		self::record( $action->ability_name, $decided_by, Outcome::WARNING, self::CODE_REJECTED, 'Rejected by a person. The action never ran.' );
	}

	/**
	 * Record that a held call lapsed undecided.
	 *
	 * Attributed to the user the call would have run as, not to whoever the
	 * cron happens to be: nobody decided, so there is no decider to name.
	 *
	 * @param PendingAction $action The action that lapsed.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function expired( PendingAction $action ): void {
		/**
		 * Fires when a held action lapses without a decision.
		 *
		 * @since 1.5.0
		 *
		 * @param PendingAction $action The lapsed action.
		 */
		do_action( 'albert/safe_mode/expired', $action );

		self::record( $action->ability_name, $action->user_id, Outcome::WARNING, self::CODE_EXPIRED, 'Nobody decided in time. The action never ran.' );
	}

	/**
	 * Record an approved run that never reported back.
	 *
	 * An `error`, unlike the rest: something broke. And the honest part, which
	 * the message says out loud, is that the work may or may not have completed
	 * before it died.
	 *
	 * @param PendingAction $action The abandoned action.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function abandoned( PendingAction $action ): void {
		/**
		 * Fires when an approved run is found abandoned.
		 *
		 * @since 1.5.0
		 *
		 * @param PendingAction $action The abandoned action.
		 */
		do_action( 'albert/safe_mode/abandoned', $action );

		self::record(
			$action->ability_name,
			$action->decided_by ?? $action->user_id,
			Outcome::ERROR,
			self::CODE_ABANDONED,
			'Approved, but the run never reported back. It may or may not have completed.'
		);
	}

	/**
	 * Record a refused write to one of Albert's control options.
	 *
	 * The ability name is the option, because no ability id is knowable here:
	 * the guard is on the resource and fires for whatever tried to write it,
	 * including code that called `update_option()` directly.
	 *
	 * @param string $option    The option whose write was refused.
	 * @param string $operation What was attempted: `write` or `delete`.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function option_blocked( string $option, string $operation = 'write' ): void {
		// A write is refused; a delete is only seen. WordPress has no
		// `pre_delete_option` filter, so the message must not claim otherwise.
		$message = $operation === 'delete'
			? sprintf( 'An assistant deleted the protected option %s. Deletion cannot be prevented, only recorded.', $option )
			: sprintf( 'An assistant attempted to write the protected option %s. Refused; the stored value is unchanged.', $option );

		self::record(
			'albert/protected-option:' . $option,
			get_current_user_id(),
			$operation === 'delete' ? Outcome::ERROR : Outcome::WARNING,
			$operation === 'delete' ? self::CODE_OPTION_DELETED : self::CODE_OPTION_BLOCKED,
			$message
		);
	}

	/**
	 * Write one activity-log row, if Free is the writer.
	 *
	 * Gated on `albert/logging/enabled` exactly like {@see \Albert\Logging\ObservabilityHandler}:
	 * Premium returns false there and owns the table itself, and it hears every
	 * one of these through the actions above regardless.
	 *
	 * @param string $ability_name Ability id, or a resource identifier for the guard.
	 * @param int    $user_id      Who the row is attributed to.
	 * @param string $status       An {@see Outcome} status.
	 * @param string $code         The event code.
	 * @param string $message      Human-readable detail.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private static function record( string $ability_name, int $user_id, string $status, string $code, string $message ): void {
		try {
			if ( ! apply_filters( 'albert/logging/enabled', true ) ) {
				return;
			}

			( new LogRepository() )->insert(
				$ability_name,
				$user_id,
				$status,
				$code,
				[ 'error_message' => $message ]
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Recording that something happened must never break anything else.
		}
	}
}
