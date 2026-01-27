<?php
/**
 * Hookable Interface
 *
 * @package Albert
 * @subpackage Contracts\Interfaces
 * @since      1.0.0
 */

namespace Albert\Contracts\Interfaces;

/**
 * The Hookable interface
 *
 * Classes implementing this interface can register WordPress actions and filters.
 *
 * @since 1.0.0
 */
interface Hookable {
	/**
	 * Register WordPress actions and filters.
	 *
	 * This method should be called during plugin initialization to register
	 * all necessary WordPress hooks for the implementing class.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks(): void;
}
