<?php
/**
 * Abilities Manager
 *
 * @package Albert
 * @subpackage Core
 * @since      1.0.0
 */

namespace Albert\Core;

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
		add_filter( 'aibridge/abilities/wordpress', [ $this, 'add_wordpress_abilities_to_settings' ] );
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

		// Register wordpress-rest category if it doesn't exist.
		if ( ! wp_has_ability_category( 'core' ) ) {
			wp_register_ability_category(
				'core',
				[
					'label'       => __( 'WordPress', 'ai-bridge' ),
					'description' => __( 'WordPress core functionality abilities.', 'ai-bridge' ),
				]
			);
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
	 * Get abilities by category.
	 *
	 * @param string $category Category to filter by.
	 *
	 * @return BaseAbility[]
	 * @since 1.0.0
	 */
	private function get_abilities_by_category( string $category ): array {
		return array_filter(
			$this->abilities,
			function ( BaseAbility $ability ) use ( $category ) {
				return $ability->get_category() === $category;
			}
		);
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
		$wordpress_abilities = $this->get_abilities_by_category( 'core' );

		foreach ( $wordpress_abilities as $ability ) {
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
}
