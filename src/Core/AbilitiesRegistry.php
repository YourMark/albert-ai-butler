<?php
/**
 * Abilities Registry
 *
 * Supplier map, source lookup, and default-state logic for registered abilities.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.0.0
 */

namespace Albert\Core;

/**
 * Abilities Registry class
 *
 * Supplier map, category grouping, and source lookup for registered abilities.
 *
 * @since 1.0.0
 */
class AbilitiesRegistry {

	/**
	 * Cached supplier map, populated on first call to get_suppliers().
	 *
	 * @since 1.1.0
	 * @var array<string, string>|null
	 */
	private static ?array $suppliers_cache = null;

	/**
	 * Get the curated supplier map.
	 *
	 * Maps an ability-id prefix (the namespace before the first `/`) to a
	 * human-readable supplier label. Addons can register their own suppliers
	 * via the `albert/abilities/suppliers` filter so that a custom prefix like
	 * `mycompany/` shows up with a branded label in the admin filter dropdown.
	 *
	 * Built-in entries cover the prefixes Albert knows about today. Anything
	 * not listed falls through to a prettified version of the prefix in
	 * {@see self::get_ability_source()}.
	 *
	 * @return array<string, string> Prefix => supplier label.
	 * @since 1.1.0
	 */
	public static function get_suppliers(): array {
		if ( self::$suppliers_cache !== null ) {
			return self::$suppliers_cache;
		}

		$suppliers = [
			'core'   => __( 'WordPress core', 'albert-ai-butler' ),
			'albert' => __( 'Albert', 'albert-ai-butler' ),
			'woo'    => __( 'WooCommerce', 'albert-ai-butler' ),
			'acf'    => __( 'ACF', 'albert-ai-butler' ),
		];

		/**
		 * Filters the curated supplier map.
		 *
		 * Allows addons and site code to register their own ability-id prefix
		 * under a branded supplier label. The array is keyed by prefix (the
		 * namespace before the first `/` in an ability id) and maps to the
		 * human-readable label shown in the admin filter dropdown and the
		 * expanded row details.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, string> $suppliers Prefix => supplier label.
		 */
		self::$suppliers_cache = apply_filters( 'albert/abilities/suppliers', $suppliers );

		return self::$suppliers_cache;
	}

	/**
	 * Get the supplier information for an ability.
	 *
	 * Looks the ability's prefix up in the curated supplier map
	 * ({@see self::get_suppliers()}). Unknown prefixes fall back to a
	 * prettified version of the prefix itself so every ability always
	 * has a supplier label in the UI.
	 *
	 * @param string $ability_name Ability name/slug, e.g. `albert/create-post`.
	 *
	 * @return array{slug: string, label: string} Supplier slug + human label.
	 * @since 1.0.0
	 */
	public static function get_ability_source( string $ability_name ): array {
		$parts  = explode( '/', $ability_name, 2 );
		$prefix = $parts[0] ?? '';

		if ( $prefix === '' ) {
			return [
				'slug'  => 'unknown',
				'label' => __( 'Unknown', 'albert-ai-butler' ),
			];
		}

		$suppliers = self::get_suppliers();

		if ( isset( $suppliers[ $prefix ] ) ) {
			return [
				'slug'  => $prefix,
				'label' => $suppliers[ $prefix ],
			];
		}

		// Unknown prefix — prettify so it at least reads nicely.
		return [
			'slug'  => $prefix,
			'label' => ucfirst( str_replace( [ '-', '_' ], ' ', $prefix ) ),
		];
	}

	/**
	 * Get the default set of disabled abilities.
	 *
	 * On fresh install, non-readonly abilities (write / delete) are disabled
	 * by default. Derives the list from each registered ability's annotations
	 * so it stays in sync automatically — no hardcoded slug lists.
	 *
	 * @return array<string> Ability IDs that are disabled by default.
	 * @since 1.0.0
	 */
	public static function get_default_disabled_abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}

		$disabled = [];

		foreach ( wp_get_abilities() as $ability ) {
			$meta        = (array) $ability->get_meta();
			$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : [];

			$id = $ability->get_name();

			// No annotations — fall back to the slug heuristic.
			if ( empty( $annotations ) ) {
				$chips = AnnotationPresenter::heuristic_chips( $id );
				if ( ! empty( $chips ) && $chips[0]['key'] !== 'read' ) {
					$disabled[] = $id;
				}
				continue;
			}

			if ( empty( $annotations['readonly'] ) ) {
				$disabled[] = $id;
			}
		}

		return $disabled;
	}
}
