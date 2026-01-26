<?php
/**
 * OAuth Authorization Server Factory
 *
 * @package Albert
 * @subpackage OAuth\Server
 * @since      1.0.0
 */

namespace Albert\OAuth\Server;

use DateInterval;
use Albert\OAuth\Repositories\AccessTokenRepository;
use Albert\OAuth\Repositories\AuthCodeRepository;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\OAuth\Repositories\RefreshTokenRepository;
use Albert\OAuth\Repositories\ScopeRepository;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;

/**
 * AuthorizationServerFactory class
 *
 * Creates and configures the OAuth 2.0 Authorization Server.
 *
 * @since 1.0.0
 */
class AuthorizationServerFactory {

	/**
	 * The singleton instance.
	 *
	 * @since 1.0.0
	 * @var AuthorizationServer|null
	 */
	private static ?AuthorizationServer $instance = null;

	/**
	 * Access token TTL (1 hour).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ACCESS_TOKEN_TTL = 'PT1H';

	/**
	 * Refresh token TTL (30 days).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const REFRESH_TOKEN_TTL = 'P30D';

	/**
	 * Auth code TTL (10 minutes).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const AUTH_CODE_TTL = 'PT10M';

	/**
	 * Get the Authorization Server instance.
	 *
	 * @return AuthorizationServer The configured authorization server.
	 * @since 1.0.0
	 */
	public static function create(): AuthorizationServer {
		if ( null !== self::$instance ) {
			return self::$instance;
		}

		// Create repositories.
		$client_repository        = new ClientRepository();
		$scope_repository         = new ScopeRepository();
		$access_token_repository  = new AccessTokenRepository();
		$auth_code_repository     = new AuthCodeRepository();
		$refresh_token_repository = new RefreshTokenRepository();

		// Get keys.
		$private_key    = new CryptKey( KeyManager::get_private_key(), null, false );
		$encryption_key = KeyManager::get_encryption_key();

		// Create server.
		$server = new AuthorizationServer(
			$client_repository,
			$access_token_repository,
			$scope_repository,
			$private_key,
			$encryption_key
		);

		// Enable Authorization Code Grant.
		$auth_code_grant = new AuthCodeGrant(
			$auth_code_repository,
			$refresh_token_repository,
			new DateInterval( self::AUTH_CODE_TTL )
		);
		$auth_code_grant->setRefreshTokenTTL( new DateInterval( self::REFRESH_TOKEN_TTL ) );

		$server->enableGrantType(
			$auth_code_grant,
			new DateInterval( self::ACCESS_TOKEN_TTL )
		);

		// Enable Refresh Token Grant.
		$refresh_token_grant = new RefreshTokenGrant( $refresh_token_repository );
		$refresh_token_grant->setRefreshTokenTTL( new DateInterval( self::REFRESH_TOKEN_TTL ) );

		$server->enableGrantType(
			$refresh_token_grant,
			new DateInterval( self::ACCESS_TOKEN_TTL )
		);

		self::$instance = $server;

		return $server;
	}
}
