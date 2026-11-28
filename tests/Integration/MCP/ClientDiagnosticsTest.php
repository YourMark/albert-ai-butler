<?php
/**
 * Integration tests for what connected clients report about themselves.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\MCP;

use Albert\MCP\ClientDiagnostics;
use Albert\OAuth\Server\ConnectionContext;
use Albert\Tests\TestCase;
use WP_REST_Request;

/**
 * Protocol, capabilities and client info per connection, from `initialize` and from 2026 `_meta`.
 *
 * @covers \Albert\MCP\ClientDiagnostics
 */
class ClientDiagnosticsTest extends TestCase {

	/**
	 * Start empty.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( ClientDiagnostics::OPTION );
	}

	/**
	 * Close the connection.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		ConnectionContext::reset();
		parent::tear_down();
	}

	/**
	 * An `initialize` on protocol 2025-11-25 names the version, capabilities and app.
	 *
	 * @return void
	 */
	public function test_initialize_is_recorded(): void {
		$this->send(
			'client-a',
			[
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => [
					'protocolVersion' => '2025-11-25',
					'capabilities'    => [
						'roots'       => [ 'listChanged' => true ],
						'elicitation' => [],
					],
					'clientInfo'      => [
						'name'    => 'claude-ai',
						'version' => '0.1.0',
					],
				],
			]
		);

		$record = ClientDiagnostics::all()['client-a'];
		$this->assertSame( '2025-11-25', $record['protocol'] );
		$this->assertSame( [ 'elicitation', 'roots' ], $record['capabilities'] );
		$this->assertFalse( $record['skills'] );
		$this->assertSame( 'claude-ai 0.1.0', $record['client'] );
	}

	/**
	 * A 2026 request carries it all in `_meta`, including the skills extension.
	 *
	 * @return void
	 */
	public function test_2026_meta_declares_skills(): void {
		$this->send(
			'client-b',
			[
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
				'params'  => [
					'_meta' => [
						'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
						'io.modelcontextprotocol/clientCapabilities' => [ 'extensions' => [ 'io.modelcontextprotocol/skills' => [] ] ],
						'io.modelcontextprotocol/clientInfo'         => [ 'name' => 'Claude' ],
					],
				],
			]
		);

		$record = ClientDiagnostics::all()['client-b'];
		$this->assertSame( '2026-07-28', $record['protocol'] );
		$this->assertTrue( $record['skills'] );
		$this->assertSame( 'Claude', $record['client'] );
	}

	/**
	 * A later request without capabilities keeps what `initialize` declared.
	 *
	 * @return void
	 */
	public function test_a_plain_request_keeps_the_declared_capabilities(): void {
		$this->send(
			'client-c',
			[
				'method' => 'initialize',
				'params' => [
					'protocolVersion' => '2025-11-25',
					'capabilities'    => [ 'sampling' => [] ],
				],
			]
		);
		$this->send( 'client-c', [ 'method' => 'tools/list' ], '2025-11-25' );

		$this->assertSame( [ 'sampling' ], ClientDiagnostics::all()['client-c']['capabilities'] );
	}

	/**
	 * Without a connection, or on another route, nothing is recorded.
	 *
	 * @return void
	 */
	public function test_only_connections_on_alberts_route_are_recorded(): void {
		$request = new WP_REST_Request( 'POST', '/albert/v1/mcp' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'method' => 'initialize',
					'params' => [ 'protocolVersion' => '2025-11-25' ],
				]
			)
		);
		( new ClientDiagnostics() )->observe( null, null, $request );

		ConnectionContext::set( 'client-d' );
		$other = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$other->set_body(
			(string) wp_json_encode(
				[
					'method' => 'initialize',
					'params' => [ 'protocolVersion' => '2025-11-25' ],
				]
			)
		);
		( new ClientDiagnostics() )->observe( null, null, $other );

		$this->assertSame( [], ClientDiagnostics::all() );
	}

	/**
	 * Site Health lists each connection on one line.
	 *
	 * @return void
	 */
	public function test_site_health_lists_connections(): void {
		$this->send(
			'client-e',
			[
				'method' => 'initialize',
				'params' => [
					'protocolVersion' => '2025-11-25',
					'clientInfo'      => [ 'name' => 'cursor' ],
				],
			]
		);

		$info = ( new ClientDiagnostics() )->add_debug_information( [] );

		$this->assertStringContainsString( '(cursor): protocol 2025-11-25, supports skills: no', $info['albert']['fields']['client_diagnostics']['value'] );
	}

	/**
	 * Send one MCP message as a connection.
	 *
	 * @param string               $client_id The connection.
	 * @param array<string, mixed> $message   The JSON-RPC message.
	 * @param string               $header    The protocol header, if any.
	 *
	 * @return void
	 */
	private function send( string $client_id, array $message, string $header = '' ): void {
		ConnectionContext::set( $client_id );

		$request = new WP_REST_Request( 'POST', '/albert/v1/mcp' );
		$request->set_body( (string) wp_json_encode( $message ) );
		if ( $header !== '' ) {
			$request->set_header( 'mcp-protocol-version', $header );
		}

		( new ClientDiagnostics() )->observe( null, null, $request );
		ConnectionContext::reset();
	}
}
