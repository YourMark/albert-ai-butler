<?php
/**
 * Core Abilities Admin Page
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * CoreAbilities class
 *
 * Displays abilities with names starting with 'albert/' (excluding 'albert/woo-')
 * or 'core/'. This is the main abilities page for WordPress core operations.
 *
 * @since 1.0.0
 */
class CoreAbilities extends AbstractAbilitiesPage {

	/**
	 * Get the page slug.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function get_page_slug(): string {
		return 'albert-abilities';
	}

	/**
	 * Get the page title.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function get_page_title(): string {
		return __( 'Core Abilities', 'albert' );
	}

	/**
	 * Get the menu title.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function get_menu_title(): string {
		return __( 'Core', 'albert' );
	}

	/**
	 * Filter abilities to exclude those handled by other pages.
	 *
	 * Uses an exclude-list approach so addon abilities are visible on this page.
	 * Abilities handled by dedicated pages (WooCommerce, ACF, mcp-adapter) are excluded.
	 *
	 * @param array<string, mixed> $grouped Grouped abilities.
	 *
	 * @return array<string, mixed> Filtered grouped abilities.
	 * @since 1.0.0
	 */
	protected function filter_abilities( array $grouped ): array {
		$excluded_prefixes = [ 'albert/woo-', 'acf/', 'mcp-adapter/' ];

		foreach ( $grouped as $slug => &$data ) {
			$data['abilities'] = array_filter(
				$data['abilities'],
				function ( $ability ) use ( $excluded_prefixes ) {
					$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';

					foreach ( $excluded_prefixes as $prefix ) {
						if ( str_starts_with( $name, $prefix ) ) {
							return false;
						}
					}

					return true;
				}
			);
		}

		return $grouped;
	}
}
