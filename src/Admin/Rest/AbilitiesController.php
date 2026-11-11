<?php
/**
 * Abilities REST Controller
 *
 * REST API for the DataViews abilities admin screen.
 *
 * @package Albert
 * @subpackage Admin\Rest
 * @since      1.3.0
 */

namespace Albert\Admin\Rest;

use Albert\Admin\AbilitiesPayload;
use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;
use Albert\Core\AbilitiesState;
use Albert\Core\Plugin;
use Albert\OAuth\Server\ConnectionContext;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * AbilitiesController class
 *
 * Serves the abilities dataset and persists per-ability enabled-state for the
 * admin screen, consumed via @wordpress/api-fetch. Routes require
 * `manage_options`. The controller also opts its own requests into the abilities
 * "management context" so the full registry (including disabled abilities) stays
 * visible — otherwise AbilitiesManager::enforce_disabled() would prune disabled
 * abilities and the screen could never re-enable them.
 *
 * @since 1.3.0
 */
class AbilitiesController implements Hookable {

	use RequiresManageOptions;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_filter( 'albert/abilities/is_management_context', [ $this, 'filter_management_context' ] );
	}

	/**
	 * Register the REST routes under the plugin namespace.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function register_routes(): void {
		$namespace = Plugin::rest_namespace();

		register_rest_route(
			$namespace,
			'/abilities',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_abilities' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);

		register_rest_route(
			$namespace,
			'/abilities/bulk',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'bulk_update' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'ids'     => [
						'type'     => 'array',
						'required' => true,
						'items'    => [ 'type' => 'string' ],
					],
					'enabled' => [
						'type'     => 'boolean',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/abilities/(?P<namespace>[a-z0-9_-]+)/(?P<name>[a-z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_ability' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'enabled' => [
						'type'     => 'boolean',
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * GET /abilities — the full dataset for the screen.
	 *
	 * @return WP_REST_Response
	 * @since 1.3.0
	 */
	public function get_abilities(): WP_REST_Response {
		return rest_ensure_response( AbilitiesPayload::build() );
	}

	/**
	 * POST /abilities/{namespace}/{name} — toggle one ability's enabled state.
	 *
	 * The id carries a slash, so it arrives as two path segments and is
	 * reconstructed here.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.3.0
	 */
	public function update_ability( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ability_id = $request['namespace'] . '/' . $request['name'];

		// `wp_has_ability()`, not `wp_get_ability()`: the latter raises
		// _doing_it_wrong() before returning null, so a request naming an id
		// that does not exist would log a notice on its way to an honest 404.
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability_id ) ) {
			return new WP_Error(
				'albert_ability_not_found',
				__( 'Ability not found.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		// The MCP adapter's own tools are protected — they can never be disabled.
		if ( in_array( $ability_id, AbilitiesRegistry::get_protected_abilities(), true ) ) {
			return new WP_Error(
				'albert_ability_protected',
				__( 'This ability is required by the MCP adapter and can’t be toggled.', 'albert-ai-butler' ),
				[ 'status' => 403 ]
			);
		}

		AbilitiesState::set_enabled( $ability_id, (bool) $request['enabled'] );

		return rest_ensure_response( AbilitiesPayload::row( $ability_id ) );
	}

	/**
	 * POST /abilities/bulk — enable or disable many abilities at once.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 * @since 1.3.0
	 */
	public function bulk_update( WP_REST_Request $request ): WP_REST_Response {
		$has_registry = function_exists( 'wp_has_ability' );
		$protected    = AbilitiesRegistry::get_protected_abilities();
		$ids          = array_values(
			array_filter(
				array_map( 'strval', (array) $request['ids'] ),
				static function ( string $id ) use ( $has_registry, $protected ): bool {
					if ( ! preg_match( '#^[a-z0-9_-]+/[a-z0-9_-]+$#', $id ) ) {
						return false;
					}
					// Protected abilities (the MCP adapter's own tools) can never
					// be disabled — drop them.
					if ( in_array( $id, $protected, true ) ) {
						return false;
					}
					// Only act on abilities that actually exist, so phantom ids
					// can't bloat the disabled-abilities option. Asked with
					// wp_has_ability(), which is silent; wp_get_ability()
					// raises _doing_it_wrong() per miss, so a bulk request
					// carrying phantom ids would print one notice each.
					return ! $has_registry || wp_has_ability( $id );
				}
			)
		);

		$enabled = (bool) $request['enabled'];
		AbilitiesState::set_enabled_bulk( $ids, $enabled );

		return rest_ensure_response(
			[
				'updated' => count( $ids ),
				'enabled' => $enabled,
			]
		);
	}

	/**
	 * Opt this controller's requests into the abilities management context.
	 *
	 * Keeps disabled abilities in the registry for our endpoint so the screen
	 * can list and re-enable them. Only for a request that WordPress will
	 * dispatch to our route, by a user who may use it, outside a connection.
	 *
	 * @param bool $is_management_context Incoming value.
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	public function filter_management_context( bool $is_management_context ): bool {
		return $is_management_context || $this->is_abilities_request();
	}

	/**
	 * Whether the current request targets this controller's abilities routes.
	 *
	 * @return bool
	 * @since 1.3.0
	 * @since 1.5.1 Matches the route WordPress dispatches, not the request path.
	 */
	private function is_abilities_request(): bool {
		// Permission first: resolving the user is what records a connection.
		if ( ! $this->check_permission() || ConnectionContext::client_id() !== null ) {
			return false;
		}

		$route = self::requested_route();

		return preg_match( '#^/' . preg_quote( Plugin::rest_namespace(), '#' ) . '/abilities(?:/|$)#', $route ) === 1;
	}

	/**
	 * The REST route this request is dispatched to, or '' when it is not a REST request.
	 *
	 * Mirrors WP::parse_request(): a `rest_route` from POST or GET overrides the
	 * route in the path, so `/wp-json/albert/v1/abilities?rest_route=/other`
	 * is a request for `/other`. The registry can be built before parse_request
	 * runs, hence the fallback.
	 *
	 * @return string
	 * @since 1.5.1
	 */
	private static function requested_route(): string {
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return (string) $GLOBALS['wp']->query_vars['rest_route'];
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Read-only routing decision.
		foreach ( [ $_POST, $_GET ] as $source ) {
			if ( isset( $source['rest_route'] ) ) {
				return is_string( $source['rest_route'] ) ? sanitize_text_field( wp_unslash( $source['rest_route'] ) ) : '';
			}
		}
		// phpcs:enable

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) strtok( $uri, '?' );

		if ( preg_match( '#/' . preg_quote( rest_get_url_prefix(), '#' ) . '(/.*)$#', $path, $matches ) !== 1 ) {
			return '';
		}

		return $matches[1];
	}
}
