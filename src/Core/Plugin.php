<?php
/**
 * Main Plugin Class
 *
 * @package Albert
 * @subpackage Core
 * @since      1.0.0
 */

namespace Albert\Core;

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
use Albert\Admin\Abilities;
use Albert\Admin\Connections;
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
	 * Array of registered hookable components.
	 *
	 * @since 1.0.0
	 * @var Hookable[]
	 */
	private array $components = [];

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
			$dashboard = new Dashboard();
			$dashboard->register_hooks();

			// Abilities page (creates top-level menu at position 80).
			$abilities = new Abilities();
			$abilities->register_hooks();

			// Connections page (adds submenu under Albert).
			$connections = new Connections();
			$connections->register_hooks();

			// Settings page (adds submenu under Albert).
			$settings = new Settings();
			$settings->register_hooks();
		}

		// Register OAuth controller (REST API endpoints for token exchange).
		$oauth_controller = new OAuthController();
		$oauth_controller->register_hooks();

		// Register OAuth authorization page (HTML-based consent flow).
		$authorization_page = new AuthorizationPage();
		$authorization_page->register_hooks();

		// Register OAuth dynamic client registration (RFC 7591).
		$client_registration = new ClientRegistration();
		$client_registration->register_hooks();

		// Register OAuth discovery endpoint (.well-known/oauth-authorization-server).
		$oauth_discovery = new OAuthDiscovery();
		$oauth_discovery->register_hooks();

		// Register MCP server (uses OAuth for authentication).
		$mcp_server = new McpServer();
		$mcp_server->register_hooks();

		// Try and run the McpAdapter. Without this, it's useless.
		if ( ! class_exists( McpAdapter::class ) ) {
			/**
			 * Handle missing McpAdapter gracefully.
			 *
			 * @todo If the class does not exist, for some reason, we need to handle this gracefully.
			 */
			return;
		}

		// Initialize the adapter.
		McpAdapter::instance();

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
