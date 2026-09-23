<?php
/**
 * Safe-mode approval executor.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Runs a staged action once a person approves it, or records their rejection.
 *
 * Approval re-runs the ability with the resolved input captured at stage time —
 * never input the model could resupply — and as the user the call was going to
 * run as, not the approving admin. That preserves the ability's own permission
 * check: approval says "let this proceed as it would have," it does not lend the
 * caller the approver's capabilities. The run happens under
 * {@see ExecutionBypass} so the interceptor lets it through instead of staging
 * it again.
 *
 * @since 1.5.0
 */
class Approver {

	/**
	 * Wire the approver to its store.
	 *
	 * @param Repository $repository The pending-actions store.
	 */
	public function __construct( private Repository $repository ) {
	}

	/**
	 * Approve and run a staged action, recording its outcome.
	 *
	 * @param PendingAction $action     The action to run.
	 * @param int           $decided_by The user approving it.
	 *
	 * @return array<string, mixed>|WP_Error What the ability returned.
	 * @since 1.5.0
	 */
	public function approve( PendingAction $action, int $decided_by ) {
		// Win the row before doing anything else. A concurrent second approval
		// (two tabs, a double-click, a proxy retry) loses the claim and returns
		// here without ever running the ability a second time.
		if ( ! $this->repository->claim( $action->id, $decided_by ) ) {
			return new WP_Error(
				'albert_already_handled',
				__( 'This request has already been handled.', 'albert-ai-butler' )
			);
		}

		$ability = wp_get_ability( $action->ability_name );

		if ( $ability === null ) {
			$error = new WP_Error(
				'albert_pending_ability_missing',
				/* translators: %s: ability name. */
				sprintf( __( 'The ability "%s" is no longer available, so this action cannot run.', 'albert-ai-butler' ), $action->ability_name )
			);

			$this->repository->record_outcome( $action->id, $decided_by, false, $this->error_shape( $error ) );

			return $error;
		}

		// Arm a one-shot ticket bound to this exact call, so the interceptor lets
		// this single execution through and nothing else. Cleared in a finally so
		// a ticket never outlives the run it was minted for, even on a throw.
		ApprovalTicket::arm( $action->ability_name, $action->input );

		try {
			$result = $this->run_as(
				$action->user_id,
				static fn() => $ability->execute( $action->input )
			);
		} finally {
			ApprovalTicket::clear();
		}

		if ( is_wp_error( $result ) ) {
			$this->repository->record_outcome( $action->id, $decided_by, false, $this->error_shape( $result ) );

			return $result;
		}

		$this->repository->record_outcome( $action->id, $decided_by, true, is_array( $result ) ? $result : [] );

		return is_array( $result ) ? $result : [];
	}

	/**
	 * Reject a staged action. The ability never runs.
	 *
	 * @param PendingAction $action     The action to reject.
	 * @param int           $decided_by The user rejecting it.
	 *
	 * @return bool True when a still-pending row was rejected; false if it had
	 *              already been handled.
	 * @since 1.5.0
	 */
	public function reject( PendingAction $action, int $decided_by ): bool {
		return $this->repository->reject( $action->id, $decided_by );
	}

	/**
	 * Run a callback as a given user, restoring the current user afterwards.
	 *
	 * @param int      $user_id The user to run as.
	 * @param callable $run     The callback.
	 *
	 * @return mixed
	 * @since 1.5.0
	 */
	private function run_as( int $user_id, callable $run ) {
		$previous = get_current_user_id();
		wp_set_current_user( $user_id );

		try {
			return $run();
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * The stored shape for a failed run.
	 *
	 * @param WP_Error $error The error.
	 *
	 * @return array<string, string>
	 * @since 1.5.0
	 */
	private function error_shape( WP_Error $error ): array {
		// A WP_Error code may be an int or a string; the queue stores it as a
		// string identifier either way.
		return [
			'code'    => (string) $error->get_error_code(),
			'message' => $error->get_error_message(),
		];
	}
}
