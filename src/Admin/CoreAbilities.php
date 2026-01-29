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
	 * Filter abilities to only core ones.
	 *
	 * Includes abilities starting with 'albert/' (but not 'albert/woo-') or 'core/'.
	 *
	 * @param array<string, mixed> $grouped Grouped abilities.
	 *
	 * @return array<string, mixed> Filtered grouped abilities.
	 * @since 1.0.0
	 */
	protected function filter_abilities( array $grouped ): array {
		foreach ( $grouped as $slug => &$data ) {
			$data['abilities'] = array_filter(
				$data['abilities'],
				function ( $ability ) {
					$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';

					// Include albert/ abilities but exclude albert/woo- ones.
					if ( str_starts_with( $name, 'albert/' ) ) {
						return ! str_starts_with( $name, 'albert/woo-' );
					}

					// Include core/ abilities.
					return str_starts_with( $name, 'core/' );
				}
			);
		}

		return $grouped;
	}
}
