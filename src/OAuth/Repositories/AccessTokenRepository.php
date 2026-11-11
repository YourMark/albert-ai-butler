<?php
/**
 * OAuth Access Token Repository
 *
 * @package Albert
 * @subpackage OAuth\Repositories
 * @since      1.0.0
 */

namespace Albert\OAuth\Repositories;

defined( 'ABSPATH' ) || exit;

use Albert\Database\Tables;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Entities\AccessTokenEntity;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

/**
 * AccessTokenRepository class
 *
 * Handles persistence and retrieval of access tokens from the WordPress database.
 *
 * @since 1.0.0
 */
class AccessTokenRepository implements AccessTokenRepositoryInterface {

	/**
	 * Create a new access token, refusing a user no longer on the allowed list.
	 *
	 * Every grant that mints a token for a person (code exchange, refresh)
	 * passes through here, so removing somebody also stops their outstanding
	 * codes and refresh tokens.
	 *
	 * @param ClientEntityInterface                                           $client_entity   The client entity.
	 * @param array<int, \League\OAuth2\Server\Entities\ScopeEntityInterface> $scopes          The scopes.
	 * @param string|int|null                                                 $user_identifier The user identifier.
	 *
	 * @return AccessTokenEntityInterface The new access token entity.
	 * @throws OAuthServerException When the user is no longer allowed.
	 * @since 1.0.0
	 */
	public function getNewToken(
		ClientEntityInterface $client_entity,
		array $scopes,
		$user_identifier = null
	): AccessTokenEntityInterface {
		$access_token = new AccessTokenEntity();
		$access_token->setClient( $client_entity );

		foreach ( $scopes as $scope ) {
			$access_token->addScope( $scope );
		}

		if ( $user_identifier !== null && (string) $user_identifier !== '' ) {
			if ( ! AllowedUsers::is_allowed( (int) $user_identifier ) ) {
				// invalid_grant (RFC 6749 §5.2): the grant is dead, so the client signs in again.
				throw OAuthServerException::invalidGrant( 'The user is no longer allowed to connect.' );
			}

			$access_token->setUserIdentifier( (string) $user_identifier );
		}

		return $access_token;
	}

	/**
	 * Persist a new access token to permanent storage.
	 *
	 * @param AccessTokenEntityInterface $access_token_entity The access token entity.
	 *
	 * @return void
	 * @throws OAuthServerException When the row cannot be written.
	 * @since 1.0.0
	 */
	public function persistNewAccessToken( AccessTokenEntityInterface $access_token_entity ): void {
		global $wpdb;

		$tables = Tables::oauth();
		$scopes = [];

		foreach ( $access_token_entity->getScopes() as $scope ) {
			$scopes[] = $scope->getIdentifier();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, no caching needed.
		$inserted = $wpdb->insert(
			$tables['access_tokens'],
			[
				'token_id'   => $access_token_entity->getIdentifier(),
				'client_id'  => $access_token_entity->getClient()->getIdentifier(),
				'user_id'    => $access_token_entity->getUserIdentifier(),
				'scopes'     => wp_json_encode( $scopes ),
				'revoked'    => 0,
				'expires_at' => $access_token_entity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
		);

		if ( $inserted === false ) {
			throw OAuthServerException::serverError( 'Failed to persist the access token.' );
		}
	}

	/**
	 * Revoke an access token.
	 *
	 * @param string $token_id The token identifier.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function revokeAccessToken( $token_id ): void {
		global $wpdb;

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			$tables['access_tokens'],
			[ 'revoked' => 1 ],
			[ 'token_id' => $token_id ],
			[ '%d' ],
			[ '%s' ]
		);
	}

	/**
	 * Check if the access token has been revoked.
	 *
	 * @param string $token_id The token identifier.
	 *
	 * @return bool True if revoked, false otherwise.
	 * @since 1.0.0
	 */
	public function isAccessTokenRevoked( $token_id ): bool {
		global $wpdb;

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$revoked = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT revoked FROM %i WHERE token_id = %s',
				$tables['access_tokens'],
				$token_id
			)
		);

		// If token not found, consider it revoked.
		if ( $revoked === null ) {
			return true;
		}

		return (bool) $revoked;
	}

