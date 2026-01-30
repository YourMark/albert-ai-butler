<?php
/**
 * ACF Abilities Admin Page
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.0.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * AcfAbilities class
 *
 * Displays abilities with names starting with 'acf/'.
 * Only registered when ACF is active.
 *
 * @since 1.0.0
 */
class AcfAbilities extends AbstractAbilitiesPage {

	/**
	 * Only add menu pages if ACF abilities are registered.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_menu_pages(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return;
		}

		$abilities = wp_get_abilities();
		foreach ( $abilities as $ability ) {
			$name = method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';
			if ( str_starts_with( $name, 'acf/' ) ) {
				parent::add_menu_pages();
				return;
			}
		}
	}

	/**
	 * Get the page slug.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function get_page_slug(): string {
		return 'albert-acf-abilities';
	}

	/**
	 * Get the page title.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function get_page_title(): string {
		return __( 'ACF Abilities', 'albert-ai-butler' );
	}

	/**
	 * Get the menu title.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function get_menu_title(): string {
		return __( 'ACF', 'albert-ai-butler' );
	}

	/**
	 * Filter abilities to only ACF ones.
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

					return str_starts_with( $name, 'acf/' );
				}
			);
		}

		return $grouped;
	}
}
