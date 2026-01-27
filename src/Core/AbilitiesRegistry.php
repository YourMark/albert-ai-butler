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
				'label'       => __( 'WordPress Core', 'albert' ),
				'description' => __( 'Core WordPress content management.', 'albert' ),
				'types'       => self::get_wordpress_types(),
			],
		];

		// Add WooCommerce if active.
		if ( class_exists( 'WooCommerce' ) ) {
			$groups['woocommerce'] = [
				'label'       => __( 'WooCommerce', 'albert' ),
				'description' => __( 'Store and order management.', 'albert' ),
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
				'label' => __( 'Posts', 'albert' ),
				'read'  => [
					'label'       => __( 'Read', 'albert' ),
					'description' => __( 'Find and view posts', 'albert' ),
					'abilities'   => [ 'albert/find-posts', 'albert/view-post' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'albert' ),
					'description' => __( 'Create, edit, and delete posts', 'albert' ),
					'abilities'   => [ 'albert/create-post', 'albert/update-post', 'albert/delete-post' ],
					'premium'     => false,
				],
			],
			'pages'      => [
				'label' => __( 'Pages', 'albert' ),
				'read'  => [
					'label'       => __( 'Read', 'albert' ),
					'description' => __( 'Find and view pages', 'albert' ),
					'abilities'   => [ 'albert/find-pages', 'albert/view-page' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'albert' ),
					'description' => __( 'Create, edit, and delete pages', 'albert' ),
					'abilities'   => [ 'albert/create-page', 'albert/update-page', 'albert/delete-page' ],
					'premium'     => false,
				],
			],
			'media'      => [
				'label' => __( 'Media', 'albert' ),
				'read'  => [
					'label'       => __( 'Read', 'albert' ),
					'description' => __( 'Find and view media files', 'albert' ),
					'abilities'   => [ 'albert/find-media', 'albert/view-media' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'albert' ),
					'description' => __( 'Upload and manage media', 'albert' ),
					'abilities'   => [ 'albert/upload-media', 'albert/set-featured-image' ],
					'premium'     => false,
				],
			],
			'users'      => [
				'label' => __( 'Users', 'albert' ),
				'read'  => [
					'label'       => __( 'Read', 'albert' ),
					'description' => __( 'Find and view users', 'albert' ),
					'abilities'   => [ 'albert/find-users', 'albert/view-user' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'albert' ),
					'description' => __( 'Create, edit, and delete users', 'albert' ),
					'abilities'   => [ 'albert/create-user', 'albert/update-user', 'albert/delete-user' ],
					'premium'     => false,
				],
			],
			'taxonomies' => [
				'label' => __( 'Taxonomies', 'albert' ),
				'read'  => [
					'label'       => __( 'Read', 'albert' ),
					'description' => __( 'Find categories, tags, and terms', 'albert' ),
					'abilities'   => [ 'albert/find-taxonomies', 'albert/find-terms', 'albert/view-term' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'albert' ),
					'description' => __( 'Create, edit, and delete terms', 'albert' ),
					'abilities'   => [ 'albert/create-term', 'albert/update-term', 'albert/delete-term' ],
					'premium'     => false,
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
			'orders'    => [
				'label' => __( 'Orders', 'albert' ),
				'read'  => [
					'label'       => __( 'Read', 'albert' ),
					'description' => __( 'Find and view orders', 'albert' ),
					'abilities'   => [ 'woo/orders/find', 'woo/orders/view' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'albert' ),
					'description' => __( 'Create and update orders', 'albert' ),
					'abilities'   => [ 'woo/orders/create', 'woo/orders/update' ],
					'premium'     => true, // Locked in free version.
				],
			],
			'products'  => [
				'label' => __( 'Products', 'albert' ),
				'read'  => [
					'label'       => __( 'Read', 'albert' ),
					'description' => __( 'Find and view products', 'albert' ),
					'abilities'   => [ 'woo/products/find', 'woo/products/view' ],
					'premium'     => false,
				],
				'write' => [
					'label'       => __( 'Write', 'albert' ),
					'description' => __( 'Create, edit, and delete products', 'albert' ),
					'abilities'   => [ 'woo/products/create', 'woo/products/update', 'woo/products/delete' ],
					'premium'     => true, // Locked in free version.
				],
			],
			'customers' => [
				'label' => __( 'Customers', 'albert' ),
				'read'  => [
					'label'       => __( 'Read', 'albert' ),
					'description' => __( 'Find and view customers', 'albert' ),
					'abilities'   => [ 'woo/customers/find', 'woo/customers/view' ],
					'premium'     => false,
				],
				// No write for customers in free version.
			],
			'stats'     => [
				'label' => __( 'Statistics', 'albert' ),
				'read'  => [
					'label'       => __( 'Read', 'albert' ),
					'description' => __( 'View sales and store statistics', 'albert' ),
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
