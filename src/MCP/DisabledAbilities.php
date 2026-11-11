<?php
/**
 * Switched-off abilities, on Albert's MCP server only.
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.6.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesState;
use Albert\Logging\ExecutionLogMarker;
use WP_Error;

/**
 * Keeps switched-off abilities away from assistants connected through Albert.
 *
 * Switching an ability off used to unregister it site-wide, which broke other
 * plugins using it. It now stays registered, and this class is the switch:
 * left out of discovery, refused by get-info and execute, through the
 * adapter's own filters.
 *
 * Needs an adapter with `McpServer::get_server_id()` and the 0.5.0 tool-call
 * filters. Without them it does nothing, and only the execute backstop in
 * {@see \Albert\Abstracts\BaseAbility::guarded_execute()} still refuses.
 *
 * @since 1.6.0
 */
class DisabledAbilities implements Hookable {

	/**
	 * The meta-tools that name a target ability in their `ability_name` argument.
	 *
	 * @since 1.6.0
	 * @var array<int, string>
	 */
	private const TARGETED_TOOLS = [
		'mcp-adapter/get-ability-info',
		'mcp-adapter/execute-ability',
	];

	/**
	 * Register the adapter filters.
	 *
	 * Discovery at 15: before {@see DiscoveryContext} appends to the result.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function register_hooks(): void {
		add_filter( 'mcp_adapter_pre_tool_call', [ $this, 'refuse' ], 10, 4 );
		add_filter( 'mcp_adapter_tool_call_result', [ $this, 'hide_from_discovery' ], 15, 5 );
	}

	/**
	 * Refuse get-info and execute for a switched-off ability.
	 *
	 * @param mixed  $args      The tool arguments.
	 * @param string $tool_name The tool being called.
	 * @param mixed  $mcp_tool  The adapter's tool instance (unused).
	 * @param mixed  $server    The MCP server firing the filter.
	 *
	 * @return mixed The arguments unchanged, or a WP_Error that stops the call.
	 * @since 1.6.0
	 */
	public function refuse( $args, string $tool_name, $mcp_tool = null, $server = null ): mixed {
		if ( is_wp_error( $args ) || ! is_array( $args ) || ! Server::is_albert_server( $server ) ) {
			return $args;
		}

		if ( ! in_array( self::normalise( $tool_name ), array_map( [ self::class, 'normalise' ], self::TARGETED_TOOLS ), true ) ) {
			return $args;
		}

		$ability_name = $args['ability_name'] ?? '';

		if ( ! is_string( $ability_name ) || $ability_name === '' || AbilitiesState::is_enabled( $ability_name ) ) {
			return $args;
		}

		$error = AbilitiesState::switched_off_error( $ability_name );

		// A WP_Error here ends the call before `mcp_adapter_tool_call_result`, where
		// {@see ToolCallObserver} would log it, so a refused execute logs here.
		if ( self::normalise( $tool_name ) === self::normalise( 'mcp-adapter/execute-ability' ) && ! ExecutionLogMarker::has( $ability_name ) ) {
			$parameters = $args['parameters'] ?? [];

			/** This action is documented in src/Abstracts/BaseAbility.php */
			do_action( 'albert/abilities/after_execute', $ability_name, is_array( $parameters ) ? $parameters : [], $error, get_current_user_id() );
		}

		return $error;
	}

	/**
	 * Leave switched-off abilities out of the discovery result.
	 *
	 * @param mixed  $result    The tool execution result.
	 * @param mixed  $args      The tool arguments (unused, discovery takes none).
	 * @param string $tool_name The tool that was called.
	 * @param mixed  $mcp_tool  The adapter's tool instance (unused).
	 * @param mixed  $server    The MCP server firing the filter.
	 *
	 * @return mixed The result, without switched-off abilities.
	 * @since 1.6.0
	 */
	public function hide_from_discovery( $result, $args, string $tool_name, $mcp_tool = null, $server = null ): mixed {
		if ( ! Server::is_albert_server( $server ) ) {
			return $result;
		}

		if ( self::normalise( $tool_name ) !== self::normalise( DiscoveryContext::DISCOVERY_ABILITY ) ) {
			return $result;
		}

		if ( $result instanceof WP_Error || ! is_array( $result ) || ! isset( $result['abilities'] ) || ! is_array( $result['abilities'] ) ) {
			return $result;
		}

		$disabled = AbilitiesState::effective();

		$result['abilities'] = array_values(
			array_filter(
				$result['abilities'],
				static fn( $ability ): bool => ! is_array( $ability ) || ! in_array( $ability['name'] ?? '', $disabled, true )
			)
		);

		return $result;
	}

	/**
	 * One spelling for a tool name: the adapter reports either the ability id
	 * or its MCP-sanitised form (`mcp-adapter-execute-ability`).
	 *
	 * @param string $tool_name Tool name in either spelling.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	private static function normalise( string $tool_name ): string {
		return str_replace( '/', '-', $tool_name );
	}
}
