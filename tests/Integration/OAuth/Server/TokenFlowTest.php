<?php
/**
 * Integration tests for the full code, token and refresh flow.
 *
 * A token is only good while its stored row belongs to the same user and
 * client and its user is still on the allowed list; removing someone stops
 * their codes and refresh tokens too.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth\Server;

use Albert\Database\Tables;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Endpoints\OAuthController;
use Albert\OAuth\Endpoints\Psr7Bridge;
use Albert\OAuth\Entities\UserEntity;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\Server\AuthorizationServerFactory;
use Albert\OAuth\Server\ConnectionContext;
use Albert\OAuth\Server\ResourceServerFactory;
use Albert\OAuth\Server\TokenValidator;
use Albert\Tests\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_User;

/**
 * Token flow tests.
 *
 * @covers \Albert\OAuth\Server\TokenValidator::validate_request
 * @covers \Albert\OAuth\Server\TokenValidator::get_token_metadata
 * @covers \Albert\OAuth\Repositories\AccessTokenRepository::ownsToken
 * @covers \Albert\OAuth\Repositories\AccessTokenRepository::getNewToken
 */
class TokenFlowTest extends TestCase {

	private const REDIRECT_URI = 'http://127.0.0.1:5000/callback';

	private const VERIFIER = 'a-pkce-verifier-that-is-long-enough-for-rfc-7636-and-then-some';

	/**
	 * The approving user.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * The registered public client.
	 *
	 * @var string
	 */
	private string $client_id;

	/**
	 * A user on the allowed list and a public loopback client.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Keys live in options, which each test rolls back.
		ResourceServerFactory::reset();
		AuthorizationServerFactory::reset();
		ConnectionContext::reset();

		$this->user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		AllowedUsers::add( $this->user_id );

		$client          = ( new ClientRepository() )->createClient( 'Test app', (string) wp_json_encode( [ self::REDIRECT_URI ] ), false, null, null, 'dcr' );
		$this->client_id = (string) $client['client_id'];
	}

	/**
	 * A validated token records its connection; clear it for the next test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		ConnectionContext::reset();

		parent::tear_down();
	}

	/**
	 * Issue an authorization code as the approving user.
	 *
	 * @return string
	 */
	private function code(): string {
		$server  = AuthorizationServerFactory::create();
		$request = Psr7Bridge::create_server_request( 'GET', home_url( '/oauth/authorize' ) )->withQueryParams(
			[
				'response_type'         => 'code',
				'client_id'             => $this->client_id,
				'redirect_uri'          => self::REDIRECT_URI,
				'scope'                 => 'default',
				'state'                 => 's',
				'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', self::VERIFIER, true ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE S256.
				'code_challenge_method' => 'S256',
			]
		);

		$auth = $server->validateAuthorizationRequest( $request );
		$auth->setUser( new UserEntity( $this->user_id ) );
		$auth->setAuthorizationApproved( true );

		$location = $server->completeAuthorizationRequest( $auth, Psr7Bridge::create_response() )->getHeaderLine( 'Location' );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		return (string) $query['code'];
	}

	/**
	 * Post to the token endpoint.
	 *
	 * @param array<string, string> $params Body parameters.
	 *
	 * @return array{status: int, data: array<string, mixed>}
	 */
	private function token_request( array $params ): array {
		$request = new WP_REST_Request( 'POST', '/albert/v1/oauth/token' );
		$request->set_header( 'content-type', 'application/x-www-form-urlencoded' );
		$request->set_body_params( $params + [ 'client_id' => $this->client_id ] );

		$response = ( new OAuthController() )->handle_token( $request );
		$data     = $response->get_data();

		if ( is_string( $data ) ) {
			$data = (array) json_decode( $data, true );
		}

		return [
			'status' => $response->get_status(),
			'data'   => (array) $data,
		];
	}

	/**
	 * Exchange a fresh code for tokens.
	 *
	 * @return array<string, mixed>
	 */
	private function tokens(): array {
		$result = $this->token_request(
			[
				'grant_type'    => 'authorization_code',
				'code'          => $this->code(),
				'redirect_uri'  => self::REDIRECT_URI,
				'code_verifier' => self::VERIFIER,
			]
		);

		$this->assertSame( 200, $result['status'], (string) wp_json_encode( $result['data'] ) );

		return $result['data'];
	}

