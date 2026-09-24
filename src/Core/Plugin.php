<?php
/**
 * Main Plugin Class
 *
 * @package Albert
 * @subpackage Core
 * @since      1.0.0
 */

namespace Albert\Core;

defined( 'ABSPATH' ) || exit;

use Albert\Abilities\WordPress\Posts\FindPosts;
use Albert\Abilities\WordPress\Posts\ViewPost;
use Albert\Abilities\WordPress\Posts\Create as CreatePost;
use Albert\Abilities\WordPress\Posts\Update as UpdatePost;
use Albert\Abilities\WordPress\Posts\Delete as DeletePost;
use Albert\Abilities\WordPress\Posts\EditBlock as EditPostBlock;
use Albert\Abilities\WordPress\Posts\AddBlock as AddPostBlock;
use Albert\Abilities\WordPress\Posts\RemoveBlock as RemovePostBlock;
use Albert\Abilities\WordPress\Posts\MoveBlock as MovePostBlock;
use Albert\Abilities\WordPress\Pages\FindPages;
use Albert\Abilities\WordPress\Pages\ViewPage;
use Albert\Abilities\WordPress\Pages\Create as CreatePage;
use Albert\Abilities\WordPress\Pages\Update as UpdatePage;
use Albert\Abilities\WordPress\Pages\Delete as DeletePage;
use Albert\Abilities\WordPress\Pages\EditBlock as EditPageBlock;
use Albert\Abilities\WordPress\Pages\AddBlock as AddPageBlock;
use Albert\Abilities\WordPress\Pages\RemoveBlock as RemovePageBlock;
use Albert\Abilities\WordPress\Pages\MoveBlock as MovePageBlock;
use Albert\Abilities\WordPress\Users\FindUsers;
use Albert\Abilities\WordPress\Users\ViewUser;
use Albert\Abilities\WordPress\Users\Create as CreateUser;
use Albert\Abilities\WordPress\Users\Update as UpdateUser;
use Albert\Abilities\WordPress\Users\Delete as DeleteUser;
use Albert\Abilities\WordPress\Media\FindMedia;
use Albert\Abilities\WordPress\Media\ViewMedia;
use Albert\Abilities\WordPress\Media\SetFeaturedImage;
use Albert\Abilities\WordPress\Media\UploadMedia;
use Albert\Abilities\WordPress\Media\CreateUploadLink;
use Albert\Abilities\WordPress\Blocks\GetBlockType;
use Albert\Abilities\WordPress\Blocks\ListBlockTypes;
use Albert\Abilities\WordPress\Blocks\FindPatterns;
use Albert\Abilities\WordPress\Blocks\ViewPattern;
use Albert\Abilities\WordPress\Blocks\CreatePattern;
use Albert\Abilities\WordPress\Blocks\UpdatePattern;
use Albert\Abilities\WordPress\Blocks\DeletePattern;
use Albert\Abilities\WordPress\Skills\GetSkill;
use Albert\Abilities\WordPress\Taxonomies\FindTaxonomies;
use Albert\Abilities\WordPress\Taxonomies\FindTerms;
use Albert\Abilities\WordPress\Taxonomies\ViewTerm;
use Albert\Abilities\WordPress\Taxonomies\CreateTerm;
use Albert\Abilities\WordPress\Taxonomies\UpdateTerm;
use Albert\Abilities\WordPress\Taxonomies\DeleteTerm;
use Albert\Abilities\WooCommerce\FindCustomers;
use Albert\Abilities\WooCommerce\FindOrders;
use Albert\Abilities\WooCommerce\FindProducts;
use Albert\Abilities\WooCommerce\ViewCustomer;
use Albert\Abilities\WooCommerce\ViewOrder;
use Albert\Abilities\WooCommerce\ViewProduct;
use Albert\Admin\AbilitiesPage;
use Albert\Admin\Assets;
use Albert\Admin\Connections;
use Albert\Admin\ContextPage;
use Albert\Admin\Menu;
use Albert\Admin\Dashboard;
use Albert\Admin\Settings;
use Albert\Settings\Overrides as SettingsOverrides;
use Albert\Settings\Storage as SettingsStorage;
use Albert\Admin\SkillsPage;
use Albert\Cron\AllowedUserExpiry;
use Albert\Cron\ConnectionRetentionSweep;
use Albert\Cron\PendingActionSweep;
use Albert\Cron\TokenCleanup;
use Albert\Database\Installer as DatabaseInstaller;
use Albert\Logging\Logger;
use Albert\Logging\Repository as LoggingRepository;
use Albert\MCP\AdapterHealth as McpAdapterHealth;
use Albert\MCP\Server as McpServer;
use Albert\Media\UploadLinks\UploadLinkController;
use Albert\OAuth\Endpoints\AuthorizationPage;
use Albert\OAuth\Endpoints\ClientRegistration;
use Albert\OAuth\Endpoints\OAuthController;
use Albert\Admin\Rest\AbilitiesController;
use Albert\Admin\Rest\ContextController;
use Albert\Admin\Rest\SkillsController;
use Albert\OAuth\Endpoints\OAuthDiscovery;
use Albert\OAuth\DiscoveryHealth;
use Albert\Privacy\PrivacyMode;
use Albert\Execution\InterceptorDecision;
use Albert\SafeMode\Gate as SafeModeGate;
use Albert\SafeMode\Repository as SafeModeRepository;
use Albert\SafeMode\Approver as SafeModeApprover;
use Albert\SafeMode\ApprovalPolicy as SafeModeApprovalPolicy;
use Albert\SafeMode\Interceptor as SafeModeInterceptor;
use Albert\SafeMode\ConnectionGuard as SafeModeConnectionGuard;
use Albert\Admin\Approvals;
use WP\MCP\Core\McpAdapter;

