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
use Albert\SafeMode\ApprovalPolicy;
use Albert\SafeMode\ApprovalUrl;
use Albert\SafeMode\Approver;
use Albert\SafeMode\InputPresenter;
use Albert\SafeMode\PendingAction;
use Albert\SafeMode\Repository;
use Albert\SafeMode\TargetResolver;
use Albert\Support\WpCompat;

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
 * A pending action is a decision, not a row to skim: each opens in its own
 * confirmation dialog that states in plain language what will happen, resolved
 * against what exists now, before either button is reachable.
 *
 * @since 1.5.0
 */
class Approvals implements Hookable {

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
	 * @param ApprovalPolicy|null $policy     Decides who may approve or reject; defaults to a fresh one.
	 */
	public function __construct(
		private Repository $repository,
		private Approver $approver,
		private ?TargetResolver $targets = null,
		private ?ApprovalPolicy $policy = null
	) {
		$this->targets = $targets ?? new TargetResolver();
		$this->policy  = $policy ?? new ApprovalPolicy();
	}

	/**
	 * Register the screen and its handlers.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], Menu::POSITION_APPROVALS );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
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
		$user_id = get_current_user_id();
		$open    = $this->policy->decides_everything( $user_id )
			? $this->repository->count_open()
			: $this->repository->count_open_for_user( $user_id );
		$title   = __( 'Approvals', 'albert-ai-butler' );

		if ( $open > 0 ) {
			$title .= ' <span class="awaiting-mod count-' . $open . '"><span class="pending-count">'
				. esc_html( (string) number_format_i18n( $open ) ) . '</span></span>';
		}

		add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Approvals', 'albert-ai-butler' ),
			$title,
			$this->menu_capability(),
			ApprovalUrl::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * The capability that gates the submenu and page load.
	 *
	 * The coarse "can open the screen" gate, matching `ApprovalPolicy::can_view()`.
	 * Per-row authority is decided separately, so this only controls who reaches
	 * the queue, never what they can do to a row once there.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private function menu_capability(): string {
		/** This documents the same filter `ApprovalPolicy::can_view()` consults. */
		$capability = apply_filters( 'albert/approvals/view_capability', ApprovalPolicy::DEFAULT_VIEW_CAPABILITY );

