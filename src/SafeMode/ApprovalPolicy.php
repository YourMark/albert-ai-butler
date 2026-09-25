<?php
/**
 * Who may decide a held action.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

/**
 * Decides who may approve or reject a staged action.
 *
 * **The rule: you may decide what you could have done yourself.** An
 * administrator decides anything. Anybody else decides only their own request,
 * and only while the ability would still let them perform it unaided. Without
 * that, every routine deletion an editor asked for would need an
 * administrator, which is not a workflow anybody runs twice.
 *
 * Self-approval is not a hole, because the requester is a person and the
 * assistant is not. The assistant holds an OAuth token and cannot reach
 * wp-admin; the human it acts for can. So a requester approving their own row
 * is still a person deciding out of band, which is the whole mechanism. What it
 * is *not* is four-eyes review, and nothing here should be described as that.
 *
 * **One question, not two.** Approve and reject share a check: if you cannot
 * act on a row you cannot act on it. A row nobody can decide expires on its own,
 * and an administrator can clear it meanwhile.
 *
 * **Evaluated now, not at staging time.** Ownership moves, posts get published,
 * roles change. The answer that matters is whether this person may do this
 * today, so a row can stop being approvable while it sits in the queue. That is
 * correct rather than unfortunate: the alternative is honouring a permission
 * somebody no longer holds.
 *
 * @since 1.5.0
 */
class ApprovalPolicy {

	/**
	 * Capability that lets somebody decide every row, not only their own.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const CAPABILITY_ALL = 'manage_options';

	/**
	 * Default capability floor for reaching the screen at all.
	 *
	 * `edit_posts`, matching the floor Albert already uses for who may be
	 * offered as an allowed user. Deliberately not `read`: that would put an
	 * Approvals item in the menu of every subscriber on the site, all of whom
	 * would find it permanently empty.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const DEFAULT_VIEW_CAPABILITY = 'edit_posts';

	/**
	 * Whether this user decides every row rather than only their own.
	 *
	 * @param int|null $user_id User to test; defaults to the current user.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function decides_everything( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		return user_can( $user_id, self::CAPABILITY_ALL );
	}

	/**
	 * Whether this user may reach the approvals screen at all.
	 *
	 * The coarse gate, before per-row scoping. Somebody who passes this and owns
	 * nothing simply sees an empty queue, which is the right outcome: the
	 * alternative is hiding the screen from a person who has a request waiting.
	 *
	 * @param int|null $user_id User to test; defaults to the current user.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function can_view( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		if ( $this->decides_everything( $user_id ) ) {
			return true;
		}

		return user_can( $user_id, $this->view_capability() );
	}

	/**
	 * Whether this user may decide this action, either way.
	 *
	 * One check for approve and reject alike. An administrator decides any row.
	 * Anybody else decides only their own, and only while the ability's own
	 * permission check still passes for them, so a decision can never reach past
	 * what they could already do unaided.
	 *
	 * An ability that has since been unregistered is decidable by nobody but an
	 * administrator: there is nothing left to ask.
	 *
	 * @param PendingAction $action  The staged action.
	 * @param int|null      $user_id User to test; defaults to the current user.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function can_decide( PendingAction $action, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		// An administrator is not held to the requester's capabilities. The call
		// still runs as the requester, so approving something they can no longer
		// do fails at execution and is logged as a failure: the administrator's
		// say-so does not lend them anything.
		if ( $this->decides_everything( $user_id ) ) {
			return true;
		}

		if ( ! $this->owns( $action, $user_id ) ) {
			return false;
		}

		return $this->ability_permits( $action, $user_id );
	}

	/**
	 * Why this user cannot decide this row, for the screen to say so.
	 *
	 * Whether such a row is hidden or shown inert is the screen's call; this
	 * only supplies the sentence for the second case. Offered because "nothing
	 * happens when I click" is the worst of the options available.
	 *
	 * @param PendingAction $action  The staged action.
	 * @param int|null      $user_id User to test; defaults to the current user.
	 *
	 * @return string|null A reason, or null when the user may decide.
	 * @since 1.5.0
	 */
	public function decide_blocked_reason( PendingAction $action, ?int $user_id = null ): ?string {
		$user_id = $user_id ?? get_current_user_id();

		if ( $this->can_decide( $action, $user_id ) ) {
			return null;
		}

		if ( ! $this->owns( $action, $user_id ) ) {
			return __( 'Only an administrator, or the person who requested this, can decide it.', 'albert-ai-butler' );
		}

		if ( wp_get_ability( $action->ability_name ) === null ) {
			return __( 'The action this request needs is no longer available. An administrator can clear it, or it will expire on its own.', 'albert-ai-butler' );
		}

		return __( 'You no longer have permission to perform this action yourself, so you cannot decide it. An administrator can, or it will expire on its own.', 'albert-ai-butler' );
	}

	/**
	 * Whether the row belongs to this user.
	 *
	 * @param PendingAction $action  The staged action.
	 * @param int           $user_id The user.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	private function owns( PendingAction $action, int $user_id ): bool {
		return $user_id > 0 && $action->user_id === $user_id;
	}

	/**
	 * Whether the ability itself would let this user make this call.
	 *
	 * Asked of the ability rather than of a capability list, because only the
	 * ability knows: a per-object check needs the input, and "can edit posts" is
	 * not the same question as "can delete this post".
	 *
	 * The check runs as the user being tested. On the screen that is always the
	 * viewer, so the switch is skipped; it exists so the predicate stays correct
	 * for any caller, and is wrapped in a `finally` so a throwing permission
	 * callback cannot leave somebody impersonated.
	 *
	 * @param PendingAction $action  The staged action.
	 * @param int           $user_id The user.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	private function ability_permits( PendingAction $action, int $user_id ): bool {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return false;
		}

		$ability = wp_get_ability( $action->ability_name );

		if ( $ability === null ) {
			return false;
		}

		if ( $user_id === get_current_user_id() ) {
			return $ability->check_permissions( $action->input ) === true;
		}

		$previous = get_current_user_id();
		wp_set_current_user( $user_id );

		try {
			return $ability->check_permissions( $action->input ) === true;
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * The capability floor for reaching the screen.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private function view_capability(): string {
		/**
		 * Filters the capability needed to reach the approvals screen.
		 *
		 * The coarse gate only. It changes who can *open* the screen, never who
		 * can decide what: that stays ownership plus the ability's own
		 * permission check, so loosening this cannot hand anybody a decision
		 * they could not already make.
		 *
		 * @since 1.5.0
		 *
		 * @param string $capability Capability required. Default `edit_posts`.
		 */
		$capability = apply_filters( 'albert/approvals/view_capability', self::DEFAULT_VIEW_CAPABILITY );

		return is_string( $capability ) && $capability !== '' ? $capability : self::DEFAULT_VIEW_CAPABILITY;
	}
}
