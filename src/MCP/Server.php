<?php
/**
 * MCP Server with OAuth Authentication
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.0.0
 */

namespace Albert\MCP;

use Albert\Contracts\Interfaces\Hookable;
use Albert\OAuth\Server\TokenValidator;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;
use WP_Error;
use WP_REST_Request;

/**
 * Server class
 *
 * Creates and configures an MCP server that authenticates via OAuth 2.0 Bearer tokens.
 * This allows AI clients like Claude Desktop to connect using OAuth authentication.
 *
 * @since 1.0.0
 */
class Server implements Hookable {

	/**
	 * Server ID.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const SERVER_ID = 'albert';

	/**
	 * Server route namespace.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ROUTE_NAMESPACE = 'albert/v1';

	/**
	 * Server route.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ROUTE = 'mcp';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'mcp_adapter_init', [ $this, 'create_server' ] );
		add_filter( 'rest_request_before_callbacks', [ $this, 'add_oauth_discovery_headers' ], 10, 3 );
	}

	/**
	 * Add OAuth discovery headers for unauthorized MCP requests.
	 *
	 * When a request to our MCP endpoint fails authentication, we need to tell
	 * the client where to find OAuth authorization server metadata.
	 *
	 * @param mixed                                 $response The response.
	 * @param array<string, mixed>                  $handler  The handler.
	 * @param WP_REST_Request<array<string, mixed>> $request  The request.
	 *
	 * @return mixed The response.
	 * @since 1.0.0
	 */
	public function add_oauth_discovery_headers( $response, $handler, $request ) {
		// Only handle our MCP endpoint.
		$route = $request->get_route();
		if ( strpos( $route, '/' . self::ROUTE_NAMESPACE . '/' . self::ROUTE ) === false ) {
			return $response;
		}

		// Check if there's no Bearer token - add discovery headers.
		$token = TokenValidator::get_bearer_token( $request );
		if ( empty( $token ) ) {
			// Send headers for OAuth discovery per MCP spec (RFC 6750).
			// Point to REST API resource endpoint for OAuth discovery.
			$resource_url = self::get_base_url() . '/wp-json/albert/v1/oauth/resource';
			header( 'WWW-Authenticate: Bearer realm="MCP", resource="' . $resource_url . '"' );
		}

		return $response;
	}

	/**
	 * Create the MCP server.
	 *
	 * @param McpAdapter $adapter The MCP adapter instance.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function create_server( McpAdapter $adapter ): void {
		$adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			__( 'Albert MCP Server', 'albert' ),
			__( 'MCP server for AI assistants to interact with WordPress', 'albert' ),
			ALBERT_VERSION,
			[ HttpTransport::class ],
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			$this->get_tools(),
			[], // Resources.
			[], // Prompts.
			[ $this, 'permission_callback' ]
		);
	}

	/**
	 * Get the tools to register for this server.
	 *
	 * Uses the same core abilities as the default MCP server.
	 *
	 * @return array<int, string> The tool names.
	 * @since 1.0.0
	 */
	private function get_tools(): array {
		return [
			'mcp-adapter/discover-abilities',
			'mcp-adapter/get-ability-info',
			'mcp-adapter/execute-ability',
		];
	}

	/**
	 * Permission callback for OAuth authentication.
	 *
	 * Validates OAuth 2.0 Bearer tokens and sets the current WordPress user.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request The REST request.
	 *
	 * @return bool|WP_Error True if authenticated, WP_Error otherwise.
	 * @since 1.0.0
	 */
	public function permission_callback( WP_REST_Request $request ): bool|WP_Error {
		// Check for Bearer token.
		$token = TokenValidator::get_bearer_token( $request );

		if ( empty( $token ) ) {
			return new WP_Error(
				'oauth_missing_token',
				__( 'OAuth Bearer token required. Include an Authorization header with a valid Bearer token.', 'albert' ),
				[ 'status' => 401 ]
			);
		}

		// Validate the token.
		$user = TokenValidator::validate_request( $request );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		// Set the current user for the request.
		wp_set_current_user( $user->ID );

		return true;
	}

	/**
	 * Check if developer settings are enabled.
	 *
	 * @return bool Whether developer settings are enabled.
	 * @since 1.0.0
	 */
	private static function is_developer_mode(): bool {
		/**
		 * Filter to enable developer settings like External URL.
		 *
		 * @param bool $show Whether to show developer settings. Default false.
		 *
		 * @since 1.0.0
		 */
		return apply_filters( 'albert/developer_mode', false );
	}

	/**
	 * Get the base URL for OAuth endpoints.
	 *
	 * Uses the external URL setting if configured and developer mode is enabled,
	 * otherwise falls back to home_url().
	 *
	 * @return string The base URL.
	 * @since 1.0.0
	 */
	public static function get_base_url(): string {
		if ( self::is_developer_mode() ) {
			$external_url = get_option( 'albert_external_url', '' );

			if ( ! empty( $external_url ) ) {
				return $external_url;
			}
		}

		return home_url();
	}

	/**
	 * Get the server endpoint URL.
	 *
	 * @param string $external_url Optional external URL to use as base (only used if developer mode enabled).
	 *
	 * @return string The full URL to the MCP server endpoint.
	 * @since 1.0.0
	 */
	public static function get_endpoint_url( string $external_url = '' ): string {
		// Only use external URL if developer mode is enabled.
		if ( self::is_developer_mode() ) {
			if ( ! empty( $external_url ) ) {
				return $external_url . '/wp-json/' . self::ROUTE_NAMESPACE . '/' . self::ROUTE;
			}

			// Check for configured external URL.
			$configured_url = get_option( 'albert_external_url', '' );
			if ( ! empty( $configured_url ) ) {
				return $configured_url . '/wp-json/' . self::ROUTE_NAMESPACE . '/' . self::ROUTE;
			}
		}

		return rest_url( self::ROUTE_NAMESPACE . '/' . self::ROUTE );
	}
}
