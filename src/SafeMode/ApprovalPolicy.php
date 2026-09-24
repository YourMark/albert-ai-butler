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
 * **The rule: you may approve what you could have done yourself.** An
 * administrator decides anything. Anybody else decides only their own request,
 * and may only *approve* it if the ability would have let them perform it
 * unaided. Without that, every routine deletion an editor asked for would need
 * an administrator, which is not a workflow anybody runs twice.
 *
 * Self-approval is not a hole, because the requester is a person and the
 * assistant is not. The assistant holds an OAuth token and cannot reach
 * wp-admin; the human it acts for can. So a requester approving their own row
 * is still a person deciding out of band, which is the whole mechanism. What it
 * is *not* is four-eyes review, and nothing here should be described as that.
 *
 * **Approving and rejecting are not the same permission.** Rejecting discards a
 * request and runs nothing, so it needs no capability beyond owning the row.
 * Approving runs code, so it needs the ability's own permission check to pass.
 * Collapsing the two would mean an editor whose capability changed after
 * staging could not even dismiss their own stale request, leaving rows nobody
 * is able to clear.
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
	 * Whether this user may reject this action.
	 *
	 * Owning the row is enough. Rejecting runs nothing, and somebody whose
	 * capability has changed since staging must still be able to clear their own
	 * stale request rather than leave it for an administrator.
	 *
	 * @param PendingAction $action  The staged action.
	 * @param int|null      $user_id User to test; defaults to the current user.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function can_reject( PendingAction $action, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		return $this->decides_everything( $user_id ) || $this->owns( $action, $user_id );
	}

	/**
	 * Whether this user may approve this action.
	 *
	 * An administrator may. Anybody else may only if the row is theirs and the
	 * ability's own permission check passes for them, so approval can never let
	 * somebody reach past what they could already do unaided.
	 *
	 * An ability that has since been unregistered is approvable by nobody: there
	 * is nothing left to ask. It stays rejectable, which is how such a row gets
	 * cleared.
	 *
	 * @param PendingAction $action  The staged action.
	 * @param int|null      $user_id User to test; defaults to the current user.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function can_approve( PendingAction $action, ?int $user_id = null ): bool {
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
	 * Why this user cannot approve their own row, for the screen to show.
	 *
	 * A row somebody owns but may no longer approve is shown disabled with this
	 * reason rather than hidden. Hiding it would make the queue read as empty
	 * while something is still waiting, which is the one thing this screen must
	 * never do.
	 *
	 * @param PendingAction $action  The staged action.
	 * @param int|null      $user_id User to test; defaults to the current user.
	 *
	 * @return string|null A reason, or null when the user may approve.
	 * @since 1.5.0
	 */
	public function approval_blocked_reason( PendingAction $action, ?int $user_id = null ): ?string {
		$user_id = $user_id ?? get_current_user_id();

		if ( $this->can_approve( $action, $user_id ) ) {
			return null;
		}

		if ( ! $this->owns( $action, $user_id ) ) {
			return __( 'Only an administrator, or the person who requested this, can approve it.', 'albert-ai-butler' );
		}

		if ( wp_get_ability( $action->ability_name ) === null ) {
			return __( 'The action this request needs is no longer available, so it cannot run. Reject it to clear it.', 'albert-ai-butler' );
		}

		return __( 'You no longer have permission to perform this action yourself, so you cannot approve it. An administrator can.', 'albert-ai-butler' );
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