/**
 * Main Plugin Class
 *
 * This is the core plugin class that initializes all functionality.
 * Uses singleton pattern to ensure only one instance exists.
 *
 * @since 1.0.0
 */
class Plugin {
	/**
	 * Default REST API namespace.
	 *
	 * Use {@see self::rest_namespace()} to get the (potentially filtered) value.
	 *
	 * @since 1.0.1
	 * @var string
	 */
	const REST_NAMESPACE = 'albert/v1';

	/**
	 * Get the REST API namespace, allowing override via filter.
	 *
	 * Sites that have a namespace collision with another plugin can change
	 * the value via the `albert/rest_namespace` filter. The result is cached
	 * for the duration of the request so the filter only fires once.
	 *
	 * @since 1.0.1
	 *
	 * @return non-falsy-string
	 */
	public static function rest_namespace(): string {
		static $namespace = null;

		if ( $namespace === null ) {
			/**
			 * Filters the REST API namespace used by all Albert endpoints.
			 *
			 * @since 1.0.1
			 *
			 * @param string $namespace Default namespace ('albert/v1').
			 */
			$filtered  = apply_filters( 'albert/rest_namespace', self::REST_NAMESPACE );
			$namespace = ( is_string( $filtered ) && $filtered !== '' && $filtered !== '0' ) ? $filtered : self::REST_NAMESPACE;
		}

		return $namespace;
	}

	/**
	 * The single instance of the plugin.
	 *
	 * @since 1.0.0
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * The abilities manager instance.
	 *
	 * @since 1.0.0
	 * @var AbilitiesManager|null
	 */
	private ?AbilitiesManager $abilities_manager = null;

