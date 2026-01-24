<?php
/**
 * Main Plugin Class
 *
 * @package    AIBridge
 * @subpackage Core
 * @since      1.0.0
 */

namespace AIBridge\Core;

use AIBridge\Abilities\WordPress\Posts\ListPosts;
use AIBridge\Abilities\WordPress\Posts\Create as CreatePost;
use AIBridge\Abilities\WordPress\Posts\Update as UpdatePost;
use AIBridge\Abilities\WordPress\Posts\Delete as DeletePost;
use AIBridge\Abilities\WordPress\Pages\ListPages;
use AIBridge\Abilities\WordPress\Pages\Create as CreatePage;
use AIBridge\Abilities\WordPress\Pages\Update as UpdatePage;
use AIBridge\Abilities\WordPress\Pages\Delete as DeletePage;
use AIBridge\Abilities\WordPress\Users\ListUsers;
use AIBridge\Abilities\WordPress\Users\Create as CreateUser;
use AIBridge\Abilities\WordPress\Users\Update as UpdateUser;
use AIBridge\Abilities\WordPress\Users\Delete as DeleteUser;
use AIBridge\Abilities\WordPress\Media\SetFeaturedImage;
use AIBridge\Abilities\WordPress\Media\UploadMedia;
use AIBridge\Abilities\WordPress\Taxonomies\ListTaxonomies;
use AIBridge\Abilities\WordPress\Taxonomies\ListTerms;
use AIBridge\Abilities\WordPress\Taxonomies\CreateTerm;
use AIBridge\Abilities\WordPress\Taxonomies\UpdateTerm;
use AIBridge\Abilities\WordPress\Taxonomies\DeleteTerm;
use AIBridge\Admin\Abilities;
use AIBridge\Admin\Settings;
use AIBridge\Admin\UserSessions;
use AIBridge\Contracts\Interfaces\Hookable;
use AIBridge\MCP\Server as McpServer;
use AIBridge\OAuth\Database\Installer as OAuthInstaller;
use AIBridge\OAuth\Endpoints\AuthorizationPage;
use AIBridge\OAuth\Endpoints\ClientRegistration;
use AIBridge\OAuth\Endpoints\OAuthController;
use AIBridge\OAuth\Endpoints\OAuthDiscovery;
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

		// Register admin components.
		if ( is_admin() ) {
			// Abilities page (creates top-level menu).
			$abilities = new Abilities();
			$abilities->register_hooks();

			// Settings page (adds submenu under Abilities).
			$settings = new Settings();
			$settings->register_hooks();

			// User sessions page (dashboard submenu for users).
			$user_sessions = new UserSessions();
			$user_sessions->register_hooks();
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
		$this->abilities_manager->add_ability( new ListPosts() );
		$this->abilities_manager->add_ability( new CreatePost() );
		$this->abilities_manager->add_ability( new UpdatePost() );
		$this->abilities_manager->add_ability( new DeletePost() );

		// Pages abilities.
		$this->abilities_manager->add_ability( new ListPages() );
		$this->abilities_manager->add_ability( new CreatePage() );
		$this->abilities_manager->add_ability( new UpdatePage() );
		$this->abilities_manager->add_ability( new DeletePage() );

		// Users abilities.
		$this->abilities_manager->add_ability( new ListUsers() );
		$this->abilities_manager->add_ability( new CreateUser() );
		$this->abilities_manager->add_ability( new UpdateUser() );
		$this->abilities_manager->add_ability( new DeleteUser() );

		// Media abilities.
		$this->abilities_manager->add_ability( new UploadMedia() );
		$this->abilities_manager->add_ability( new SetFeaturedImage() );

		// Taxonomy abilities.
		$this->abilities_manager->add_ability( new ListTaxonomies() );
		$this->abilities_manager->add_ability( new ListTerms() );
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
		do_action( 'aibridge/core/activated' );
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
		do_action( 'aibridge/core/deactivated' );
	}
}
