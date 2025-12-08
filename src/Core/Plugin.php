<?php
/**
 * Main Plugin Class
 *
 * @package    ExtendedAbilities
 * @subpackage Core
 * @since      1.0.0
 */

namespace ExtendedAbilities\Core;

use ExtendedAbilities\Abilities\WordPress\ListPostsAbility;
use ExtendedAbilities\Abilities\WordPress\CreatePostAbility;
use ExtendedAbilities\Abilities\WordPress\UpdatePostAbility;
use ExtendedAbilities\Abilities\WordPress\DeletePostAbility;
use ExtendedAbilities\Abilities\WordPress\ListPagesAbility;
use ExtendedAbilities\Abilities\WordPress\CreatePageAbility;
use ExtendedAbilities\Abilities\WordPress\UpdatePageAbility;
use ExtendedAbilities\Abilities\WordPress\DeletePageAbility;
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
	 * Private constructor to prevent direct instantiation.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Private constructor for singleton pattern.
	}

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
			 * @ToDo: If the class does not exist, for some reason, we need to handle this gracefully.
			 */
			return;
		}

		// Initialize the adapter.
		McpAdapter::instance();

		// Initialize abilities manager.
		$this->abilities_manager = new AbilitiesManager();

		// Add abilities to the manager.
		$this->abilities_manager->add_ability( new ListPostsAbility() );
		$this->abilities_manager->add_ability( new CreatePostAbility() );
		$this->abilities_manager->add_ability( new UpdatePostAbility() );
		$this->abilities_manager->add_ability( new DeletePostAbility() );
		$this->abilities_manager->add_ability( new ListPagesAbility() );
		$this->abilities_manager->add_ability( new CreatePageAbility() );
		$this->abilities_manager->add_ability( new UpdatePageAbility() );
		$this->abilities_manager->add_ability( new DeletePageAbility() );

		// Register abilities manager hooks.
		$this->abilities_manager->register_hooks();
	}

	/**
	 * Load plugin text domain for translations.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'extended-abilities',
			false,
			dirname( plugin_basename( EXTENDED_ABILITIES_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Register all plugin components.
	 *
	 * Components are classes that implement the Hookable interface
	 * and provide specific functionality for the plugin.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_components(): void {
		// Register admin components only in admin area.
		if ( is_admin() ) {
			$settings = new Settings();
			$settings->register_hooks();
		}

		/**
		 * Allows additional components to be registered.
		 *
		 * @param Plugin $plugin The plugin instance.
		 *
		 * @since 1.0.0
		 */
		do_action( 'extended_abilities_register_components', $this );
	}

	/**
	 * Register built-in abilities.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function register_abilities(): void {
		// Register WordPress abilities.
		$this->abilities_manager->add_ability( new CreatePostAbility() );

		/**
		 * Allows additional abilities to be registered.
		 *
		 * @param AbilitiesManager $abilities_manager The abilities manager instance.
		 *
		 * @since 1.0.0
		 */
		do_action( 'extended_abilities_register_abilities', $this->abilities_manager );
	}

	/**
	 * Add a component to the plugin.
	 *
	 * @param Hookable $component The component to add.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_component( Hookable $component ): void {
		$this->components[] = $component;
	}

	/**
	 * Register hooks for all registered components.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_component_hooks(): void {
		foreach ( $this->components as $component ) {
			$component->register_hooks();
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

	/**
	 * Prevent cloning of the instance.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function __clone() {
		// Prevent cloning.
	}

	/**
	 * Prevent unserialization of the instance.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __wakeup() {
		// Prevent unserialization.
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}
