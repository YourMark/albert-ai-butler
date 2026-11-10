<?php
/**
 * Stubs for the post fields (slug, date, author, parent, template and the rest).
 *
 * Users: $GLOBALS['albert_test_users'][ $id ] = [ 'display_name' => '...', 'caps' => [ ... ] ].
 * Ancestors: $GLOBALS['albert_test_ancestors'][ $id ] = [ parent ids ].
 * Sticky posts: $GLOBALS['albert_test_sticky'].
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * Stub get_userdata().
	 *
	 * @param int $user_id User ID.
	 * @return object|false
	 */
	function get_userdata( $user_id ) {
		$user = $GLOBALS['albert_test_users'][ (int) $user_id ] ?? null;

		return $user === null ? false : (object) ( $user + [ 'ID' => (int) $user_id ] );
	}
}

if ( ! function_exists( 'user_can' ) ) {
	/**
	 * Stub user_can() against the user's `caps` list.
	 *
	 * @param object|int $user       User or ID.
	 * @param string     $capability Capability.
	 * @return bool
	 */
	function user_can( $user, $capability ) {
		$user = is_object( $user ) ? $user : get_userdata( (int) $user );

		return $user && in_array( $capability, (array) ( $user->caps ?? [] ), true );
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	/**
	 * Stub get_post_type_object() with the default capability names.
	 *
	 * @param string $post_type Post type.
	 * @return object
	 */
	function get_post_type_object( $post_type ) {
		$plural = $post_type === 'page' ? 'pages' : 'posts';

		return (object) [
			'cap' => (object) [
				'edit_posts'        => 'edit_' . $plural,
				'edit_others_posts' => 'edit_others_' . $plural,
			],
		];
	}
}

if ( ! function_exists( 'get_post_ancestors' ) ) {
	/**
	 * Stub get_post_ancestors().
	 *
	 * @param object|int $post Post or ID.
	 * @return array<int, int>
	 */
	function get_post_ancestors( $post ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;

		return (array) ( $GLOBALS['albert_test_ancestors'][ $id ] ?? [] );
	}
}

if ( ! function_exists( 'get_page_template_slug' ) ) {
	/**
	 * Stub get_page_template_slug().
	 *
	 * @param object $post Post.
	 * @return string
	 */
	function get_page_template_slug( $post = null ) {
		return (string) ( $post->page_template ?? '' );
	}
}

if ( ! function_exists( 'is_sticky' ) ) {
	/**
	 * Stub is_sticky().
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	function is_sticky( $post_id = 0 ) {
		return in_array( (int) $post_id, (array) ( $GLOBALS['albert_test_sticky'] ?? [] ), true );
	}
}

if ( ! function_exists( 'get_post_format' ) ) {
	/**
	 * Stub get_post_format().
	 *
	 * @param object $post Post.
	 * @return string|false
	 */
	function get_post_format( $post = null ) {
		return isset( $post->post_format ) ? (string) $post->post_format : false;
	}
}

// phpcs:enable
