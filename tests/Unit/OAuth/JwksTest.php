<?php
/**
 * Unit tests for the JSON Web Key Set builder.
 *
 * The key set is published at the `jwks_uri` a client reads from the RFC 8414
 * metadata. It must be a well-formed RFC 7517 document carrying the real signing
 * key, so a client that validates discovery — or that verifies token signatures
 * against it — gets the right answer.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\OAuth;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\OAuth\Jwks;
use Albert\OAuth\Server\KeyManager;
use PHPUnit\Framework\TestCase;

/**
 * JSON Web Key Set tests.
 *
 * @covers \Albert\OAuth\Jwks
 */
class JwksTest extends TestCase {

	/**
	 * The RSA public key seeded for the test, in PEM.
	 *
	 * @var string
	 */
	private string $public_pem = '';

	/**
	 * The raw modulus and exponent of that key, for cross-checking the JWK.
	 *
	 * @var array<string, string>
	 */
	private array $rsa = [];

	/**
	 * Seed a real RSA public key as the stored OAuth key.
	 *
	 * A genuine key, not a fixture: the builder's whole job is extracting `n`
	 * and `e` from an actual key, so a canned JWK would test nothing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$resource = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);

		$this->assertNotFalse( $resource, 'OpenSSL could not generate a test key pair.' );

		$details = openssl_pkey_get_details( $resource );

		$this->assertNotFalse( $details, 'OpenSSL could not read the test key details.' );

		$this->public_pem = $details['key'];
		$this->rsa        = [
			'n' => $details['rsa']['n'],
			'e' => $details['rsa']['e'],
		];

		$GLOBALS['albert_test_options'] = [
			KeyManager::PUBLIC_KEY_OPTION => $this->public_pem,
		];
	}

	/**
	 * The document is always the `keys` envelope RFC 7517 defines.
	 *
	 * @return void
	 */
	public function test_document_is_a_keys_envelope(): void {
		$document = Jwks::document();

		$this->assertArrayHasKey( 'keys', $document );
		$this->assertIsArray( $document['keys'] );
	}

	/**
	 * The one key is a signing RSA JWK with the expected fixed members.
	 *
	 * @return void
	 */
	public function test_publishes_a_signing_rsa_jwk(): void {
		$key = Jwks::document()['keys'][0];

		$this->assertSame( 'RSA', $key['kty'] );
		$this->assertSame( 'sig', $key['use'] );
		$this->assertSame( 'RS256', $key['alg'] );
		$this->assertNotEmpty( $key['kid'] );
	}

	/**
	 * `n` and `e` are the key's own values, base64url-encoded (no `+`, `/`, `=`).
	 *
	 * The base64url check is the one that matters: a base64-standard `n` is the
	 * classic JWKS bug, accepted by lenient parsers and rejected by strict ones.
	 *
	 * @return void
	 */
	public function test_modulus_and_exponent_are_the_key_base64url_encoded(): void {
		$key = Jwks::document()['keys'][0];

		$this->assertSame( $this->base64url( $this->rsa['n'] ), $key['n'] );
		$this->assertSame( $this->base64url( $this->rsa['e'] ), $key['e'] );

		foreach ( [ $key['n'], $key['e'], $key['kid'] ] as $value ) {
			$this->assertDoesNotMatchRegularExpression( '#[+/=]#', $value );
		}
	}

	/**
	 * The `kid` is stable for one key: two reads agree.
	 *
	 * A client caches keys by `kid`; a `kid` that changed per request would
	 * defeat that and churn the cache.
	 *
	 * @return void
	 */
	public function test_kid_is_stable_across_reads(): void {
		$this->assertSame(
			Jwks::document()['keys'][0]['kid'],
			Jwks::document()['keys'][0]['kid']
		);
	}

	/**
	 * An unreadable key yields an empty set, not a fatal or a malformed key.
	 *
	 * A well-formed empty document still passes a client's shape validation,
	 * where a 500 or a half-built key would not.
	 *
	 * @return void
	 */
	public function test_unparseable_key_yields_an_empty_set(): void {
		$GLOBALS['albert_test_options'] = [
			KeyManager::PUBLIC_KEY_OPTION => 'not-a-key',
		];

		$this->assertSame( [ 'keys' => [] ], Jwks::document() );
	}

	/**
	 * Encode as unpadded base64url, the way the builder does.
	 *
	 * @param string $value The raw bytes.
	 *
	 * @return string The base64url string.
	 */
	private function base64url( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}
}
