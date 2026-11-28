<?php
/**
 * Native MCP skills over Albert's route.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\MCP\Skills;

use Albert\Core\Plugin;
use Albert\Database\Tables;
use Albert\MCP\Server;
use Albert\MCP\Skills\NativeSkills;
use Albert\MCP\Skills\SkillRegistry;
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
use WP\MCP\Core\McpAdapter;
use WP\MCP\Transport\HttpTransport;
use WP_REST_Request;

/**
 * A 2026-07-28 client that declares the skills extension lists and reads the
 * registry's skills natively; every other client sees what it saw before.
 *
 * Real requests to `/albert/v1/mcp` with a connection's token, through the adapter.
 *
 * @covers \Albert\MCP\Skills\NativeSkills
 */
class NativeSkillsTest extends TestCase {

	private const URI = 'skill://native-fixture/SKILL.md';

	private const BODY = "# Native fixture\n\nDo the thing: carefully.";

	/**
	 * The connection's access token.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * The skill file written for the test.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * One registered skill, from a file whose frontmatter carries Albert's keys, and a connection.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->file = wp_tempnam( 'native-fixture' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents(
			$this->file,
			"---\nname: native-fixture\ndescription: Use this: when testing \"native\" skills.\nmetadata:\n  priority: \"2\"\n  requires: \"\"\n---\n\n" . self::BODY . "\n"
		);

		add_filter( 'albert/skills/registry', [ $this, 'register_fixture' ] );
		SkillRegistry::reset_cache();

		ResourceServerFactory::reset();
		ConnectionContext::reset();

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		AllowedUsers::add( $user_id );
		$this->token = $this->issue_token( $user_id );
		wp_set_current_user( 0 );

		$this->ensure_route();
	}

	/**
	 * Register Albert's MCP route when an earlier test's hook reset dropped it.
	 *
	 * The adapter starts once per process, and its transport adds the route on
	 * `rest_api_init` from inside that start, which the test case's hook backup undoes.
	 *
	 * @return void
	 */
	private function ensure_route(): void {
		$route = '/' . Plugin::rest_namespace() . '/' . Server::ROUTE;
		if ( isset( rest_get_server()->get_routes()[ $route ] ) ) {
			return;
		}

		$server = McpAdapter::instance()->get_server( Server::SERVER_ID );
		$this->assertNotNull( $server, 'Albert\'s MCP server is created.' );

		// The transport adds its route on `rest_api_init`; a fresh REST server fires it.
		new HttpTransport( $server->create_transport_context() );
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * Remove the fixture and the connection state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_delete_file( $this->file );
		SkillRegistry::reset_cache();
		ConnectionContext::reset();
		parent::tear_down();
	}

	/**
	 * Only the fixture skill is registered.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_fixture(): array {
		return [
			'native-fixture' => [
				'summary'  => 'Use this: when testing "native" skills.',
				'file'     => $this->file,
				'priority' => 2,
			],
		];
	}

	/**
	 * Discovery names the extension for a client that declares it, beside `resources`.
	 *
	 * @return void
	 */
	public function test_discover_advertises_the_extension_to_a_client_that_declares_it(): void {
		$response = $this->call( 'server/discover' );

		$capabilities = $response['data']['result']['capabilities'];
		$this->assertSame( 200, $response['status'] );
		$this->assertArrayHasKey( 'resources', $capabilities );
		$this->assertSame( [], $capabilities['extensions'][ NativeSkills::EXTENSION ] );
		$this->assertStringContainsString( '"' . NativeSkills::EXTENSION . '":{}', $response['json'], 'Sent as an empty object.' );
	}

	/**
	 * A 2026-07-28 client that does not declare the extension is not told about it.
	 *
	 * @return void
	 */
	public function test_discover_leaves_out_the_extension_without_the_client_opting_in(): void {
		$response = $this->call( 'server/discover', [], false );

		$this->assertSame( 200, $response['status'] );
		$this->assertArrayNotHasKey( 'extensions', $response['data']['result']['capabilities'] );
	}

