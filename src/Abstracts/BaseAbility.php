<?php
/**
 * Base Ability Abstract Class
 *
 * @package Albert
 * @subpackage Abstracts
 * @since      1.0.0
 */

namespace Albert\Abstracts;

use Albert\Admin\Abilities;
use Albert\Contracts\Interfaces\Ability;
use Albert\Core\AbilitiesRegistry;
use WP_Error;

/**
 * Base Ability class
 *
 * Abstract class that all abilities should extend.
 * Provides common functionality and enforces implementation of required methods.
 *
 * @since 1.0.0
 */
abstract class BaseAbility implements Ability {
	/**
	 * Ability unique identifier (e.g., 'wordpress/create-post').
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected string $id;

	/**
	 * Ability name/label.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected string $label;

	/**
	 * Ability description.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected string $description;

	/**
	 * Ability category.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected string $category = '';

	/**
	 * Ability group (optional).
	 * Used to group related abilities together in the settings UI.
	 * Example: 'posts', 'media', 'users'
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected string $group = '';

	/**
	 * Input JSON schema.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>
	 */
	protected array $input_schema = [];

	/**
	 * Output JSON schema.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>
	 */
	protected array $output_schema = [];

	/**
	 * Additional metadata.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>
	 */
	protected array $meta = [];

	/**
	 * Constructor.
	 *
	 * Child classes should call parent::__construct() after setting their properties.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// Properties should be set by child class before calling parent constructor.
		// This allows for validation or post-initialization logic here if needed.
	}

	/**
	 * Register the ability with WordPress.
	 *
	 * This is called automatically if the ability is enabled.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_ability(): void {
		// Only register if function exists and ability is enabled.
		if ( ! function_exists( 'wp_register_ability' ) || ! $this->enabled() ) {
			return;
		}

		wp_register_ability(
			$this->id,
			[
				'label'               => $this->label,
				'description'         => $this->description,
				'input_schema'        => $this->input_schema,
				'output_schema'       => $this->output_schema,
				'category'            => $this->category ?? 'core',
				'execute_callback'    => [ $this, 'execute' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'meta'                => $this->meta,
			]
		);
	}

	/**
	 * Execute the ability.
	 *
	 * Must be implemented by child classes.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 *
	 * @return array<string, mixed>|WP_Error The result of the ability execution.
	 * @since 1.0.0
	 */
	abstract public function execute( array $args ): array|WP_Error;

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * Child classes should override this with specific permission logic.
	 *
	 * @return bool Whether user has permission.
	 * @since 1.0.0
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Check if ability is enabled.
	 *
	 * Checks the aibridge_options option to see if this ability is enabled.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	/**
	 * Check if this ability is enabled.
	 *
	 * Checks if the ability is enabled based on the permission groups system.
	 *
	 * @return bool True if enabled, false otherwise.
	 * @since 1.0.0
	 */
	public function enabled(): bool {
		// Get enabled permissions (e.g., ['posts_read', 'posts_write']).
		$enabled_permissions = Abilities::get_enabled_permissions();

		// Convert permissions to individual ability slugs.
		$enabled_abilities = AbilitiesRegistry::get_enabled_abilities( $enabled_permissions );

		// Check if this ability's ID is in the enabled list.
		return in_array( $this->id, $enabled_abilities, true );
	}

	/**
	 * Get the settings key for this ability.
	 *
	 * Returns the ability ID as-is for use as the settings key.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function get_setting_key(): string {
		return $this->id;
	}

	/**
	 * Get ability ID.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get ability label.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Get ability description.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * Get ability category.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_category(): string {
		return $this->category;
	}

	/**
	 * Get ability group.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_group(): string {
		return $this->group;
	}

	/**
	 * Get ability metadata for settings page.
	 *
	 * @return array<string, string>
	 * @since 1.0.0
	 */
	public function get_settings_data(): array {
		return [
			'label'       => $this->label,
			'description' => $this->description,
			'group'       => $this->group,
		];
	}
}
