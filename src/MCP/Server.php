<?php
/**
 * MCP Server with OAuth Authentication
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.0.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;
use Albert\Core\Plugin;
use Albert\Logging\ObservabilityHandler;
use Albert\MCP\Skills\SkillLoader;
use Albert\OAuth\Server\TokenValidator;
use Albert\OAuth\ServerMetadata;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Transport\HttpTransport;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

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
	 * Server route.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ROUTE = 'mcp';

	/**
	 * The MCP adapter's own tool abilities, registered on every Albert server.
	 *
	 * Required for protocol discovery and execution — unregistering any of them
	 * breaks MCP entirely — so they are also treated as protected abilities that
	 * can never be disabled. See {@see AbilitiesRegistry::get_protected_abilities()}.
	 *
	 * @since 1.3.0
	 * @var list<string>
	 */
	public const CORE_TOOL_ABILITIES = [
		'mcp-adapter/discover-abilities',
		'mcp-adapter/get-ability-info',
		'mcp-adapter/execute-ability',
	];

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		add_action( 'mcp_adapter_init', [ $this, 'create_server' ] );
		// Bound to *_after_ callbacks, not before: the header depends on whether
		// authentication actually failed (and how), which is only known once the
		// permission callback has run.
		add_filter( 'rest_request_after_callbacks', [ $this, 'add_oauth_discovery_headers' ], 10, 3 );

		// Hide tools the connected user can't execute from tools/list, so
		// discovery matches what's actually callable (the adapter only enforces
		// permission on tools/call).
		add_filter( 'mcp_adapter_tools_list', [ $this, 'hide_unauthorized_tools' ], 10, 2 );

		// Improve LLM-facing tool errors and log failures rejected before the
		// ability runs (e.g. input-schema validation).
		( new ToolCallObserver() )->register_hooks();

		// Strip server-only keys from schemas before they reach the client
		// (WordPress 7.1+); a no-op on older versions.
		( new SchemaPreparer() )->register_hooks();

		// Add `site` and `skills` to the discovery response, so an assistant
		// knows what this site is before it starts guessing.
		( new DiscoveryContext() )->register_hooks();

		// CORE_TOOL_ABILITIES otherwise only exist while the adapter's default
		// server is on, which any plugin can switch off for the whole site.
		( new AdapterAbilities() )->register_hooks();
	}

	/**
	 * Whether the server firing a global adapter hook is Albert's own.
	 *
	 * `instanceof McpServer` is not enough. WooCommerce and other plugins bundle
	 * their own copy of the adapter under the same `WP\MCP\Core` namespace, so
	 * whichever autoloads first defines the class for all of them and every
	 * server is an instance of it. Only the server id tells them apart.
	 *
	 * `get_server_id()` is checked rather than assumed for the same reason: the
	 * class that wins the race may come from an adapter version older than ours.
	 * A copy without the method is treated as "not Albert's", which is the safe
	 * direction — Albert leaves a server it cannot identify alone.
	 *
	 * @param mixed $server The server firing the hook.
	 *
	 * @return bool True when this is Albert's own server.
	 * @phpstan-assert-if-true McpServer $server
	 * @since 1.4.0
	 */
	public static function is_albert_server( $server ): bool {
		// Checked before the instanceof narrows $server: the class that wins the
		// autoload race may come from an adapter version older than ours, and
		// method_exists() on our own copy would be a tautology.
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
			return false;
		}

		return $server instanceof McpServer && $server->get_server_id() === self::SERVER_ID;
	}

	/**
	 * Hide tools the connected user cannot execute from tools/list.
	 *
	 * The adapter lists every registered tool regardless of permission — only
	 * tools/call enforces it — so a client (e.g. Claude Desktop) sees tools it
	 * can't actually run. This drops any tool whose permission check fails for the
	 * connected user, making discovery consistent with execution. It reuses the
	 * ability permission pipeline (WP_Ability::check_permissions() via the wrapped
	 * permission_callback), so it honours both the baseline capability and Albert
	 * Premium's advanced permission rules without knowing about either.
	 *
	 * Bound to the global `mcp_adapter_tools_list` filter, so it is matched on the
	 * server id — see {@see self::is_albert_server()} for why `instanceof` alone
	 * is not enough.
	 *
	 * @param array<int, object> $tools  Tool DTOs about to be returned to the client.
	 * @param object             $server The MCP server firing the filter.
	 *
	 * @return array<int, object> The filtered tool list.
	 * @since 1.3.0
	 */
	public function hide_unauthorized_tools( array $tools, object $server ): array {
		if ( ! self::is_albert_server( $server ) ) {
			return $tools;
		}

		/**
		 * Filters whether tools the connected user can't execute are hidden from
		 * discovery. Default true — the listed tools match what's callable. Set
		 * false to fall back to the adapter default (list everything, deny on call).
		 *
		 * @since 1.3.0
		 *
		 * @param bool $enabled Whether to hide unauthorized tools from tools/list.
		 */
		if ( ! apply_filters( 'albert/mcp/hide_unauthorized_tools', true ) ) {
			return $tools;
		}

		$allowed = array_filter(
			$tools,
			static function ( $tool ) use ( $server ): bool {
				if ( ! is_object( $tool ) || ! method_exists( $tool, 'getName' ) ) {
					return true;
				}

				$name = $tool->getName();

				// The adapter's own meta-tools gate on a target-ability *argument*,
				// not on the user, so a []-args check would wrongly hide them. They
				// are infrastructure — always list them (the same protected set that
				// can never be disabled); the ability they proxy is still gated on
				// execution. The tool name here is the MCP-sanitised spelling
				// (`mcp-adapter-execute-ability`), while the protected set holds the
				// raw ability IDs (`mcp-adapter/execute-ability`), so the match must
				// be slash/hyphen-insensitive — is_transport_ability() normalises both
				// sides against an exact allowlist so a future ability can't be named
				// to slip through the gate.
				if ( AbilitiesRegistry::is_transport_ability( $name ) ) {
					return true;
				}

				$mcp_tool = $server->get_mcp_tool( $name );

				// No backing McpTool (unexpected) — leave it listed rather than
				// hide something we can't evaluate.
				if ( $mcp_tool === null ) {
					return true;
				}

				return $mcp_tool->check_permission( [] ) === true;
			}
		);

		return array_values( $allowed );
	}

	/**
	 * Add OAuth discovery headers for unauthorized MCP requests.
	 *
	 * When a request to our MCP endpoint fails authentication, we need to tell
	 * the client where to find OAuth authorization server metadata — on every
	 * 401, not only when no token was sent. An expired or otherwise invalid
	 * token (access tokens are 1 hour) previously skipped this entirely, so a
	 * client mid-session got a bare 401 indistinguishable from "never
	 * authorised", with no signal that refreshing would fix it. Per RFC 6750
	 * §3, a token that was supplied but rejected also carries `error="invalid_token"`.
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
		if ( strpos( $route, '/' . Plugin::rest_namespace() . '/' . self::ROUTE ) === false ) {
			return $response;
		}

		if ( $this->response_status( $response ) !== 401 ) {
			return $response;
		}

		$resource_url = ServerMetadata::rest_url( Plugin::rest_namespace() . '/oauth/resource' );
		$token_sent   = ! empty( TokenValidator::get_bearer_token( $request ) );

		header( 'WWW-Authenticate: ' . $this->build_challenge( $resource_url, $token_sent ) );

		return $response;
	}

	/**
	 * Build the RFC 6750 §3 WWW-Authenticate challenge value.
	 *
	 * The metadata pointer uses `resource_metadata` (RFC 9728 §5.1), not `resource`;
	 * a strict client reads that exact name to find discovery and dead-ends without it.
	 * A rejected token adds `error="invalid_token"`; a never-sent one does not.
	 *
	 * @param string $resource_url The protected-resource metadata URL.
	 * @param bool   $token_sent   Whether the request carried a Bearer token.
	 *
	 * @return string The header value (without the `WWW-Authenticate: ` prefix).
	 * @since 1.4.0
	 */
	private function build_challenge( string $resource_url, bool $token_sent ): string {
		$challenge = 'Bearer realm="MCP", resource_metadata="' . $resource_url . '"';

		if ( $token_sent ) {
			$challenge .= ', error="invalid_token"';
		}

		return $challenge;
	}

	/**
	 * Resolve the HTTP status a REST response (success or error) carries.
	 *
	 * @param mixed $response The value `rest_request_after_callbacks` passed.
	 *
	 * @return int The HTTP status, or 200 when none can be determined.
	 * @since 1.4.0
	 */
	private function response_status( $response ): int {
		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		}

		if ( $response instanceof WP_REST_Response ) {
			return $response->get_status();
		}

		return 200;
	}

	/**
	 * Create the MCP server.
	 *
	 * Bound to the global `mcp_adapter_init` action. Every plugin that bundles the
	 * adapter ships it unscoped under the same `WP\MCP\…` namespace, so whichever
	 * copy autoloads first defines the classes for all of them and `McpAdapter` is
	 * a single shared singleton — this action fires once, with that one instance.
	 * The `instanceof` check is therefore a type guard, not a way of telling our
	 * adapter from someone else's: there is only one, and it is shared.
	 *
	 * Telling servers apart is a different problem, and the shared adapter is
	 * exactly why it exists — see {@see self::is_albert_server()}, which every
	 * callback on a global adapter hook must use.
	 *
	 * @param object $adapter The MCP adapter instance fired on the global hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function create_server( object $adapter ): void {
		if ( ! $adapter instanceof McpAdapter ) {
			return;
		}

		/**
		 * Filters the MCP observability handler class used for the Albert server.
		 *
		 * Premium (or any addon) can replace Free's handler by returning a
		 * class-string that implements McpObservabilityHandlerInterface.
		 * The class must be instantiable with no constructor arguments.
		 * Classes that do not implement the interface are ignored and the
		 * default handler is used instead.
		 *
		 * @since 1.2.0
		 *
		 * @param class-string<McpObservabilityHandlerInterface> $handler_class Fully-qualified class name. Default ObservabilityHandler::class.
		 */
		$filtered = apply_filters( 'albert/mcp/observability_handler', ObservabilityHandler::class );

		// Validate the filtered value implements the required interface;
		// fall back to the default handler if the class is unknown or invalid.
		$observability_handler = ObservabilityHandler::class;
		if (
			is_string( $filtered )
			&& class_exists( $filtered )
			&& is_a( $filtered, McpObservabilityHandlerInterface::class, true )
		) {
			$observability_handler = $filtered;
		}

		$adapter->create_server(
			self::SERVER_ID,
			Plugin::rest_namespace(),
			self::ROUTE,
			__( 'Albert MCP Server', 'albert-ai-butler' ),
			__( 'MCP server for AI assistants to interact with WordPress', 'albert-ai-butler' ),
			ALBERT_VERSION,
			[ HttpTransport::class ],
			ErrorLogMcpErrorHandler::class,
			$observability_handler,
			$this->get_tools(),
			$this->get_resources(),
			$this->get_prompts(),
			[ $this, 'permission_callback' ]
		);
	}

	/**
	 * Get the tools to register for this server.
	 *
	 * Uses the same core abilities as the default MCP server.
	 *
	 * @return list<string> The tool names.
	 * @since 1.0.0
	 */
	private function get_tools(): array {
		return self::CORE_TOOL_ABILITIES;
	}

	/**
	 * Get the resources to register for this server.
	 *
	 * Resources are built McpResource instances (not abilities), assembled by
	 * {@see ResourceLoader} — mirroring how prompts are supplied.
	 *
	 * @return list<McpResource> The resource instances to expose.
	 * @since 1.2.0
	 */
	private function get_resources(): array {
		return ( new ResourceLoader() )->resources();
	}

	/**
	 * Get the prompts (skills) to register for this server.
	 *
	 * Skills are bundled Markdown playbooks loaded as built McpPrompt instances
	 * (not abilities), mirroring how resources are supplied. See {@see SkillLoader}.
	 *
	 * @return array<int, McpPrompt> The prompt instances to expose.
	 * @since 1.2.0
	 */
	private function get_prompts(): array {
		return ( new SkillLoader() )->prompts();
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
				__( 'OAuth Bearer token required. Include an Authorization header with a valid Bearer token.', 'albert-ai-butler' ),
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
	 * Get the base URL for OAuth endpoints.
	 *
	 * Consults the `albert/mcp/external_url` filter. Returns `home_url()` when
	 * the filter is empty or returns a value that fails
	 * {@see wp_http_validate_url()}.
	 *
	 * @return string The base URL.
	 * @since 1.0.0
	 */
	public static function get_base_url(): string {
		$state = self::get_external_url_state();

		if ( $state['state'] === 'active' ) {
			return $state['value'];
		}

		return home_url();
	}

	/**
	 * Get the server endpoint URL.
	 *
	 * Consults the `albert/mcp/external_url` filter for the base URL. If the
	 * filter returns a non-empty value that fails {@see wp_http_validate_url()},
	 * emits a `_doing_it_wrong()` notice and falls back to {@see rest_url()}.
	 *
	 * @return string The full URL to the MCP server endpoint.
	 * @since 1.0.0
	 */
	public static function get_endpoint_url(): string {
		$state = self::get_external_url_state();

		if ( $state['state'] === 'active' ) {
			return ServerMetadata::rest_url( Plugin::rest_namespace() . '/' . self::ROUTE );
		}

		return rest_url( Plugin::rest_namespace() . '/' . self::ROUTE );
	}

	/**
	 * Get the current state of the `albert/mcp/external_url` filter.
	 *
	 * Used by both the endpoint resolver and the Connections admin screen.
	 * The filter is evaluated once per request and the result is memoised.
	 *
	 * Possible states:
	 *  - `inactive` — filter returns an empty string (no override).
	 *  - `active`   — filter returns a non-empty, valid URL; `value` is the URL.
	 *  - `invalid`  — filter returns a non-empty string that fails
	 *                 {@see wp_http_validate_url()}; `value` is the raw filter
	 *                 output so the UI can surface it to the admin.
	 *
	 * @since 1.1.0
	 *
	 * @return array{state: 'inactive'|'active'|'invalid', value: string}
	 */
	public static function get_external_url_state(): array {
		static $cache = null;
		if ( $cache !== null ) {
			return $cache;
		}

		/**
		 * Filters the external base URL used for the MCP endpoint.
		 *
		 * Return a fully-qualified URL (including scheme) to replace the host
		 * portion of the MCP endpoint — useful when the site is reachable
		 * through a tunnel or reverse proxy during development. Return an
		 * empty string (the default) to use {@see rest_url()} as-is.
		 *
		 * Invalid URLs are ignored with a `_doing_it_wrong()` notice.
		 *
		 * @since 1.1.0
		 *
		 * @param string $external_url Empty string by default.
		 */
		$filtered = (string) apply_filters( 'albert/mcp/external_url', '' );
		$filtered = rtrim( $filtered, '/' );

		if ( $filtered === '' ) {
			$cache = [
				'state' => 'inactive',
				'value' => '',
			];
			return $cache;
		}

		$validated = wp_http_validate_url( $filtered );
		if ( $validated === false ) {
			_doing_it_wrong(
				'albert/mcp/external_url',
				sprintf(
					/* translators: %s: invalid URL returned by the filter */
					esc_html__( 'Filter returned an invalid URL: %s. Falling back to rest_url().', 'albert-ai-butler' ),
					esc_html( $filtered )
				),
				'1.1.0'
			);
			$cache = [
				'state' => 'invalid',
				'value' => $filtered,
			];
			return $cache;
		}

		$cache = [
			'state' => 'active',
			'value' => $validated,
		];
		return $cache;
	}
}
