<?php
/**
 * OAuth Authorization Server Factory
 *
 * @package Albert
 * @subpackage OAuth\Server
 * @since      1.0.0
 */

namespace Albert\OAuth\Server;

defined( 'ABSPATH' ) || exit;

use DateInterval;
use Exception;
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
		if ( self::$instance !== null ) {
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
			self::ttl( 'albert/oauth/auth_code_ttl', self::AUTH_CODE_TTL )
		);
		$auth_code_grant->setRefreshTokenTTL( self::ttl( 'albert/oauth/refresh_token_ttl', self::REFRESH_TOKEN_TTL ) );

		$server->enableGrantType(
			$auth_code_grant,
			self::ttl( 'albert/oauth/access_token_ttl', self::ACCESS_TOKEN_TTL )
		);

		// Enable Refresh Token Grant.
		$refresh_token_grant = new RefreshTokenGrant( $refresh_token_repository );
		$refresh_token_grant->setRefreshTokenTTL( self::ttl( 'albert/oauth/refresh_token_ttl', self::REFRESH_TOKEN_TTL ) );

		$server->enableGrantType(
			$refresh_token_grant,
			self::ttl( 'albert/oauth/access_token_ttl', self::ACCESS_TOKEN_TTL )
		);

		self::$instance = $server;

		return $server;
	}

	/**
	 * Build a token TTL, honouring a site-supplied override.
	 *
	 * The one-hour access token default is deliberate, but it is currently the
	 * only knob a site has: a client unable to complete the OAuth dance (no
	 * browser to redirect through) has no way to use Albert except a bearer
	 * token that expires every hour, with no way to tune that. These filters
	 * don't solve that on their own — a revocable long-lived credential is the
	 * real fix — but they at least make the TTLs configurable instead of hardcoded.
	 *
	 * @param non-empty-string $filter_name   The filter name.
	 * @param string           $default_value The default ISO 8601 duration string.
	 *
	 * @return DateInterval
	 * @since 1.4.0
	 */
	private static function ttl( string $filter_name, string $default_value ): DateInterval {
		/**
		 * Filters an OAuth token TTL.
		 *
		 * @since 1.4.0
		 *
		 * @param string $duration ISO 8601 duration string (e.g. `PT1H`). Default varies by filter.
		 */
		$duration = (string) apply_filters( $filter_name, $default_value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- One of the albert/oauth/*_ttl filters.

		try {
			return new DateInterval( $duration );
		} catch ( Exception $e ) {
			return new DateInterval( $default_value );
		}
	}

	/**
	 * Reset the singleton instance, as {@see ResourceServerFactory::reset()}
	 * does, so it is rebuilt with the current keys.
	 *
	 * @return void
	 * @since 1.5.1
	 */
	public static function reset(): void {
		self::$instance = null;
	}
}
