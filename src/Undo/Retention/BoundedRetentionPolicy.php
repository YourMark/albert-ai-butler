<?php
/**
 * A retention policy that only extends changes recorded after a date.
 *
 * @package Albert
 * @subpackage Undo\Retention
 * @since      1.7.0
 */

namespace Albert\Undo\Retention;

defined( 'ABSPATH' ) || exit;

/**
 * A longer window that is never retroactive: sessions last active before
 * {@see self::not_before()} keep only Free's window, so installing an add-on
 * cannot bring back changes Free was already due to delete.
 *
 * @since 1.7.0
 */
interface BoundedRetentionPolicy extends RetentionPolicy {

	/**
	 * When the longer window starts applying, as a Unix timestamp.
	 *
	 * @return int
	 * @since 1.7.0
	 */
	public function not_before(): int;
}
