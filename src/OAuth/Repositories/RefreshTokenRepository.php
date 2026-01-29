<?php
/**
 * OAuth Refresh Token Repository
 *
 * @package Albert
 * @subpackage OAuth\Repositories
 * @since      1.0.0
 */

namespace Albert\OAuth\Repositories;

use Albert\OAuth\Database\Installer;
use Albert\OAuth\Entities\RefreshTokenEntity;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * RefreshTokenRepository class
 *
 * Handles persistence and retrieval of refresh tokens from the WordPress database.
 *
 * @since 1.0.0
 */
class RefreshTokenRepository implements RefreshTokenRepositoryInterface {

	/**
	 * Create a new refresh token.
	 *
	 * @return RefreshTokenEntityInterface|null The new refresh token entity.
	 * @since 1.0.0
	 */
	public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
		return new RefreshTokenEntity();
	}

	/**
	 * Persist a new refresh token to permanent storage.
	 *
	 * @param RefreshTokenEntityInterface $refresh_token_entity The refresh token entity.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function persistNewRefreshToken( RefreshTokenEntityInterface $refresh_token_entity ): void {
		global $wpdb;

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, no caching needed.
		$wpdb->insert(
			$tables['refresh_tokens'],
			[
				'token_id'        => $refresh_token_entity->getIdentifier(),
				'access_token_id' => $refresh_token_entity->getAccessToken()->getIdentifier(),
				'revoked'         => 0,
				'expires_at'      => $refresh_token_entity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%d', '%s' ]
		);
	}

	/**
	 * Revoke a refresh token.
	 *
	 * @param string $token_id The token identifier.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function revokeRefreshToken( $token_id ): void {
		global $wpdb;

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			$tables['refresh_tokens'],
			[ 'revoked' => 1 ],
			[ 'token_id' => $token_id ],
			[ '%d' ],
			[ '%s' ]
		);
	}

	/**
	 * Check if the refresh token has been revoked.
	 *
	 * @param string $token_id The token identifier.
	 *
	 * @return bool True if revoked, false otherwise.
	 * @since 1.0.0
	 */
	public function isRefreshTokenRevoked( $token_id ): bool {
		global $wpdb;

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$revoked = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT revoked FROM %i WHERE token_id = %s',
				$tables['refresh_tokens'],
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
	 * Revoke all refresh tokens for an access token.
	 *
	 * @param string $access_token_id The access token identifier.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function revokeRefreshTokensByAccessToken( string $access_token_id ): void {
		global $wpdb;

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			$tables['refresh_tokens'],
			[ 'revoked' => 1 ],
			[ 'access_token_id' => $access_token_id ],
			[ '%d' ],
			[ '%s' ]
		);
	}

	/**
	 * Clean up expired refresh tokens.
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
				$tables['refresh_tokens'],
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return (int) $result;
	}
}
