<?php
/**
 * Integration tests for the guidance ToolCallObserver adds to a rejected call.
 *
 * These run against real WordPress: a real registered ability, real
 * `WP_Ability::execute()`, and core's real `rest_validate_value_from_schema()`.
 * The unit tests cover the observer's own branching against a stubbed
 * validator; these cover the part that must agree with core.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\MCP;

use Albert\MCP\Server;
use Albert\MCP\ToolCallObserver;
use Albert\Tests\TestCase;
use WP\MCP\Core\McpServer;
use WP_Error;

/**
 * Tool-call guidance, against the validator that produced the rejection.
 *
 * @since 1.4.0
 */
class ToolCallObserverTest extends TestCase {

	/**
	 * The observer under test.
	 *
	 * @var ToolCallObserver
	 */
	private ToolCallObserver $observer;

	/**
	 * Every test runs as an authenticated administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );

		$this->observer = new ToolCallObserver();
	}

	/**
	 * An ability that is registered and takes a required integer id.
	 *
	 * @return string The ability id.
	 */
	private function ability(): string {
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'albert/view-term' ) ) {
			$this->markTestSkipped( 'albert/view-term is not registered.' );
		}

		return 'albert/view-term';
	}

	/**
	 * Run a wrongly-named parameter through both paths a client can take.
	 *
	 * @return array{direct: string, proxied: string, core: string}
	 */
	private function guidance_for_a_misspelled_parameter(): array {
		$supplied = [
			'term_id' => 76,
			'fields'  => 'all',
		];

		$raw = wp_get_ability( $this->ability() )->execute( $supplied );
		$this->assertWPError( $raw, 'Expected the Abilities API to reject an undeclared parameter.' );

		$direct = $this->handle( $raw, $supplied, 'albert-view-term' );

		$proxied = $this->handle(
			[
				'success' => false,
				'error'   => $raw->get_error_message(),
			],
			[
				'ability_name' => $this->ability(),
				'parameters'   => $supplied,
			],
			'mcp-adapter-execute-ability'
		);

		return [
			'core'    => $raw->get_error_message(),
			'direct'  => $direct->get_error_message(),
			'proxied' => $proxied['error'],
		];
	}

	/**
	 * The wrongly-named parameter is named, along with what is accepted.
	 *
	 * @return void
	 */
	public function test_the_wrong_parameter_name_is_named(): void {
		$guidance = $this->guidance_for_a_misspelled_parameter();

		foreach ( [ 'direct', 'proxied' ] as $path ) {
			$this->assertStringContainsString( 'Unrecognised parameters: `term_id`, `fields`.', $guidance[ $path ], $path );
			$this->assertStringContainsString( 'Accepted parameters:', $guidance[ $path ], $path );
			$this->assertStringContainsString( '`id` (integer', $guidance[ $path ], $path );
		}
	}

	/**
	 * The guidance is unchanged when the error message text changes.
	 *
	 * Against real core, real validation and a real registered schema: what the
	 * message says is read off the schema and the caller's input, never out of
	 * the text WordPress produced. Core translates that text, which is how the
	 * execute-ability path came to improve nothing on a non-English site — so
	 * this varies the text directly rather than needing a language pack.
	 *
	 * @return void
	 */
	public function test_guidance_ignores_the_error_message_text(): void {
		$real = $this->guidance_for_a_misspelled_parameter();

		$supplied = [
			'term_id' => 76,
			'fields'  => 'all',
		];

		$replaced = $this->handle(
			new WP_Error( 'ability_invalid_input', 'iets heel anders' ),
			$supplied,
			'albert-view-term'
		)->get_error_message();

		$this->assertSame( $real['direct'], $replaced );
		$this->assertStringContainsString( '`term_id`', $replaced );
	}

	/**
	 * A meta-tool failure that is not an input rejection is left alone.
	 *
	 * Permission refusals and errors from the ability itself are reported by
	 * `execute-ability` in the same shape as a rejection, so the only thing that
	 * separates them is whether the input actually fails the schema. The input
	 * here has to be genuinely valid for the ability, or the message is improved
	 * and rightly so.
	 *
	 * @return void
	 */
	public function test_a_non_validation_meta_tool_failure_is_untouched(): void {
		$schema   = wp_get_ability( $this->ability() )->get_input_schema();
		$valid    = [
			'id'       => 1,
			'taxonomy' => 'category',
		];
		$this->assertTrue(
			rest_validate_value_from_schema( $valid, $schema, 'input' ),
			'This test only means anything while its input satisfies the real schema.'
		);

		$result = [
			'success' => false,
			'error'   => 'Sorry, you are not allowed to do that.',
		];

		$out = $this->handle(
			$result,
			[
				'ability_name' => $this->ability(),
				'parameters'   => $valid,
			],
			'mcp-adapter-execute-ability'
		);

		$this->assertSame( $result, $out );
	}

	/**
	 * Build a mocked MCP server with a given id.
	 *
	 * The adapter hooks are global, so the callbacks under test only act on
	 * Albert's own server — see {@see Server::is_albert_server()}. These tests
	 * exercise Albert's path, so they supply Albert's id.
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
	 * Call the observer as the adapter does, on Albert's own server.
	 *
	 * @param mixed  $result    The tool result.
	 * @param mixed  $args      The tool arguments.
	 * @param string $tool_name The tool name.
	 * @param mixed  $mcp_tool  The adapter tool instance, if any.
	 *
	 * @return mixed
	 */
	private function handle( $result, $args, string $tool_name, $mcp_tool = null ): mixed {
		return $this->observer->handle( $result, $args, $tool_name, $mcp_tool, $this->server() );
	}
}
