<?php
/**
 * Safe-mode execution interceptor.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Execution\InterceptorDecision;
use Albert\Logging\Outcome;
use Albert\OAuth\Server\ConnectionContext;
use Albert\Settings\Value;
use WP_Ability;
use WP_Error;

/**
 * Holds a destructive assistant-initiated ability call for approval.
 *
 * Bound to WordPress 7.1's `wp_pre_execute_ability` short-circuit filter: return
 * anything other than the sentinel and the ability's normalisation, validation,
 * permission check and execute callback are all skipped, and the value is
 * returned to the caller. So staging a call and returning an "awaiting approval"
 * error is a genuine gate — the destructive callback never runs — not advisory.
 *
 * The scope is **assistant-initiated** calls: only a request carrying an Albert
 * OAuth connection ({@see ConnectionContext}) is gated. A destructive ability
 * run directly in PHP, by WP-CLI, or by another plugin's own code is not an
 * assistant triggering it through Albert, and is left alone — that matches issue
 * 04's scope boundary and avoids blocking non-AI flows that were never the
 * threat. Below WordPress 7.1 the filter never fires, so this is inert.
 *
 * @since 1.5.0
 */
class Interceptor implements Hookable {

	/**
	 * The option holding how long a staged action may be approved for, in minutes.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const TTL_OPTION = 'albert_safe_mode_ttl_minutes';

	/**
	 * How long a staged action may be approved for, in minutes.
	 *
	 * The window's cost is drift: approving an hours-old `update-post`
	 * overwrites whatever was edited since. An hour covers "deal with it now"
	 * without covering "yesterday's intent against today's content".
	 *
	 * @since 1.5.0
	 * @var int
	 */
	public const DEFAULT_TTL_MINUTES = 60;

	/**
	 * Shortest window an owner may set, in minutes.
	 *
	 * A floor so the queue stays usable: below the round trip to wp-admin, every
	 * request lapses before anybody sees it.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	public const MIN_TTL_MINUTES = 5;

	/**
	 * Largest approval window, in minutes (one week).
	 *
	 * @since 1.5.0
	 * @var int
	 */
	public const MAX_TTL_MINUTES = 10080;

	/**
	 * Wire the interceptor to its collaborators.
	 *
	 * @param InterceptorDecision $decision        Resolves the MCP double-fire.
	 * @param Gate                $gate            The safe-mode gate rule.
	 * @param Repository          $repository      The pending-actions store.
	 * @param TargetResolver|null $target_resolver Describes the affected object; defaults to a fresh one.
	 */
	public function __construct(
		private InterceptorDecision $decision,
		private Gate $gate,
		private Repository $repository,
		private ?TargetResolver $target_resolver = null
	) {
		$this->target_resolver = $target_resolver ?? new TargetResolver();
	}

