<?php
/**
 * Albert Free's retention limits.
 *
 * @package Albert
 * @subpackage Undo\Retention
 * @since      1.5.0
 */

namespace Albert\Undo\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed limits: most reverts happen right away, and Premium keeps changes longer.
 *
 * Constants with no hook around them, by design. Free never stores more than it
 * lets the owner revert, so there is no data kept here that a setting elsewhere
 * would unlock (WordPress.org guideline 5).
 *
 * @since 1.5.0
 */
final class FreeRetentionPolicy implements RetentionPolicy {

	/**
	 * Days a session stays revertable, counted from its last call.
	 *
	 * @var int
	 */
	public const MAX_AGE_DAYS = 2;

	/**
	 * Sessions kept per item.
	 *
	 * @var int
	 */
	public const RUNS_PER_ITEM = 2;

	/**
	 * Ceiling on stored before-images: 50 MB.
	 *
	 * @var int
	 */
	public const MAX_BYTES = 52428800;

	/**
	 * {@inheritDoc}
	 */
	public function max_age_days(): int {
		return self::MAX_AGE_DAYS;
	}

	/**
	 * {@inheritDoc}
	 */
	public function runs_per_item(): int {
		return self::RUNS_PER_ITEM;
	}

	/**
	 * {@inheritDoc}
	 */
	public function max_bytes(): int {
		return self::MAX_BYTES;
	}
}
