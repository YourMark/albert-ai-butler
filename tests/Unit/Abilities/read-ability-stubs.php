<?php
/**
 * Stubs for the read-ability `format` selector tests (view/find posts & pages).
 *
 * Provides a controllable get_post() plus the small helpers the view abilities
 * call (get_permalink, get_post_thumbnail_id, the term readers). REST plumbing for the find
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

if ( ! function_exists( 'is_object_in_taxonomy' ) ) {
	/**
	 * Stub is_object_in_taxonomy(): posts use categories and tags, nothing else does.
	 *
	 * @param string $object_type Post type.
	 * @param string $taxonomy    Taxonomy.
	 * @return bool
	 */
	function is_object_in_taxonomy( $object_type, $taxonomy ) {
		return 'post' === $object_type && in_array( $taxonomy, [ 'category', 'post_tag' ], true );
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	/**
	 * Stub get_the_terms(): no terms assigned.
	 *
	 * @param object|int $post     Post or ID.
	 * @param string     $taxonomy Taxonomy.
	 * @return false
	 */
	function get_the_terms( $post, $taxonomy ) {
		return false;
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

// get_permalink() is provided by block-write-rest-stubs.php (object-aware).

require_once __DIR__ . '/post-fields-stubs.php';

// phpcs:enable