	/**
	 * Get the singleton instance of the plugin.
	 *
	 * @return Plugin The plugin instance.
	 * @since 1.0.0
	 */
	public static function get_instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init(): void {
		// Apply any pending schema migration (a cheap no-op until DB_VERSION moves).
		DatabaseInstaller::maybe_upgrade();

		// One-time cleanup of legacy options on upgrade from pre-1.1.0 installs.
		$this->maybe_cleanup_legacy_options();

		// Initialize the logging system (hooks wp_after_execute_ability).
		$logging_repository = new LoggingRepository();
		$logger             = new Logger( $logging_repository );
		$logger->register_hooks();

		// Relay WP 7.1's wp_ability_invoked onto albert/abilities/invoked. No
		// consumers in Free; this is the seam Premium's activity log binds to.
		( new InvocationRelay() )->register_hooks();

		// Safe mode: hold assistant-initiated destructive (or unannotated) ability
		// calls for a person to approve. Bound to WP 7.1's wp_pre_execute_ability;
		// inert below 7.1, where that filter never fires. Not admin-only — the
		// gate runs on MCP requests.
		$safe_mode_repository = new SafeModeRepository();
		( new SafeModeInterceptor(
			new InterceptorDecision(),
			new SafeModeGate(),
			$safe_mode_repository
		) )->register_hooks();

		// Refuse writes to Albert's own gate-controlling options while a request
		// is driven by an assistant, so the gate cannot be turned off by what it
		// gates. Resource-scoped, so it holds against any ability. Not admin-only.
		( new SafeModeConnectionGuard() )->register_hooks();

		// Daily sweep of never-authorised allowed-user invitations. schedule()
		// is idempotent (guarded by wp_next_scheduled()), so calling it here
		// too — not just from activate() — self-heals sites that already had
		// Albert active before this cron was introduced and never re-activate.
		( new AllowedUserExpiry() )->register_hooks();
		AllowedUserExpiry::schedule();

		// Daily cleanup of expired OAuth token rows. Same self-healing reason.
		( new TokenCleanup() )->register_hooks();
		TokenCleanup::schedule();

		// Safe mode's queue: expire what lapsed, fail claims that never came
		// back, delete decided rows once they are old. Scheduled here as well as
		// in activate() so a site that upgraded into safe mode gets it too.
		( new PendingActionSweep() )->register_hooks();
		PendingActionSweep::schedule();

		// Daily sweep of never-used and idle connections. Same self-healing reason.
		( new ConnectionRetentionSweep() )->register_hooks();
		ConnectionRetentionSweep::schedule();

		// Bridges the domain-specific override filters onto the generic settings
		// chain. Not admin-only: albert_get_setting() reads that chain on MCP
		// and front-end requests too.
		( new SettingsOverrides() )->register_hooks();

		// Register admin components.
		if ( is_admin() ) {
			// Shared admin assets. Registers the design-token stylesheet every
			// Albert screen (and every add-on screen) depends on, before any
			// screen enqueues its own styles.
			( new Assets() )->register_hooks();

			// Page navigation above the screen content. Menu also owns the
			// submenu ordering constants every screen below registers with.
			( new Menu() )->register_hooks();

			// Dashboard page (creates top-level menu and first submenu).
			( new Dashboard( $logging_repository ) )->register_hooks();

			// Unified abilities page (toggle abilities on/off).
			( new AbilitiesPage() )->register_hooks();

			// Skills page (read-only library of the skills Albert and add-ons ship).
			( new SkillsPage() )->register_hooks();

			// Context page (what connected assistants are told about this site).
			( new ContextPage() )->register_hooks();

			// Connections page (allowed users + active sessions).
			( new Connections() )->register_hooks();

			// Safe-mode approvals queue (approve or reject held destructive calls).
			( new Approvals( $safe_mode_repository, new SafeModeApprover( $safe_mode_repository ), null, new SafeModeApprovalPolicy() ) )->register_hooks();

			// Settings page (MCP endpoint, developer options, licenses).
			( new Settings() )->register_hooks();

			// Hands every registered setting to WordPress's register_setting(),
			// so sanitisation and defaults belong to the option rather than to
			// the form. Storage only; Albert still renders every control.
			( new SettingsStorage() )->register_hooks();

			// Addon submenu pages (registered via filter — see Menu for ordering).
			add_action( 'admin_menu', [ $this, 'register_addon_admin_pages' ], Menu::POSITION_ADDONS );
		}

		// Register the abilities REST controller (data + toggles for the admin screen).
		( new AbilitiesController() )->register_hooks();

		// Register the context REST controller (data + instant save for the Context screen).
		( new ContextController() )->register_hooks();

		// Register the skills REST controller (read-only data for the Skills screen).
		( new SkillsController() )->register_hooks();

		// Register the media upload link redemption endpoint (doc 32, Path B).
		( new UploadLinkController() )->register_hooks();

		// Register OAuth controller (REST API endpoints for token exchange).
		( new OAuthController() )->register_hooks();

		// Register OAuth authorization page (HTML-based consent flow).
		( new AuthorizationPage() )->register_hooks();

		// Register OAuth dynamic client registration (RFC 7591).
		( new ClientRegistration() )->register_hooks();

		// Register OAuth discovery endpoint (.well-known/oauth-authorization-server).
		( new OAuthDiscovery() )->register_hooks();

		// Report, in the admin and in Site Health, when the OAuth discovery
		// address is not reachable. Some hosts serve /.well-known/ themselves, so
		// assistant sign-in fails with no sign of why from inside WordPress.
		( new DiscoveryHealth() )->register_hooks();

		// Register MCP server (uses OAuth for authentication).
		( new McpServer() )->register_hooks();

		// Report, in the admin and in Site Health, when the MCP endpoint cannot
		// work at all. Its failure mode is a 401 that looks exactly like an
		// authentication problem, so it has to be stated somewhere else.
		( new McpAdapterHealth() )->register_hooks();

		// Initialize the MCP adapter, but not on admin pages.
		//
		// McpAdapter::instance() hooks the adapter's init() to rest_api_init, which
		// fires mcp_adapter_init — the hook Albert's Server listens on to create its
		// MCP server and register REST routes.
		//
		// On admin pages, WooCommerce preloads REST data (triggering rest_api_init),
		// and the adapter's DefaultServerFactory calls wp_get_ability() for tools that
		// aren't registered yet (wp_abilities_api_init already fired during admin page
		// render). Skipping initialization on admin pages avoids this timing conflict.
		// REST API requests (is_admin() === false) are unaffected.
		if ( class_exists( McpAdapter::class ) && ! is_admin() ) {
			McpAdapter::instance();
		}

		add_action( 'init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Register built-in abilities.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_abilities(): void {
		// Initialize abilities manager.
		$this->abilities_manager = new AbilitiesManager();

		// Posts abilities.
		$this->abilities_manager->add_ability( new FindPosts() );
		$this->abilities_manager->add_ability( new ViewPost() );
		$this->abilities_manager->add_ability( new CreatePost() );
		$this->abilities_manager->add_ability( new UpdatePost() );
		$this->abilities_manager->add_ability( new DeletePost() );

		// Posts: granular per-block edits.
		$this->abilities_manager->add_ability( new EditPostBlock() );
		$this->abilities_manager->add_ability( new AddPostBlock() );
		$this->abilities_manager->add_ability( new RemovePostBlock() );
		$this->abilities_manager->add_ability( new MovePostBlock() );

		// Pages abilities.
		$this->abilities_manager->add_ability( new FindPages() );
		$this->abilities_manager->add_ability( new ViewPage() );
		$this->abilities_manager->add_ability( new CreatePage() );
		$this->abilities_manager->add_ability( new UpdatePage() );
		$this->abilities_manager->add_ability( new DeletePage() );

		// Pages: granular per-block edits.
		$this->abilities_manager->add_ability( new EditPageBlock() );
		$this->abilities_manager->add_ability( new AddPageBlock() );
		$this->abilities_manager->add_ability( new RemovePageBlock() );
		$this->abilities_manager->add_ability( new MovePageBlock() );

		// Users abilities.
		$this->abilities_manager->add_ability( new FindUsers() );
		$this->abilities_manager->add_ability( new ViewUser() );
		$this->abilities_manager->add_ability( new CreateUser() );
		$this->abilities_manager->add_ability( new UpdateUser() );
		$this->abilities_manager->add_ability( new DeleteUser() );

		// Media abilities.
		$this->abilities_manager->add_ability( new FindMedia() );
		$this->abilities_manager->add_ability( new ViewMedia() );
		$this->abilities_manager->add_ability( new UploadMedia() );
		$this->abilities_manager->add_ability( new CreateUploadLink() );
		$this->abilities_manager->add_ability( new SetFeaturedImage() );

		// Taxonomy abilities.
		$this->abilities_manager->add_ability( new FindTaxonomies() );
		$this->abilities_manager->add_ability( new FindTerms() );
		$this->abilities_manager->add_ability( new ViewTerm() );
		$this->abilities_manager->add_ability( new CreateTerm() );
		$this->abilities_manager->add_ability( new UpdateTerm() );
		$this->abilities_manager->add_ability( new DeleteTerm() );

		// Block abilities (block type discovery).
		$this->abilities_manager->add_ability( new ListBlockTypes() );
		$this->abilities_manager->add_ability( new GetBlockType() );

		// Block pattern abilities (read).
		$this->abilities_manager->add_ability( new FindPatterns() );
		$this->abilities_manager->add_ability( new ViewPattern() );
		$this->abilities_manager->add_ability( new CreatePattern() );
		$this->abilities_manager->add_ability( new UpdatePattern() );
		$this->abilities_manager->add_ability( new DeletePattern() );

		// Returns the full text of one task guide, by slug.
		$this->abilities_manager->add_ability( new GetSkill() );

		// WooCommerce abilities (only when WooCommerce is active).
		if ( class_exists( 'WooCommerce' ) ) {
			$this->abilities_manager->add_ability( new FindProducts() );
			$this->abilities_manager->add_ability( new ViewProduct() );
			$this->abilities_manager->add_ability( new FindOrders() );
			$this->abilities_manager->add_ability( new ViewOrder() );
			$this->abilities_manager->add_ability( new FindCustomers() );
			$this->abilities_manager->add_ability( new ViewCustomer() );
		}

		/**
		 * Fires after built-in abilities are registered.
		 *
		 * Addon plugins hook here to register their own abilities by calling
		 * $manager->add_ability() with a BaseAbility subclass.
		 *
		 * @since 1.1.0
		 *
		 * @param AbilitiesManager $manager The abilities manager instance.
		 */
		do_action( 'albert/abilities/register', $this->abilities_manager );

		// Register abilities manager hooks.
		$this->abilities_manager->register_hooks();
	}

	/**
	 * Get the abilities manager instance.
	 *
	 * Returns null until built-in abilities have been registered on the `init`
	 * hook (see {@see self::register_abilities()}). Callers that run during
	 * admin page render — well after `init` — can rely on the manager being
	 * populated, and use it to resolve ability labels without touching the
	 * WordPress Abilities API.
	 *
	 * @return AbilitiesManager|null The abilities manager, or null if not yet built.
	 * @since 1.2.0
	 */
	public function get_abilities_manager(): ?AbilitiesManager {
		return $this->abilities_manager;
	}

	/**
	 * Register addon admin submenu pages.
	 *
	 * Addon plugins can add pages to the Albert admin menu via the
	 * 'albert_admin_submenu_pages' filter. Each page definition must
	 * include a 'slug' and a callable 'callback'.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function register_addon_admin_pages(): void {
		/**
		 * Filters the list of addon admin submenu page definitions.
		 *
		 * @since 1.1.0
		 *
		 * @param array[] $pages Array of page definitions. Each should have:
		 *                       - string   'slug'       Page slug (required).
		 *                       - callable 'callback'   Render callback (required).
		 *                       - string   'page_title' Browser/page title (optional).
		 *                       - string   'menu_title' Sidebar menu title (optional).
		 *                       - string   'capability' Required capability (optional, default 'manage_options').
		 *                       - int      'position'   Menu position (optional, default 100).
		 */
		$pages = apply_filters( 'albert/admin/submenu_pages', [] );

		if ( ! is_array( $pages ) || empty( $pages ) ) {
			return;
		}

		// Validate and set defaults.
		$valid_pages = [];
		foreach ( $pages as $page ) {
			if ( empty( $page['slug'] ) || ! is_callable( $page['callback'] ?? null ) ) {
				continue;
			}

			$page['position'] = (int) ( $page['position'] ?? 100 );
			$valid_pages[]    = $page;
		}

		// Sort by position.
		usort(
			$valid_pages,
			function ( $a, $b ) {
				return $a['position'] <=> $b['position'];
			}
		);

		foreach ( $valid_pages as $page ) {
			add_submenu_page(
				'albert',
				$page['page_title'] ?? $page['slug'],
				$page['menu_title'] ?? $page['slug'],
				$page['capability'] ?? 'manage_options',
				$page['slug'],
				$page['callback']
			);
		}
	}

