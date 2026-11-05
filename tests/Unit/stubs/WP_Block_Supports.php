<?php
/**
 * Minimal stub of WP_Block_Supports for unit tests.
 *
 * Writes the generated `wp-block-*` class, a custom className, an align class
 * and the anchor id from the stub registry's `supports`. The real engine runs in
 * the integration suite and the editor validity check.
 *
 * @package Albert
 */

// phpcs:disable

if ( ! class_exists( 'WP_Block_Supports' ) ) {
	/**
	 * Stub supports engine.
	 */
	class WP_Block_Supports {

		/**
		 * Block being rendered.
		 *
		 * @var array|null
		 */
		public static $block_to_render = null;

		/**
		 * Singleton.
		 *
		 * @var WP_Block_Supports|null
		 */
		private static $instance = null;

		/**
		 * Get the singleton.
		 *
		 * @return WP_Block_Supports
		 */
		public static function get_instance() {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Apply the stubbed supports.
		 *
		 * @return array<string, string>
		 */
		public function apply_block_supports() {
			$name     = self::$block_to_render['blockName'] ?? '';
			$attrs    = self::$block_to_render['attrs'] ?? [];
			$type     = $GLOBALS['albert_test_block_types'][ $name ] ?? null;
			$supports = $type ? (array) $type->supports : [];
			$classes  = [];
			$output   = [];

			if ( ! $type ) {
				return [];
			}

			if ( ! empty( $supports['align'] ) && ! empty( $attrs['align'] ) ) {
				$classes[] = 'align' . $attrs['align'];
			}

			if ( ( $supports['customClassName'] ?? true ) && ! empty( $attrs['className'] ) ) {
				$classes[] = $attrs['className'];
			}

			if ( $supports['className'] ?? true ) {
				$classes[] = 'wp-block-' . str_replace( [ 'core/', '/' ], [ '', '-' ], $name );
			}

			if ( ! empty( $supports['anchor'] ) && ! empty( $attrs['anchor'] ) ) {
				$output['id'] = $attrs['anchor'];
			}

			if ( $classes !== [] ) {
				$output['class'] = implode( ' ', $classes );
			}

			return $output;
		}
	}
}
