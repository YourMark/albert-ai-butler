<?php
/**
 * Abilities Admin Page
 *
 * Mounts the Albert → Abilities screen: a React app built on @wordpress/dataviews
 * that lists every registered ability in a flat, filterable list with per-row
 * enable/disable, bulk actions, and a detail fly-in. The PHP side only renders
 * the mount point and enqueues the compiled bundle; all data flows over REST
 * (see Admin\Rest\AbilitiesController).
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.1.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Core\AbilitiesState;

/**
 * AbilitiesPage class
 *
 * Registers the Albert → Abilities admin page and enqueues the DataViews app
 * bundle for it. The page is entirely client-rendered; persistence happens
 * through the REST controller, not the Settings API.
 *
 * @since 1.1.0
 */
class AbilitiesPage extends AbstractReactPage {

	/**
	 * Option name for storing the disabled-abilities blocklist.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const DISABLED_ABILITIES_OPTION = AbilitiesState::OPTION;

	/**
	 * Admin page slug.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const PAGE_SLUG = 'albert-abilities';

	/**
	 * Get currently disabled abilities.
	 *
	 * On fresh install returns the default blocklist (Albert write abilities).
	 * Thin wrapper over {@see AbilitiesState::disabled()}, kept for existing callers.
	 *
	 * @return array<int, string>
	 * @since 1.1.0
	 */
	public static function get_disabled_abilities(): array {
		return AbilitiesState::disabled();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function asset_key(): string {
		return 'abilities';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function menu_position(): int {
		return Menu::POSITION_ABILITIES;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function screen_title(): string {
		return __( 'Abilities', 'albert-ai-butler' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function root_id(): string {
		return 'albert-abilities-root';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function js_global(): string {
		return 'albertAbilities';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function rest_suffix(): string {
		return 'abilities';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function stylesheet(): array {
		return [
			'handle' => 'albert-abilities',
			'path'   => 'assets/css/admin-abilities.css',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function needs_dataviews_css(): bool {
		return true;
	}
}
