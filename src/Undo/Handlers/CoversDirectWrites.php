<?php
/**
 * A handler that vouches for direct writes to WordPress's tables.
 *
 * @package Albert
 * @subpackage Undo\Handlers
 * @since      1.7.0
 */

namespace Albert\Undo\Handlers;

defined( 'ABSPATH' ) || exit;

/**
 * Optional for a {@see Handler}: says a direct write to one of WordPress's own
 * tables was captured by the handler itself, so the entry is not marked as
 * holding an unrevertable direct write.
 *
 * For a plugin that writes core tables around their API on purpose, like
 * WooCommerce's atomic stock update on `postmeta`, after the handler captured
 * the entity that query changes. Answer true only for a write already captured:
 * a wrong yes hides a change the journal cannot put back.
 *
 * @since 1.7.0
 */
interface CoversDirectWrites {

	/**
	 * Whether this handler captured what a direct write changes.
	 *
	 * @param string $table  The table written, prefixed (`wp_postmeta`).
	 * @param string $writer The function that issued the query, as
	 *                       `Class::method()` or `function()`.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function covers_direct_write( string $table, string $writer ): bool;
}