		return is_string( $capability ) && $capability !== '' ? $capability : ApprovalPolicy::DEFAULT_VIEW_CAPABILITY;
	}

	/**
	 * Load the screen's own stylesheet and dialog script, on this screen only.
	 *
	 * Mirrors the other server-rendered Albert screens: the primitives handle is
	 * declared as a dependency so the shared token and component layer loads
	 * first, and this file's authored rules load after it.
	 *
	 * @param string $hook The current admin page's hook suffix.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function enqueue_assets( string $hook ): void {
		if ( Menu::PARENT_SLUG . '_page_' . ApprovalUrl::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'albert-approvals',
			ALBERT_PLUGIN_URL . 'assets/css/admin-approvals.css',
			[ Assets::PRIMITIVES_HANDLE ],
			Assets::version( 'assets/css/admin-approvals.css' )
		);

		wp_enqueue_script(
			'albert-approvals',
			ALBERT_PLUGIN_URL . 'assets/js/admin-approvals.js',
			[],
			Assets::version( 'assets/js/admin-approvals.js' ),
			true
		);
	}

	/**
	 * Approve a staged action and run it.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function handle_approve(): void {
		$action = $this->authorize_decision();

		$result = $this->approver->approve( $action, get_current_user_id() );

		// A lost race (another tab/retry got there first) is not a failure and did
		// not run anything: show the "no longer waiting" notice, not an error.
		if ( is_wp_error( $result ) && $result->get_error_code() === 'albert_already_handled' ) {
			$this->redirect_with_notice( 'gone' );
		}

		$this->redirect_with_notice( is_wp_error( $result ) ? 'failed' : 'approved', $action->action_id );
	}

	/**
	 * Reject a staged action.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function handle_reject(): void {
		$action = $this->authorize_decision();

		$rejected = $this->approver->reject( $action, get_current_user_id() );

		if ( ! $rejected ) {
			$this->redirect_with_notice( 'gone' );
		}

		$this->redirect_with_notice( 'rejected', $action->action_id );
	}

	/**
	 * Render the queue.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function render_page(): void {
		if ( ! $this->policy->can_view() ) {
			// Not a bare return: that renders a blank admin page, which reads as
			// a broken screen rather than a refusal. Unreachable while the menu
			// uses the same check, and worth keeping honest for the day they drift.
			wp_die( esc_html__( 'You are not allowed to view approvals.', 'albert-ai-butler' ), '', [ 'response' => 403 ] );
		}

		$user_id = get_current_user_id();
		$all     = $this->policy->decides_everything( $user_id );
		$open    = $all ? $this->repository->list_open() : $this->repository->list_open_for_user( $user_id );
		$decided = $all ? $this->repository->list_decided() : $this->repository->list_decided_for_user( $user_id );
		$focus   = isset( $_GET['pending'] ) ? sanitize_text_field( wp_unslash( $_GET['pending'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only focus hint, no state change.
		$just    = $this->just_decided( $decided );

		echo '<div class="wrap albert-approvals">';
		echo '<div class="albert-page albert-approvals__page">';

		echo '<div class="albert-page__header"><div class="albert-page__text">';
		echo '<h1 class="albert-page__title">' . esc_html__( 'Approvals', 'albert-ai-butler' ) . '</h1>';
		echo '<p class="albert-page__description">' . esc_html__( 'Destructive actions your assistant requested are held here until you approve them. Approving runs the request exactly as it was made; rejecting discards it.', 'albert-ai-butler' ) . '</p>';
		echo '</div></div>';

		$this->render_notice( $just );
		$this->render_unenforceable_notice();

		if ( $focus !== '' && ! $this->has_open_action( $open, $focus ) ) {
			echo '<div class="notice notice-info"><p>'
				. esc_html__( 'That request is no longer waiting for a decision. It may have already been approved, rejected, or expired.', 'albert-ai-butler' )
				. '</p></div>';
		}

		echo '<div class="albert-page__body">';

		$this->render_open( $open, $focus );
		$this->render_decided( $decided, $just );

		echo '</div>'; // .albert-page__body

		echo '</div>'; // .albert-page
		echo '</div>'; // .wrap
	}

	/**
	 * Render the "waiting for approval" card: a list of held actions, or the
	 * empty state.
	 *
	 * @param list<PendingAction> $open  Open actions.
	 * @param string              $focus The deep-linked action id, if any.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_open( array $open, string $focus ): void {
		echo '<div class="albert-card albert-approvals__queue">';
		echo '<div class="albert-card__header"><div class="albert-card__text">';
		echo '<h2 class="albert-card__title">' . esc_html__( 'Waiting for approval', 'albert-ai-butler' ) . '</h2>';
		echo '</div>';

		if ( ! empty( $open ) ) {
			echo '<span class="albert-badge">' . esc_html( (string) number_format_i18n( count( $open ) ) ) . '</span>';
		}

		echo '</div>'; // .albert-card__header

		if ( empty( $open ) ) {
			echo '<div class="albert-empty-state">';
			echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>';
			echo '<p>' . esc_html__( 'Nothing is waiting for approval.', 'albert-ai-butler' ) . '</p>';
			echo '</div>';
			echo '</div>'; // .albert-card

			return;
		}

		echo '<div class="albert-card__body albert-card__body--flush">';
		echo '<ul class="albert-approvals__list">';

		foreach ( $open as $action ) {
			$this->render_open_row( $action, $action->action_id === $focus );
		}

		echo '</ul>';
		echo '</div>'; // .albert-card__body
		echo '</div>'; // .albert-card
	}

	/**
	 * Render one open action as a list row plus its confirmation dialog.
	 *
	 * @param PendingAction $action   The staged action.
	 * @param bool          $is_focus Whether this row is the deep-link target.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_open_row( PendingAction $action, bool $is_focus ): void {
		$state     = $this->target_state( $action );
		$dialog_id = 'albert-approve-dialog-' . $action->action_id;
		$classes   = 'albert-approvals__item' . ( $is_focus ? ' albert-approvals__item--focus' : '' );

		echo '<li class="' . esc_attr( $classes ) . '" id="albert-pending-' . esc_attr( $action->action_id ) . '">';

		echo '<div class="albert-approvals__item-text">';
		echo '<p class="albert-approvals__item-title">' . esc_html( $this->consequence_line( $action, $state ) );
		$this->render_state_badge( $state, $is_focus );
		echo '</p>';
		echo '<p class="albert-approvals__item-meta">';
		echo '<code class="albert-approvals__id">' . esc_html( $action->ability_name ) . '</code> ';
		echo esc_html(
			sprintf(
				/* translators: 1: who requested the action, 2: how long ago. */
				__( '%1$s, %2$s', 'albert-ai-butler' ),
				$this->requester_label( $action ),
				$this->when_label( $action->created_at )
			)
		);
		echo '</p>';
		echo '</div>'; // .albert-approvals__item-text

		echo '<div class="albert-approvals__item-action">';
		echo '<button type="button" class="button button-primary" data-albert-review data-albert-dialog="' . esc_attr( $dialog_id ) . '" aria-haspopup="dialog">'
			. esc_html__( 'Review…', 'albert-ai-butler' )
			. '</button>';
		echo '</div>';

		$this->render_decision_dialog( $action, $state, $dialog_id );

		echo '</li>';
	}

	/**
	 * Render the confirmation dialog for one action.
	 *
	 * A single POST form carries both choices: the submitted button's own name
	 * routes admin-post.php to approve or reject, so one nonce covers the pair.
	 * The form has no text inputs, so the browser's default focus lands on the
	 * close button rather than on a decision — pressing Enter dismisses, it never
	 * decides.
	 *
	 * @param PendingAction        $action    The staged action.
	 * @param array<string, mixed> $state     Resolved target state from target_state().
	 * @param string               $dialog_id The dialog element id.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_decision_dialog( PendingAction $action, array $state, string $dialog_id ): void {
		$title_id       = $dialog_id . '-title';
		$consequence_id = $dialog_id . '-consequence';

		// Evaluated for the viewer, now. One rule decides the whole row: a viewer
		// who cannot decide it (not theirs, capability changed since staging, or
		// the ability is gone) gets no action at all, only the reason. Such a row
		// clears when it expires, or when an administrator decides it.
		$blocked_reason = $this->policy->decide_blocked_reason( $action );

		echo '<dialog id="' . esc_attr( $dialog_id ) . '" class="albert-dialog albert-approvals__dialog" aria-labelledby="' . esc_attr( $title_id ) . '" aria-describedby="' . esc_attr( $consequence_id ) . '">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="albert-approvals__decide">';
		wp_nonce_field( 'albert_decide_' . $action->action_id );
		echo '<input type="hidden" name="action_id" value="' . esc_attr( $action->action_id ) . '">';

		echo '<div class="albert-dialog__header"><div class="albert-dialog__heading">';
		echo '<h2 class="albert-dialog__title" id="' . esc_attr( $title_id ) . '">' . esc_html__( 'Approve this action?', 'albert-ai-butler' ) . '</h2>';
		echo '</div>';
		echo '<button type="button" class="albert-dialog__close" data-albert-dialog-close aria-label="' . esc_attr__( 'Close', 'albert-ai-butler' ) . '">';
		echo '<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>';
		echo '</button>';
		echo '</div>'; // .albert-dialog__header

		echo '<div class="albert-dialog__body">';

		echo '<p class="albert-approvals__consequence" id="' . esc_attr( $consequence_id ) . '"><strong>' . esc_html( $this->consequence_line( $action, $state ) ) . '</strong></p>';

		if ( $state['gone'] ) {
			$this->render_hint(
				'warning',
				'dashicons-warning',
				__( 'The thing this would affect no longer exists. Approving may do nothing, or fail.', 'albert-ai-butler' )
			);
		} elseif ( $state['drifted'] ) {
			$this->render_hint(
				'warning',
				'dashicons-warning',
				__( 'This has changed since it was requested. Approving will overwrite edits made in the meantime.', 'albert-ai-butler' )
			);
		}

		if ( $blocked_reason !== null ) {
			$this->render_hint( 'warning', 'dashicons-lock', $blocked_reason );
		}

		echo '<p class="albert-approvals__provenance">';
		echo esc_html(
			sprintf(
				/* translators: 1: who requested the action, 2: how long ago. */
				__( 'Requested by %1$s, %2$s.', 'albert-ai-butler' ),
				$this->requester_label( $action ),
				$this->when_label( $action->created_at )
			)
		);
		echo '</p>';

		echo '<details class="albert-preview albert-approvals__request">';
		echo '<summary>' . esc_html__( 'View the exact request', 'albert-ai-butler' ) . '</summary>';
		echo '<div class="albert-preview__body" tabindex="0">' . esc_html( (string) wp_json_encode( InputPresenter::for_display( $action->input, $action->ability_name ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</div>';
		echo '</details>';

		echo '</div>'; // .albert-dialog__body

		echo '<div class="albert-dialog__footer">';

		if ( $blocked_reason === null ) {
			echo '<button type="submit" name="action" value="' . esc_attr( self::ACTION_REJECT ) . '" class="button">' . esc_html__( 'Reject', 'albert-ai-butler' ) . '</button>';
			echo '<button type="submit" name="action" value="' . esc_attr( self::ACTION_APPROVE ) . '" class="button button-primary">' . esc_html__( 'Approve and run', 'albert-ai-butler' ) . '</button>';
		} else {
			// No action for a viewer who cannot decide the row: the reason above
			// stands in for the buttons, and Close is the only move.
			echo '<button type="button" class="button" data-albert-dialog-close>' . esc_html__( 'Close', 'albert-ai-butler' ) . '</button>';
		}

		echo '</div>';

		echo '</form>';
		echo '</dialog>';
	}

	/**
	 * Resolve, fresh, what a staged call affects and whether it is safe to trust
	 * the request text.
	 *
	 * `delete-post {id:47}` is opaque; this resolves the object now so a reviewer
	 * decides against what exists, not against the request. `drifted` means the
	 * object was edited since staging (approving overwrites those edits); `gone`
	 * means it no longer exists.
	 *
	 * @param PendingAction $action The staged action.
	 *
	 * @return array{object: ?string, gone: bool, drifted: bool}
	 * @since 1.5.0
	 */
	private function target_state( PendingAction $action ): array {
		$current = $this->targets->describe( $action->ability_name, $action->input );
		$staged  = $action->target;

		if ( $current === null && $staged === null ) {
			return [
				'object'  => null,
				'gone'    => false,
				'drifted' => false,
			];
		}

		if ( $current === null ) {
			return [
				'object'  => (string) ( $staged['label'] ?? '' ),
				'gone'    => true,
				'drifted' => false,
			];
		}

		$object = (string) $current['label'];

		if ( (string) $current['status'] !== '' ) {
			$object .= sprintf( ' (%s)', (string) $current['status'] );
		}

		return [
			'object'  => $object,
			'gone'    => false,
			'drifted' => $this->targets->has_drifted( $staged, $current ),
		];
	}

	/**
	 * A plain-language line for what a call does, leading with the effect and the
	 * object rather than the ability id.
	 *
	 * @param PendingAction        $action The staged action.
	 * @param array<string, mixed> $state  Resolved target state from target_state().
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private function consequence_line( PendingAction $action, array $state ): string {
		$label = $this->ability_label( $action->ability_name );

		if ( $state['object'] !== null && $state['object'] !== '' ) {
			return sprintf(
				/* translators: 1: the action, e.g. "Delete a page"; 2: the affected object, e.g. "About us (published)". */
				__( '%1$s: %2$s', 'albert-ai-butler' ),
				$label,
				(string) $state['object']
			);
		}

		return $label;
	}

	/**
	 * The registered, human label for an ability, falling back to its id.
	 *
	 * @param string $ability_name The ability id.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private function ability_label( string $ability_name ): string {
		if ( function_exists( 'wp_get_ability' ) ) {
			$ability = wp_get_ability( $ability_name );

			if ( $ability !== null ) {
				$label = (string) $ability->get_label();

				if ( $label !== '' ) {
					return $label;
				}
			}
		}

		return $ability_name;
	}

	/**
	 * Render the status badge shown on a queue row: drift, a missing target, or
	 * the deep-link marker. Each carries its own text, so none is signalled by
	 * colour alone.
	 *
	 * @param array<string, mixed> $state    Resolved target state from target_state().
	 * @param bool                 $is_focus Whether this row is the deep-link target.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_state_badge( array $state, bool $is_focus ): void {
		if ( $is_focus ) {
			echo ' <span class="albert-badge albert-badge--info">' . esc_html__( 'From your assistant’s link', 'albert-ai-butler' ) . '</span>';
		}

		if ( $state['gone'] ) {
			echo ' <span class="albert-badge albert-badge--danger">' . esc_html__( 'No longer exists', 'albert-ai-butler' ) . '</span>';
		} elseif ( $state['drifted'] ) {
			echo ' <span class="albert-badge albert-badge--warning">' . esc_html__( 'Changed since requested', 'albert-ai-butler' ) . '</span>';
		}
	}

	/**
	 * Render the recently-decided list, if any.
	 *
	 * A stacked list, not a table: this screen is reached from a deep link an
	 * assistant hands over, so it is opened on a phone as often as a desktop, and
	 * a four-column table has nowhere to go at 360px.
	 *
	 * @param list<PendingAction> $decided Recently decided actions.
	 * @param PendingAction|null  $just    The action decided just now, highlighted.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_decided( array $decided, ?PendingAction $just = null ): void {
		if ( empty( $decided ) ) {
			return;
		}

		echo '<div class="albert-card albert-approvals__decided">';
		echo '<div class="albert-card__header"><div class="albert-card__text">';
		echo '<h2 class="albert-card__title">' . esc_html__( 'Recently decided', 'albert-ai-butler' ) . '</h2>';
		echo '</div></div>';

		echo '<div class="albert-card__body albert-card__body--flush">';
		echo '<ul class="albert-approvals__list albert-approvals__list--decided">';

		foreach ( $decided as $action ) {
			$this->render_decided_row( $action, $just !== null && $just->action_id === $action->action_id );
		}

		echo '</ul>';
		echo '</div>'; // .albert-card__body
		echo '</div>'; // .albert-card
	}

	/**
	 * Render one recently-decided item, with the reason a run failed.
	 *
	 * The reason is read only for a failed item, and only its code and message
	 * (`Approver` stores an error shape, never a payload). A successful run's
	 * result is never rendered — it can hold a credential the caller was handed.
	 *
	 * @param PendingAction $action  The decided action.
	 * @param bool          $is_just Whether it was decided just now.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_decided_row( PendingAction $action, bool $is_just = false ): void {
		[ $tone, $label ] = $this->status_badge( $action->status );
		$who              = $action->decided_by !== null ? $this->user_label( $action->decided_by ) : '';
		$classes          = 'albert-approvals__decided-item' . ( $is_just ? ' albert-approvals__decided-item--just' : '' );

		echo '<li class="' . esc_attr( $classes ) . '">';

		echo '<p class="albert-approvals__item-title">';
		echo esc_html( $this->decided_line( $action ) );
		echo ' <span class="albert-badge' . ( $tone !== '' ? ' albert-badge--' . esc_attr( $tone ) : '' ) . '">' . esc_html( $label ) . '</span>';
		echo '</p>';

		$this->render_failure_reason( $action );

		echo '<p class="albert-approvals__item-meta">';
		echo '<code class="albert-approvals__id">' . esc_html( $action->ability_name ) . '</code> ';

		if ( $who !== '' ) {
			echo esc_html(
				sprintf(
					/* translators: %s: the user who decided the action. */
					__( 'Decided by %s,', 'albert-ai-butler' ),
					$who
				)
			) . ' ';
		}

		echo $this->time_tag( $action->decided_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- time_tag escapes its own output.
		echo '</p>';

		echo '</li>';
	}

	/**
	 * Render why a run failed, from the stored error shape, for a failed row only.
	 *
	 * @param PendingAction $action The decided action.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_failure_reason( PendingAction $action ): void {
		if ( $action->status !== PendingAction::STATUS_FAILED ) {
			return;
		}

		$result  = is_array( $action->result ) ? $action->result : [];
		$message = isset( $result['message'] ) ? (string) $result['message'] : '';
		$code    = isset( $result['code'] ) ? (string) $result['code'] : '';

		if ( $message === '' && $code === '' ) {
			return;
		}

		echo '<p class="albert-approvals__reason">';
		echo esc_html( $message !== '' ? $message : $code );

		if ( $message !== '' && $code !== '' ) {
			echo ' <code class="albert-approvals__id">' . esc_html( $code ) . '</code>';
		}

		echo '</p>';
	}

	/**
	 * Verify a decision request and return its still-open action, or stop.
	 *
	 * Both buttons post one form carrying a single per-action nonce, and one rule
	 * gates both: a viewer who cannot decide the row can neither approve nor
	 * reject it. The check runs against the person clicking, evaluated now, so a
	 * row whose decider lost the right since the page loaded is caught here rather
	 * than run.
	 *
	 * @return PendingAction
	 * @since 1.5.0
	 */
	private function authorize_decision(): PendingAction {
		if ( ! $this->policy->can_view() ) {
			wp_die( esc_html__( 'You are not allowed to decide approvals.', 'albert-ai-butler' ), '', [ 'response' => 403 ] );
		}

		$action_id = isset( $_POST['action_id'] ) ? sanitize_text_field( wp_unslash( $_POST['action_id'] ) ) : '';

		check_admin_referer( 'albert_decide_' . $action_id );

		$pending = $action_id !== '' ? $this->repository->find_open( $action_id ) : null;

		if ( ! $pending instanceof PendingAction ) {
			$this->redirect_with_notice( 'gone' );
		}

		if ( ! $this->policy->can_decide( $pending ) ) {
			$this->redirect_with_notice( 'blocked' );
		}

		return $pending;
	}

	/**
	 * Redirect back to the queue with a result notice, then stop.
	 *
	 * @param string $outcome   One of approved|failed|rejected|gone|blocked.
	 * @param string $action_id The action just decided, so the notice can name it.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function redirect_with_notice( string $outcome, string $action_id = '' ): void {
		$args = [ 'albert_notice' => $outcome ];

		if ( $action_id !== '' ) {
			$args['decided'] = $action_id;
		}

		wp_safe_redirect( add_query_arg( $args, ApprovalUrl::queue() ) );
		exit;
	}

	/**
	 * The action the redirect says was just decided, if this viewer can see it.
	 *
	 * Looked up in the viewer's own decided list, never by id alone, so a crafted
	 * `decided` value cannot surface another person's action.
	 *
	 * @param list<PendingAction> $decided The viewer's recently decided actions.
	 *
	 * @return PendingAction|null
	 * @since 1.5.0
	 */
	private function just_decided( array $decided ): ?PendingAction {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only highlight hint, no state change.
		$action_id = isset( $_GET['decided'] ) ? sanitize_text_field( wp_unslash( $_GET['decided'] ) ) : '';

		if ( $action_id === '' ) {
			return null;
		}

		foreach ( $decided as $action ) {
			if ( $action->action_id === $action_id ) {
				return $action;
			}
		}

		return null;
	}

	/**
	 * Render the result notice from the redirect, if present.
	 *
	 * Names the action when it is known, so the notice says which request it is
	 * about; the Recently decided list below shows it highlighted.
	 *
	 * @param PendingAction|null $subject The action decided just now, if known.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_notice( ?PendingAction $subject = null ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, cosmetic notice keyed to a fixed vocabulary.
		$notice = isset( $_GET['albert_notice'] ) ? sanitize_key( wp_unslash( $_GET['albert_notice'] ) ) : '';
		$name   = $subject !== null ? $this->decided_line( $subject ) : '';

		if ( $name !== '' ) {
			$messages = [
				/* translators: %s: the decided request, e.g. "Delete a page: About us". */
				'approved' => [ 'success', sprintf( __( 'Approved and run: %s.', 'albert-ai-butler' ), $name ) ],
				/* translators: %s: the decided request, e.g. "Delete a page: About us". */
				'failed'   => [ 'error', sprintf( __( 'Approved, but it could not be completed: %s. See Recently decided for details.', 'albert-ai-butler' ), $name ) ],
				/* translators: %s: the decided request, e.g. "Delete a page: About us". */
				'rejected' => [ 'success', sprintf( __( 'Rejected: %s. It never ran.', 'albert-ai-butler' ), $name ) ],
			];
		} else {
			$messages = [
				'approved' => [ 'success', __( 'Approved. The request was run.', 'albert-ai-butler' ) ],
				'failed'   => [ 'error', __( 'Approved, but the request could not be completed. See Recently decided for details.', 'albert-ai-butler' ) ],
				'rejected' => [ 'success', __( 'Rejected. The request was discarded and never ran.', 'albert-ai-butler' ) ],
			];
		}

		$messages += [
			'gone'    => [ 'info', __( 'That request was no longer waiting for a decision.', 'albert-ai-butler' ) ],
			'blocked' => [ 'error', __( 'You are no longer able to decide that request. Your permission may have changed since it was requested.', 'albert-ai-butler' ) ],
		];

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		[ $level, $text ] = $messages[ $notice ];

		echo '<div class="notice notice-' . esc_attr( $level ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * A decided action's name: its ability plus the object as it was when staged.
	 *
	 * The staged label, not the current one: after an approved delete the object
	 * is gone, and naming it by its current state would read "(trash)".
	 *
	 * @param PendingAction $action The decided action.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private function decided_line( PendingAction $action ): string {
		$object = (string) ( $action->target['label'] ?? '' );

		return $this->consequence_line(
			$action,
			[
				'object'  => $object !== '' ? $object : null,
				'gone'    => false,
				'drifted' => false,
			]
		);
	}

	/**
	 * Say plainly when nothing can be held, whatever the setting says.
	 *
	 * Without this, a site below WordPress 7.1 shows "Nothing is waiting for
	 * approval" while every destructive call runs unattended. An empty queue is
	 * the most convincing false reassurance this screen can give, because it
	 * reads as proof the gate is working.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_unenforceable_notice(): void {
		if ( WpCompat::supports_execution_lifecycle() ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'Nothing can be held on this site yet.', 'albert-ai-butler' )
			. '</strong> '
			. esc_html(
				sprintf(
					/* translators: %s: the WordPress version this site is running. */
					__( 'Safe mode needs WordPress 7.1, which added the check it relies on. This site runs %s, so destructive actions an assistant requests are carried out immediately and this queue stays empty.', 'albert-ai-butler' ),
					get_bloginfo( 'version' )
				)
			)
			. ' <a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">'
			. esc_html__( 'Update WordPress', 'albert-ai-butler' )
			. '</a></p></div>';
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
	 * Render a scoped, tinted hint with a leading icon.
	 *
	 * @param string $tone One of the .albert-hint tones: info|warning.
	 * @param string $icon A dashicons-* class for the leading glyph.
	 * @param string $text The hint text.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_hint( string $tone, string $icon, string $text ): void {
		echo '<div class="albert-hint albert-hint--' . esc_attr( $tone ) . '">';
		echo '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		echo '<p>' . esc_html( $text ) . '</p>';
		echo '</div>';
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
	 * A `<time>` element for a stored UTC datetime, machine-readable and relative,
	 * or a plain dash when there is none. Escapes its own output.
	 *
	 * @param string|null $mysql_datetime UTC MySQL datetime, or null.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	private function time_tag( ?string $mysql_datetime ): string {
		if ( $mysql_datetime === null ) {
			return '—';
		}

		$timestamp = strtotime( $mysql_datetime . ' UTC' );

		if ( $timestamp === false ) {
			return esc_html( $mysql_datetime );
		}

		return '<time datetime="' . esc_attr( gmdate( 'c', $timestamp ) ) . '">' . esc_html( $this->when_label( $mysql_datetime ) ) . '</time>';
	}

	/**
	 * The badge tone and label for a decided action's status.
	 *
	 * @param string $status One of the PendingAction STATUS_* values.
	 *
	 * @return array{0: string, 1: string} Tone ('' for neutral) and label.
	 * @since 1.5.0
	 */
	private function status_badge( string $status ): array {
		switch ( $status ) {
			case PendingAction::STATUS_EXECUTING:
				return [ 'info', __( 'Approved, running', 'albert-ai-butler' ) ];
			case PendingAction::STATUS_EXECUTED:
				return [ 'success', __( 'Approved and run', 'albert-ai-butler' ) ];
			case PendingAction::STATUS_FAILED:
				return [ 'danger', __( 'Approved, but failed', 'albert-ai-butler' ) ];
			case PendingAction::STATUS_REJECTED:
				return [ '', __( 'Rejected', 'albert-ai-butler' ) ];
			case PendingAction::STATUS_EXPIRED:
				return [ 'outline', __( 'Expired', 'albert-ai-butler' ) ];
			default:
				return [ '', $status ];
		}
	}
}
