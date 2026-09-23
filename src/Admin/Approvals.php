<?php
/**
 * Safe-mode approvals screen.
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.5.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\SafeMode\ApprovalUrl;
use Albert\SafeMode\Approver;
use Albert\SafeMode\PendingAction;
use Albert\SafeMode\Repository;
use Albert\SafeMode\TargetResolver;

/**
 * The wp-admin queue where a person approves or rejects the destructive actions
 * safe mode has held.
 *
 * This is the always-present approval channel — the deep link an assistant is
 * handed leads here. Approving runs the staged call server-side, as the user it
 * was going to run as, with the input captured at stage time; the model is never
 * in the loop. Every state-changing action is capability-checked and nonce-
 * protected, so the deep link merely navigates here and grants nothing on its
 * own.
 *
 * @since 1.5.0
 */
class Approvals implements Hookable {

	/**
	 * Capability required to view and decide approvals.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * The admin-post action name for approving.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const ACTION_APPROVE = 'albert_approve_action';

	/**
	 * The admin-post action name for rejecting.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const ACTION_REJECT = 'albert_reject_action';

	/**
	 * Wire the screen to its store and executor.
	 *
	 * @param Repository          $repository The pending-actions store.
	 * @param Approver            $approver   Runs an approved action or records a rejection.
	 * @param TargetResolver|null $targets    Describes the affected object; defaults to a fresh one.
	 */
	public function __construct(
		private Repository $repository,
		private Approver $approver,
		private ?TargetResolver $targets = null
	) {
		$this->targets = $targets ?? new TargetResolver();
	}

