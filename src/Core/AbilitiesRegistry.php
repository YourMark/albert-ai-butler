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
				'label'       => __( 'WordPress Core', 'ai-bridge' ),
				'description' => __( 'Core WordPress content management.', 'ai-bridge' ),
				'types'       => self::get_wordpress_types(),
			],
		];

		// Add WooCommerce if active.
		if ( class_exists( 'WooCommerce' ) ) {
			$groups['woocommerce'] = [
				'label'       => __( 'WooCommerce', 'ai-bridge' ),
				'description' => __( 'Store and order management.', 'ai-bridge' ),
				'types'       => self::get_woocommerce_types(),
			];
		}

		return apply_filters( 'aibridge_ability_groups', $groups );
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
				'label' => __( 'Posts', 'ai-bridge' ),
				'read'  => [
					'label'       => __( 'Read', 'ai-bridge' ),
					'description' => __( 'Find and view posts', 'ai-bridge' ),
					'abilities'   => [ 'core/posts-find', 'core/posts-view' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'ai-bridge' ),
					'description' => __( 'Create, edit, and delete posts', 'ai-bridge' ),
					'abilities'   => [ 'core/posts-create', 'core/posts-update', 'core/posts-delete' ],
					'premium'     => false,
				],
			],
			'pages'      => [
				'label' => __( 'Pages', 'ai-bridge' ),
				'read'  => [
					'label'       => __( 'Read', 'ai-bridge' ),
					'description' => __( 'Find and view pages', 'ai-bridge' ),
					'abilities'   => [ 'core/pages-find', 'core/pages-view' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'ai-bridge' ),
					'description' => __( 'Create, edit, and delete pages', 'ai-bridge' ),
					'abilities'   => [ 'core/pages-create', 'core/pages-update', 'core/pages-delete' ],
					'premium'     => false,
				],
			],
			'media'      => [
				'label' => __( 'Media', 'ai-bridge' ),
				'read'  => [
					'label'       => __( 'Read', 'ai-bridge' ),
					'description' => __( 'Find and view media files', 'ai-bridge' ),
					'abilities'   => [ 'core/media-find', 'core/media-view' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'ai-bridge' ),
					'description' => __( 'Upload and manage media', 'ai-bridge' ),
					'abilities'   => [ 'core/media-upload', 'core/media-set-featured-image' ],
					'premium'     => false,
				],
			],
			'users'      => [
				'label' => __( 'Users', 'ai-bridge' ),
				'read'  => [
					'label'       => __( 'Read', 'ai-bridge' ),
					'description' => __( 'Find and view users', 'ai-bridge' ),
					'abilities'   => [ 'core/users-find', 'core/users-view' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'ai-bridge' ),
					'description' => __( 'Create, edit, and delete users', 'ai-bridge' ),
					'abilities'   => [ 'core/users-create', 'core/users-update', 'core/users-delete' ],
					'premium'     => false,
				],
			],
			'taxonomies' => [
				'label' => __( 'Taxonomies', 'ai-bridge' ),
				'read'  => [
					'label'       => __( 'Read', 'ai-bridge' ),
					'description' => __( 'Find categories, tags, and terms', 'ai-bridge' ),
					'abilities'   => [ 'core/taxonomies-find', 'core/terms-view' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'ai-bridge' ),
					'description' => __( 'Create, edit, and delete terms', 'ai-bridge' ),
					'abilities'   => [ 'core/terms-create', 'core/terms-update', 'core/terms-delete' ],
					'premium'     => false,
				],
			],
			'site'       => [
				'label' => __( 'Site Info', 'ai-bridge' ),
				'read'  => [
					'label'       => __( 'Read', 'ai-bridge' ),
					'description' => __( 'View site information', 'ai-bridge' ),
					'abilities'   => [ 'core/site-info' ],
					'premium'     => false,
				],
				// No write for site info.
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
			'orders'    => [
				'label' => __( 'Orders', 'ai-bridge' ),
				'read'  => [
					'label'       => __( 'Read', 'ai-bridge' ),
					'description' => __( 'Find and view orders', 'ai-bridge' ),
					'abilities'   => [ 'woo/orders/find', 'woo/orders/view' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'ai-bridge' ),
					'description' => __( 'Create and update orders', 'ai-bridge' ),
					'abilities'   => [ 'woo/orders/create', 'woo/orders/update' ],
					'premium'     => true, // Locked in free version.
				],
			],
			'products'  => [
				'label' => __( 'Products', 'ai-bridge' ),
				'read'  => [
					'label'       => __( 'Read', 'ai-bridge' ),
					'description' => __( 'Find and view products', 'ai-bridge' ),
					'abilities'   => [ 'woo/products/find', 'woo/products/view' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'ai-bridge' ),
					'description' => __( 'Create, edit, and delete products', 'ai-bridge' ),
					'abilities'   => [ 'woo/products/create', 'woo/products/update', 'woo/products/delete' ],
					'premium'     => true, // Locked in free version.
				],
			],
			'customers' => [
				'label' => __( 'Customers', 'ai-bridge' ),
				'read'  => [
					'label'       => __( 'Read', 'ai-bridge' ),
					'description' => __( 'Find and view customers', 'ai-bridge' ),
					'abilities'   => [ 'woo/customers/find', 'woo/customers/view' ],
					'premium'     => false,
				],
				// No write for customers in free version.
			],
			'stats'     => [
				'label' => __( 'Statistics', 'ai-bridge' ),
				'read'  => [
					'label'       => __( 'Read', 'ai-bridge' ),
					'description' => __( 'View sales and store statistics', 'ai-bridge' ),
					'abilities'   => [ 'woo/stats/sales', 'woo/stats/overview' ],
					'premium'     => false,
				],
				// No write for statistics.
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
				// Enable read by default if not premium.
				if ( isset( $type['read'] ) && ! $type['read']['premium'] ) {
					$defaults[] = $type_key . '_read';
				}
				// Write disabled by default.
			}
		}

		return $defaults;
	}
}