	/**
	 * Bind the interceptor to the pre-execute short-circuit filter.
	 *
	 * Registered unconditionally: below WordPress 7.1 the filter never fires, so
	 * this costs one array entry and stays correct, exactly like
	 * {@see \Albert\Core\InvocationRelay}.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		add_filter( 'wp_pre_execute_ability', [ $this, 'intercept' ], 10, 4 );
	}

	/**
	 * Stage a gated call and short-circuit it, or let the call proceed.
	 *
	 * @param mixed  $pre          The short-circuit sentinel; returned unchanged to proceed.
	 * @param string $ability_name The ability being executed.
	 * @param mixed  $input        The raw input for the ability, before core normalises it.
	 * @param mixed  $ability      The ability instance (a WP_Ability on 7.1+).
	 *
	 * @return mixed The sentinel to proceed, or a WP_Error to short-circuit.
	 * @since 1.5.0
	 */
	public function intercept( $pre, string $ability_name, $input, $ability ) {
		// The wrapper fires first and the real ability fires next; act on that one.
		if ( ! $this->decision->should_intercept( $ability_name ) ) {
			return $pre;
		}

		$resolved = is_array( $input ) ? $input : [];

		// A person approved exactly this call: a one-shot ticket bound to the
		// ability and input lets that single execution through. Bound and
		// single-use, so a ticket that leaked into a later request cannot
		// authorise a different call or the same one twice.
		if ( ApprovalTicket::consume( $ability_name, $resolved ) ) {
			return $pre;
		}

		if ( ! $ability instanceof WP_Ability ) {
			return $pre;
		}

		// Only gate calls an assistant made through an Albert connection.
		if ( ConnectionContext::client_id() === null ) {
			return $pre;
		}

		if ( ! $this->gate->must_gate( $ability, $resolved ) ) {
			return $pre;
		}

		// Don't stage a call this connection was never allowed to make: it would
		// only fail at approval time and, until then, sit in the queue as noise
		// and a social-engineering surface. Deny it outright.
		//
		// The denial is a short-circuit, never a return of $pre: proceeding would
		// let core run its own pipeline and execute the call *ungated* whenever
		// this pre-check is a false negative (it runs on pre-normalisation input).
		// A denial is fail-safe in both directions — a wrongly-denied legitimate
		// call is refused, never executed unattended.
		if ( $ability->check_permissions( $resolved ) !== true ) {
			return new WP_Error(
				'albert_permission_denied',
				__( 'You do not have permission to perform this action, so it was not queued for approval. This is a permanent denial, not a pending request.', 'albert-ai-butler' )
			);
		}

		$pending = $this->repository->stage(
			$ability_name,
			$resolved,
			get_current_user_id(),
			ConnectionContext::client_id(),
			ConnectionContext::client_name(),
			self::ttl_seconds(),
			$this->target_resolver->describe( $ability_name, $resolved )
		);

		if ( ! $pending->is_stored() ) {
			return new WP_Error(
				'albert_hold_failed',
				__( 'This action was not performed. This site holds destructive changes for a person to approve first, but the request could not be recorded, so nothing is waiting for approval. Ask the site owner to check that Albert is installed correctly; retrying will not help until then.', 'albert-ai-butler' ),
				[ 'status' => 500 ]
			);
		}

		return $this->awaiting_approval( $pending );
	}

	/**
	 * How long a newly staged action may be approved for, in seconds.
	 *
	 * Read through {@see Value} like every other setting, so a `wp-config.php`
	 * constant or the `albert/settings/value/albert_safe_mode_ttl_minutes`
	 * filter can pin it, and clamped so neither a stored value nor an override
	 * can produce a window that is unusable or effectively unbounded.
	 *
	 * @return int Seconds.
	 * @since 1.5.0
	 */
	public static function ttl_seconds(): int {
		$minutes = (int) Value::get( self::TTL_OPTION, self::DEFAULT_TTL_MINUTES );

		return self::clamp_minutes( $minutes ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Hold a minutes value inside the allowed range.
	 *
	 * Also the setting's `sanitize_callback`, so the stored value and an
	 * override are bounded by the same rule rather than by two that can drift.
	 *
	 * @param mixed $value The submitted value.
	 *
	 * @return int Minutes.
	 * @since 1.5.0
	 */
	public static function clamp_minutes( $value ): int {
		$minutes = is_numeric( $value ) ? (int) $value : self::DEFAULT_TTL_MINUTES;

		return max( self::MIN_TTL_MINUTES, min( self::MAX_TTL_MINUTES, $minutes ) );
	}

	/**
	 * The short-circuit value returned for a staged call.
	 *
	 * A WP_Error, because the call was not performed — the MCP adapter renders it
	 * as a tool failure, which is the truthful signal. The message carries the
	 * deep link and reference because the adapter surfaces only the message, and
	 * it tells the assistant plainly that retrying will not run the action, so a
	 * retry returns this same staged row rather than piling up duplicates.
	 *
	 * @param PendingAction $pending The staged action.
	 *
	 * @return WP_Error
	 * @since 1.5.0
	 */
	private function awaiting_approval( PendingAction $pending ): WP_Error {
		$url = ApprovalUrl::for_action( $pending->action_id );

		$message = sprintf(
			/* translators: 1: wp-admin approval URL, 2: action reference. */
			__( 'This action was not performed. For safety, this site holds destructive changes for a person to approve first. It is queued for approval (reference %2$s). Ask the site owner to approve or reject it in wp-admin: %1$s Retrying will not run it; it runs only once a person approves.', 'albert-ai-butler' ),
			$url,
			$pending->action_id
		);

		return new WP_Error(
			Outcome::HELD_CODE,
			$message,
			[
				'status'       => 'pending_approval',
				'action_id'    => $pending->action_id,
				'approval_url' => $url,
			]
		);
	}
}
