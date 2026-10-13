<?php
/**
 * Integration tests for neutralising the MCP adapter's built-in default server.
 *
 * The adapter's default server ("mcp-adapter-default-server") would expose every
 * public ability under the adapter's default transport permission
 * (current_user_can('read')), outside Albert's OAuth flow, allowed-users list and
 * consent screen. Albert leaves the server created — so the adapter still
 * registers the meta-tool abilities Albert's own server depends on — but strips
 * its tools, resources and prompts so it can execute nothing. These tests run
 * against the real WordPress filter system to prove the wiring.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\MCP;

use Albert\MCP\Server;
use Albert\Tests\TestCase;

/**
 * Default-server neutralisation integration tests.
 *
 * @covers \Albert\MCP\Server::neutralize_default_server_config
 */
class DefaultServerTest extends TestCase {

	/**
	 * After register_hooks(), the adapter's default-server configuration comes
	 * back with an empty tools/resources/prompts list — so the server it builds
	 * can discover and execute nothing.
	 *
	 * @return void
	 */
	public function test_register_hooks_empties_the_default_server_config(): void {
		( new Server() )->register_hooks();

		$defaults = [
			'server_id' => 'mcp-adapter-default-server',
			'tools'     => [ 'mcp-adapter/discover-abilities', 'mcp-adapter/execute-ability' ],
			'resources' => [ 'some-resource' ],
			'prompts'   => [ 'some-prompt' ],
		];

		$config = apply_filters( 'mcp_adapter_default_server_config', $defaults );

		$this->assertSame( [], $config['tools'] );
		$this->assertSame( [], $config['resources'] );
		$this->assertSame( [], $config['prompts'] );
	}

	/**
	 * A site that genuinely wants the adapter's default server intact can keep it
	 * by returning false from `albert/mcp/disable_default_server`; the adapter's
	 * own configuration then stands unchanged.
	 *
	 * @return void
	 */
	public function test_a_site_can_keep_the_default_server_intact(): void {
		( new Server() )->register_hooks();
		add_filter( 'albert/mcp/disable_default_server', '__return_false' );

		$defaults = [
			'tools'     => [ 'mcp-adapter/execute-ability' ],
			'resources' => [],
			'prompts'   => [],
		];

		$this->assertSame( $defaults, apply_filters( 'mcp_adapter_default_server_config', $defaults ) );
	}
}
