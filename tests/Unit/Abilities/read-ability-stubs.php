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

// get_permalink() is provided by block-write-rest-stubs.php (object-aware).

// phpcs:enable

if ( ! function_exists( 'get_registered_meta_keys' ) ) {
	/**
	 * Stub get_registered_meta_keys(): nothing is registered.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Subtype.
	 * @return array<string, mixed>
	 */
	function get_registered_meta_keys( $object_type, $object_subtype = '' ) {
		return [];
	}
}

if ( ! class_exists( 'WP_REST_Post_Meta_Fields' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test stub.
	/**
	 * Stub of core's post meta REST fields: no registered fields.
	 */
	class WP_REST_Post_Meta_Fields {
		/**
		 * Values of the registered fields.
		 *
		 * @param int    $object_id Object ID.
		 * @param object $request   Request.
		 * @return array<string, mixed>
		 */
		public function get_value( $object_id, $request ) {
			return [];
		}
	}
}
