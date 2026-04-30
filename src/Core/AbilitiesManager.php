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
use Albert\Admin\AbilitiesPage;
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
		// Register ability categories on the standard hook at default priority.
		// WP 6.9 registers its built-in categories (e.g. 'site', 'user') via
		// default-filters on the same action, so using the default priority
		// guarantees we run AFTER core in the same turn of the hook — and
		// wp_has_ability_category() inside register_categories() skips anything
		// already in place.
		add_action( 'abilities_api_categories_init', [ $this, 'register_categories' ] );
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ] );

		// Register abilities on WordPress abilities API init hooks.
		add_action( 'abilities_api_init', [ $this, 'register_abilities' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );

		// Remove disabled abilities from the registry after every plugin has
		// registered. PHP_INT_MAX guarantees we run last so we can also strip
		// abilities registered directly by third-party plugins.
		add_action( 'wp_abilities_api_init', [ $this, 'enforce_disabled' ], PHP_INT_MAX );

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

		// Albert's own categories. WP 6.9 ships 'site' and 'user' as
		// built-ins on the same hook at default priority; the
		// wp_has_ability_category() guard below skips slugs core (or any
		// other plugin) has already registered. 'user' is kept here as a
		// defensive fallback for environments where core's registration
		// has not (yet) fired — without it, our Users abilities cannot
		// register because their category does not exist.
		$categories = [
			'content'     => [
				'label'       => __( 'Content', 'albert-ai-butler' ),
				'description' => __( 'Posts, pages, and media management.', 'albert-ai-butler' ),
			],
			'user'        => [
				'label'       => __( 'Users', 'albert-ai-butler' ),
				'description' => __( 'User accounts, roles, and profiles.', 'albert-ai-butler' ),
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
	 * Registers every Albert-managed ability unconditionally. Disabled
	 * abilities are removed from the global registry afterwards by
	 * enforce_disabled() so the admin management page can still see them
	 * via the same wp_get_abilities() API.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_abilities(): void {
		foreach ( $this->abilities as $ability ) {
			$ability->register_ability();
		}
	}

	/**
	 * Remove disabled abilities from the global registry.
	 *
	 * Runs at PHP_INT_MAX on wp_abilities_api_init so every ability — Albert's
	 * built-ins, abilities contributed by add-ons via `albert/abilities/register`,
	 * and abilities registered directly by third-party plugins — is already
	 * registered. We then walk the registry and strip out anything that should
	 * not be executable in this request, so MCP, REST, the WP Abilities client,
	 * and any other consumer all see only enabled abilities.
	 *
	 * The Albert → Abilities admin page intentionally keeps the full registry
	 * (so the user can re-enable disabled abilities). is_abilities_management_context()
	 * detects that page and short-circuits this method.
	 *
	 * Per ability we apply two checks in order:
	 *  1. Effective disabled list (option + `albert/abilities/disabled_list`
	 *     filter). Applies to every ability regardless of who registered it,
	 *     so toggling a third-party ability off in Albert's UI removes it
	 *     globally and add-ons can extend the list at runtime.
	 *  2. is_executable() pipeline, only for Albert-managed abilities. Lets
	 *     the `albert/abilities/is_executable` filter return a reasoned
	 *     WP_Error (e.g. licence-blocked) and unregister on that.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function enforce_disabled(): void {
		if ( ! function_exists( 'wp_get_abilities' ) || ! function_exists( 'wp_unregister_ability' ) ) {
			return;
		}

		if ( $this->is_abilities_management_context() ) {
			return;
		}

		$disabled_list = $this->get_effective_disabled_list();

		foreach ( wp_get_abilities() as $ability ) {
			$id = $ability->get_name();

			if ( in_array( $id, $disabled_list, true ) ) {
				wp_unregister_ability( $id );
				continue;
			}

			$albert_instance = $this->abilities[ $id ] ?? null;
			if ( $albert_instance instanceof BaseAbility ) {
				$check = $albert_instance->is_executable();
				if ( is_wp_error( $check ) ) {
					wp_unregister_ability( $id );
				}
			}
		}
	}

	/**
	 * Compute the effective disabled-ability list for this request.
	 *
	 * Reads the persisted blocklist option, falls back to the registry's
	 * default-disabled list on a fresh install, and then runs the result
	 * through the `albert/abilities/disabled_list` filter so add-ons can
	 * contribute additional IDs at runtime without writing to the option.
	 *
	 * @return array<int, string> Ability IDs that should not be executable.
	 * @since 1.2.0
	 */
	private function get_effective_disabled_list(): array {
		$disabled = get_option( AbilitiesPage::DISABLED_ABILITIES_OPTION, [] );

		if ( empty( $disabled ) && ! get_option( 'albert_abilities_saved' ) ) {
			$disabled = AbilitiesRegistry::get_default_disabled_abilities();
		}

		$disabled = array_values( array_unique( array_map( 'strval', (array) $disabled ) ) );

		/**
		 * Filters the effective list of disabled ability IDs.
		 *
		 * Lets add-ons contribute extra IDs to be unregistered for the current
		 * request without writing to the persisted option. Useful for state
		 * that changes per-request (e.g. licence/plan checks computed by an
		 * add-on, time-of-day windows, kill switches).
		 *
		 * @since 1.2.0
		 *
		 * @param array<int, string> $disabled Ability IDs that should not be executable.
		 */
		$filtered = apply_filters( 'albert/abilities/disabled_list', $disabled );

		return array_values( array_unique( array_map( 'strval', (array) $filtered ) ) );
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
	 * Determine whether the current request is the abilities management context.
	 *
	 * The Albert → Abilities admin page must always show every registered
	 * ability, enabled or disabled, so the user can re-enable disabled ones.
	 * On every other request, AbilitiesManager::enforce_disabled() removes
	 * disabled abilities from the global registry. This helper tells the
	 * enforcer to skip itself when the user is on the management page.
	 *
	 * Add-ons that ship admin pages listing abilities can opt themselves into
	 * the same "show all" behaviour via the `albert/abilities/is_management_context`
	 * filter — return true to suppress unregistration on that request.
	 *
	 * @return bool True when on a management context, false otherwise.
	 * @since 1.2.0
	 */
	private function is_abilities_management_context(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$is_page = is_admin() && AbilitiesPage::PAGE_SLUG === $page;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		/**
		 * Filters whether the current request is an abilities management context.
		 *
		 * When true, AbilitiesManager::enforce_disabled() leaves the global
		 * abilities registry untouched so admin UIs can list every ability.
		 * Add-ons hook this to opt their own admin pages into the same
		 * "show all" semantics without the core having to know about them.
		 *
		 * @since 1.2.0
		 *
		 * @param bool $is_management_context Whether this request is a management context.
		 */
		return (bool) apply_filters( 'albert/abilities/is_management_context', $is_page );
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
