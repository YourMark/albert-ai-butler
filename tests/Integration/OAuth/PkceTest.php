<?php
/**
 * Integration tests for PKCE at the point a code is issued.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth;

use Albert\Database\Tables;
use Albert\OAuth\Endpoints\AuthorizationPage;
use Albert\OAuth\Entities\ClientEntity;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\Tests\TestCase;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Codes are issued only for an S256 challenge, confidential clients included.
 *
 * @covers \Albert\OAuth\Endpoints\AuthorizationPage::issue_code
 */
class PkceTest extends TestCase {

	private const REDIRECT  = 'https://app.example.com/callback';
	private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	/**
	 * A confidential client, the kind league exempts from PKCE.
	 *
	 * @return ClientEntity
	 */
	private function confidential_client(): ClientEntity {
		$repository  = new ClientRepository();
		$credentials = $repository->createClient( 'Confidential app', self::REDIRECT, true );
		$client      = $repository->getClientEntity( (string) $credentials['client_id'] );

		$this->assertNotNull( $client );
		$this->assertTrue( $client->isConfidential() );

		return $client;
	}

	/**
	 * Codes stored for the client.
	 *
	 * @param ClientEntity $client The client.
	 *
	 * @return int
	 */
	private function codes( ClientEntity $client ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE client_id = %s', Tables::oauth()['auth_codes'], $client->getIdentifier() ) );
	}

	/**
	 * An S256 challenge gets a code.
	 *
	 * @return void
	 */
	public function test_an_s256_challenge_gets_a_code(): void {
		$client   = $this->confidential_client();
		$response = AuthorizationPage::issue_code( $client, self::REDIRECT, 'xyz', 'default', self::CHALLENGE, 'S256', self::factory()->user->create() );

		$this->assertStringContainsString( 'code=', $response->getHeaderLine( 'Location' ) );
		$this->assertSame( 1, $this->codes( $client ) );
	}

	/**
	 * No challenge, or a `plain` one, gets no code.
	 *
	 * @dataProvider refused_challenges
	 *
	 * @param string $challenge The code_challenge.
	 * @param string $method    The code_challenge_method.
	 *
	 * @return void
	 */
	public function test_a_refused_challenge_gets_no_code( string $challenge, string $method ): void {
		$client = $this->confidential_client();

		try {
			AuthorizationPage::issue_code( $client, self::REDIRECT, 'xyz', 'default', $challenge, $method, self::factory()->user->create() );
			$this->fail( 'A code was issued.' );
		} catch ( OAuthServerException $e ) {
			$this->assertSame( 'invalid_request', $e->getErrorType() );
		}

		$this->assertSame( 0, $this->codes( $client ) );
	}

	/**
	 * Challenges league would accept from a confidential client.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function refused_challenges(): array {
		return [
			'no challenge' => [ '', '' ],
			'plain'        => [ self::CHALLENGE, 'plain' ],
		];
	}
}
