<?php
/**
 * Abilities Registry
 *
 * Defines ability groups and permission mappings.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.0.0
 */

namespace Albert\Core;

/**
 * Abilities Registry class
 *
 * Manages ability grouping and permission-to-ability mapping.
 *
 * @since 1.0.0
 */
class AbilitiesRegistry {

	/**
	 * Get all ability groups.
	 *
	 * @return array<string, array<string, mixed>> Ability groups structure.
	 * @since 1.0.0
	 */
	public static function get_ability_groups(): array {
		$groups = [
			'wordpress' => [
				'label'       => __( 'WordPress Core', 'albert-ai-butler' ),
				'description' => __( 'Core WordPress content management.', 'albert-ai-butler' ),
				'types'       => self::get_wordpress_types(),
			],
		];

		// Add WooCommerce if active.
		if ( class_exists( 'WooCommerce' ) ) {
			$groups['woocommerce'] = [
				'label'       => __( 'WooCommerce', 'albert-ai-butler' ),
				'description' => __( 'Store and order management.', 'albert-ai-butler' ),
				'types'       => self::get_woocommerce_types(),
			];
		}

		return apply_filters( 'albert_ability_groups', $groups );
	}

	/**
	 * Get WordPress content types.
	 *
	 * @return array<string, array<string, mixed>> WordPress content types with read/write permissions.
	 * @since 1.0.0
	 */
	private static function get_wordpress_types(): array {
		return [
			'posts'      => [
				'label' => __( 'Posts', 'albert-ai-butler' ),
				'read'  => [
					'label'       => __( 'Read', 'albert-ai-butler' ),
					'description' => __( 'Find and view posts', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/find-posts', 'albert/view-post' ],
				],
				'write' => [
					'label'       => __( 'Write', 'albert-ai-butler' ),
					'description' => __( 'Create, edit, and delete posts', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/create-post', 'albert/update-post', 'albert/delete-post' ],
				],
			],
			'pages'      => [
				'label' => __( 'Pages', 'albert-ai-butler' ),
				'read'  => [
					'label'       => __( 'Read', 'albert-ai-butler' ),
					'description' => __( 'Find and view pages', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/find-pages', 'albert/view-page' ],
				],
				'write' => [
					'label'       => __( 'Write', 'albert-ai-butler' ),
					'description' => __( 'Create, edit, and delete pages', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/create-page', 'albert/update-page', 'albert/delete-page' ],
				],
			],
			'media'      => [
				'label' => __( 'Media', 'albert-ai-butler' ),
				'read'  => [
					'label'       => __( 'Read', 'albert-ai-butler' ),
					'description' => __( 'Find and view media files', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/find-media', 'albert/view-media' ],
				],
				'write' => [
					'label'       => __( 'Write', 'albert-ai-butler' ),
					'description' => __( 'Upload and manage media', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/upload-media', 'albert/set-featured-image' ],
				],
			],
			'users'      => [
				'label' => __( 'Users', 'albert-ai-butler' ),
				'read'  => [
					'label'       => __( 'Read', 'albert-ai-butler' ),
					'description' => __( 'Find and view users', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/find-users', 'albert/view-user' ],
				],
				'write' => [
					'label'       => __( 'Write', 'albert-ai-butler' ),
					'description' => __( 'Create, edit, and delete users', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/create-user', 'albert/update-user', 'albert/delete-user' ],
				],
			],
			'taxonomies' => [
				'label' => __( 'Taxonomies', 'albert-ai-butler' ),
				'read'  => [
					'label'       => __( 'Read', 'albert-ai-butler' ),
					'description' => __( 'Find categories, tags, and terms', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/find-taxonomies', 'albert/find-terms', 'albert/view-term' ],
				],
				'write' => [
					'label'       => __( 'Write', 'albert-ai-butler' ),
					'description' => __( 'Create, edit, and delete terms', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/create-term', 'albert/update-term', 'albert/delete-term' ],
				],
			],
		];
	}

	/**
	 * Get WooCommerce content types.
	 *
	 * @return array<string, array<string, mixed>> WooCommerce content types with read/write permissions.
	 * @since 1.0.0
	 */
	private static function get_woocommerce_types(): array {
		return [
			'products'  => [
				'label' => __( 'Products', 'albert-ai-butler' ),
				'read'  => [
					'label'       => __( 'Read', 'albert-ai-butler' ),
					'description' => __( 'Find and view products', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/woo-find-products', 'albert/woo-view-product' ],
				],
				'write' => [
					'label'       => __( 'Write', 'albert-ai-butler' ),
					'description' => __( 'Create, edit, and delete products', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/woo-create-product', 'albert/woo-update-product', 'albert/woo-delete-product' ],
				],
			],
			'orders'    => [
				'label' => __( 'Orders', 'albert-ai-butler' ),
				'read'  => [
					'label'       => __( 'Read', 'albert-ai-butler' ),
					'description' => __( 'Find and view orders', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/woo-find-orders', 'albert/woo-view-order' ],
				],
				'write' => [
					'label'       => __( 'Write', 'albert-ai-butler' ),
					'description' => __( 'Create and update orders', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/woo-create-order', 'albert/woo-update-order' ],
				],
			],
			'customers' => [
				'label' => __( 'Customers', 'albert-ai-butler' ),
				'read'  => [
					'label'       => __( 'Read', 'albert-ai-butler' ),
					'description' => __( 'Find and view customers', 'albert-ai-butler' ),
					'abilities'   => [ 'albert/woo-find-customers', 'albert/woo-view-customer' ],
				],
			],
		];
	}

	/**
	 * Get all individual ability slugs from enabled permissions.
	 *
	 * @param array<string> $enabled_permissions Array of enabled permission keys (e.g., 'posts_read', 'posts_write').
	 * @return array<string> Array of individual ability slugs.
	 * @since 1.0.0
	 */
	public static function get_enabled_abilities( array $enabled_permissions ): array {
		$abilities = [];
		$groups    = self::get_ability_groups();

		foreach ( $groups as $group_key => $group ) {
			foreach ( $group['types'] as $type_key => $type ) {
				foreach ( [ 'read', 'write' ] as $permission ) {
					if ( ! isset( $type[ $permission ] ) ) {
						continue;
					}

					$permission_key = $type_key . '_' . $permission;

					if ( in_array( $permission_key, $enabled_permissions, true ) ) {
						$abilities = array_merge( $abilities, $type[ $permission ]['abilities'] );
					}
				}
			}
		}

		return array_unique( $abilities );
	}

	/**
	 * Get default permissions (all read enabled, write disabled).
	 *
	 * @return array<string> Array of default permission keys.
	 * @since 1.0.0
	 */
	public static function get_default_permissions(): array {
		$defaults = [];
		$groups   = self::get_ability_groups();

		foreach ( $groups as $group ) {
			foreach ( $group['types'] as $type_key => $type ) {
				// Enable read by default.
				if ( isset( $type['read'] ) ) {
					$defaults[] = $type_key . '_read';
				}
				// Write disabled by default.
			}
		}

		return $defaults;
	}

	/**
	 * Get all abilities grouped by category.
	 *
	 * Calls the WP Abilities API to get all registered abilities and categories,
	 * and groups abilities by their category slug.
	 *
	 * @return array<string, array<string, mixed>> Grouped abilities.
	 * @since 1.0.0
	 */
	public static function get_abilities_grouped_by_category(): array {
		if ( ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'wp_get_ability_categories' ) ) {
			return [];
		}

		$all_abilities  = wp_get_abilities();
		$all_categories = wp_get_ability_categories();
		$grouped        = [];

		// Initialize groups for all categories.
		foreach ( $all_categories as $slug => $category ) {
			$grouped[ $slug ] = [
				'category'  => $category,
				'abilities' => [],
			];
		}

		// Group abilities into their categories.
		foreach ( $all_abilities as $ability ) {
			$cat_slug = method_exists( $ability, 'get_category' )
				? $ability->get_category()
				: 'uncategorized';
			if ( ! isset( $grouped[ $cat_slug ] ) ) {
				$grouped[ $cat_slug ] = [
					'category'  => [
						'label'       => ucfirst( $cat_slug ),
						'description' => '',
					],
					'abilities' => [],
				];
			}
			$grouped[ $cat_slug ]['abilities'][] = $ability;
		}

		return $grouped;
	}

	/**
	 * Get the predefined sort order for categories.
	 *
	 * @return array<string> Ordered category slugs.
	 * @since 1.0.0
	 */
	public static function get_category_sort_order(): array {
		return [
			'site',
			'user',
			'content',
			'taxonomy',
			'comments',
			'commerce',
			'woo-products',
			'woo-orders',
			'woo-customers',
			'seo',
			'fields',
			'forms',
			'lms',
			'maintenance',
		];
	}

	/**
	 * Sort grouped categories by predefined order, then alphabetical for unknown.
	 *
	 * @param array<string, array<string, mixed>> $grouped Grouped abilities.
	 *
	 * @return array<string, array<string, mixed>> Sorted grouped abilities.
	 * @since 1.0.0
	 */
	public static function sort_grouped_categories( array $grouped ): array {
		$order  = self::get_category_sort_order();
		$sorted = [];

		// Add categories in predefined order.
		foreach ( $order as $slug ) {
			if ( isset( $grouped[ $slug ] ) ) {
				$sorted[ $slug ] = $grouped[ $slug ];
			}
		}

		// Add remaining categories alphabetically.
		$remaining = array_diff_key( $grouped, $sorted );
		ksort( $remaining );
		foreach ( $remaining as $slug => $data ) {
			$sorted[ $slug ] = $data;
		}

		return $sorted;
	}

	/**
	 * Get the source information for an ability.
	 *
	 * @param string $ability_name Ability name/slug.
	 *
	 * @return array{label: string, type: string} Source info.
	 * @since 1.0.0
	 */
	public static function get_ability_source( string $ability_name ): array {
		// Core abilities (registered by WordPress itself).
		if ( str_starts_with( $ability_name, 'core/' ) ) {
			return [
				'label' => 'CORE',
				'type'  => 'core',
			];
		}

		// Albert abilities.
		if ( str_starts_with( $ability_name, 'albert/' ) ) {
			return [
				'label' => 'ALBERT',
				'type'  => 'albert',
			];
		}

		// Third-party abilities — use namespace as label.
		$parts     = explode( '/', $ability_name, 2 );
		$namespace = strtoupper( $parts[0] ?? 'UNKNOWN' );

		return [
			'label' => $namespace,
			'type'  => 'third-party',
		];
	}

	/**
	 * Get the default set of disabled abilities.
	 *
	 * On fresh install, Albert write abilities are disabled by default.
	 * Everything else (read abilities, core, third-party) is enabled.
	 *
	 * @return array<string> Array of ability slugs that are disabled by default.
	 * @since 1.0.0
	 */
	public static function get_default_disabled_abilities(): array {
		$disabled = [];
		$groups   = self::get_ability_groups();

		foreach ( $groups as $group ) {
			foreach ( $group['types'] as $type ) {
				if ( isset( $type['write'] ) ) {
					$disabled = array_merge( $disabled, $type['write']['abilities'] );
				}
			}
		}

		return array_unique( $disabled );
	}
}