	/**
	 * Register the screen and its handlers.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], Menu::POSITION_APPROVALS );
		add_action( 'admin_post_' . self::ACTION_APPROVE, [ $this, 'handle_approve' ] );
		add_action( 'admin_post_' . self::ACTION_REJECT, [ $this, 'handle_reject' ] );
	}

	/**
	 * Add the Approvals submenu, badging it with the open count.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function add_menu_page(): void {
		$open  = $this->repository->count_open();
		$title = __( 'Approvals', 'albert-ai-butler' );

		if ( $open > 0 ) {
			$title .= ' <span class="awaiting-mod count-' . $open . '"><span class="pending-count">'
				. esc_html( (string) number_format_i18n( $open ) ) . '</span></span>';
		}

		add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Approvals', 'albert-ai-butler' ),
			$title,
			self::CAPABILITY,
			ApprovalUrl::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Approve a staged action and run it.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function handle_approve(): void {
		$action = $this->authorize_decision( self::ACTION_APPROVE );

		$result = $this->approver->approve( $action, get_current_user_id() );

		// A lost race (another tab/retry got there first) is not a failure and did
		// not run anything: show the "no longer waiting" notice, not an error.
		if ( is_wp_error( $result ) && $result->get_error_code() === 'albert_already_handled' ) {
			$this->redirect_with_notice( 'gone' );
		}

		$this->redirect_with_notice( is_wp_error( $result ) ? 'failed' : 'approved' );
	}

	/**
	 * Reject a staged action.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function handle_reject(): void {
		$action = $this->authorize_decision( self::ACTION_REJECT );

		$rejected = $this->approver->reject( $action, get_current_user_id() );

		$this->redirect_with_notice( $rejected ? 'rejected' : 'gone' );
	}

	/**
	 * Render the queue.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$open    = $this->repository->list_open();
		$decided = $this->repository->list_decided();
		$focus   = isset( $_GET['pending'] ) ? sanitize_text_field( wp_unslash( $_GET['pending'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only focus hint, no state change.

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Approvals', 'albert-ai-butler' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Destructive actions your assistant requested are held here until you approve them. Approving runs the request exactly as it was made; rejecting discards it.', 'albert-ai-butler' ) . '</p>';

		$this->render_notice();

		if ( $focus !== '' && ! $this->has_open_action( $open, $focus ) ) {
			echo '<div class="notice notice-info"><p>'
				. esc_html__( 'That request is no longer waiting for a decision. It may have already been approved, rejected, or expired.', 'albert-ai-butler' )
				. '</p></div>';
		}

		if ( empty( $open ) ) {
			echo '<p>' . esc_html__( 'Nothing is waiting for approval.', 'albert-ai-butler' ) . '</p>';
		} else {
			echo '<table class="widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Requested action', 'albert-ai-butler' ) . '</th>';
			echo '<th>' . esc_html__( 'Requested by', 'albert-ai-butler' ) . '</th>';
			echo '<th>' . esc_html__( 'When', 'albert-ai-butler' ) . '</th>';
			echo '<th>' . esc_html__( 'Decision', 'albert-ai-butler' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $open as $action ) {
				$this->render_open_row( $action, $action->action_id === $focus );
			}

			echo '</tbody></table>';
		}

		$this->render_decided( $decided );

		echo '</div>';
	}

	/**
	 * Render one open action row with its approve/reject controls.
	 *
	 * @param PendingAction $action    The staged action.
	 * @param bool          $is_focus  Whether this row is the deep-link target.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_open_row( PendingAction $action, bool $is_focus ): void {
		$style = $is_focus ? ' style="outline:2px solid #2271b1;"' : '';

		echo '<tr id="albert-pending-' . esc_attr( $action->action_id ) . '"' . $style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $style is a fixed literal.

		echo '<td>';
		echo '<strong>' . esc_html( $action->ability_name ) . '</strong>';
		$this->render_target( $action );
		echo '<details><summary>' . esc_html__( 'View the exact request', 'albert-ai-butler' ) . '</summary>';
		echo '<pre style="white-space:pre-wrap;word-break:break-word;">' . esc_html( (string) wp_json_encode( $action->input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		echo '</details>';
		echo '</td>';

		echo '<td>' . esc_html( $this->requester_label( $action ) ) . '</td>';
		echo '<td>' . esc_html( $this->when_label( $action->created_at ) ) . '</td>';

		echo '<td>';
		$this->render_decision_form( self::ACTION_APPROVE, $action->action_id, __( 'Approve', 'albert-ai-butler' ), 'button button-primary' );
		echo ' ';
		$this->render_decision_form( self::ACTION_REJECT, $action->action_id, __( 'Reject', 'albert-ai-butler' ), 'button' );
		echo '</td>';

		echo '</tr>';
	}

	/**
	 * Show what the call actually affects, resolved fresh, with a drift warning.
	 *
	 * `delete-post {id:47}` is opaque; this resolves the object now so a reviewer
	 * approves against what exists, not the request text. When the object has been
	 * edited since the call was staged, it warns that approving will overwrite
	 * those edits — the one thing that turns an approval-on-trust back into a
	 * decision.
	 *
	 * @param PendingAction $action The staged action.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_target( PendingAction $action ): void {
		$current = $this->targets->describe( $action->ability_name, $action->input );
		$staged  = $action->target;

		if ( $current === null && $staged === null ) {
			return;
		}

		if ( $current === null ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: label of the object as captured when the call was staged. */
					__( 'Affects: %s (no longer exists)', 'albert-ai-butler' ),
					(string) ( $staged['label'] ?? '' )
				)
			) . '</p>';

			return;
		}

		$affected = sprintf( '%s: %s', ucfirst( (string) $current['type'] ), (string) $current['label'] );

		if ( (string) $current['status'] !== '' ) {
			$affected .= sprintf( ' (%s)', (string) $current['status'] );
		}

		echo '<p class="description">' . esc_html__( 'Affects', 'albert-ai-butler' ) . ': ' . esc_html( $affected ) . '</p>';

		if ( $this->targets->has_drifted( $staged, $current ) ) {
			echo '<p class="albert-approvals__drift" style="color:#b32d2e;"><strong>'
				. esc_html__( 'This has changed since it was requested. Approving will overwrite edits made in the meantime.', 'albert-ai-butler' )
				. '</strong></p>';
		}
	}

	/**
	 * Render the recently-decided list, if any.
	 *
	 * @param list<PendingAction> $decided Recently decided actions.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_decided( array $decided ): void {
		if ( empty( $decided ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Recently decided', 'albert-ai-butler' ) . '</h2>';
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Requested action', 'albert-ai-butler' ) . '</th>';
		echo '<th>' . esc_html__( 'Outcome', 'albert-ai-butler' ) . '</th>';
		echo '<th>' . esc_html__( 'Decided by', 'albert-ai-butler' ) . '</th>';
		echo '<th>' . esc_html__( 'When', 'albert-ai-butler' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $decided as $action ) {
			echo '<tr>';
			echo '<td>' . esc_html( $action->ability_name ) . '</td>';
			echo '<td>' . esc_html( $this->outcome_label( $action->status ) ) . '</td>';
			echo '<td>' . esc_html( $action->decided_by !== null ? $this->user_label( $action->decided_by ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $action->decided_at !== null ? $this->when_label( $action->decided_at ) : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render a single-button decision form posting to admin-post.php.
	 *
	 * @param string $action    The admin-post action name.
	 * @param string $action_id The staged action's public reference.
	 * @param string $label     The button label.
	 * @param string $classes   Button CSS classes.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_decision_form( string $action, string $action_id, string $label, string $classes ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="action_id" value="' . esc_attr( $action_id ) . '">';
		wp_nonce_field( $action . '_' . $action_id );
		echo '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Verify a decision request and return its still-open action, or stop.
	 *
	 * @param string $action The admin-post action name being handled.
	 *
	 * @return PendingAction
	 * @since 1.5.0
	 */
	private function authorize_decision( string $action ): PendingAction {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to decide approvals.', 'albert-ai-butler' ), '', [ 'response' => 403 ] );
		}

		$action_id = isset( $_POST['action_id'] ) ? sanitize_text_field( wp_unslash( $_POST['action_id'] ) ) : '';

		check_admin_referer( $action . '_' . $action_id );

		$pending = $action_id !== '' ? $this->repository->find_open( $action_id ) : null;

		if ( ! $pending instanceof PendingAction ) {
			$this->redirect_with_notice( 'gone' );
		}

		return $pending;
	}

	/**
	 * Redirect back to the queue with a result notice, then stop.
	 *
	 * @param string $outcome One of approved|failed|rejected|gone.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function redirect_with_notice( string $outcome ): void {
		wp_safe_redirect( add_query_arg( 'albert_notice', $outcome, ApprovalUrl::queue() ) );
		exit;
	}

	/**
	 * Render the result notice from the redirect, if present.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, cosmetic notice keyed to a fixed vocabulary.
		$notice = isset( $_GET['albert_notice'] ) ? sanitize_key( wp_unslash( $_GET['albert_notice'] ) ) : '';

		$messages = [
			'approved' => [ 'success', __( 'Approved. The request was run.', 'albert-ai-butler' ) ],
			'failed'   => [ 'error', __( 'Approved, but the request could not be completed. See Recently decided for details.', 'albert-ai-butler' ) ],
			'rejected' => [ 'success', __( 'Rejected. The request was discarded and never ran.', 'albert-ai-butler' ) ],
			'gone'     => [ 'info', __( 'That request was no longer waiting for a decision.', 'albert-ai-butler' ) ],
		];

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		[ $level, $text ] = $messages[ $notice ];

		echo '<div class="notice notice-' . esc_attr( $level ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Whether a given action id is present among the open actions.
	 *
	 * @param list<PendingAction> $open      Open actions.
	 * @param string              $action_id The id to look for.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	private function has_open_action( array $open, string $action_id ): bool {
		foreach ( $open as $action ) {
			if ( $action->action_id === $action_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A human label for who requested an action: the acting user and, when known,
	 * the connecting client.
	 *
	 * @param PendingAction $action The staged action.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private function requester_label( PendingAction $action ): string {
		$user = $this->user_label( $action->user_id );

		if ( $action->client_name !== null && $action->client_name !== '' ) {
			/* translators: 1: WordPress user, 2: connecting client name. */
			return sprintf( __( '%1$s via %2$s', 'albert-ai-butler' ), $user, $action->client_name );
		}

		return $user;
	}

	/**
	 * A display name for a user id, falling back to the id.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private function user_label( int $user_id ): string {
		$user = get_userdata( $user_id );

		if ( $user === false ) {
			/* translators: %d: user id. */
			return sprintf( __( 'User #%d', 'albert-ai-butler' ), $user_id );
		}

		return $user->display_name;
	}

	/**
	 * A relative "x ago" label for a stored UTC datetime.
	 *
	 * @param string $mysql_datetime UTC MySQL datetime.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private function when_label( string $mysql_datetime ): string {
		$timestamp = strtotime( $mysql_datetime . ' UTC' );

		if ( $timestamp === false ) {
			return $mysql_datetime;
		}

		/* translators: %s: human time difference, e.g. "5 mins". */
		return sprintf( __( '%s ago', 'albert-ai-butler' ), human_time_diff( $timestamp ) );
	}

	/**
	 * A human label for a decided action's status.
	 *
	 * @param string $status One of the PendingAction STATUS_* values.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private function outcome_label( string $status ): string {
		switch ( $status ) {
			case PendingAction::STATUS_EXECUTING:
				return __( 'Approved, running', 'albert-ai-butler' );
			case PendingAction::STATUS_EXECUTED:
				return __( 'Approved and run', 'albert-ai-butler' );
			case PendingAction::STATUS_FAILED:
				return __( 'Approved, but failed', 'albert-ai-butler' );
			case PendingAction::STATUS_REJECTED:
				return __( 'Rejected', 'albert-ai-butler' );
			case PendingAction::STATUS_EXPIRED:
				return __( 'Expired', 'albert-ai-butler' );
			default:
				return $status;
		}
	}
}
