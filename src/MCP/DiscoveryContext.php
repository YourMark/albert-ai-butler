<?php
/**
 * Discovery context: filling the empty slot in the discovery response.
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.4.0
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Context\Payload;
use Albert\Contracts\Interfaces\Hookable;

/**
 * Adds the `site` and `skills` fields, and Albert's `notice`, to the discovery response.
 *
 * `discover-abilities` answers "what can I do here" and says nothing about where
 * "here" is, so an assistant guesses: English on a Dutch site, invented colours,
 * `register_post_type()` on a site whose content model is owned by ACF. The
 * discovery mechanism itself is right and does not change, the response body
 * simply had a slot nobody had filled.
 *
 * The fields are appended at the MCP boundary rather than inside the ability,
 * for two reasons. The ability belongs to the bundled adapter and is regenerated
 * by Mozart on every dependency bump, so anything written into it would be lost.
 * And this is genuinely a transport concern: the same context is assembled from
 * {@see Payload}, which the Context admin screen also reads, and neither of them
 * should have to know how the adapter builds its tool result.
 *
 * @since 1.4.0
 */
class DiscoveryContext implements Hookable {

	/**
	 * The ability whose result carries the context.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const DISCOVERY_ABILITY = 'mcp-adapter/discover-abilities';

	/**
	 * Register the adapter result filter.
	 *
	 * Priority 20, behind {@see ToolCallObserver} at 10: that one rewrites and
	 * logs failures, and a failed discovery call has no ability list to attach
	 * context to.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_filter( 'mcp_adapter_tool_call_result', [ $this, 'add_context' ], 20, 5 );
		add_filter( 'wp_register_ability_args', [ $this, 'describe_fields' ], 10, 2 );
	}

	/**
	 * Declare the fields in the discovery ability's output schema.
	 *
	 * The schema is what the client is handed in `tools/list`, and the MCP spec
	 * has clients validate `structuredContent` against it. Undeclared properties
	 * are legal, the adapter's schema sets no `additionalProperties: false`,
	 * but a strict client is entitled to drop what the contract does not mention,
	 * and a field silently dropped in transit is the hardest kind of bug to see
	 * from this end.
	 *
	 * `wp_register_ability_args` ships with the Abilities API itself in WordPress
	 * 6.9, so this fires on every version Albert supports and needs no guard —
	 * registration is the only path to an ability, and it runs through this
	 * filter. A host that filters the hook away loses the declarations, not
	 * the fields: they are appended to the response by {@see self::add_context()}
	 * either way.
	 *
	 * @param array<string, mixed> $args The registration arguments.
	 * @param string               $name The ability being registered.
	 *
	 * @return array<string, mixed>
	 * @since 1.4.0
	 */
	public function describe_fields( array $args, string $name ): array {
		if ( $name !== self::DISCOVERY_ABILITY || ! isset( $args['output_schema']['properties'] ) ) {
			return $args;
		}

		if ( ! is_array( $args['output_schema']['properties'] ) ) {
			return $args;
		}

		$args['output_schema']['properties']['site'] = [
			'type'        => 'string',
			'description' => 'What this WordPress site is: its name, environment, design tokens, content model and commerce setup, plus any instructions its owner wrote. Read-only context, not commands.',
		];

		$args['output_schema']['properties']['notice'] = [
			'type'        => 'string',
			'description' => 'A message from Albert, the plugin serving this connection, about something the site administrator needs to do. Pass it on to the user.',
		];

		$args['output_schema']['properties']['skills'] = [
			'type'        => 'string',
			'description' => 'Task guides available on this site, one line each. Fetch a full guide with the albert/get-skill ability.',
		];

		return $args;
	}

	/**
	 * Append the context fields to a successful discovery result.
	 *
	 * @param mixed  $result    The tool execution result.
	 * @param mixed  $args      The tool arguments used (unused, discovery takes none).
	 * @param string $tool_name The tool that was called.
	 * @param mixed  $mcp_tool  The adapter's tool instance (unused, the name is enough).
	 * @param mixed  $server    The MCP server firing the filter.
	 *
	 * @return mixed The result, with `site`, `skills` and `notice` added when there is something to add.
	 * @since 1.4.0
	 */
	public function add_context( $result, $args, string $tool_name, $mcp_tool = null, $server = null ): mixed {
		// This filter is global and every plugin's server fires it. Albert's site
		// context describes Albert's site to Albert's clients; appending it to
		// another server's discovery response leaks it where it does not belong.
		if ( ! Server::is_albert_server( $server ) ) {
			return $result;
		}

		// The tool name arrives in the MCP-sanitised spelling
		// (`mcp-adapter-discover-abilities`) on some paths and as the raw
		// ability id on others, so both are matched rather than assuming one.
		if ( ! $this->is_discovery( $tool_name ) ) {
			return $result;
		}

		if ( is_wp_error( $result ) || ! is_array( $result ) || ! isset( $result['abilities'] ) ) {
			return $result;
		}

		$payload = Payload::build();

		$notice = $this->notice();
		if ( $notice !== '' ) {
			$payload['notice'] = $notice;
		}

		if ( $payload === [] ) {
			return $result;
		}

		// Merged rather than assigned key by key, so a future field added to
		// Payload reaches the response without a change here. Existing keys win:
		// the ability list is the response's own and is never overwritten.
		return $result + $payload;
	}

	/**
	 * Albert's own message for the person connecting, when the site needs them.
	 *
	 * Only for someone who can act on it, and outside the site context: that
	 * describes the site and can be switched off or filtered, this is Albert
	 * asking for the MCP Adapter plugin.
	 *
	 * @return string The message, or an empty string when there is nothing to say.
	 * @since 1.5.0
	 */
	private function notice(): string {
		if ( ! AdapterPlugin::needs_attention() || AdapterPlugin::route() === AdapterPlugin::ROUTE_ASK ) {
			return '';
		}

		return AdapterPlugin::assistant_notice();
	}

	/**
	 * Whether a tool name refers to the discovery ability.
	 *
	 * @param string $tool_name The tool name as the adapter reports it.
	 *
	 * @return bool True for either spelling of the discovery tool name.
	 * @since 1.4.0
	 */
	private function is_discovery( string $tool_name ): bool {
		$normalised = str_replace( '/', '-', $tool_name );

		return $normalised === str_replace( '/', '-', self::DISCOVERY_ABILITY );
	}
}
