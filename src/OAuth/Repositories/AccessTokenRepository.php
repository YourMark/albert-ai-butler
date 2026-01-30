<?php
/**
 * OAuth Access Token Repository
 *
 * @package Albert
 * @subpackage OAuth\Repositories
 * @since      1.0.0
 */

namespace Albert\OAuth\Repositories;

use Albert\OAuth\Database\Installer;
use Albert\OAuth\Entities\AccessTokenEntity;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
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
	 * Create a new access token.
	 *
	 * @param ClientEntityInterface                                           $client_entity   The client entity.
	 * @param array<int, \League\OAuth2\Server\Entities\ScopeEntityInterface> $scopes          The scopes.
	 * @param string|int|null                                                 $user_identifier The user identifier.
	 *
	 * @return AccessTokenEntityInterface The new access token entity.
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
	 * @since 1.0.0
	 */
	public function persistNewAccessToken( AccessTokenEntityInterface $access_token_entity ): void {
		global $wpdb;

		$tables = Installer::get_table_names();
		$scopes = [];

		foreach ( $access_token_entity->getScopes() as $scope ) {
			$scopes[] = $scope->getIdentifier();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, no caching needed.
		$wpdb->insert(
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

		$tables = Installer::get_table_names();

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

		$tables = Installer::get_table_names();

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
	 * Get all access tokens for a user.
	 *
	 * @param int|null $user_id The WordPress user ID, or null for all tokens.
	 *
	 * @return array<int, array<string, mixed>> Array of access token data.
	 * @since 1.0.0
	 */
	public function getAccessTokensByUser( ?int $user_id = null ): array {
		global $wpdb;

		$tables = Installer::get_table_names();

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

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$result = $wpdb->delete(
			$tables['access_tokens'],
			[ 'token_id' => $token_id ],
			[ '%s' ]
		);

		return $result !== false;
	}

	/**
	 * Clean up expired tokens.
	 *
	 * @return int Number of tokens deleted.
	 * @since 1.0.0
	 */
	public function cleanupExpiredTokens(): int {
		global $wpdb;

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at < %s',
				$tables['access_tokens'],
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return (int) $result;
	}
}
