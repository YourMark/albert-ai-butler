<?php
/**
 * Abilities Manager
 *
 * @package Albert
 * @subpackage Core
 * @since      1.0.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

use Albert\Abstracts\BaseAbility;
use Albert\Contracts\Interfaces\Hookable;

/**
 * Abilities Manager class
 *
 * Manages all registered abilities and handles their registration.
 *
 * @since 1.0.0
 */
class AbilitiesManager implements Hookable {
	/**
	 * Registered abilities.
	 *
	 * @since 1.0.0
	 * @var BaseAbility[]
	 */
	private array $abilities = [];

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		// Register ability categories first.
		add_action( 'abilities_api_categories_init', [ $this, 'register_categories' ], 5 );
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ], 5 );

		// Register abilities on WordPress abilities API init hooks.
		add_action( 'abilities_api_init', [ $this, 'register_abilities' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );

		// Add abilities to settings page filters.
		add_filter( 'albert/abilities/wordpress', [ $this, 'add_wordpress_abilities_to_settings' ] );

		// Bridge show_in_rest to mcp.public for MCP adapter compatibility.
		add_filter( 'wp_register_ability_args', [ $this, 'normalize_mcp_metadata' ], 10, 2 );
	}


	/**
	 * Register ability categories.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || ! function_exists( 'wp_has_ability_category' ) ) {
			return;
		}

		$categories = [
			'content'     => [
				'label'       => __( 'Content', 'albert-ai-butler' ),
				'description' => __( 'Posts, pages, and media management.', 'albert-ai-butler' ),
			],
			'taxonomy'    => [
				'label'       => __( 'Taxonomies', 'albert-ai-butler' ),
				'description' => __( 'Categories, tags, and custom taxonomies.', 'albert-ai-butler' ),
			],
			'comments'    => [
				'label'       => __( 'Comments', 'albert-ai-butler' ),
				'description' => __( 'Comment management.', 'albert-ai-butler' ),
			],
			'commerce'    => [
				'label'       => __( 'Commerce', 'albert-ai-butler' ),
				'description' => __( 'Store and order management.', 'albert-ai-butler' ),
			],
			'seo'         => [
				'label'       => __( 'SEO', 'albert-ai-butler' ),
				'description' => __( 'Search engine optimization.', 'albert-ai-butler' ),
			],
			'fields'      => [
				'label'       => __( 'Custom Fields', 'albert-ai-butler' ),
				'description' => __( 'Custom field management.', 'albert-ai-butler' ),
			],
			'forms'       => [
				'label'       => __( 'Forms', 'albert-ai-butler' ),
				'description' => __( 'Form management.', 'albert-ai-butler' ),
			],
			'lms'         => [
				'label'       => __( 'Learning', 'albert-ai-butler' ),
				'description' => __( 'Learning management.', 'albert-ai-butler' ),
			],
			'maintenance' => [
				'label'       => __( 'Maintenance', 'albert-ai-butler' ),
				'description' => __( 'Site maintenance and monitoring.', 'albert-ai-butler' ),
			],
		];

		// Register WooCommerce-specific categories when WooCommerce is active.
		if ( class_exists( 'WooCommerce' ) ) {
			$categories['woo-products']  = [
				'label'       => __( 'Products', 'albert-ai-butler' ),
				'description' => __( 'WooCommerce product management.', 'albert-ai-butler' ),
			];
			$categories['woo-orders']    = [
				'label'       => __( 'Orders', 'albert-ai-butler' ),
				'description' => __( 'WooCommerce order management.', 'albert-ai-butler' ),
			];
			$categories['woo-customers'] = [
				'label'       => __( 'Customers', 'albert-ai-butler' ),
				'description' => __( 'WooCommerce customer management.', 'albert-ai-butler' ),
			];
		}

		foreach ( $categories as $slug => $args ) {
			if ( ! wp_has_ability_category( $slug ) ) {
				wp_register_ability_category( $slug, $args );
			}
		}
	}

	/**
	 * Add an ability instance to the manager.
	 *
	 * @param BaseAbility $ability Ability instance.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_ability( BaseAbility $ability ): void {
		$this->abilities[ $ability->get_id() ] = $ability;
	}

	/**
	 * Register all abilities with WordPress.
	 *
	 * This is called on abilities_api_init hook.
	 * Only enabled abilities are registered.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_abilities(): void {
		foreach ( $this->abilities as $ability ) {
			// BaseAbility::register_ability() checks enabled() internally.
			$ability->register_ability();
		}
	}

	/**
	 * Add WordPress abilities to settings page.
	 *
	 * @param array<string, array<string, string>> $abilities Existing abilities.
	 *
	 * @return array<string, array<string, string>> Modified abilities.
	 * @since 1.0.0
	 */
	public function add_wordpress_abilities_to_settings( array $abilities ): array {
		foreach ( $this->abilities as $ability ) {
			$abilities[ $ability->get_id() ] = $ability->get_settings_data();
		}

		return $abilities;
	}


	/**
	 * Get all registered abilities.
	 *
	 * @return BaseAbility[]
	 * @since 1.0.0
	 */
	public function get_abilities(): array {
		return $this->abilities;
	}

	/**
	 * Get a specific ability by ID.
	 *
	 * @param string $id Ability ID.
	 *
	 * @return BaseAbility|null
	 * @since 1.0.0
	 */
	public function get_ability( string $id ): ?BaseAbility {
		return $this->abilities[ $id ] ?? null;
	}

	/**
	 * Ensure all abilities are exposed via MCP.
	 *
	 * The mcp-adapter checks `meta.mcp.public` to determine if an ability
	 * should be discoverable. This filter ensures all registered abilities
	 * are exposed to MCP clients.
	 *
	 * Here we expose the registered core abilities too for the MCP so we can use them.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @param string               $name Ability name.
	 *
	 * @return array<string, mixed> Modified arguments.
	 * @since 1.0.0
	 */
	public function normalize_mcp_metadata( array $args, string $name ): array {
		if ( ! str_starts_with( $name, 'core/' ) ) {
			return $args;
		}

		if ( ! isset( $args['meta']['mcp'] ) ) {
			$args['meta']['mcp'] = [];
		}
		$args['meta']['mcp']['public'] = true;

		return $args;
	}
}
