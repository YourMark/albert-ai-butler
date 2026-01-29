<?php
/**
 * OAuth Authorization Code Repository
 *
 * @package Albert
 * @subpackage OAuth\Repositories
 * @since      1.0.0
 */

namespace Albert\OAuth\Repositories;

use Albert\OAuth\Database\Installer;
use Albert\OAuth\Entities\AuthCodeEntity;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

/**
 * AuthCodeRepository class
 *
 * Handles persistence and retrieval of authorization codes from the WordPress database.
 *
 * @since 1.0.0
 */
class AuthCodeRepository implements AuthCodeRepositoryInterface {

	/**
	 * Create a new auth code.
	 *
	 * @return AuthCodeEntityInterface The new auth code entity.
	 * @since 1.0.0
	 */
	public function getNewAuthCode(): AuthCodeEntityInterface {
		return new AuthCodeEntity();
	}

	/**
	 * Persist a new auth code to permanent storage.
	 *
	 * @param AuthCodeEntityInterface $auth_code_entity The auth code entity.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function persistNewAuthCode( AuthCodeEntityInterface $auth_code_entity ): void {
		global $wpdb;

		$tables = Installer::get_table_names();
		$scopes = [];

		foreach ( $auth_code_entity->getScopes() as $scope ) {
			$scopes[] = $scope->getIdentifier();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, no caching needed.
		$wpdb->insert(
			$tables['auth_codes'],
			[
				'code_id'    => $auth_code_entity->getIdentifier(),
				'client_id'  => $auth_code_entity->getClient()->getIdentifier(),
				'user_id'    => $auth_code_entity->getUserIdentifier(),
				'scopes'     => wp_json_encode( $scopes ),
				'revoked'    => 0,
				'expires_at' => $auth_code_entity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%d', '%s', '%d', '%s' ]
		);
	}

	/**
	 * Revoke an auth code.
	 *
	 * @param string $code_id The auth code identifier.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function revokeAuthCode( $code_id ): void {
		global $wpdb;

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			$tables['auth_codes'],
			[ 'revoked' => 1 ],
			[ 'code_id' => $code_id ],
			[ '%d' ],
			[ '%s' ]
		);
	}

	/**
	 * Check if the auth code has been revoked.
	 *
	 * @param string $code_id The auth code identifier.
	 *
	 * @return bool True if revoked, false otherwise.
	 * @since 1.0.0
	 */
	public function isAuthCodeRevoked( $code_id ): bool {
		global $wpdb;

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$revoked = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT revoked FROM %i WHERE code_id = %s',
				$tables['auth_codes'],
				$code_id
			)
		);

		// If code not found, consider it revoked.
		if ( $revoked === null ) {
			return true;
		}

		return (bool) $revoked;
	}

	/**
	 * Clean up expired authorization codes.
	 *
	 * @return int Number of codes deleted.
	 * @since 1.0.0
	 */
	public function cleanupExpiredCodes(): int {
		global $wpdb;

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at < %s',
				$tables['auth_codes'],
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return (int) $result;
	}
}
