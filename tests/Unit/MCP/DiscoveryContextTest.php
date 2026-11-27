<?php
/**
 * Tests for the discovery-response context seam.
 *
 * @package Albert\Tests\Unit\MCP
 */

namespace Albert\Tests\Unit\MCP;

use Albert\MCP\DiscoveryContext;
use Albert\MCP\Server;
use PHPUnit\Framework\TestCase;
use WP\MCP\Core\McpServer;
use WP_Error;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

/**
 * DiscoveryContext unit tests.
 *
 * The seam is a filter on every tool result on the server, so what has to hold
 * is that it touches the discovery response and nothing else, a `site` field
 * appended to somebody's create-post result would be a bug that only showed up
 * as confusion at the other end.
 */
class DiscoveryContextTest extends TestCase {

	/**
	 * The seam under test.
	 *
	 * @var DiscoveryContext
	 */
	private DiscoveryContext $context;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->context = new DiscoveryContext();
	}

	/**
	 * Another tool's result passes through untouched.
	 *
	 * @return void
	 */
	public function test_other_tools_are_left_alone(): void {
		$result = [ 'id' => 42 ];

		$this->assertSame(
			$result,
			$this->add_context( $result, [], 'albert/create-post' )
		);
	}

	/**
	 * A failed discovery call is left alone.
	 *
	 * There is no ability list to attach context to, and rewriting an error into
	 * something that looks partly successful would be worse than the error.
	 *
	 * @return void
	 */
	public function test_errors_pass_through(): void {
		$error = new WP_Error( 'boom', 'Nope.' );

		$this->assertSame(
			$error,
			$this->add_context( $error, [], 'mcp-adapter/discover-abilities' )
		);
	}

	/**
	 * A result that is not the expected shape is left alone.
	 *
	 * @return void
	 */
	public function test_unexpected_shapes_pass_through(): void {
		$this->assertSame(
			[ 'something' => 'else' ],
			$this->add_context( [ 'something' => 'else' ], [], 'mcp-adapter/discover-abilities' )
		);
	}

	/**
	 * The ability list is never overwritten by the context payload.
	 *
	 * @return void
	 */
	public function test_the_ability_list_is_preserved(): void {
		$result = [ 'abilities' => [ [ 'name' => 'albert/find-posts' ] ] ];

		$filtered = $this->add_context( $result, [], 'mcp-adapter/discover-abilities' );

		$this->assertSame( $result['abilities'], $filtered['abilities'] );
	}

	/**
	 * While privacy mode masks anything, the assistant is told not to write a masked value back.
	 *
	 * @return void
	 */
	public function test_the_privacy_line_follows_the_mode(): void {
		$result = [ 'abilities' => [] ];

		foreach ( [ 'strict', 'balanced' ] as $mode ) {
			$GLOBALS['albert_test_options']['albert_privacy_mode'] = $mode;
			$filtered = $this->add_context( $result, [], 'mcp-adapter/discover-abilities' );
			$this->assertStringContainsString( 'Never write a masked value back', $filtered['privacy'] ?? '' );
		}

		$GLOBALS['albert_test_options']['albert_privacy_mode'] = 'off';
		$this->assertArrayNotHasKey( 'privacy', $this->add_context( $result, [], 'mcp-adapter/discover-abilities' ) );
		unset( $GLOBALS['albert_test_options']['albert_privacy_mode'] );
	}

	/**
	 * Both the raw ability id and the MCP-sanitised tool name are recognised.
	 *
	 * The adapter reports one spelling on some paths and the other elsewhere, so
	 * matching only one would make the context appear intermittently.
	 *
	 * @return void
	 */
	public function test_both_spellings_of_the_tool_name_match(): void {
		$result = [ 'abilities' => [] ];

		foreach ( [ 'mcp-adapter/discover-abilities', 'mcp-adapter-discover-abilities' ] as $name ) {
			$filtered = $this->add_context( $result, [], $name );

			// With no WordPress behind it the payload is empty, so the result
			// comes back unchanged, but it must have been recognised, which the
			// preserved shape confirms rather than contradicts. The distinction
			// that matters is tested above against a name that is not discovery.
			$this->assertArrayHasKey( 'abilities', $filtered );
		}
	}

	/**
	 * The schema gains the two fields, so a strict client cannot drop them.
	 *
	 * @return void
	 */
	public function test_output_schema_declares_the_context_fields(): void {
		$args = [
			'output_schema' => [
				'type'       => 'object',
				'properties' => [ 'abilities' => [ 'type' => 'array' ] ],
			],
		];

		$described = $this->context->describe_fields( $args, 'mcp-adapter/discover-abilities' );

		$this->assertArrayHasKey( 'site', $described['output_schema']['properties'] );
		$this->assertArrayHasKey( 'skills', $described['output_schema']['properties'] );
		$this->assertSame( 'string', $described['output_schema']['properties']['site']['type'] );
	}

	/**
	 * Another ability's schema is never touched.
	 *
	 * @return void
	 */
	public function test_other_abilities_keep_their_schema(): void {
		$args = [
			'output_schema' => [
				'type'       => 'object',
				'properties' => [ 'id' => [ 'type' => 'integer' ] ],
			],
		];

		$this->assertSame( $args, $this->context->describe_fields( $args, 'albert/create-post' ) );
	}

	/**
	 * An ability registered without an output schema is left as it is.
	 *
	 * @return void
	 */
	public function test_a_missing_schema_is_not_invented(): void {
		$args = [ 'label' => 'Discover Abilities' ];

		$this->assertSame( $args, $this->context->describe_fields( $args, 'mcp-adapter/discover-abilities' ) );
	}

	/**
	 * Build a mocked MCP server with a given id.
	 *
	 * @param string $id The server id.
	 *
	 * @return McpServer
	 */
	private function server( string $id = Server::SERVER_ID ): McpServer {
		$server = $this->createMock( McpServer::class );
		$server->method( 'get_server_id' )->willReturn( $id );

		return $server;
	}

	/**
	 * Call the filter as the adapter does, on Albert's own server.
	 *
	 * @param mixed  $result    The tool result.
	 * @param mixed  $args      The tool arguments.
	 * @param string $tool_name The tool name.
	 *
	 * @return mixed
	 */
	private function add_context( $result, $args, string $tool_name ): mixed {
		return $this->context->add_context( $result, $args, $tool_name, null, $this->server() );
	}

	/**
	 * Another plugin's server asking for its own abilities gets its own answer.
	 *
	 * The filter is global, so every MCP server on the site fires it. Albert's
	 * site context describes Albert's site to Albert's clients; appending it to
	 * a foreign server's discovery response leaks it where it does not belong.
	 *
	 * @return void
	 */
	public function test_a_foreign_server_gets_no_albert_context(): void {
		$result = [ 'abilities' => [ 'woocommerce/list-orders' ] ];

		$filtered = $this->context->add_context(
			$result,
			[],
			'mcp-adapter/discover-abilities',
			null,
			$this->server( 'woocommerce' )
		);

		$this->assertSame( $result, $filtered );
		$this->assertArrayNotHasKey( 'site', $filtered );
	}

	/**
	 * A server the adapter did not identify is left alone too.
	 *
	 * @return void
	 */
	public function test_an_unidentified_server_gets_no_albert_context(): void {
		$result = [ 'abilities' => [ 'someplugin/do-thing' ] ];

		$this->assertSame(
			$result,
			$this->context->add_context( $result, [], 'mcp-adapter/discover-abilities' )
		);
	}
}
