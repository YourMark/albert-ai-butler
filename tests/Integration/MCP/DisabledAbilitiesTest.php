<?php
/**
 * Switched-off abilities on Albert's MCP server.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\MCP;

use Albert\Database\Tables;
use Albert\MCP\DisabledAbilities;
use Albert\MCP\Server;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Entities\AccessTokenEntity;
use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Entities\ScopeEntity;
use Albert\OAuth\Repositories\AccessTokenRepository;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\Server\ConnectionContext;
use Albert\OAuth\Server\KeyManager;
use Albert\OAuth\Server\ResourceServerFactory;
use Albert\Tests\TestCase;
use DateTimeImmutable;
use League\OAuth2\Server\CryptKey;
use WP\MCP\Core\McpServer;
use WP_Error;

/**
 * A switched-off ability is hidden from discovery and refused over Albert, and
 * a connection's token reaches it nowhere else.
 *
 * @covers \Albert\MCP\DisabledAbilities
 */
class DisabledAbilitiesTest extends TestCase {

	/**
	 * Under test.
	 *
	 * @var DisabledAbilities
	 */
	private DisabledAbilities $gate;

	/**
	 * Switch off one ability.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->gate = new DisabledAbilities();

		update_option( 'albert_abilities_saved', true );
		update_option( 'albert_disabled_abilities', [ 'other/switched-off' ] );
	}

	/**
	 * Discovery leaves the switched-off ability out and keeps the rest.
	 *
	 * @return void
	 */
	public function test_discovery_leaves_out_a_switched_off_ability(): void {
		$result = [
			'abilities' => [
				[ 'name' => 'other/switched-off' ],
				[ 'name' => 'other/switched-on' ],
			],
		];

		$filtered = $this->gate->hide_from_discovery( $result, [], 'mcp-adapter-discover-abilities', null, $this->server() );

		$this->assertSame( [ [ 'name' => 'other/switched-on' ] ], $filtered['abilities'] );
	}

	/**
	 * Another plugin's MCP server is not Albert's to filter.
	 *
	 * @return void
	 */
	public function test_another_server_is_left_alone(): void {
		$result = [ 'abilities' => [ [ 'name' => 'other/switched-off' ] ] ];

		$this->assertSame( $result, $this->gate->hide_from_discovery( $result, [], 'mcp-adapter/discover-abilities', null, $this->server( 'someone-else' ) ) );

		$args = [ 'ability_name' => 'other/switched-off' ];
		$this->assertSame( $args, $this->gate->refuse( $args, 'mcp-adapter/execute-ability', null, $this->server( 'someone-else' ) ) );
	}

	/**
	 * Execute and get-info are refused, in either tool-name spelling.
	 *
	 * @return void
	 */
	public function test_execute_and_get_info_are_refused(): void {
		$args = [
			'ability_name' => 'other/switched-off',
			'parameters'   => [],
		];

		foreach ( [ 'mcp-adapter/execute-ability', 'mcp-adapter-execute-ability', 'mcp-adapter-get-ability-info' ] as $tool ) {
			$result = $this->gate->refuse( $args, $tool, null, $this->server() );

			$this->assertInstanceOf( WP_Error::class, $result, $tool );
			$this->assertSame( 'ability_disabled', $result->get_error_code() );
			$this->assertStringContainsString( 'switched off on this site', $result->get_error_message() );
		}
	}

	/**
	 * A switched-on ability passes through untouched.
	 *
	 * @return void
	 */
	public function test_a_switched_on_ability_passes(): void {
		$args = [ 'ability_name' => 'other/switched-on' ];

		$this->assertSame( $args, $this->gate->refuse( $args, 'mcp-adapter/execute-ability', null, $this->server() ) );
	}

	/**
	 * A connection's token authenticates only Albert's routes, so it cannot
	 * reach a switched-off ability through core's Abilities REST API.
	 *
	 * @return void
	 */
	public function test_a_token_does_not_authenticate_core_routes(): void {
		ResourceServerFactory::reset();
		ConnectionContext::reset();

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		AllowedUsers::add( $user_id );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->issue_token( $user_id );
		wp_set_current_user( 0 );

		try {
			$this->assertSame( 0, (int) apply_filters( 'determine_current_user', false ), 'A Bearer token must not log anybody in site-wide.' );

			$response = rest_do_request( new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/other/switched-off/run' ) );

			$this->assertContains( $response->get_status(), [ 401, 403, 404 ] );
			$this->assertNull( ConnectionContext::client_id(), 'Core routes never set a connection.' );
		} finally {
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
			ConnectionContext::reset();
		}
	}

	/**
	 * Persist an access token for a user and return its JWT.
	 *
	 * @param int $user_id The token's user.
	 *
	 * @return string
	 */
	private function issue_token( int $user_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reset.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::oauth()['clients'] ) );

		$created = ( new ClientRepository() )->createClient( 'Assistant', 'https://example.test/cb', true );

		$client = new ClientEntity();
		$client->setIdentifier( (string) $created['client_id'] );

		$token = new AccessTokenEntity();
		$token->setIdentifier( 'tok_' . $created['client_id'] );
		$token->setClient( $client );
		$token->setUserIdentifier( (string) $user_id );
		$token->setExpiryDateTime( new DateTimeImmutable( '+1 hour' ) );
		$token->addScope( new ScopeEntity( 'default' ) );
		$token->setPrivateKey( new CryptKey( KeyManager::get_private_key(), null, false ) );

		( new AccessTokenRepository() )->persistNewAccessToken( $token );

		return $token->toString();
	}

	/**
	 * A mocked MCP server with a given id.
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
}
