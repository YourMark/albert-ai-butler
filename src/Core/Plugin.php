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
use Albert\Abilities\WordPress\Pages\FindPages;
use Albert\Abilities\WordPress\Pages\ViewPage;
use Albert\Abilities\WordPress\Pages\Create as CreatePage;
use Albert\Abilities\WordPress\Pages\Update as UpdatePage;
use Albert\Abilities\WordPress\Pages\Delete as DeletePage;
use Albert\Abilities\WordPress\Users\FindUsers;
use Albert\Abilities\WordPress\Users\ViewUser;
use Albert\Abilities\WordPress\Users\Create as CreateUser;
use Albert\Abilities\WordPress\Users\Update as UpdateUser;
use Albert\Abilities\WordPress\Users\Delete as DeleteUser;
use Albert\Abilities\WordPress\Media\FindMedia;
use Albert\Abilities\WordPress\Media\ViewMedia;
use Albert\Abilities\WordPress\Media\SetFeaturedImage;
use Albert\Abilities\WordPress\Media\UploadMedia;
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
use Albert\Admin\AcfAbilities;
use Albert\Admin\CoreAbilities;
use Albert\Admin\Connections;
use Albert\Admin\WooCommerceAbilities;
use Albert\Admin\Dashboard;
use Albert\Admin\Settings;
use Albert\Contracts\Interfaces\Hookable;
use Albert\MCP\Server as McpServer;
use Albert\OAuth\Database\Installer as OAuthInstaller;
use Albert\Core\SettingsMigration;
use Albert\OAuth\Endpoints\AuthorizationPage;
use Albert\OAuth\Endpoints\ClientRegistration;
use Albert\OAuth\Endpoints\OAuthController;
use Albert\OAuth\Endpoints\OAuthDiscovery;
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
		if ( null === self::$instance ) {
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
		// Check for database updates (handles upgrades without re-activation).
		OAuthInstaller::install();

		// Migrate settings from old format to new format (one-time).
		SettingsMigration::maybe_migrate();

		// Register admin components.
		if ( is_admin() ) {
			// Dashboard page (creates top-level menu and first submenu).
			( new Dashboard() )->register_hooks();

			// Abilities pages (toggle abilities on/off).
			( new CoreAbilities() )->register_hooks();

			if ( class_exists( 'ACF' ) ) {
				( new AcfAbilities() )->register_hooks();
			}

			if ( class_exists( 'WooCommerce' ) ) {
				( new WooCommerceAbilities() )->register_hooks();
			}

			// Connections page (allowed users + active sessions).
			( new Connections() )->register_hooks();

			// Settings page (MCP endpoint, developer options).
			( new Settings() )->register_hooks();
		}

		// Register OAuth controller (REST API endpoints for token exchange).
		( new OAuthController() )->register_hooks();

		// Register OAuth authorization page (HTML-based consent flow).
		( new AuthorizationPage() )->register_hooks();

		// Register OAuth dynamic client registration (RFC 7591).
		( new ClientRegistration() )->register_hooks();

		// Register OAuth discovery endpoint (.well-known/oauth-authorization-server).
		( new OAuthDiscovery() )->register_hooks();

		// Register MCP server (uses OAuth for authentication).
		( new McpServer() )->register_hooks();

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

		// Pages abilities.
		$this->abilities_manager->add_ability( new FindPages() );
		$this->abilities_manager->add_ability( new ViewPage() );
		$this->abilities_manager->add_ability( new CreatePage() );
		$this->abilities_manager->add_ability( new UpdatePage() );
		$this->abilities_manager->add_ability( new DeletePage() );

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
		$this->abilities_manager->add_ability( new SetFeaturedImage() );

		// Taxonomy abilities.
		$this->abilities_manager->add_ability( new FindTaxonomies() );
		$this->abilities_manager->add_ability( new FindTerms() );
		$this->abilities_manager->add_ability( new ViewTerm() );
		$this->abilities_manager->add_ability( new CreateTerm() );
		$this->abilities_manager->add_ability( new UpdateTerm() );
		$this->abilities_manager->add_ability( new DeleteTerm() );

		// WooCommerce abilities (only when WooCommerce is active).
		if ( class_exists( 'WooCommerce' ) ) {
			$this->abilities_manager->add_ability( new FindProducts() );
			$this->abilities_manager->add_ability( new ViewProduct() );
			$this->abilities_manager->add_ability( new FindOrders() );
			$this->abilities_manager->add_ability( new ViewOrder() );
			$this->abilities_manager->add_ability( new FindCustomers() );
			$this->abilities_manager->add_ability( new ViewCustomer() );
		}

		// Register abilities manager hooks.
		$this->abilities_manager->register_hooks();
	}

	/**
	 * Get the abilities manager instance.
	 *
	 * @return AbilitiesManager|null The abilities manager instance.
	 * @since 1.0.0
	 */
	public function get_abilities_manager(): ?AbilitiesManager {
		return $this->abilities_manager;
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
		// Install OAuth database tables.
		OAuthInstaller::install();

		// Register OAuth discovery rewrite rules.
		OAuthDiscovery::activate();

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

		/**
		 * Fires when the plugin is deactivated.
		 *
		 * @since 1.0.0
		 */
		do_action( 'albert/deactivated' );
	}
}
