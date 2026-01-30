<?php
/**
 * OAuth Client Repository
 *
 * @package Albert
 * @subpackage OAuth\Repositories
 * @since      1.0.0
 */

namespace Albert\OAuth\Repositories;

use Albert\OAuth\Database\Installer;
use Albert\OAuth\Entities\ClientEntity;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

/**
 * ClientRepository class
 *
 * Handles persistence and retrieval of OAuth clients from the WordPress database.
 *
 * @since 1.0.0
 */
class ClientRepository implements ClientRepositoryInterface {

	/**
	 * Get a client by its identifier.
	 *
	 * @param string $client_identifier The client's identifier.
	 *
	 * @return ClientEntity|null The client entity or null if not found.
	 * @since 1.0.0
	 */
	public function getClientEntity( $client_identifier ): ?ClientEntity {
		global $wpdb;

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE client_id = %s',
				$tables['clients'],
				$client_identifier
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->hydrate_client( $row );
	}

	/**
	 * Validate a client's secret.
	 *
	 * @param string      $client_identifier The client's identifier.
	 * @param string|null $client_secret     The client's secret (if sent).
	 * @param string|null $grant_type        The grant type used (optional).
	 *
	 * @return bool Whether the client credentials are valid.
	 * @since 1.0.0
	 */
	public function validateClient( $client_identifier, $client_secret, $grant_type ): bool {
		$client = $this->getClientEntity( $client_identifier );

		if ( ! $client ) {
			return false;
		}

		// If the client is confidential, we need to validate the secret.
		if ( $client->isConfidential() ) {
			if ( empty( $client_secret ) ) {
				return false;
			}

			$stored_secret = $client->getClientSecret();
			if ( empty( $stored_secret ) ) {
				return false;
			}

			// Use WordPress password verification for hashed secrets.
			if ( ! wp_check_password( $client_secret, $stored_secret ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Create a new OAuth client.
	 *
	 * @param string      $name            The client name.
	 * @param string      $redirect_uri    The redirect URI (JSON encoded array or single URI).
	 * @param bool        $is_confidential Whether the client is confidential.
	 * @param int|null    $user_id         The WordPress user ID who created this client.
	 * @param string|null $client_secret   The plain text client secret (will be hashed).
	 *
	 * @return array{client_id: string, client_secret: string|null}|null The client credentials or null on failure.
	 * @since 1.0.0
	 */
	public function createClient(
		string $name,
		string $redirect_uri,
		bool $is_confidential = true,
		?int $user_id = null,
		?string $client_secret = null
	): ?array {
		global $wpdb;

		$tables = Installer::get_table_names();

		// Generate a unique client ID.
		$client_id = $this->generate_client_id();

		// Generate a secret if not provided and client is confidential.
		$plain_secret = null;
		if ( $is_confidential ) {
			$plain_secret  = $client_secret ?? $this->generate_client_secret();
			$hashed_secret = wp_hash_password( $plain_secret );
		} else {
			$hashed_secret = null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, no caching needed.
		$result = $wpdb->insert(
			$tables['clients'],
			[
				'client_id'       => $client_id,
				'client_secret'   => $hashed_secret,
				'name'            => $name,
				'redirect_uri'    => $redirect_uri,
				'user_id'         => $user_id,
				'is_confidential' => $is_confidential ? 1 : 0,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%d' ]
		);

		if ( ! $result ) {
			return null;
		}

		return [
			'client_id'     => $client_id,
			'client_secret' => $plain_secret,
		];
	}

	/**
	 * Delete a client by its identifier.
	 *
	 * @param string $client_identifier The client's identifier.
	 *
	 * @return bool Whether the deletion was successful.
	 * @since 1.0.0
	 */
	public function deleteClient( string $client_identifier ): bool {
		global $wpdb;

		$tables = Installer::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$result = $wpdb->delete(
			$tables['clients'],
			[ 'client_id' => $client_identifier ],
			[ '%s' ]
		);

		return $result !== false;
	}

	/**
	 * Get all clients for a user.
	 *
	 * @param int|null $user_id The WordPress user ID, or null for all clients.
	 *
	 * @return ClientEntity[] Array of client entities.
	 * @since 1.0.0
	 */
	public function getClientsByUser( ?int $user_id = null ): array {
		global $wpdb;

		$tables = Installer::get_table_names();

		if ( $user_id === null ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC', $tables['clients'] ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE user_id = %d ORDER BY created_at DESC',
					$tables['clients'],
					$user_id
				),
				ARRAY_A
			);
		}

		if ( ! $rows ) {
			return [];
		}

		return array_map( [ $this, 'hydrate_client' ], $rows );
	}

	/**
	 * Hydrate a client entity from a database row.
	 *
	 * @param array<string, mixed> $row The database row.
	 *
	 * @return ClientEntity The hydrated client entity.
	 * @since 1.0.0
	 */
	private function hydrate_client( array $row ): ClientEntity {
		$client = new ClientEntity();
		$client->setIdentifier( $row['client_id'] );
		$client->setName( $row['name'] );
		$client->setRedirectUri( json_decode( $row['redirect_uri'], true ) ?? $row['redirect_uri'] );
		$client->setConfidential( (bool) $row['is_confidential'] );
		$client->setUserId( $row['user_id'] ? (int) $row['user_id'] : null );
		$client->setClientSecret( $row['client_secret'] );

		if ( ! empty( $row['created_at'] ) ) {
			$client->setCreatedAt( new \DateTimeImmutable( $row['created_at'] ) );
		}

		return $client;
	}

	/**
	 * Generate a unique client ID.
	 *
	 * @return string The generated client ID.
	 * @since 1.0.0
	 */
	private function generate_client_id(): string {
		return 'albert_' . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Generate a client secret.
	 *
	 * @return string The generated client secret.
	 * @since 1.0.0
	 */
	private function generate_client_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
