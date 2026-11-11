<?php
/**
 * Stubs for the read-ability `format` selector tests (view/find posts & pages).
 *
 * Provides a controllable get_post() plus the small helpers the view abilities
 * call (get_permalink, get_post_thumbnail_id). REST plumbing for the find
 * abilities is shared with the write-ability gate tests via block-write-rest-stubs.php.
 *
 * Drive get_post() with $GLOBALS['albert_test_posts'][ $id ] = (object) [ ... ].
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Stub get_post().
	 *
	 * @param int $id Post ID.
	 * @return object|null
	 */
	function get_post( $id = 0 ) {
		return $GLOBALS['albert_test_posts'][ (int) $id ] ?? null;
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	/**
	 * Stub get_post_thumbnail_id().
	 *
	 * @param object|int $post Post or ID.
	 * @return int
	 */
	function get_post_thumbnail_id( $post = null ) {
		return 0;
	}
}

if ( ! function_exists( '_prime_post_caches' ) ) {
	/**
	 * Stub _prime_post_caches().
	 *
	 * The find abilities prime the post cache once before their per-item
	 * get_post() loop; in unit tests get_post() reads directly from a global, so
	 * priming is a no-op.
	 *
	 * @param array<int, int> $ids Post IDs.
	 * @return void
	 */
	function _prime_post_caches( $ids ) {
	}
}

if ( ! function_exists( 'post_password_required' ) ) {
	/**
	 * Stub post_password_required(): no unlock cookie, so any password applies.
	 *
	 * @param int|object|null $post Post or ID.
	 * @return bool
	 */
	function post_password_required( $post = null ) {
		$post = is_object( $post ) ? $post : get_post( (int) $post );
		return ( $post->post_password ?? '' ) !== '';
	}
}

// get_permalink() is provided by block-write-rest-stubs.php (object-aware).

// phpcs:enable
