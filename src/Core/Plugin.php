<?php
/**
 * Main Plugin Class
 *
 * @package    ExtendedAbilities
 * @subpackage Core
 * @since      1.0.0
 */

namespace ExtendedAbilities\Core;

use ExtendedAbilities\Abilities\WordPress\Posts\ListPosts;
use ExtendedAbilities\Abilities\WordPress\Posts\Create as CreatePost;
use ExtendedAbilities\Abilities\WordPress\Posts\Update as UpdatePost;
use ExtendedAbilities\Abilities\WordPress\Posts\Delete as DeletePost;
use ExtendedAbilities\Abilities\WordPress\Pages\ListPages;
use ExtendedAbilities\Abilities\WordPress\Pages\Create as CreatePage;
use ExtendedAbilities\Abilities\WordPress\Pages\Update as UpdatePage;
use ExtendedAbilities\Abilities\WordPress\Pages\Delete as DeletePage;
use ExtendedAbilities\Abilities\WordPress\Users\ListUsers;
use ExtendedAbilities\Abilities\WordPress\Users\Create as CreateUser;
use ExtendedAbilities\Abilities\WordPress\Users\Update as UpdateUser;
use ExtendedAbilities\Abilities\WordPress\Users\Delete as DeleteUser;
use ExtendedAbilities\Abilities\WordPress\Media\UploadMedia;
use ExtendedAbilities\Abilities\WordPress\Media\SetFeaturedImage;
use ExtendedAbilities\Abilities\WordPress\Taxonomies\ListTaxonomies;
use ExtendedAbilities\Abilities\WordPress\Taxonomies\ListTerms;
use ExtendedAbilities\Abilities\WordPress\Taxonomies\CreateTerm;
use ExtendedAbilities\Abilities\WordPress\Taxonomies\UpdateTerm;
use ExtendedAbilities\Abilities\WordPress\Taxonomies\DeleteTerm;
use ExtendedAbilities\Admin\Settings;
use ExtendedAbilities\Contracts\Interfaces\Hookable;
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
		// Register admin components.
		if ( is_admin() ) {
			$settings = new Settings();
			$settings->register_hooks();
		}

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
		// Set default options if they don't exist.
		// Flush rewrite rules if needed.
		// Create custom database tables if needed.

		/**
		 * Fires when the plugin is activated.
		 *
		 * @since 1.0.0
		 */
		do_action( 'extended_abilities_activated' );
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
		// Flush rewrite rules if needed.
		// Clean up temporary data if needed.

		/**
		 * Fires when the plugin is deactivated.
		 *
		 * @since 1.0.0
		 */
		do_action( 'extended_abilities_deactivated' );
	}
}
