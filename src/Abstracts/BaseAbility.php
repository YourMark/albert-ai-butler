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
				'input_schema'        => $this->prepare_input_schema( $this->input_schema ),
				'output_schema'       => $this->output_schema,
				'category'            => $this->category !== '' ? $this->category : 'albert',
				'execute_callback'    => [ $this, 'guarded_execute' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'meta'                => $this->meta,
			]
		);
	}

	/**
	 * Prepare the input schema for registration.
	 *
	 * Ensures an object-typed root schema carries a `default` of an empty
	 * object so WP_Ability::normalize_input() can rescue calls that arrive
	 * with a null payload. The mcp-adapter ExecuteAbilityAbility coerces
	 * empty `{}` parameters to null via `empty()` before invoking
	 * `WP_Ability::execute()`; without a root default, validate_input(null)
	 * fails with "input is not of type object" and the assistant sees an
	 * unhelpful error for any tool call made without arguments.
	 *
	 * Child classes that already declare a root `default` keep theirs.
	 *
	 * @param array<string, mixed> $schema Input schema declared by the ability.
	 *
	 * @return array<string, mixed> Schema safe to pass through the registry.
	 * @since 1.1.1
	 */
	protected function prepare_input_schema( array $schema ): array {
		if ( empty( $schema ) ) {
			return $schema;
		}

		if ( ( $schema['type'] ?? null ) === 'object' && ! array_key_exists( 'default', $schema ) ) {
			$schema['default'] = [];
		}

		return $schema;
	}

	/**
	 * Execute the ability with an executability check.
	 *
	 * Wraps the actual execute() call with the is_executable() pipeline.
	 * Disabled and otherwise-gated abilities should already be unregistered
	 * by AbilitiesManager::enforce_disabled() before this point, but the
	 * check is kept here as defence-in-depth — if anything routes to this
	 * callback for a non-executable ability, the assistant gets the
	 * pipeline's reasoned WP_Error instead of an opaque failure.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 *
	 * @return array<string, mixed>|WP_Error The result or error if not executable.
	 * @since 1.0.0
	 */
	public function guarded_execute( array $args ): array|WP_Error {
		$check = $this->is_executable();
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$user_id = get_current_user_id();

		try {
			/**
			 * Fires before any ability is executed.
			 *
			 * @since 1.1.0
			 *
			 * @param string $ability_id Ability identifier.
			 * @param array  $args       Input parameters.
			 * @param int    $user_id    Current user ID.
			 */
			do_action( 'albert/abilities/before_execute', $this->id, $args, $user_id );

			/**
			 * Fires before a specific ability is executed.
			 *
			 * The dynamic portion of the hook name, `$this->id`, refers to the
			 * ability identifier (e.g. 'core/posts/create', 'albert/woo-find-products').
			 *
			 * @since 1.1.0
			 *
			 * @param array $args    Input parameters.
			 * @param int   $user_id Current user ID.
			 */
			do_action( "albert/abilities/before_execute/{$this->id}", $args, $user_id );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Log in debug mode but never break execution.
		}

		$result = $this->execute( $args );

		try {
			/**
			 * Fires after any ability is executed.
			 *
			 * @since 1.1.0
			 *
			 * @param string         $ability_id Ability identifier.
			 * @param array          $args       Input parameters.
			 * @param array|WP_Error $result     Execution result.
			 * @param int            $user_id    Current user ID.
			 */
			do_action( 'albert/abilities/after_execute', $this->id, $args, $result, $user_id );

			/**
			 * Fires after a specific ability is executed.
			 *
			 * The dynamic portion of the hook name, `$this->id`, refers to the
			 * ability identifier (e.g. 'core/posts/create', 'albert/woo-find-products').
			 *
			 * @since 1.1.0
			 *
			 * @param array          $args    Input parameters.
			 * @param array|WP_Error $result  Execution result.
			 * @param int            $user_id Current user ID.
			 */
			do_action( "albert/abilities/after_execute/{$this->id}", $args, $result, $user_id );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Log in debug mode but never break execution.
		}

		return $result;
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
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): bool|WP_Error {
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
	 * @return bool|WP_Error True if the user has the capability, WP_Error otherwise.
	 * @since 1.0.0
	 */
	protected function require_capability( string $capability ): bool|WP_Error {
		if ( current_user_can( $capability ) ) {
			return true;
		}

		return new WP_Error(
			'ability_permission_denied',
			sprintf(
				/* translators: 1: ability label, 2: capability name */
				__( 'You do not have permission to use "%1$s". The "%2$s" capability is required.', 'albert-ai-butler' ),
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
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	protected function check_rest_permission( string $route, string $method, string $fallback_cap ): bool|WP_Error {
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
	 * @return bool|WP_Error True if permitted, WP_Error otherwise.
	 * @since 1.0.0
	 */
	private function check_rest_endpoints( array $endpoints, string $method, string $route, string $fallback_cap ): bool|WP_Error {
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
	 * @since 1.2.0
	 */
	public function is_enabled(): bool {
		$disabled = get_option( 'albert_disabled_abilities', [] );

		// On fresh install, use default disabled list (Albert write abilities).
		if ( empty( $disabled ) && ! get_option( 'albert_abilities_saved' ) ) {
			$disabled = AbilitiesRegistry::get_default_disabled_abilities();
		}

		return ! in_array( $this->id, (array) $disabled, true );
	}

	/**
	 * Check if this ability is enabled.
	 *
	 * @return bool True if enabled, false otherwise.
	 * @since      1.0.0
	 * @deprecated 1.2.0 Use {@see self::is_enabled()} instead.
	 */
	public function enabled(): bool {
		_deprecated_function( __METHOD__, '1.2.0', static::class . '::is_enabled' );

		return $this->is_enabled();
	}

	/**
	 * Determine whether this ability is executable in the current request.
	 *
	 * Runs a chain of global executability checks. The ability is executable
	 * only if every check passes; the first failing check's WP_Error is
	 * returned so callers receive a meaningful reason.
	 *
	 * Used at two seams:
	 *  - Registration phase: AbilitiesManager unregisters Albert-managed
	 *    abilities whose is_executable() returns a WP_Error, so disabled or
	 *    licence-blocked abilities never appear in wp_get_abilities().
	 *  - Execution phase: guarded_execute() short-circuits with the same
	 *    WP_Error as defence-in-depth when an ability somehow gets called
	 *    despite not being registered.
	 *
	 * Per-user gating (capability checks) lives in check_permission() and is
	 * intentionally NOT part of this pipeline — per-user state must never
	 * decide whether an ability is registered globally.
	 *
	 * @return bool|WP_Error True when executable, WP_Error otherwise.
	 * @since 1.2.0
	 */
	public function is_executable(): bool|WP_Error {
		if ( ! $this->is_enabled() ) {
			return new WP_Error(
				'ability_disabled',
				sprintf(
					/* translators: %s: ability name */
					__( 'The ability "%s" is currently disabled.', 'albert-ai-butler' ),
					$this->label
				),
				[ 'status' => 403 ]
			);
		}

		/**
		 * Filters whether an ability is executable.
		 *
		 * Return true to allow execution. Return a WP_Error to block it; the
		 * WP_Error is propagated to callers so the AI assistant can show a
		 * meaningful reason instead of a generic failure.
		 *
		 * Add-ons hook this filter to gate abilities on global state such as
		 * licence validity, plan tier, kill switches, or time-of-day windows.
		 * For per-user gating, override check_permission() on the ability
		 * itself instead.
		 *
		 * @since 1.2.0
		 *
		 * @param bool|WP_Error $result  True if no callback has objected yet,
		 *                               or a WP_Error from a previous callback.
		 * @param BaseAbility   $ability The ability being checked.
		 */
		$result = apply_filters( 'albert/abilities/is_executable', true, $this );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
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
