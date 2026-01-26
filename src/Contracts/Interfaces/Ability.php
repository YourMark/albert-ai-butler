<?php
/**
 * Ability Interface
 *
 * @package Albert
 * @subpackage Contracts\Interfaces
 * @since      1.0.0
 */

namespace Albert\Contracts\Interfaces;

use WP_Error;

/**
 * The Ability interface
 *
 * All abilities must implement this interface.
 *
 * @since 1.0.0
 */
interface Ability {
	/**
	 * Register the ability.
	 *
	 * This method should register the ability with WordPress core
	 * using wp_register_ability() or similar mechanisms.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_ability(): void;

	/**
	 * Execute the ability.
	 *
	 * This method contains the core logic for what the ability does.
	 *
	 * @param array<string, mixed> $args Arguments passed to the ability.
	 *
	 * @return array<string, mixed>|WP_Error The result of the ability execution.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error;
}