	/**
	 * The listing carries the skill's URI, spec frontmatter, digest and size, cached privately.
	 *
	 * @return void
	 */
	public function test_skills_list_lists_the_available_skills(): void {
		$response = $this->call( 'skills/list' );
		$result   = $response['data']['result'];
		$document = $this->read_text();

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'complete', $result['resultType'] );
		$this->assertSame( 'private', $result['cacheScope'] );
		$this->assertSame( NativeSkills::TTL_MS, $result['ttlMs'] );
		$this->assertArrayNotHasKey( 'nextCursor', $result );
		$this->assertCount( 1, $result['skills'] );

		$skill = $result['skills'][0];
		$this->assertSame( self::URI, $skill['uri'] );
		$this->assertSame(
			[
				'uri'    => self::URI,
				'digest' => 'sha256:' . hash( 'sha256', $document ),
				'size'   => strlen( $document ),
			],
			$skill['resources'][0]
		);
	}

	/**
	 * Albert's own keys never reach the client, in the entry or in the file.
	 *
	 * @return void
	 */
	public function test_albert_keys_are_stripped(): void {
		$skill = $this->call( 'skills/list' )['data']['result']['skills'][0];

		$this->assertSame(
			[
				'name'        => 'native-fixture',
				'description' => 'Use this: when testing "native" skills.',
			],
			$skill['frontmatter']
		);
		$this->assertStringNotContainsString( 'metadata', $this->read_text() );
		$this->assertStringNotContainsString( 'priority', $this->read_text() );
	}

	/**
	 * `skills/get` returns the same entry the listing does.
	 *
	 * @return void
	 */
	public function test_skills_get_returns_the_entry(): void {
		$listed = $this->call( 'skills/list' )['data']['result']['skills'][0];
		$result = $this->call( 'skills/get', [ 'uri' => self::URI ] )['data']['result'];

		$this->assertSame( 'complete', $result['resultType'] );
		$this->assertSame( 'private', $result['cacheScope'] );
		$this->assertSame( $listed, $result['skill'] );
	}

	/**
	 * `resources/read` returns SKILL.md: spec frontmatter that matches the entry, then the body.
	 *
	 * @return void
	 */
	public function test_resources_read_returns_the_skill_file(): void {
		$served = [];
		add_action(
			'albert/skills/served',
			static function ( string $slug, string $route ) use ( &$served ): void {
				$served[] = [ $slug, $route ];
			},
			10,
			2
		);

		$response = $this->call( 'resources/read', [ 'uri' => self::URI ] );
		$result   = $response['data']['result'];

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'private', $result['cacheScope'] );
		$this->assertSame( self::URI, $result['contents'][0]['uri'] );
		$this->assertSame( 'text/markdown', $result['contents'][0]['mimeType'] );
		$this->assertSame(
			"---\nname: native-fixture\ndescription: \"Use this: when testing \\\"native\\\" skills.\"\n---\n\n" . self::BODY . "\n",
			$result['contents'][0]['text']
		);
		$this->assertSame( [ [ 'native-fixture', 'native' ] ], $served );
	}

	/**
	 * An unknown skill is invalid params, to `skills/get` and to `resources/read`.
	 *
	 * @return void
	 */
	public function test_an_unknown_uri_is_invalid_params(): void {
		$unknown = 'skill://no-such-skill/SKILL.md';

		$get = $this->call( 'skills/get', [ 'uri' => $unknown ] );
		$this->assertSame( -32602, $get['data']['error']['code'] );
		$this->assertSame( 400, $get['status'] );

		$read = $this->call( 'resources/read', [ 'uri' => $unknown ] );
		$this->assertSame( -32602, $read['data']['error']['code'] );

		$file = $this->call( 'skills/get', [ 'uri' => 'skill://native-fixture/other.md' ] );
		$this->assertSame( -32602, $file['data']['error']['code'] );
	}

	/**
	 * A switched-off or unavailable skill is neither listed nor served.
	 *
	 * @return void
	 */
	public function test_an_unavailable_skill_is_not_served(): void {
		add_filter( 'albert/skills/available', '__return_false' );

		$this->assertSame( [], $this->call( 'skills/list' )['data']['result']['skills'] );
		$this->assertSame( -32602, $this->call( 'skills/get', [ 'uri' => self::URI ] )['data']['error']['code'] );
		$this->assertArrayNotHasKey( 'extensions', $this->call( 'server/discover' )['data']['result']['capabilities'] );
	}

	/**
	 * A 2025-11-25 client gets exactly what it got before: no skills methods.
	 *
	 * @return void
	 */
	public function test_a_2025_client_sees_no_change(): void {
		$on = $this->call( 'skills/list', [], true, '2025-11-25' );

		add_filter( 'albert/skills/native', '__return_false' );
		$off = $this->call( 'skills/list', [], true, '2025-11-25' );

		$this->assertSame( $off, $on );
		$this->assertArrayHasKey( 'error', $on['data'] );
		$this->assertArrayNotHasKey( 'result', $on['data'] );
	}

	/**
	 * The filter turns it all off: the adapter's own answers come back.
	 *
	 * @return void
	 */
	public function test_the_filter_stands_native_skills_down(): void {
		add_filter( 'albert/skills/native', '__return_false' );

		$this->assertArrayNotHasKey( 'extensions', $this->call( 'server/discover' )['data']['result']['capabilities'] );
		$this->assertSame( -32601, $this->call( 'skills/list' )['data']['error']['code'] );
		$this->assertSame( -32602, $this->call( 'resources/read', [ 'uri' => self::URI ] )['data']['error']['code'] );
	}

	/**
	 * When the adapter declares the extension itself, Albert leaves its declaration alone.
	 *
	 * @return void
	 */
	public function test_an_adapter_that_declares_the_extension_is_left_alone(): void {
		$settings = (object) [ 'directoryRead' => true ];
		add_filter(
			'rest_request_after_callbacks',
			static function ( $response ) use ( $settings ) {
				$data = json_decode( (string) wp_json_encode( $response->get_data() ) );
				if ( isset( $data->result->capabilities ) ) {
					$data->result->capabilities->extensions = (object) [ NativeSkills::EXTENSION => $settings ];
					$response->set_data( $data );
				}
				return $response;
			},
			5
		);

		$capabilities = $this->call( 'server/discover' )['data']['result']['capabilities'];

		$this->assertSame( [ 'directoryRead' => true ], $capabilities['extensions'][ NativeSkills::EXTENSION ] );
	}

	/**
	 * The text of the fixture's SKILL.md as served.
	 *
	 * @return string
	 */
	private function read_text(): string {
		return (string) $this->call( 'resources/read', [ 'uri' => self::URI ] )['data']['result']['contents'][0]['text'];
	}

	/**
	 * Send one JSON-RPC request to Albert's MCP route.
	 *
	 * @param string               $method    The MCP method.
	 * @param array<string, mixed> $params    Params besides `_meta`.
	 * @param bool                 $declares  Whether the client declares the skills extension.
	 * @param string               $revision  The protocol revision.
	 *
	 * @return array{status: int, data: array<string, mixed>, json: string}
	 */
	private function call( string $method, array $params = [], bool $declares = true, string $revision = NativeSkills::REVISION ): array {
		ConnectionContext::reset();
		SkillRegistry::reset_cache();
		wp_set_current_user( 0 );

		$capabilities = $declares ? [ 'extensions' => [ NativeSkills::EXTENSION => new \stdClass() ] ] : new \stdClass();

		$body = [
			'jsonrpc' => '2.0',
			'id'      => 7,
			'method'  => $method,
			'params'  => $params + [
				'_meta' => [
					'io.modelcontextprotocol/protocolVersion' => $revision,
					'io.modelcontextprotocol/clientCapabilities' => $capabilities,
					'io.modelcontextprotocol/clientInfo' => [
						'name'    => 'Test client',
						'version' => '1.0.0',
					],
				],
			],
		];

		$request = new WP_REST_Request( 'POST', '/albert/v1/mcp' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'mcp-protocol-version', $revision );
		$request->set_header( 'mcp-method', $method );
		if ( isset( $params['uri'] ) ) {
			$request->set_header( 'mcp-name', $params['uri'] );
		}
		if ( $this->token !== '' ) {
			$request->set_header( 'authorization', 'Bearer ' . $this->token );
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
		}
		$request->set_body( (string) wp_json_encode( $body ) );

		try {
			$response = rest_get_server()->dispatch( $request );
		} finally {
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
		}

		$json = (string) wp_json_encode( $response->get_data(), JSON_UNESCAPED_SLASHES );

		return [
			'status' => $response->get_status(),
			'data'   => (array) json_decode( $json, true ),
			'json'   => $json,
		];
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
}
