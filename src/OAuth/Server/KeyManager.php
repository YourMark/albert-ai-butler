<?php
/**
 * OAuth Key Manager
 *
 * @package Albert
 * @subpackage OAuth\Server
 * @since      1.0.0
 */

namespace Albert\OAuth\Server;

defined( 'ABSPATH' ) || exit;

use Defuse\Crypto\Key;

/**
 * KeyManager class
 *
 * Manages cryptographic keys for OAuth 2.0 server.
 * Keys are stored in WordPress options and generated on first use.
 *
 * @since 1.0.0
 */
class KeyManager {

	/**
	 * Option name for the encryption key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ENCRYPTION_KEY_OPTION = 'albert_oauth_encryption_key';

	/**
	 * Option name for the private key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const PRIVATE_KEY_OPTION = 'albert_oauth_private_key';

	/**
	 * Option name for the public key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const PUBLIC_KEY_OPTION = 'albert_oauth_public_key';

	/**
	 * Option holding why the key pair could not be created, for the admin notice.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	const KEY_ERROR_OPTION = 'albert_oauth_key_error';

	/**
	 * The generator in use; null for the real one.
	 *
	 * @since 1.7.0
	 * @var KeyPairGenerator|null
	 */
	private static ?KeyPairGenerator $generator = null;

	/**
	 * Get or generate the encryption key.
	 *
	 * @return string The encryption key.
	 * @since 1.0.0
	 */
	public static function get_encryption_key(): string {
		$key = get_option( self::ENCRYPTION_KEY_OPTION );

		if ( ! $key ) {
			$key = self::generate_encryption_key();
			update_option( self::ENCRYPTION_KEY_OPTION, $key, false );
		}

		return $key;
	}

	/**
	 * Get or generate the private key for JWT signing.
	 *
	 * @return string The private key in PEM format.
	 * @throws KeyGenerationFailed When the key pair cannot be created.
	 * @since 1.0.0
	 * @since 1.7.0 Throws instead of returning an empty string.
	 */
	public static function get_private_key(): string {
		$key = get_option( self::PRIVATE_KEY_OPTION );

		if ( ! is_string( $key ) || $key === '' ) {
			$key = self::generate_key_pair()['private'];
		}

		return $key;
	}

	/**
	 * Get or generate the public key for JWT verification.
	 *
	 * @return string The public key in PEM format.
	 * @throws KeyGenerationFailed When the key pair cannot be created.
	 * @since 1.0.0
	 * @since 1.7.0 Throws instead of returning an empty string.
	 */
	public static function get_public_key(): string {
		$key = get_option( self::PUBLIC_KEY_OPTION );

		if ( ! is_string( $key ) || $key === '' ) {
			$key = self::generate_key_pair()['public'];
		}

		return $key;
	}

	/**
	 * Generate the encryption key using Defuse.
	 *
	 * @return string The generated encryption key.
	 * @since 1.0.0
	 */
	private static function generate_encryption_key(): string {
		$key = Key::createNewRandomKey();
		return $key->saveToAsciiSafeString();
	}

	/**
	 * Generate and store the RSA key pair for JWT signing.
	 *
	 * Stored only when both keys exist, so a failed export never leaves a
	 * public key without its private one. A failure is kept for the admin
	 * notice ({@see KeyHealth}) and cleared by the next success.
	 *
	 * @return array{private: string, public: string} PEM-encoded keys.
	 * @throws KeyGenerationFailed When OpenSSL cannot create them.
	 * @since 1.0.0
	 * @since 1.7.0 Falls back to a bundled OpenSSL config, and throws on failure.
	 */
	private static function generate_key_pair(): array {
		try {
			$pair = ( self::$generator ?? new KeyPairGenerator() )->generate();
		} catch ( KeyGenerationFailed $e ) {
			update_option( self::KEY_ERROR_OPTION, $e->getMessage(), false );
			throw $e;
		}

		// Not autoloaded: read only when a token is signed or checked.
		update_option( self::PRIVATE_KEY_OPTION, $pair['private'], false );
		update_option( self::PUBLIC_KEY_OPTION, $pair['public'], false );
		delete_option( self::KEY_ERROR_OPTION );

		return $pair;
	}

	/**
	 * Swap the key pair generator.
	 *
	 * @internal For tests that need OpenSSL to fail.
	 *
	 * @param KeyPairGenerator|null $generator The generator, or null for the real one.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public static function use_generator( ?KeyPairGenerator $generator ): void {
		self::$generator = $generator;
	}

	/**
	 * Regenerate all keys.
	 *
	 * Warning: This will invalidate all existing tokens.
	 *
	 * @return void
	 * @throws KeyGenerationFailed When the new key pair cannot be created.
	 * @since 1.0.0
	 */
	public static function regenerate_keys(): void {
		delete_option( self::ENCRYPTION_KEY_OPTION );
		delete_option( self::PRIVATE_KEY_OPTION );
		delete_option( self::PUBLIC_KEY_OPTION );

		// Generate new keys.
		self::get_encryption_key();
		self::get_private_key();
	}

	/**
	 * Delete all keys on uninstall.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function delete_keys(): void {
		delete_option( self::ENCRYPTION_KEY_OPTION );
		delete_option( self::PRIVATE_KEY_OPTION );
		delete_option( self::PUBLIC_KEY_OPTION );
		delete_option( self::KEY_ERROR_OPTION );
	}
}
