<?php
/**
 * One-shot approved-execution ticket.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

/**
 * A single-use ticket authorising exactly one ability execution to skip the gate.
 *
 * When a person approves a staged call, {@see Approver} arms a ticket bound to
 * that call's ability name and resolved input, runs the ability, and the
 * interceptor consumes the ticket on the matching `wp_pre_execute_ability`
 * firing. Because the ticket is bound to the exact execution and consumed once,
 * a stale ticket that somehow survived into a later request (a persistent worker
 * whose bootstrap did not re-run — FrankenPHP/Swoole/RoadRunner) can neither
 * unlock a *different* ability or input (fingerprint mismatch) nor unlock the
 * same one twice (one-shot). This replaces an earlier name-only marker whose
 * safety rested on a "no connection present" ordering invariant — a negative
 * property that held only until someone added a path where an approval and a
 * connection coexist.
 *
 * Process-local state, nothing else: no cache, no option, no row. Armed only by
 * {@see Approver}, after its capability and nonce checks.
 *
 * @since 1.5.0
 */
class ApprovalTicket {

	/**
	 * How many seconds an armed ticket stays valid.
	 *
	 * A ticket is minted immediately before a synchronous execution, so a
	 * legitimate consume happens within milliseconds. The window exists only to
	 * close the orphan case: a fatal that skips both the consume and the cleanup
	 * (a persistent worker where statics survive) would otherwise leave a ticket
	 * matching any later call with the same ability and input. Past this age a
	 * match is refused and the stale ticket discarded.
	 *
	 * @since 1.5.0
	 * @var int
	 */
	private const TTL_SECONDS = 10;

	/**
	 * Fingerprint of the one execution currently authorised, or null.
	 *
	 * @since 1.5.0
	 * @var string|null
	 */
	private static ?string $fingerprint = null;

	/**
	 * When the live ticket was minted (Unix seconds), or null.
	 *
	 * @since 1.5.0
	 * @var int|null
	 */
	private static ?int $minted_at = null;

	/**
	 * Arm a ticket for one approved execution.
	 *
	 * Bound to the ability and input, not the row id: the interceptor verifies
	 * the firing against exactly those two, so the row id could not participate
	 * in the match and storing it would be dead state.
	 *
	 * @param string               $ability_name The ability that will run.
	 * @param array<string, mixed> $input        The exact input it will run with.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function arm( string $ability_name, array $input ): void {
		self::$fingerprint = self::fingerprint( $ability_name, $input );
		self::$minted_at   = time();
	}

	/**
	 * Consume the ticket if it authorises this exact execution.
	 *
	 * One-shot: a match clears the ticket so it can never authorise a second
	 * execution. A mismatch leaves the ticket untouched and returns false, so the
	 * call is gated normally. A ticket older than {@see self::TTL_SECONDS} is
	 * treated as an orphan — discarded and refused — which closes the window where
	 * a ticket minted but never consumed (a fatal before the ability ran) could
	 * match a later call in a persistent worker.
	 *
	 * @param string               $ability_name The ability being intercepted.
	 * @param array<string, mixed> $input        Its resolved input.
	 * @param int|null             $now          Current Unix time; defaults to now.
	 *
	 * @return bool True when this execution was authorised by an armed ticket.
	 * @since 1.5.0
	 */
	public static function consume( string $ability_name, array $input, ?int $now = null ): bool {
		if ( self::$fingerprint === null ) {
			return false;
		}

		$now = $now ?? time();

		if ( self::$minted_at === null || ( $now - self::$minted_at ) > self::TTL_SECONDS ) {
			// Orphaned or expired: discard it so it can never match again.
			self::clear();

			return false;
		}

		if ( ! hash_equals( self::$fingerprint, self::fingerprint( $ability_name, $input ) ) ) {
			return false;
		}

		self::clear();

		return true;
	}

	/**
	 * Discard any armed ticket. Called in a `finally` around the approved run so
	 * a ticket never outlives the execution it was minted for.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function clear(): void {
		self::$fingerprint = null;
		self::$minted_at   = null;
	}

	/**
	 * The fingerprint binding a ticket to one ability + input.
	 *
	 * Both sides compute it the same way over the same array, so the encode is
	 * deterministic for equal input.
	 *
	 * @param string               $ability_name The ability.
	 * @param array<string, mixed> $input        Its resolved input.
	 *
	 * @return string 64-char sha256 hex.
	 * @since 1.5.0
	 */
	public static function fingerprint( string $ability_name, array $input ): string {
		return hash( 'sha256', $ability_name . '|' . (string) wp_json_encode( $input ) );
	}
}
