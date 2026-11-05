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
	 * How many abilities exist and how many are switched on, counted while the
	 * registry was still whole.
	 *
	 * Null until {@see self::enforce_disabled()} has run.
	 *
	 * @since 1.4.0
	 * @var array{total: int, enabled: int}|null
	 */
	private ?array $ability_counts = null;

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

		// Self-heal the persisted blocklist before anything reads it: strip any MCP
		// transport meta-tool a past build may have wrongly disabled, so the
		// transport comes back automatically on update. Runs early (priority 1) so
		// it lands before reconcile_new_abilities() and enforce_disabled().
		add_action( 'abilities_api_init', [ $this, 'heal_transport_tools' ], 1 );
		add_action( 'wp_abilities_api_init', [ $this, 'heal_transport_tools' ], 1 );

		// Switch off abilities first seen after the toggles were saved, so an
		// upgrade never widens what an assistant can reach. After every
		// registration, so the set is complete.
		add_action( 'abilities_api_init', [ $this, 'reconcile_new_abilities' ], PHP_INT_MAX - 1 );
		add_action( 'wp_abilities_api_init', [ $this, 'reconcile_new_abilities' ], PHP_INT_MAX - 1 );

		// Unregister Albert abilities an add-on refuses (a lapsed licence). Last,
		// so every ability is in the registry when it is counted.
		add_action( 'wp_abilities_api_init', [ $this, 'enforce_disabled' ], PHP_INT_MAX );

		// Tell the admin when an upgrade disabled newly-added abilities by default.
		add_action( 'admin_notices', [ $this, 'render_new_abilities_notice' ] );

		// Add abilities to settings page filters.
		add_filter( 'albert/abilities/wordpress', [ $this, 'add_wordpress_abilities_to_settings' ] );

		// Bridge show_in_rest to mcp.public for MCP adapter compatibility.
		add_filter( 'wp_register_ability_args', [ $this, 'normalize_mcp_metadata' ], 10, 2 );

		// Wrap every ability's permission_callback so Albert Premium's advanced
		// permission manager can gate access per role/user via the internal
		// `albert/abilities/check_permission` filter.
		add_filter( 'wp_register_ability_args', [ $this, 'wrap_permission_callback' ], 20, 2 );
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
		// other plugin) has already registered. Both are kept here as a
		// defensive fallback for environments where core's registration
		// has not (yet) fired, without them, our Users and Skills abilities
		// cannot register because their category does not exist. That is not
		// hypothetical: the WordPress test suite reaches ability registration
		// with neither built-in present, and `albert/get-skill` silently failed
		// to register until 'site' was listed here.
		$categories = [
			'content'     => [
				'label'       => __( 'Content', 'albert-ai-butler' ),
				'description' => __( 'Posts, pages, and media management.', 'albert-ai-butler' ),
			],
			'site'        => [
				'label'       => __( 'Site', 'albert-ai-butler' ),
				'description' => __( 'Site-level information and guidance.', 'albert-ai-butler' ),
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
	 * Unregister the Albert abilities an add-on refuses for this site.
	 *
	 * Only the `albert/abilities/is_executable` filter decides (a lapsed licence,
	 * a plan tier). Switching an ability off never unregisters it: that answers
	 * whether an assistant connected through Albert may use it, and other
	 * plugins relying on the ability keep working. See {@see \Albert\MCP\DisabledAbilities}.
	 *
	 * Skipped on the Abilities screen (and add-on screens opting in through
	 * `albert/abilities/is_management_context`), which list every ability.
	 *
	 * @return void
	 * @since 1.2.0
	 * @since 1.6.0 Switched-off abilities stay registered.
	 */
	public function enforce_disabled(): void {
		if ( ! function_exists( 'wp_unregister_ability' ) ) {
			return;
		}

		if ( $this->is_abilities_management_context() ) {
			return;
		}

		// Counted before unregistering, while the registry is still whole.
		$this->ability_counts = $this->count_registry( $this->get_effective_disabled_list() );

		foreach ( $this->abilities as $id => $ability ) {
			// The transport meta-tools are never unregistered: MCP depends on them.
			if ( AbilitiesRegistry::is_transport_ability( $id ) ) {
				continue;
			}

			if ( is_wp_error( $ability->is_available() ) && wp_has_ability( $id ) ) {
				wp_unregister_ability( $id );
			}
		}
	}

	/**
	 * Self-heal the persisted disabled-abilities option.
	 *
	 * The MCP transport meta-tools (discover / get-info / execute) must never be
	 * disabled — the whole MCP transport depends on them. A site upgraded from a
	 * build that wrongly auto-disabled them can still carry their IDs in the
	 * `albert_disabled_abilities` option; this actively removes any transport
	 * meta-tool ID from that option so those sites get the transport back
	 * automatically on this update, with no admin action.
	 *
	 * There is a version-keyed migration hook ({@see \Albert\Database\Installer::maybe_upgrade()}),
	 * but it only fires when the plugin version advances, so it cannot guarantee
	 * the heal on a release that ships this fix without a version bump. Instead this
	 * runs as a cheap defensive scrub early in the abilities lifecycle (priority 1
	 * on the abilities-init hooks, before reconcile and enforce). It reads the
	 * option once and writes ONLY when a transport meta-tool was actually present,
	 * so the steady state performs no writes and there is no option churn.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function heal_transport_tools(): void {
		$disabled = get_option( AbilitiesPage::DISABLED_ABILITIES_OPTION, [] );

		if ( ! is_array( $disabled ) || $disabled === [] ) {
			return;
		}

		$disabled = array_map( 'strval', $disabled );

		$scrubbed = array_values(
			array_filter(
				$disabled,
				static fn( string $id ): bool => ! AbilitiesRegistry::is_transport_ability( $id )
			)
		);

		// Only write when a transport meta-tool was actually present, to avoid churn.
		if ( count( $scrubbed ) !== count( $disabled ) ) {
			update_option( AbilitiesPage::DISABLED_ABILITIES_OPTION, $scrubbed );
		}
	}

	/**
	 * Reconcile abilities added since the site last saved its toggles.
	 *
	 * Albert's fresh-install default gives an out-of-the-box starting point
	 * (reads on, writes off). But once a site has saved its toggles, an upgrade
	 * must not silently expand what a connected AI can reach: any ability added
	 * later is absent from the persisted disabled list and would fall through to
	 * enabled. Expanding the agent's reach — even a new read, which can expose a
	 * new category of data — should be the admin's explicit choice. So on an
	 * already-configured site this method switches EVERY newly-seen ability off
	 * for assistants (the admin opts in), without ever retroactively changing
	 * toggles the admin already set. Other plugins can still use it.
	 *
	 * Keyed off the `albert_known_abilities` option (the set of ability IDs the
	 * site has already accounted for):
	 *
	 *  1. Fresh install (`albert_abilities_saved` unset) → return; the existing
	 *     default fallback already covers it.
	 *  2. Capture every currently registered ability ID. Runs before
	 *     enforce_disabled(), so the set is complete.
	 *  3. Baseline (`albert_known_abilities` unset) → record the current set as
	 *     known and return WITHOUT touching the disabled list. The current state
	 *     is the baseline; we never retroactively re-disable. Also covers the
	 *     first reconcile right after the admin's first save.
	 *  4. Compute the newly-seen IDs. None → return (no option writes in the
	 *     steady state).
	 *  5. Disable ALL the new IDs by merging them into the persisted disabled
	 *     option, and flag them in a transient so the admin can be told.
	 *  6. Fold the new IDs into the known set.
	 *
	 * Hooked on `wp_abilities_api_init` / `abilities_api_init` at
	 * `PHP_INT_MAX - 1`.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function reconcile_new_abilities(): void {
		if ( ! get_option( 'albert_abilities_saved' ) ) {
			return;
		}

		// Raw registry, not wp_get_abilities(): if a filter narrowed the view, the
		// abilities it removed would look "newly seen" the moment it stopped
		// applying and be auto-disabled behind the admin's back.
		$registered = [];
		foreach ( AbilitiesRegistry::get_all_raw() as $ability ) {
			$registered[] = $ability->get_name();
		}
		$registered = array_values( array_unique( array_map( 'strval', $registered ) ) );

		// No registry (Abilities API absent, or filtered away by the host) means we
		// cannot tell new abilities from missing ones. Stop before the baseline
		// write below records an empty known-set that would later mark every real
		// ability as newly seen.
		if ( $registered === [] ) {
			return;
		}

		$known = get_option( 'albert_known_abilities', null );

		// Baseline: an already-configured site seeing this feature for the first
		// time (or the first reconcile right after the admin's first save). Record
		// the current set and stop — never retroactively disable.
		if ( $known === null ) {
			update_option( 'albert_known_abilities', $registered, false );
			return;
		}

		$known = array_values( array_unique( array_map( 'strval', (array) $known ) ) );
		$new   = array_values( array_diff( $registered, $known ) );

		// The MCP transport meta-tools must always stay enabled, so they are never
		// auto-disabled as "newly seen". Excluding them here means an update can
		// never add them to the disabled option (and, since they're left out of the
		// known set too, they simply stay ignored — no option churn either way).
		$new = array_values(
			array_filter(
				$new,
				static fn( string $id ): bool => ! AbilitiesRegistry::is_transport_ability( $id )
			)
		);

		if ( $new === [] ) {
			return;
		}

		// Every newly-seen ability on an already-configured site is disabled by
		// default — the admin opts in. Expanding what the AI can reach (even a new
		// read, which may expose new data) requires explicit review.
		$disabled = (array) get_option( AbilitiesPage::DISABLED_ABILITIES_OPTION, [] );
		$disabled = array_values( array_unique( array_merge( array_map( 'strval', $disabled ), $new ) ) );
		update_option( AbilitiesPage::DISABLED_ABILITIES_OPTION, $disabled );

		set_transient( 'albert_new_abilities_disabled', $new, DAY_IN_SECONDS );

		update_option( 'albert_known_abilities', array_values( array_unique( array_merge( $known, $new ) ) ), false );
	}

	/**
	 * Show a one-time admin notice when an upgrade disabled new abilities.
	 *
	 * Reads the transient set by {@see self::reconcile_new_abilities()} and, when
	 * present, tells the admin that newly-added abilities were disabled by default
	 * for safety, linking to the Abilities page. The transient is cleared once the
	 * notice has been rendered so it shows only once. The richer per-item review
	 * UX belongs to the abilities-page redo, not here.
	 *
	 * Note: this follows the standard single-shot transient pattern — the first
	 * `manage_options` user to load any admin page consumes it. On a multi-admin
	 * site a second admin may not see the notice; the safe default state still
	 * applies regardless, and the Abilities page remains the source of truth.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function render_new_abilities_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$disabled = get_transient( 'albert_new_abilities_disabled' );

		if ( empty( $disabled ) || ! is_array( $disabled ) ) {
			return;
		}

		delete_transient( 'albert_new_abilities_disabled' );

		$count = count( $disabled );
		$url   = menu_page_url( AbilitiesPage::PAGE_SLUG, false );

		$message = sprintf(
			/* translators: %s: number of new abilities. */
			_n(
				'Albert found %s new ability and switched it off for assistants until you review it.',
				'Albert found %s new abilities and switched them off for assistants until you review them.',
				$count,
				'albert-ai-butler'
			),
			number_format_i18n( $count )
		);

		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php echo esc_html( $message ); ?>
				<a href="<?php echo esc_url( $url ); ?>">
					<?php esc_html_e( 'Review them on the Abilities page.', 'albert-ai-butler' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * The abilities switched off for assistants in this request.
	 *
	 * @return array<int, string>
	 * @since 1.2.0
	 */
	private function get_effective_disabled_list(): array {
		return AbilitiesState::effective();
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
	 * Resolve an ability ID to its human-readable label.
	 *
	 * Reads the ability instances the manager holds, so it also knows the
	 * abilities enforce_disabled() has unregistered from WordPress.
	 *
	 * Returns null for IDs the manager does not hold (e.g. third-party
	 * abilities registered directly with WordPress), so callers can fall back
	 * to another resolver.
	 *
	 * @param string $id Ability ID.
	 *
	 * @return string|null Human-readable label, or null when not held.
	 * @since 1.2.0
	 */
	public function get_label( string $id ): ?string {
		$ability = $this->abilities[ $id ] ?? null;

		return $ability?->get_label();
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

	/**
	 * Wrap an ability's permission_callback with the Albert permission filter.
	 *
	 * Runs the ability's own permission_callback first (preserving its capability
	 * / REST-delegated check as the baseline), then passes the result through the
	 * internal `albert/abilities/check_permission` filter, which Albert Premium's
	 * advanced permission rules use to gate per role or per user. Applies to EVERY
	 * registered ability — Albert's, WooCommerce's, ACF's, and any third party — so
	 * those rules gate the whole registry the admin screen lists.
	 *
	 * The callback runs in WP_Ability::check_permissions() with the connected user
	 * already set (OAuth via TokenValidator), which is the meaningful "who" for MCP
	 * calls. WordPress treats a permission_callback as denied unless it returns
	 * exactly `true`, so add-ons must return `true` to allow or a WP_Error to deny.
	 *
	 * @param array<string, mixed> $args Ability registration arguments.
	 * @param string               $name Ability name.
	 *
	 * @return array<string, mixed> Modified arguments.
	 * @since 1.3.0
	 */
	public function wrap_permission_callback( array $args, string $name ): array {
		$original = $args['permission_callback'] ?? null;

		$args['permission_callback'] = static function ( $input = null ) use ( $original, $name ) {
			$result = is_callable( $original ) ? call_user_func( $original, $input ) : true;

			/**
			 * Filters the permission result for an ability.
			 *
			 * @internal Internal seam for Albert's own permission layer (Albert
			 * Premium's advanced permission rules). Not a public extension point —
			 * it may change or be removed without notice; third-party code should
			 * not rely on it.
			 *
			 * The baseline `$result` is the ability's own permission_callback output
			 * (true or WP_Error). A callback returns `true` to allow or a WP_Error to
			 * deny; any other value is treated as denied by WordPress. Runs with the
			 * connected user set, so per-user rules can evaluate against
			 * `get_current_user_id()` and its roles.
			 *
			 * @since 1.3.0
			 *
			 * @param bool|\WP_Error $result     Baseline permission result.
			 * @param string         $ability_id Ability identifier.
			 * @param int            $user_id    Current user ID.
			 */
			return apply_filters( 'albert/abilities/check_permission', $result, $name, get_current_user_id() );
		};

		return $args;
	}

	/**
	 * How many abilities this site has, and how many are switched on.
	 *
	 * Prefer this over counting {@see AbilitiesRegistry::get_all_raw()} on an
	 * admin screen: {@see self::enforce_disabled()} may have unregistered
	 * abilities an add-on refused, which still count towards the total.
	 *
	 * The MCP transport meta-tools are excluded, matching
	 * {@see \Albert\Admin\AbilitiesPayload::build()}. They cannot be switched
	 * off and are hidden from the Abilities screen, so counting them here would
	 * make two screens disagree about the same site by exactly three.
	 *
	 * @since 1.4.0
	 *
	 * @return array{total: int, enabled: int}
	 */
	public function get_ability_counts(): array {
		if ( $this->ability_counts !== null ) {
			return $this->ability_counts;
		}

		$this->prime_registry();

		if ( $this->ability_counts !== null ) {
			return $this->ability_counts;
		}

		// Still nothing means enforcement did not run (the Abilities page, or
		// a WordPress without wp_unregister_ability), so the registry is whole
		// and safe to count directly.
		return $this->count_registry( $this->get_effective_disabled_list() );
	}

	/**
	 * Make sure the abilities registry has been built for this request.
	 *
	 * Reading the registry is what fires `wp_abilities_api_init`, and
	 * {@see self::enforce_disabled()} rides that action, so this call is also
	 * what causes the count snapshot to be taken. Without it, the fallback in
	 * {@see self::get_ability_counts()} triggers enforcement itself and then
	 * counts what is left, missing the abilities it just unregistered.
	 *
	 * A method on `$this` rather than the static call it wraps, so the side
	 * effect on `$this->ability_counts` is visible where it happens.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	private function prime_registry(): void {
		AbilitiesRegistry::get_all_raw();
	}

	/**
	 * Count the registry as it stands right now.
	 *
	 * @since 1.4.0
	 *
	 * @param array<int, string> $disabled_list Effective disabled ability ids.
	 *
	 * @return array{total: int, enabled: int}
	 */
	private function count_registry( array $disabled_list ): array {
		$total   = 0;
		$enabled = 0;

		foreach ( AbilitiesRegistry::get_all_raw() as $ability ) {
			$id = $ability->get_name();

			if ( AbilitiesRegistry::is_transport_ability( $id ) ) {
				continue;
			}

			++$total;

			if ( in_array( $id, $disabled_list, true ) ) {
				continue;
			}

			// An add-on's refusal (a lapsed licence) also means off, or the tile
			// claims an ability is on that enforce_disabled() is about to remove.
			$instance = $this->abilities[ $id ] ?? null;

			if ( $instance instanceof BaseAbility && is_wp_error( $instance->is_available() ) ) {
				continue;
			}

			++$enabled;
		}

		return [
			'total'   => $total,
			'enabled' => $enabled,
		];
	}
}
