<?php
/**
 * Integration tests for the OAuth KeyManager.
 *
 * KeyManager touches both WP options and OpenSSL. These tests run against a
 * real WP environment and require OpenSSL to be available (it is on every
 * CI platform we target; a skip guards local environments without it).
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth\Server;

use Albert\OAuth\Endpoints\AuthorizationPage;
use Albert\OAuth\Endpoints\OAuthController;
use Albert\OAuth\KeyHealth;
use Albert\OAuth\Server\AuthorizationServerFactory;
use Albert\OAuth\Server\KeyGenerationFailed;
use Albert\OAuth\Server\KeyManager;
use Albert\OAuth\Server\KeyPairGenerator;
use Albert\OAuth\Server\ResourceServerFactory;
use Albert\OAuth\Server\TokenValidator;
use WP_Error;
use WP_REST_Request;
use Albert\Tests\TestCase;

/**
 * KeyManager integration tests.
 *
 * @covers \Albert\OAuth\Server\KeyManager
 */
class KeyManagerTest extends TestCase {

	/**
	 * Clear every KeyManager option before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			$this->markTestSkipped( 'OpenSSL is not available.' );
		}

		KeyManager::delete_keys();
	}

	/**
	 * Restore a clean state so other OAuth tests that need keys start fresh.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		KeyManager::use_generator( null );
		KeyManager::delete_keys();
		AuthorizationServerFactory::reset();
		ResourceServerFactory::reset();
		parent::tear_down();
	}

	// ─── encryption key ──────────────────────────────────────────────

	/**
	 * Generates and caches a non-empty encryption key on first access.
	 *
	 * @return void
	 */
	public function test_get_encryption_key_generates_and_caches(): void {
		$this->assertFalse( get_option( KeyManager::ENCRYPTION_KEY_OPTION ) );

		$first = KeyManager::get_encryption_key();

		$this->assertNotEmpty( $first );
		$this->assertSame( $first, get_option( KeyManager::ENCRYPTION_KEY_OPTION ) );

		// Second call returns the cached value, not a new one.
		$this->assertSame( $first, KeyManager::get_encryption_key() );
	}

	// ─── RSA key pair ────────────────────────────────────────────────

	/**
	 * Generates a valid RSA private/public pair on first access.
	 *
	 * @return void
	 */
	public function test_get_private_key_generates_valid_pem(): void {
		$private = KeyManager::get_private_key();
		$public  = KeyManager::get_public_key();

		$this->assertStringContainsString( '-----BEGIN PRIVATE KEY-----', $private );
		$this->assertStringContainsString( '-----BEGIN PUBLIC KEY-----', $public );

		// The public key parses under OpenSSL — i.e., it is actually valid.
		$this->assertNotFalse( openssl_pkey_get_public( $public ) );
		$this->assertNotFalse( openssl_pkey_get_private( $private ) );
	}

	/**
	 * The generated private and public key are from the same pair.
	 *
	 * @return void
	 */
	public function test_private_and_public_key_match(): void {
		$private = KeyManager::get_private_key();
		$public  = KeyManager::get_public_key();

		$data      = 'albert-key-pair-roundtrip';
		$signature = '';
		openssl_sign( $data, $signature, $private, OPENSSL_ALGO_SHA256 );

		$this->assertSame( 1, openssl_verify( $data, $signature, $public, OPENSSL_ALGO_SHA256 ) );
	}

	/**
	 * Generating the private key twice does not produce a new pair.
	 *
	 * @return void
	 */
	public function test_get_private_key_is_cached(): void {
		$first  = KeyManager::get_private_key();
		$second = KeyManager::get_private_key();

		$this->assertSame( $first, $second );
	}

	// ─── regenerate_keys() ──────────────────────────────────────────

	/**
	 * Regenerating produces new encryption + RSA keys.
	 *
	 * Existing tokens signed with the previous keys would stop validating —
	 * the docblock on regenerate_keys() calls this out. The test locks it in.
	 *
	 * @return void
	 */
	public function test_regenerate_keys_replaces_all_keys(): void {
		$old_encryption = KeyManager::get_encryption_key();
		$old_private    = KeyManager::get_private_key();

		KeyManager::regenerate_keys();

		$this->assertNotSame( $old_encryption, KeyManager::get_encryption_key() );
		$this->assertNotSame( $old_private, KeyManager::get_private_key() );
	}

	// ─── delete_keys() ──────────────────────────────────────────────

