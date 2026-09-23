<?php
/**
 * A destructive ability call held for approval.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

/**
 * One staged destructive ability call: what was going to run, as whom, and how
 * it ended.
 *
 * Immutable. The interceptor stages one of these instead of letting a gated
 * ability execute; the approvals screen reads it back, and on approval the
 * ability runs with exactly the {@see self::$input} captured here — never input
 * the model could resupply at approval time.
 *
 * @since 1.5.0
 */
class PendingAction {

	/**
	 * Staged, awaiting a decision.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Approved and run; {@see self::$result} holds what the ability returned.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const STATUS_EXECUTED = 'executed';

	/**
	 * Approved and run, but the ability itself returned an error.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * A person declined it. The ability never ran.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const STATUS_REJECTED = 'rejected';

	/**
	 * Nobody decided in time. The ability never ran.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	public const STATUS_EXPIRED = 'expired';

	/**
	 * Hold one staged destructive call.
	 *
	 * @param int                       $id           Auto-increment row id, 0 for an unsaved instance.
	 * @param string                    $action_id    Random public reference used in the approval link.
	 * @param string                    $ability_name The ability that was intercepted.
	 * @param array<string, mixed>      $input The resolved input it was about to run with.
	 * @param string                    $status       One of the STATUS_* constants.
	 * @param int                       $user_id      The WordPress user the call runs as once approved.
	 * @param string|null               $client_id    OAuth client id of the calling connection, if any.
	 * @param string|null               $client_name  Snapshotted client name at stage time, if any.
	 * @param string                    $created_at   MySQL datetime the call was staged.
	 * @param string                    $expires_at   MySQL datetime after which it may no longer be approved.
	 * @param string|null               $decided_at   MySQL datetime a person decided, or null.
	 * @param int|null                  $decided_by   The user who approved or rejected, or null.
	 * @param array<string, mixed>|null $result The execution outcome once approved, or null.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $action_id,
		public readonly string $ability_name,
		public readonly array $input,
		public readonly string $status,
		public readonly int $user_id,
		public readonly ?string $client_id,
		public readonly ?string $client_name,
		public readonly string $created_at,
		public readonly string $expires_at,
		public readonly ?string $decided_at,
		public readonly ?int $decided_by,
		public readonly ?array $result
	) {
	}

	/**
	 * Whether this action is still awaiting a decision.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function is_pending(): bool {
		return self::STATUS_PENDING === $this->status;
	}

	/**
	 * Build one from a database row.
	 *
	 * @param array<string, mixed> $row Row as returned by `$wpdb`, all values string|null.
	 *
	 * @return self
	 * @since 1.5.0
	 */
	public static function from_row( array $row ): self {
		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['action_id'] ?? '' ),
			(string) ( $row['ability_name'] ?? '' ),
			self::decode( $row['input'] ?? null ) ?? [],
			(string) ( $row['status'] ?? self::STATUS_PENDING ),
			(int) ( $row['user_id'] ?? 0 ),
			isset( $row['client_id'] ) ? (string) $row['client_id'] : null,
			isset( $row['client_name'] ) ? (string) $row['client_name'] : null,
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['expires_at'] ?? '' ),
			isset( $row['decided_at'] ) ? (string) $row['decided_at'] : null,
			isset( $row['decided_by'] ) ? (int) $row['decided_by'] : null,
			self::decode( $row['result'] ?? null )
		);
	}

	/**
	 * Decode a stored JSON column to an array, or null when absent/malformed.
	 *
	 * @param mixed $value Raw column value.
	 *
	 * @return array<mixed>|null
	 * @since 1.5.0
	 */
	private static function decode( $value ): ?array {
		if ( ! is_string( $value ) || $value === '' ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
