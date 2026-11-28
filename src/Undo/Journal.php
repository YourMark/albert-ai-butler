<?php
/**
 * Records assistant calls made over MCP.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.7.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\Core\AbilitiesRegistry;
use Albert\Core\NoteInput;
use Albert\OAuth\Server\ConnectionContext;
use WP_Error;

/**
 * Opens a recording window around every MCP tool call made over an Albert connection.
 *
 * The adapter's tool-call hooks are the one boundary every ability passes
 * through when an assistant calls it, whoever wrote the ability, so nothing is
 * needed per ability. Only calls with a {@see ConnectionContext} are recorded:
 * WP-CLI, cron, the editor and other plugins never are.
 *
 * @since 1.7.0
 */
class Journal implements Hookable {

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function register_hooks(): void {
		// Last, so a filter that blocks the call has already had its say.
		add_filter( 'mcp_adapter_pre_tool_call', [ $this, 'open' ], PHP_INT_MAX, 3 );
		// First, so other result filters' own writes are not recorded as the call's.
		add_filter( 'mcp_adapter_tool_call_result', [ $this, 'close' ], PHP_INT_MIN );
	}

	/**
	 * Open a window for the tool call about to run.
	 *
	 * @param mixed  $args      Tool arguments, or a WP_Error from an earlier filter.
	 * @param string $tool_name Tool name.
	 * @param mixed  $mcp_tool  The adapter's tool.
	 *
	 * @return mixed The arguments, unchanged.
	 * @since 1.7.0
	 */
	public function open( $args, $tool_name = '', $mcp_tool = null ) {
		if ( $args instanceof WP_Error || ! is_array( $args ) ) {
			return $args;
		}

		$client_id = ConnectionContext::client_id();
		if ( $client_id === null ) {
			return $args;
		}

		// Recording is a safety net: a journal error must never break the assistant's call.
		try {
			// A previous call in this request that threw never reached the result filter.
			if ( Window::source() === WindowContext::SOURCE_MCP ) {
				Window::close( Recorder::STATUS_INTERRUPTED );
			}

			$ability_name = self::ability_name( $args, (string) $tool_name, $mcp_tool );

			Window::open(
				new WindowContext(
					WindowContext::SOURCE_MCP,
					$ability_name,
					get_current_user_id(),
					$client_id,
					ConnectionContext::client_name(),
					null,
					self::session_id(),
					null,
					self::note( $args, (string) $tool_name, $ability_name )
				)
			);
		} catch ( \Throwable $e ) {
			FailureCounter::record( 1 );
		}

		return $args;
	}

	/**
	 * Close the window once the tool call returned.
	 *
	 * @param mixed $result The tool result.
	 *
	 * @return mixed The result, unchanged.
	 * @since 1.7.0
	 */
	public function close( $result ) {
		if ( Window::source() === WindowContext::SOURCE_MCP ) {
			Window::close( Recorder::STATUS_RECORDED );
		}

		return $result;
	}

	/**
	 * The assistant's note, from the ability's input: inside `parameters` when
	 * the call goes through the `execute-ability` meta-tool.
	 *
	 * @param array<string, mixed> $args         Tool arguments.
	 * @param string               $tool_name    Tool name.
	 * @param string               $ability_name The ability the call runs.
	 *
	 * @return mixed
	 */
	private static function note( array $args, string $tool_name, string $ability_name ) {
		$input = AbilitiesRegistry::is_transport_ability( $tool_name ) && is_array( $args['parameters'] ?? null ) ? $args['parameters'] : $args;

		return NoteInput::of( $ability_name, $input );
	}

	/**
	 * The MCP session the call belongs to, from the `Mcp-Session-Id` header.
	 *
	 * @return string|null
	 */
	private static function session_id(): ?string {
		$header = isset( $_SERVER['HTTP_MCP_SESSION_ID'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_MCP_SESSION_ID'] ) ) : '';

		return $header === '' ? null : substr( $header, 0, 191 );
	}

	/**
	 * The ability a tool call runs.
	 *
	 * Albert's server exposes the adapter's meta-tools, so the ability is the
	 * `execute-ability` argument; a server exposing abilities as tools names it
	 * in the tool's observability context.
	 *
	 * @param array<string, mixed> $args      Tool arguments.
	 * @param string               $tool_name Tool name.
	 * @param mixed                $mcp_tool  The adapter's tool.
	 *
	 * @return string
	 */
	private static function ability_name( array $args, string $tool_name, $mcp_tool ): string {
		if ( AbilitiesRegistry::is_transport_ability( $tool_name ) && is_string( $args['ability_name'] ?? null ) && $args['ability_name'] !== '' ) {
			return $args['ability_name'];
		}

		if ( is_object( $mcp_tool ) && method_exists( $mcp_tool, 'get_observability_context' ) ) {
			$context = $mcp_tool->get_observability_context();
			if ( is_array( $context ) && is_string( $context['ability_name'] ?? null ) && $context['ability_name'] !== '' ) {
				return $context['ability_name'];
			}
		}

		return $tool_name;
	}
}
