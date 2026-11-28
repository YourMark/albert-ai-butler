<?php
/**
 * Minimal stub of WP_Block_Type_Registry for unit tests.
 *
 * Mirrors the singleton accessor and get_all_registered() surface used by
 * Albert\Blocks\BlockSchema. Tests populate the registry via
 * $GLOBALS['albert_test_block_types'] (a map of block name => object with
 * `attributes`, `supports`, and optional `render_callback` properties).
 *
 * The companion albert_test_register_block_type() and
 * albert_test_register_default_block_types() helpers live in
 * tests/wp-function-stubs.php (loaded alongside this stub), kept there so this
 * file declares only the class.
 *
 * @package Albert\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	/**
	 * Stub block type registry.
	 */
	class WP_Block_Type_Registry {

		/**
		 * Singleton instance.
		 *
		 * @var WP_Block_Type_Registry|null
		 */
		private static $instance = null;

		/**
		 * Get the singleton instance.
		 *
		 * @return WP_Block_Type_Registry
		 */
		public static function get_instance() {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Return all registered block types keyed by name.
		 *
		 * @return array<string, object>
		 */
		public function get_all_registered() {
			return (array) ( $GLOBALS['albert_test_block_types'] ?? [] );
		}

		/**
		 * Return one registered block type, or null.
		 *
		 * @param string $name Block name.
		 *
		 * @return object|null
		 */
		public function get_registered( $name ) {
			return $this->get_all_registered()[ $name ] ?? null;
		}
	}
}

// phpcs:enable
