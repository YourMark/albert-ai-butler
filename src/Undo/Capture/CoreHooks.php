<?php
/**
 * Before-write hooks on WordPress's own data.
 *
 * @package Albert
 * @subpackage Undo\Capture
 * @since      1.5.0
 */

namespace Albert\Undo\Capture;

defined( 'ABSPATH' ) || exit;

use Albert\Undo\Entity;
use Albert\Undo\Exclusions;
use Albert\Undo\Recorder;

/**
 * Takes before-images at the hooks WordPress fires immediately before it writes.
 *
 * Chosen for firing *after* every short-circuit a plugin could apply and right
 * before the query, so a write that is cancelled is never recorded: the
 * `update_option` action rather than the `pre_update_option` filter (which runs
 * before WordPress checks the value changed), and the `{$type}_meta` actions
 * rather than the `{$type}_metadata` filters (which another plugin may use to
 * cancel the write). Creates are recorded from the hooks that fire once the new
 * row exists (`wp_insert_post`, `add_attachment`, `created_term`,
 * `user_register`, `wp_insert_comment`). Rows WordPress rewrites in one query
 * as a side effect (children reparented on delete, a post's comments trashed
 * with it) are captured one by one at the hook just before.
 *
 * These hooks are the capture. {@see \Albert\Undo\Coverage\QueryMonitor} only
 * notes writes to tables they do not cover.
 *
 * Attached only while a call is being recorded.
 *
 * @since 1.5.0
 */
final class CoreHooks {

	/**
	 * Meta types captured through the meta actions.
	 *
	 * @var array<int, string>
	 */
	private const META_TYPES = [ 'post', 'term', 'user', 'comment' ];

	/**
	 * Attached callbacks: [hook, callback, accepted args].
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $attached = [];

	/**
	 * Build the hook set for one recorder.
	 *
	 * @param Recorder $recorder The recorder to report to.
	 */
	public function __construct( private Recorder $recorder ) {}

	/**
	 * Attach every hook, ahead of other listeners so their own writes come after.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function attach(): void {
		$post = fn( $post_id ) => $this->capture( Entity::post( (int) $post_id ) );
		$term = fn( $term_id ) => $this->capture( Entity::term( (int) $term_id ) );
		$user = fn( $user_id ) => $this->capture( Entity::user( (int) $user_id ) );

		$this->on( 'pre_post_update', $post );
		// Earlier than pre_post_update: trashing renames the slug before that fires.
		$this->on(
			'wp_insert_post_empty_content',
			function ( $maybe_empty, $postarr ) {
				if ( is_array( $postarr ) && ! empty( $postarr['ID'] ) ) {
					$this->capture( Entity::post( (int) $postarr['ID'] ) );
				}
				return $maybe_empty;
			},
			2
		);
		$this->on(
			'wp_insert_post',
			function ( $post_id, $post, $update ): void {
				if ( ! $update ) {
					$this->created( Entity::post( (int) $post_id ) );
				}
			},
			3
		);
		// Attachments return before `wp_insert_post` fires.
		$this->on( 'add_attachment', fn( $post_id ) => $this->created( Entity::post( (int) $post_id ) ) );
		$this->on(
			'before_delete_post',
			function ( $post_id ): void {
				$this->capture( Entity::post( (int) $post_id ) );
				$this->each( 'SELECT ID FROM %i WHERE post_parent = %d', 'posts', (int) $post_id, Entity::post( ... ) );
			}
		);
		$this->on(
			'delete_attachment',
			function ( $post_id ): void {
				$this->capture( Entity::post( (int) $post_id ) );
				// The files go from disk, and a before-image of rows cannot bring them back.
				$this->recorder->untracked( 'media files' );
			}
		);

		foreach ( self::META_TYPES as $type ) {
			$this->on( "add_{$type}_meta", fn( $object_id, $meta_key ) => $this->meta( $type, $object_id, $meta_key ), 2 );
			$this->on( "update_{$type}_meta", fn( $meta_id, $object_id, $meta_key ) => $this->meta( $type, $object_id, $meta_key ), 3 );
			$this->on( "delete_{$type}_meta", fn( $meta_ids, $object_id, $meta_key ) => $this->meta( $type, $object_id, $meta_key ), 3 );
		}

		$this->on( 'edit_terms', $term );
		$this->on( 'created_term', fn( $term_id ) => $this->created( Entity::term( (int) $term_id ) ) );
		$this->on( 'pre_delete_term', $term );
		// Fired by wp_delete_term() before it reparents the deleted term's children.
		$this->on(
			'edit_term_taxonomies',
			function ( $tt_ids ): void {
				foreach ( (array) $tt_ids as $tt_id ) {
					$this->each( 'SELECT term_id FROM %i WHERE term_taxonomy_id = %d', 'term_taxonomy', (int) $tt_id, Entity::term( ... ) );
				}
			}
		);
		$this->on(
			'add_term_relationship',
			fn( $object_id, $tt_id, $taxonomy ) => $this->capture( Entity::relationships( (int) $object_id, (string) $taxonomy ) ),
			3
		);
		$this->on(
			'delete_term_relationships',
			fn( $object_id, $tt_ids, $taxonomy ) => $this->capture( Entity::relationships( (int) $object_id, (string) $taxonomy ) ),
			3
		);

		$option = fn( $option ) => $this->capture( Entity::option( (string) $option ) );
		$this->on( 'add_option', $option );
		$this->on( 'update_option', $option );
		$this->on( 'delete_option', $option );

		$this->on(
			'wp_pre_insert_user_data',
			function ( $data, $update, $user_id ) {
				if ( $update && $user_id ) {
					$this->capture( Entity::user( (int) $user_id ) );
				}
				return $data;
			},
			3
		);
		$this->on( 'user_register', fn( $user_id ) => $this->created( Entity::user( (int) $user_id ) ) );
		$this->on( 'delete_user', $user );
		$this->on( 'wpmu_delete_user', $user );

		$this->on(
			'wp_update_comment_data',
			function ( $data, $comment ) {
				if ( is_array( $comment ) && isset( $comment['comment_ID'] ) ) {
					$this->capture( Entity::comment( (int) $comment['comment_ID'] ) );
				}
				return $data;
			},
			2
		);
		$this->on( 'wp_insert_comment', fn( $comment_id ) => $this->created( Entity::comment( (int) $comment_id ) ) );
		$this->on(
			'delete_comment',
			function ( $comment_id ): void {
				$this->capture( Entity::comment( (int) $comment_id ) );
				$this->each( 'SELECT comment_ID FROM %i WHERE comment_parent = %d', 'comments', (int) $comment_id, Entity::comment( ... ) );
			}
		);
		$post_comments = fn( $post_id ) => $this->each( 'SELECT comment_ID FROM %i WHERE comment_post_ID = %d', 'comments', (int) $post_id, Entity::comment( ... ) );
		$this->on( 'trash_post_comments', $post_comments );
		$this->on( 'untrash_post_comments', $post_comments );
	}

	/**
	 * Detach every hook.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function detach(): void {
		foreach ( $this->attached as [ $hook, $callback ] ) {
			remove_filter( $hook, $callback, PHP_INT_MIN );
		}

		$this->attached = [];
	}

	/**
	 * Attach one callback.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $args     Accepted args.
	 *
	 * @return void
	 */
	private function on( string $hook, callable $callback, int $args = 1 ): void {
		add_filter( $hook, $callback, PHP_INT_MIN, $args );
		$this->attached[] = [ $hook, $callback, $args ];
	}