	/**
	 * Run one-time cleanup of legacy options when the plugin upgrades.
	 *
	 * Tracks the last-seen plugin version in the `albert_installed_version`
	 * option. When the stored version is lower than the current
	 * {@see ALBERT_VERSION} constant, removes options that no longer drive
	 * any behaviour:
	 *
	 *  - `albert_external_url` — replaced by the `albert/mcp/external_url` filter.
	 *
	 * The stored version is bumped after cleanup so the block only runs once
	 * per upgrade.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function maybe_cleanup_legacy_options(): void {
		if ( ! defined( 'ALBERT_VERSION' ) ) {
			return;
		}

		$current_version = (string) ALBERT_VERSION;
		$stored_version  = (string) get_option( 'albert_installed_version', '0.0.0' );

		if ( version_compare( $stored_version, $current_version, '>=' ) ) {
			return;
		}

		// Legacy options removed in 1.1.0 — delete unconditionally, `delete_option()`
		// is a no-op if the option doesn't exist.
		if ( version_compare( $stored_version, '1.1.0', '<' ) ) {
			delete_option( 'albert_external_url' );
		}

		// The per-context schema-version options were superseded by the unified
		// albert_db_version when the Database installer was centralised in 1.2.0.
		if ( version_compare( $stored_version, '1.2.0', '<' ) ) {
			delete_option( 'albert_logging_db_version' );
			delete_option( 'albert_oauth_db_version' );
		}

		update_option( 'albert_installed_version', $current_version, false );
	}

	/**
	 * Default a genuinely new install to Strict privacy, without touching a
	 * site that has run this plugin before.
	 *
	 * `albert_installed_version` is only ever absent the very first time this
	 * plugin activates on a site — {@see self::maybe_cleanup_legacy_options()}
	 * writes it on every subsequent request. That makes it the one reliable
	 * "has this site ever run Albert before" signal available at activation
	 * time; the privacy option's own absence is not, since a pre-1.3.0 site
	 * that has simply never opened Settings would look identical to a new
	 * install if that were the only check. See docs/features/70-admin-design-system.md
	 * §4: "Never change the behaviour of an installed site silently."
	 *
	 * The registered default stays `balanced`, which is not a contradiction:
	 * that is what a site with nothing stored falls back to, and the sites with
	 * nothing stored are precisely the ones that predate the option. Writing a
	 * value here is what makes Strict a *new install's* default without
	 * reaching back and changing what an existing one does.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	private static function maybe_set_new_install_privacy_default(): void {
		$is_new_install = get_option( 'albert_installed_version', false ) === false;

		if ( $is_new_install && get_option( 'albert_privacy_mode', false ) === false ) {
			update_option( 'albert_privacy_mode', PrivacyMode::Strict->value );
		}
	}

	/**
	 * Plugin activation hook callback.
	 *
	 * Runs when the plugin is activated.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function activate(): void {
		self::maybe_set_new_install_privacy_default();

		// maybe_upgrade(), not install(): install() stamps the schema version
		// without running the version-keyed data migrations, so activating
		// across a version boundary — deactivate, update the files, reactivate
		// — recorded the site as up to date and skipped them permanently.
		DatabaseInstaller::maybe_upgrade();

		// Register OAuth discovery rewrite rules.
		OAuthDiscovery::activate();

		// Schedule the daily invitation-expiry sweep.
		AllowedUserExpiry::schedule();

		// Schedule the daily expired-token cleanup.
		TokenCleanup::schedule();

		// Schedule the daily safe-mode pending-actions sweep.
		PendingActionSweep::schedule();

		// Schedule the daily never-used/idle connection sweep.
		ConnectionRetentionSweep::schedule();

		/**
		 * Fires when the plugin is activated.
		 *
		 * @since 1.0.0
		 */
		do_action( 'albert/activated' );
	}

	/**
	 * Plugin deactivation hook callback.
	 *
	 * Runs when the plugin is deactivated.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function deactivate(): void {
		// Clean up OAuth discovery rewrite rules.
		OAuthDiscovery::deactivate();

		// Unschedule the daily invitation-expiry sweep.
		AllowedUserExpiry::unschedule();

		// Unschedule the daily expired-token cleanup.
		TokenCleanup::unschedule();
		PendingActionSweep::unschedule();

		// Unschedule the daily never-used/idle connection sweep.
		ConnectionRetentionSweep::unschedule();

		/**
		 * Fires when the plugin is deactivated.
		 *
		 * @since 1.0.0
		 */
		do_action( 'albert/deactivated' );
	}
}
