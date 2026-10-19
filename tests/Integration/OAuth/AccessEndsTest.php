<?php
/**
 * A connection ends when the person's access does.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth;

use Albert\Database\Tables;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Entities\AccessTokenEntity;
use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Entities\ScopeEntity;
use Albert\OAuth\PasswordChange;
use Albert\OAuth\Repositories\AccessTokenRepository;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\Server\ConnectionContext;
use Albert\OAuth\Server\KeyManager;
use Albert\OAuth\Server\ResourceServerFactory;
use Albert\OAuth\Server\TokenValidator;
use Albert\Tests\TestCase;
use DateTimeImmutable;
use League\OAuth2\Server\CryptKey;
use WP_Error;
use WP_REST_Request;
use WP_User;

/**
 * Password changes revoke tokens, and the allowed list is checked on every request.
 *
 * @covers \Albert\OAuth\PasswordChange
 * @covers \Albert\OAuth\Server\TokenValidator::validate_request
 */
class AccessEndsTest extends TestCase {

	/**
	 * The person holding the token.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Their token, as the assistant sends it.
	 *
	 * @var string
	 */
	private string $jwt;

	/**
	 * An allowed person with one live connection.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		ConnectionContext::reset();
		// Keys live in options, which roll back per test; a cached server would keep the last test's key.
		ResourceServerFactory::reset();

		global $wpdb;

		foreach ( [ 'clients', 'access_tokens', 'refresh_tokens' ] as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reset.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::oauth()[ $key ] ) );
		}

		$this->user_id = self::factory()->user->create( [ 'role' => 'editor', 'user_pass' => 'old-password' ] );
		AllowedUsers::add( $this->user_id );
		AllowedUsers::mark_authorised( $this->user_id );

		$this->jwt = $this->issue_token();
	}

	/**
	 * validate_request() marks the request as a connection, which would refuse later option writes.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		ConnectionContext::reset();
		parent::tear_down();
	}

	/**
	 * Persist an access token for the user and return its JWT.
	 *
	 * @return string
	 */
	private function issue_token(): string {
		$created = ( new ClientRepository() )->createClient( 'Assistant', 'https://example.test/cb', true );

		$client = new ClientEntity();
		$client->setIdentifier( (string) $created['client_id'] );

		$token = new AccessTokenEntity();
		$token->setIdentifier( 'tok_' . $created['client_id'] );
		$token->setClient( $client );
		$token->setUserIdentifier( (string) $this->user_id );
		$token->setExpiryDateTime( new DateTimeImmutable( '+1 hour' ) );
		$token->addScope( new ScopeEntity( 'default' ) );
		$token->setPrivateKey( new CryptKey( KeyManager::get_private_key(), null, false ) );

		( new AccessTokenRepository() )->persistNewAccessToken( $token );

		return $token->toString();
	}

	/**
	 * Validate the token the way the MCP endpoint does.
	 *
	 * @return WP_User|WP_Error
	 */
	private function validate() {
		$request = new WP_REST_Request( 'POST', '/albert/v1/mcp' );
		$request->set_header( 'authorization', 'Bearer ' . $this->jwt );

		return TokenValidator::validate_request( $request );
	}

	/**
	 * How many of the user's access tokens are still unrevoked.
	 *
	 * @return int
	 */
	private function live_tokens(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND revoked = 0', Tables::oauth()['access_tokens'], $this->user_id ) );
	}

	public function test_an_allowed_persons_token_works(): void {
		$user = $this->validate();

		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( $this->user_id, $user->ID );
	}

	public function test_a_token_stops_working_once_its_user_is_off_the_list(): void {
		// Not AllowedUsers::remove(), which revokes on the Connections screen's behalf;
		// this is a list that changed some other way.
		update_option( AllowedUsers::OPTION, [] );

		$result = $this->validate();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'oauth_user_not_allowed', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_changing_the_password_revokes_the_tokens(): void {
		wp_update_user(
			[
				'ID'        => $this->user_id,
				'user_pass' => 'new-password',
			]
		);

		$this->assertSame( 0, $this->live_tokens() );
		$this->assertInstanceOf( WP_Error::class, $this->validate() );
	}

	public function test_resetting_the_password_revokes_the_tokens(): void {
		reset_password( get_userdata( $this->user_id ), 'reset-password' );

		$this->assertSame( 0, $this->live_tokens() );
	}

	public function test_a_profile_save_without_a_new_password_keeps_the_tokens(): void {
		wp_update_user(
			[
				'ID'           => $this->user_id,
				'display_name' => 'Renamed',
			]
		);

		$this->assertSame( 1, $this->live_tokens() );
	}

	public function test_a_rehash_at_login_keeps_the_tokens(): void {
		// What core does when a stored hash needs upgrading during sign-in.
		wp_set_password( 'old-password', $this->user_id );

		$this->assertSame( 1, $this->live_tokens() );
	}

	public function test_the_hooks_are_registered(): void {
		$this->assertNotFalse( has_action( 'after_password_reset' ) );
		$this->assertNotFalse( has_action( 'profile_update' ) );
		$this->assertTrue( class_exists( PasswordChange::class ) );
	}
}
