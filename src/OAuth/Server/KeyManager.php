<?php
/**
 * OAuth Key Manager
 *
 * @package Albert
 * @subpackage OAuth\Server
 * @since      1.0.0
 */

namespace Albert\OAuth\Server;

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
	 * @since 1.0.0
	 */
	public static function get_private_key(): string {
		$key = get_option( self::PRIVATE_KEY_OPTION );

		if ( ! $key ) {
			self::generate_key_pair();
			$key = get_option( self::PRIVATE_KEY_OPTION );
		}

		return $key;
	}

	/**
	 * Get or generate the public key for JWT verification.
	 *
	 * @return string The public key in PEM format.
	 * @since 1.0.0
	 */
	public static function get_public_key(): string {
		$key = get_option( self::PUBLIC_KEY_OPTION );

		if ( ! $key ) {
			self::generate_key_pair();
			$key = get_option( self::PUBLIC_KEY_OPTION );
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
	 * Generate RSA key pair for JWT signing.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function generate_key_pair(): void {
		$config = [
			'digest_alg'       => 'sha256',
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		];

		$resource = openssl_pkey_new( $config );

		// Check if key generation failed.
		if ( $resource === false ) {
			return;
		}

		// Extract private key.
		openssl_pkey_export( $resource, $private_key );

		// Extract public key.
		$details = openssl_pkey_get_details( $resource );
		if ( $details === false ) {
			return;
		}
		$public_key = $details['key'];

		// Store keys (not autoloaded for security).
		update_option( self::PRIVATE_KEY_OPTION, $private_key, false );
		update_option( self::PUBLIC_KEY_OPTION, $public_key, false );
	}

	/**
	 * Regenerate all keys.
	 *
	 * Warning: This will invalidate all existing tokens.
	 *
	 * @return void
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
	}
}