	/**
	 * A request carrying the access token.
	 *
	 * @param string $access_token The token.
	 *
	 * @return WP_REST_Request<array<string, mixed>>
	 */
	private function bearer( string $access_token ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/albert/v1/mcp' );
		$request->set_header( 'authorization', 'Bearer ' . $access_token );

		return $request;
	}

	/**
	 * Change the stored row of every access token.
	 *
	 * @param array<string, mixed> $data Columns to set.
	 *
	 * @return void
	 */
	private function tamper( array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->update( Tables::oauth()['access_tokens'], $data, [ 'client_id' => $this->client_id ] );
	}

	/**
	 * A genuine token authenticates its user.
	 *
	 * @return void
	 */
	public function test_a_genuine_token_authenticates(): void {
		$user = TokenValidator::validate_request( $this->bearer( (string) $this->tokens()['access_token'] ) );

		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( $this->user_id, $user->ID );
	}

	/**
	 * A token whose row belongs to another user is refused.
	 *
	 * @return void
	 */
	public function test_a_row_for_another_user_is_refused(): void {
		$token = (string) $this->tokens()['access_token'];
		$this->tamper( [ 'user_id' => self::factory()->user->create() ] );

		$result = TokenValidator::validate_request( $this->bearer( $token ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'oauth_invalid_token', $result->get_error_code() );
	}

	/**
	 * A token whose row belongs to another client is refused.
	 *
	 * @return void
	 */
	public function test_a_row_for_another_client_is_refused(): void {
		$token = (string) $this->tokens()['access_token'];

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET client_id = %s', Tables::oauth()['access_tokens'], 'another-client' ) );

		$result = TokenValidator::validate_request( $this->bearer( $token ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'oauth_invalid_token', $result->get_error_code() );
	}

	/**
	 * A token whose row has expired is refused, whatever the JWT says.
	 *
	 * @return void
	 */
	public function test_an_expired_row_is_refused(): void {
		$token = (string) $this->tokens()['access_token'];
		$this->tamper( [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );

		$this->assertInstanceOf( WP_Error::class, TokenValidator::validate_request( $this->bearer( $token ) ) );
	}

	/**
	 * The metadata path runs the same checks.
	 *
	 * @return void
	 */
	public function test_metadata_runs_the_same_checks(): void {
		$token = (string) $this->tokens()['access_token'];

		$this->assertSame( $this->user_id, TokenValidator::get_token_metadata( $this->bearer( $token ) )['user_id'] );

		$this->tamper( [ 'user_id' => self::factory()->user->create() ] );

		$this->assertInstanceOf( WP_Error::class, TokenValidator::get_token_metadata( $this->bearer( $token ) ) );
	}

	/**
	 * Removing someone from the allowed list stops their access token at once.
	 *
	 * @return void
	 */
	public function test_a_removed_user_is_refused_on_the_next_request(): void {
		$token = (string) $this->tokens()['access_token'];
		AllowedUsers::remove( $this->user_id );

		$result = TokenValidator::validate_request( $this->bearer( $token ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'oauth_user_not_allowed', $result->get_error_code() );
	}

	/**
	 * A removed user's refresh token gets invalid_grant.
	 *
	 * @return void
	 */
	public function test_a_removed_user_cannot_refresh(): void {
		$refresh = (string) $this->tokens()['refresh_token'];
		AllowedUsers::remove( $this->user_id );

		$result = $this->token_request(
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			]
		);

		$this->assertSame( 'invalid_grant', $result['data']['error'] ?? null );
	}

	/**
	 * A removed user's outstanding authorization code gets invalid_grant.
	 *
	 * @return void
	 */
	public function test_a_removed_user_cannot_exchange_a_code(): void {
		$code = $this->code();
		AllowedUsers::remove( $this->user_id );

		$result = $this->token_request(
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => self::REDIRECT_URI,
				'code_verifier' => self::VERIFIER,
			]
		);

		$this->assertSame( 'invalid_grant', $result['data']['error'] ?? null );
	}

	/**
	 * A user still on the list can refresh.
	 *
	 * @return void
	 */
	public function test_an_allowed_user_can_refresh(): void {
		$result = $this->token_request(
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => (string) $this->tokens()['refresh_token'],
			]
		);

		$this->assertSame( 200, $result['status'] );
		$this->assertArrayHasKey( 'access_token', $result['data'] );
	}
}
