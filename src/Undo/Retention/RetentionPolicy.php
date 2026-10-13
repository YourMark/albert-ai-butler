<?php
/**
 * Change-journal retention contract.
 *
 * @package Albert
 * @subpackage Undo\Retention
 * @since      1.5.0
 */

namespace Albert\Undo\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * How long recorded changes stay revertable.
 *
 * Free's limits are {@see FreeRetentionPolicy}'s constants. Replacing them means
 * registering a class implementing this interface through
 * `albert/undo/register_retention_policy`; there is no filter around the numbers.
 *
 * @since 1.5.0
 */
interface RetentionPolicy {

	/**
	 * Days a session stays revertable, counted from its last call.
	 *
	 * @return int
	 * @since 1.5.0
	 */
	public function max_age_days(): int;

	/**
	 * Sessions kept per item, newest first; 0 for no limit.
	 *
	 * Counts sessions, not calls: building one page takes many calls, and
	 * "revert what you just did" has to cover all of them. A session that falls
	 * outside the count for any item it changed is pruned whole.
	 *
	 * @return int
	 * @since 1.5.0
	 */
	public function runs_per_item(): int;

	/**
	 * Ceiling on the stored before-images, in bytes. Oldest go first past it.
	 *
	 * @return int
	 * @since 1.5.0
	 */
	public function max_bytes(): int;
}