	/**
	 * Removes every key option so a fresh install reaches the generator path.
	 *
	 * @return void
	 */
	public function test_delete_keys_removes_all_options(): void {
		KeyManager::get_encryption_key();
		KeyManager::get_private_key();

		KeyManager::delete_keys();

		$this->assertFalse( get_option( KeyManager::ENCRYPTION_KEY_OPTION ) );
		$this->assertFalse( get_option( KeyManager::PRIVATE_KEY_OPTION ) );
		$this->assertFalse( get_option( KeyManager::PUBLIC_KEY_OPTION ) );
	}

	/**
	 * When OpenSSL cannot make keys, nothing is stored, the caller gets a clear error, and the reason is kept.
	 *
	 * @return void
	 */
	public function test_a_failure_stores_nothing_and_keeps_the_reason(): void {
		KeyManager::use_generator( $this->failing_generator() );

		try {
			KeyManager::get_private_key();
			$this->fail( 'Expected a failure.' );
		} catch ( KeyGenerationFailed $e ) {
			$this->assertStringContainsString( 'could not create the keys', $e->getMessage() );
		}

		$this->assertFalse( get_option( KeyManager::PRIVATE_KEY_OPTION ) );
		$this->assertFalse( get_option( KeyManager::PUBLIC_KEY_OPTION ) );
		$this->assertStringContainsString( 'could not create the keys', (string) get_option( KeyManager::KEY_ERROR_OPTION ) );
	}

	/**
	 * The next success clears the kept reason.
	 *
	 * @return void
	 */
	public function test_a_success_clears_the_reason(): void {
		update_option( KeyManager::KEY_ERROR_OPTION, 'Earlier failure.' );

		$this->assertNotSame( '', KeyManager::get_public_key() );
		$this->assertFalse( get_option( KeyManager::KEY_ERROR_OPTION ) );
	}

	/**
	 * An administrator sees the reason on the Plugins screen; nobody else does.
	 *
	 * @return void
	 */
	public function test_the_notice_shows_the_reason_to_administrators(): void {
		update_option( KeyManager::KEY_ERROR_OPTION, 'OpenSSL said: no config.' );
		set_current_screen( 'plugins' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		( new KeyHealth() )->maybe_render_notice();
		$admin = (string) ob_get_clean();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		ob_start();
		( new KeyHealth() )->maybe_render_notice();
		$editor = (string) ob_get_clean();

		$this->assertStringContainsString( 'cannot connect', $admin );
		$this->assertStringContainsString( 'OpenSSL said: no config.', $admin );
		$this->assertSame( '', $editor );
	}

	/**
	 * Without keys, the token endpoint and token checks answer with a server error, not a crash.
	 *
	 * @return void
	 */
	public function test_requests_fail_cleanly_without_keys(): void {
		KeyManager::use_generator( $this->failing_generator() );
		AuthorizationServerFactory::reset();
		ResourceServerFactory::reset();

		$token = ( new OAuthController() )->handle_token( new WP_REST_Request( 'POST', '/albert/v1/oauth/token' ) );

		$request = new WP_REST_Request( 'POST', '/albert/v1/mcp' );
		$request->set_header( 'authorization', 'Bearer abc' );
		$checked = TokenValidator::validate_request( $request );

		$this->assertSame( 500, $token->get_status() );
		$this->assertInstanceOf( WP_Error::class, $checked );
		$this->assertSame( 500, $checked->get_error_data()['status'] ?? null );
	}

	/**
	 * The sign-in window gives an administrator the reason and anyone else who can fix it.
	 *
	 * @return void
	 */
	public function test_the_sign_in_window_explains_missing_keys(): void {
		$failure = KeyGenerationFailed::because( 'no config' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$admin = AuthorizationPage::server_error_message( $failure );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$author = AuthorizationPage::server_error_message( $failure );

		$this->assertStringContainsString( 'OpenSSL said: no config', $admin );
		$this->assertStringContainsString( 'administrator', $author );
		$this->assertStringNotContainsString( 'no config', $author );
		$this->assertStringContainsString( 'try again', AuthorizationPage::server_error_message( new \RuntimeException( 'x' ) ) );
	}

	/**
	 * A generator whose every attempt fails.
	 *
	 * @return KeyPairGenerator
	 */
	private function failing_generator(): KeyPairGenerator {
		return new class() extends KeyPairGenerator {
			/**
			 * Fail.
			 *
			 * @param array<string, mixed> $options Options.
			 *
			 * @return array{private: string, public: string}|null
			 */
			protected function attempt( array $options ): ?array {
				return null;
			}
		};
	}
}
