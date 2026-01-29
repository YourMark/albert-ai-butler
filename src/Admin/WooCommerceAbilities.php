<?php
/**
 * WooCommerce Abilities Admin Page
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerceAbilities class
 *
 * Displays abilities with names starting with 'albert/woo-'.
 * Only registered when WooCommerce is active.
 *
 * @since 1.0.0
 */
class WooCommerceAbilities extends AbstractAbilitiesPage {

	/**
	 * Get the page slug.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function get_page_slug(): string {
		return 'albert-woocommerce-abilities';
	}

	/**
	 * Get the page title.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function get_page_title(): string {
		return __( 'WooCommerce Abilities', 'albert' );
	}

	/**
	 * Get the menu title.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function get_menu_title(): string {
		return __( 'WooCommerce', 'albert' );
	}

	/**
	 * Filter abilities to only WooCommerce ones.
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

					return str_starts_with( $name, 'albert/woo-' );
				}
			);
		}

		return $grouped;
	}
}
