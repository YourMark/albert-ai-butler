<?php
/**
 * Site Info Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Site
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Site;

use Albert\Abstracts\BaseAbility;
use WP_Error;
use WP_REST_Request;

/**
 * Site Info Ability class
 *
 * Allows AI assistants to retrieve site information.
 *
 * @since 1.0.0
 */
class Info extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'core/site-info';
		$this->label       = __( 'Site Info', 'ai-bridge' );
		$this->description = __( 'Retrieve WordPress site information and settings.', 'ai-bridge' );
		$this->category    = 'core';
		$this->group       = 'site';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp' => [
				'public' => true,
			],
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.0.0
	 */
	private function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.0.0
	 */
	private function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'site' => [
					'type'        => 'object',
					'description' => 'Site information object.',
				],
			],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * @return bool True if user can execute, false otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): bool {
		return current_user_can( 'read' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		$theme = wp_get_theme();

		return [
			'site' => [
				'name'              => get_bloginfo( 'name' ),
				'description'       => get_bloginfo( 'description' ),
				'url'               => get_bloginfo( 'url' ),
				'admin_email'       => get_bloginfo( 'admin_email' ),
				'language'          => get_bloginfo( 'language' ),
				'timezone'          => wp_timezone_string(),
				'wordpress_version' => get_bloginfo( 'version' ),
				'theme'             => [
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
				],
				'active_plugins'    => count( get_option( 'active_plugins', [] ) ),
				'multisite'         => is_multisite(),
			],
		];
	}
}
