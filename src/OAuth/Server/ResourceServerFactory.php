<?php
/**
 * OAuth Resource Server Factory
 *
 * @package Albert
 * @subpackage OAuth\Server
 * @since      1.0.0
 */

namespace Albert\OAuth\Server;

use Albert\OAuth\Repositories\AccessTokenRepository;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\ResourceServer;

/**
 * ResourceServerFactory class
 *
 * Creates and configures the OAuth 2.0 Resource Server for token validation.
 *
 * @since 1.0.0
 */
class ResourceServerFactory {

	/**
	 * The singleton instance.
	 *
	 * @since 1.0.0
	 * @var ResourceServer|null
	 */
	private static ?ResourceServer $instance = null;

	/**
	 * Get the Resource Server instance.
	 *
	 * @return ResourceServer The configured resource server.
	 * @since 1.0.0
	 */
	public static function create(): ResourceServer {
		if ( null !== self::$instance ) {
			return self::$instance;
		}

		$access_token_repository = new AccessTokenRepository();
		$public_key              = new CryptKey( KeyManager::get_public_key(), null, false );

		self::$instance = new ResourceServer(
			$access_token_repository,
			$public_key
		);

		return self::$instance;
	}

	/**
	 * Reset the singleton instance (for testing).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function reset(): void {
		self::$instance = null;
	}
}