	/**
	 * Capture a meta key. A delete across every object passes 0 and cannot be
	 * captured per object, so it is noted as a gap instead.
	 *
	 * @param string $type      Meta type.
	 * @param mixed  $object_id Object ID.
	 * @param mixed  $meta_key  Meta key.
	 *
	 * @return void
	 */
	private function meta( string $type, $object_id, $meta_key ): void {
		if ( ! is_string( $meta_key ) ) {
			return;
		}

		if ( (int) $object_id > 0 ) {
			$this->capture( Entity::meta( $type, (int) $object_id, $meta_key ) );
			return;
		}

		try {
			if ( Exclusions::meta_key( $meta_key ) === null ) {
				$this->recorder->untracked( "{$type} meta {$meta_key}" );
			}
		} catch ( \Throwable $e ) {
			$this->recorder->untracked( "{$type} meta {$meta_key}" );
		}
	}

	/**
	 * Capture every row an id query returns.
	 *
	 * @param string   $sql    Query with `%i` for the table and `%d` for the id.
	 * @param string   $table  wpdb table property.
	 * @param int      $id     The id.
	 * @param callable $entity Builds the entity from a row id.
	 *
	 * @return void
	 */
	private function each( string $sql, string $table, int $id, callable $entity ): void {
		$ids = [];

		try {
			$this->recorder->quietly(
				function () use ( $sql, $table, $id, &$ids ): void {
					global $wpdb;
					$ids = $wpdb->get_col( $wpdb->prepare( $sql, $wpdb->{$table}, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Fixed queries from this class; reads the rows about to change.
				}
			);
		} catch ( \Throwable $e ) {
			$this->recorder->untracked( $table );
			return;
		}

		foreach ( $ids as $row_id ) {
			$this->capture( $entity( (int) $row_id ) );
		}
	}

	/**
	 * Record a created entity through the recorder, never letting a failure reach the write.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return void
	 */
	private function created( Entity $entity ): void {
		try {
			$this->recorder->created( $entity, 'hook' );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Capture an entity through the recorder, never letting a failure reach the write.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return void
	 */
	private function capture( Entity $entity ): void {
		try {
			$this->recorder->capture( $entity, 'hook' );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}
}
