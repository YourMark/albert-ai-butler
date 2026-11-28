<?php
/**
 * Keeps journal content alive when its revision is deleted.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.7.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Database\Tables;
use Albert\Undo\Storage\Repository;

/**
 * Copies a revision's content into every journal item that points at it,
 * just before WordPress deletes it.
 *
 * Revisions go when a post passes its revision limit or is deleted, by anyone.
 * Without this, that housekeeping would quietly take away a revert.
 *
 * @since 1.7.0
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Albert's own table; never cached.
 */
final class RevisionGuard implements Hookable {

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function register_hooks(): void {
		add_action( 'before_delete_post', [ $this, 'materialise' ], 10, 2 );
	}

	/**
	 * Put a revision's content into the items that point at it.
	 *
	 * @param int   $post_id The post being deleted.
	 * @param mixed $post    Its post object.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function materialise( $post_id, $post = null ): void {
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'revision' ) {
			return;
		}

		// Inside a recorded call this is the journal's own work, not the call's.
		$recorder = Window::recorder();
		$copy     = static fn() => self::copy_into_items( (int) $post_id );

		try {
			if ( $recorder !== null ) {
				$recorder->quietly( $copy );
			} else {
				$copy();
			}
		} catch ( \Throwable $e ) {
			// Housekeeping must never fail a delete; the read-time check reports the loss.
			FailureCounter::record( 1 );
		}
	}

	/**
	 * Copy one revision's content into the items pointing at it.
	 *
	 * @param int $revision_id The revision.
	 *
	 * @return void
	 */
	private static function copy_into_items( int $revision_id ): void {
		global $wpdb;

		$table = Tables::change_items();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, before_image, content_hash FROM %i WHERE content_revision_id = %d', $table, $revision_id ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || $rows === [] ) {
			return;
		}

		$content = Snapshot::revision_content( $revision_id );

		foreach ( $rows as $row ) {
			$before = is_string( $row['before_image'] ) ? Repository::decode( $row['before_image'] ) : null;
			$before = $before !== null && $content !== null ? Snapshot::hydrate( $before, $revision_id, (string) $row['content_hash'] ) : null;

			// Pruned, or the revision no longer holds the content: nothing to keep.
			if ( $before === null ) {
				continue;
			}

			$image = Repository::encode( $before );
			$wpdb->update(
				$table,
				[
					'before_image'        => $image,
					'image_bytes'         => strlen( $image ),
					'content_revision_id' => null,
					'content_hash'        => null,
				],
				[ 'id' => (int) $row['id'] ]
			);
		}
	}
}
