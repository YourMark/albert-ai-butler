<?php
/**
 * Minimal WP_Post stub for unit tests.
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Stub WP_Post: copies the fields of a plain object, like core's constructor.
	 */
	#[AllowDynamicProperties]
	final class WP_Post {

		/**
		 * Copy the given post fields.
		 *
		 * @param object $post Post fields.
		 */
		public function __construct( object $post ) {
			foreach ( get_object_vars( $post ) as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}