	/**
	 * Whether a stored, live access token was issued to this user and client.
	 *
	 * The signature proves a key signed the token; this proves the server
	 * issued it, so a token forged with a leaked key and a real token ID
	 * still fails unless every claim matches the row.
	 *
	 * @param string $token_id  The token identifier (JWT `jti`).
	 * @param int    $user_id   The user the token names.
	 * @param string $client_id The client the token names.
	 *
	 * @return bool
	 * @since 1.5.1
	 */
	public function ownsToken( string $token_id, int $user_id, string $client_id ): bool {
		global $wpdb;

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i
				WHERE token_id = %s AND user_id = %d AND client_id = %s
					AND revoked = 0 AND expires_at > UTC_TIMESTAMP()
				LIMIT 1',
				$tables['access_tokens'],
				$token_id,
				$user_id,
				$client_id
			)
		);

		return $found !== null;
	}

	/**
	 * Get all access tokens for a user.
	 *
	 * @param int|null $user_id The WordPress user ID, or null for all tokens.
	 *
	 * @return array<int, array<string, mixed>> Array of access token data.
	 * @since 1.0.0
	 */
	public function getAccessTokensByUser( ?int $user_id = null ): array {
		global $wpdb;

		$tables = Tables::oauth();

		if ( $user_id === null ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC', $tables['access_tokens'] ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d ORDER BY created_at DESC',
					$tables['access_tokens'],
					$user_id
				),
				ARRAY_A
			);
		}

		return $rows ? $rows : [];
	}

	/**
	 * Delete an access token.
	 *
	 * @param string $token_id The token identifier.
	 *
	 * @return bool Whether the deletion was successful.
	 * @since 1.0.0
	 */
	public function deleteAccessToken( string $token_id ): bool {
		global $wpdb;

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$result = $wpdb->delete(
			$tables['access_tokens'],
			[ 'token_id' => $token_id ],
			[ '%s' ]
		);

		return $result !== false;
	}

	/**
	 * Clean up expired tokens, except any still anchoring a live refresh token.
	 *
	 * **The exception is not housekeeping, it is what keeps a live connection
	 * visible.** `refresh_tokens` carries only `access_token_id`, no
	 * `client_id`, so the access-token row is the *only* path from a refresh
	 * token back to the client that holds it. Deleting an expired access token
	 * whose refresh token is still valid severs that path, and an orphaned
	 * refresh token can no longer be attributed to anybody.
	 *
	 * What that looked like: an access token lives about an hour and a refresh
	 * token thirty days, so an assistant that had simply been quiet for a while
	 * lost its access-token row on the next daily sweep. It could still refresh
	 * and go on calling the site, while
	 * {@see ClientRepository::getLiveConnections()} (which selects
	 * `FROM access_tokens`) stopped returning it. The connection disappeared
	 * from the Connections screen, from the Dashboard count and from the
	 * retention sweeps: still working, no longer listed, and no longer
	 * revocable from the UI. A connection an owner cannot see is one they
	 * cannot withdraw.
	 *
	 * The row is collected on a later run, once the refresh token has expired
	 * or been revoked and there is nothing left to attribute.
	 *
	 * @return int Number of tokens deleted.
	 * @since 1.0.0
	 */
	public function cleanupExpiredTokens(): int {
		global $wpdb;

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE t FROM %i t
				LEFT JOIN %i r
					ON r.access_token_id = t.token_id
					AND r.revoked = 0
					AND r.expires_at > UTC_TIMESTAMP()
				WHERE t.expires_at < %s
					AND r.id IS NULL',
				$tables['access_tokens'],
				$tables['refresh_tokens'],
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return (int) $result;
	}
}
