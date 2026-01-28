<?php
/**
 * Base Ability Abstract Class
 *
 * @package Albert
 * @subpackage Abstracts
 * @since      1.0.0
 */

namespace Albert\Abstracts;

use Albert\Contracts\Interfaces\Ability;
use Albert\Core\AbilitiesRegistry;
use WP_Error;
use WP_REST_Request;

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
	 * Always registers so the ability appears in the admin UI.
	 * The enabled check is performed at execution time, not registration time.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			$this->id,
			[
				'label'               => $this->label,
				'description'         => $this->description,
				'input_schema'        => $this->input_schema,
				'output_schema'       => $this->output_schema,
				'category'            => $this->category ?? 'albert',
				'execute_callback'    => [ $this, 'guarded_execute' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'meta'                => $this->meta,
			]
		);
	}

	/**
	 * Execute the ability with an enabled check.
	 *
	 * Wraps the actual execute() call with a check against the blocklist.
	 * This allows all abilities to be registered (visible in admin UI)
	 * while preventing disabled abilities from being executed.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 *
	 * @return array<string, mixed>|WP_Error The result or error if disabled.
	 * @since 1.1.0
	 */
	public function guarded_execute( array $args ): array|WP_Error {
		if ( ! $this->enabled() ) {
			return new WP_Error(
				'ability_disabled',
				sprintf(
					/* translators: %s: ability name */
					__( 'The ability "%s" is currently disabled.', 'albert' ),
					$this->label
				),
				[ 'status' => 403 ]
			);
		}

		return $this->execute( $args );
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
	 * Returns a WP_Error with a descriptive message on failure so AI
	 * clients can communicate the reason to the user.
	 *
	 * @return true|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): true|WP_Error {
		return $this->require_capability( 'manage_options' );
	}

	/**
	 * Require that the current user has a specific capability.
	 *
	 * Returns true on success or a descriptive WP_Error on failure,
	 * so AI clients understand why access was denied.
	 *
	 * @param string $capability The capability to check.
	 *
	 * @return true|WP_Error True if the user has the capability, WP_Error otherwise.
	 * @since 1.1.0
	 */
	protected function require_capability( string $capability ): true|WP_Error {
		if ( current_user_can( $capability ) ) {
			return true;
		}

		return new WP_Error(
			'ability_permission_denied',
			sprintf(
				/* translators: 1: ability label, 2: capability name */
				__( 'You do not have permission to use "%1$s". The "%2$s" capability is required.', 'albert' ),
				$this->label,
				$capability
			),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Check permission via a WordPress REST API endpoint's permission callback.
	 *
	 * Delegates to the REST API's own permission callback for the given route
	 * and HTTP method. If the REST API returns a WP_Error, it is passed through
	 * so the AI client receives the specific reason. Falls back to a capability
	 * check when the route is not found.
	 *
	 * @param string $route        The REST API route (exact) or route pattern (regex).
	 * @param string $method       The HTTP method (GET, POST, DELETE, etc.).
	 * @param string $fallback_cap The capability to check if the route is unavailable.
	 *
	 * @return true|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.1.0
	 */
	protected function check_rest_permission( string $route, string $method, string $fallback_cap ): true|WP_Error {
		$server     = rest_get_server();
		$routes     = $server->get_routes();
		$is_pattern = str_contains( $route, '(?P<' );

		if ( $is_pattern ) {
			foreach ( $routes as $registered_route => $endpoints ) {
				if ( ! preg_match( '#^' . $route . '$#', $registered_route ) ) {
					continue;
				}

				return $this->check_rest_endpoints( $endpoints, $method, $registered_route, $fallback_cap );
			}
		} elseif ( isset( $routes[ $route ] ) ) {
			return $this->check_rest_endpoints( $routes[ $route ], $method, $route, $fallback_cap );
		}

		// Route not found, fall back to capability check.
		return $this->require_capability( $fallback_cap );
	}

	/**
	 * Check permission against a set of REST API endpoints.
	 *
	 * @param array<int, array<string, mixed>> $endpoints    REST API endpoint definitions.
	 * @param string                           $method       The HTTP method to match.
	 * @param string                           $route        The resolved route path.
	 * @param string                           $fallback_cap The capability to check on failure.
	 *
	 * @return true|WP_Error True if permitted, WP_Error otherwise.
	 * @since 1.1.0
	 */
	private function check_rest_endpoints( array $endpoints, string $method, string $route, string $fallback_cap ): true|WP_Error {
		foreach ( $endpoints as $endpoint ) {
			if ( ! isset( $endpoint['methods'][ $method ], $endpoint['permission_callback'] ) ) {
				continue;
			}

			if ( ! is_callable( $endpoint['permission_callback'] ) ) {
				continue;
			}

			$request = new WP_REST_Request( $method, $route );
			$result  = call_user_func( $endpoint['permission_callback'], $request );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $result ) {
				return true;
			}

			return $this->require_capability( $fallback_cap );
		}

		// No matching endpoint found, fall back to capability check.
		return $this->require_capability( $fallback_cap );
	}

	/**
	 * Check if this ability is enabled.
	 *
	 * Uses a blocklist approach: all abilities are enabled unless explicitly disabled.
	 * On fresh install (before first save), Albert write abilities are disabled by default.
	 *
	 * @return bool True if enabled, false otherwise.
	 * @since 1.0.0
	 */
	public function enabled(): bool {
		$disabled = get_option( 'albert_disabled_abilities', [] );

		// On fresh install, use default disabled list (Albert write abilities).
		if ( empty( $disabled ) && ! get_option( 'albert_abilities_saved' ) ) {
			$disabled = AbilitiesRegistry::get_default_disabled_abilities();
		}

		return ! in_array( $this->id, (array) $disabled, true );
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
