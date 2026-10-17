<?php
/**
 * OAuth Client Repository
 *
 * @package Albert
 * @subpackage OAuth\Repositories
 * @since      1.0.0
 */

namespace Albert\OAuth\Repositories;

use Albert\Database\Tables;
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

		$tables = Tables::oauth();

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
	 * @param string|null $origin          How the client was created (e.g. 'dcr').
	 *
	 * @return array{client_id: string, client_secret: string|null}|null The client credentials or null on failure.
	 * @since 1.0.0
	 */
	public function createClient(
		string $name,
		string $redirect_uri,
		bool $is_confidential = true,
		?int $user_id = null,
		?string $client_secret = null,
		?string $origin = null
	): ?array {
		global $wpdb;

		$tables = Tables::oauth();

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
				'origin'          => $origin,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%d', '%s' ]
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

		$tables = Tables::oauth();

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

		$tables = Tables::oauth();

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
		$client->setOrigin( isset( $row['origin'] ) && $row['origin'] !== '' ? (string) $row['origin'] : null );

		// Guarded with isset() rather than assumed: these columns arrive by
		// dbDelta on upgrade, and a row read before that ran simply has no key.
		$client->setLabel( isset( $row['label'] ) && $row['label'] !== '' ? (string) $row['label'] : null );
		$client->setLabelSetBy( ! empty( $row['label_set_by'] ) ? (int) $row['label_set_by'] : null );
		$client->setConnectHost( isset( $row['connect_host'] ) && $row['connect_host'] !== '' ? (string) $row['connect_host'] : null );

		if ( ! empty( $row['label_set_at'] ) ) {
			$client->setLabelSetAt( new \DateTimeImmutable( (string) $row['label_set_at'] ) );
		}

		if ( ! empty( $row['created_at'] ) ) {
			$client->setCreatedAt( new \DateTimeImmutable( $row['created_at'] ) );
		}

		if ( ! empty( $row['last_used_at'] ) ) {
			$client->setLastUsedAt( new \DateTimeImmutable( $row['last_used_at'] ) );
		}

		return $client;
	}

	/**
	 * Set (or clear) the site owner's own name for a connection.
	 *
	 * The client's own `name` is never touched: it is what the client called
	 * itself at registration, and rewriting it would lose the only record of
	 * that. The label sits beside it.
	 *
	 * The label carries its own attribution. Who wrote it and when are stored
	 * with it and rendered on the row, because a label is a display name one
	 * administrator writes onto another person's connection on the very screen
	 * where an owner decides what looks trustworthy. Clearing the label clears
	 * the attribution with it: there is nothing left to attribute.
	 *
	 * @param string      $client_identifier The client's identifier.
	 * @param string|null $label             The label, or null/'' to clear it.
	 * @param int|null    $author_id         Who wrote it. Null records no author.
	 *
	 * @return bool Whether the write succeeded.
	 * @since 1.4.0
	 */
	public function updateClientLabel( string $client_identifier, ?string $label, ?int $author_id = null ): bool {
		global $wpdb;

		$tables = Tables::oauth();

		$label   = $label === null ? null : trim( $label );
		$cleared = ( $label === null || $label === '' );

		$data = [
			'label'        => $cleared ? null : $label,
			'label_set_by' => ( $cleared || ! $author_id ) ? null : $author_id,
			'label_set_at' => $cleared ? null : gmdate( 'Y-m-d H:i:s' ),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$result = $wpdb->update(
			$tables['clients'],
			$data,
			[ 'client_id' => $client_identifier ],
			[ '%s', '%d', '%s' ],
			[ '%s' ]
		);

		return $result !== false;
	}

	/**
	 * Record that a client just authenticated.
	 *
	 * "Last used" is the signal an owner scans for a connection they do not
	 * recognise, so the column has to actually be written: a Never that is
	 * always Never is worse than no column, because it reads as "this assistant
	 * has done nothing".
	 *
	 * Written on the authenticated-request path, which is hot, so the staleness
	 * check is in the SQL rather than in a read-then-write. The statement matches
	 * no rows on all but the first request in each interval, which is cheaper
	 * than the SELECT that deciding in PHP would cost.
	 *
	 * @param string $client_identifier The client's identifier.
	 * @param int    $interval_minutes  How stale the stored value must be before it is rewritten.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function touchLastUsed( string $client_identifier, int $interval_minutes = 5 ): void {
		global $wpdb;

		if ( $client_identifier === '' ) {
			return;
		}

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, throttled write.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
				SET last_used_at = UTC_TIMESTAMP()
				WHERE client_id = %s
					AND ( last_used_at IS NULL OR last_used_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d MINUTE ) )',
				$tables['clients'],
				$client_identifier,
				$interval_minutes
			)
		);
	}

	/**
	 * Count the registered clients.
	 *
	 * @return int The number of clients.
	 * @since 1.3.1
	 */
	public function count_clients(): int {
		global $wpdb;

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, count for cap enforcement.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tables['clients'] ) );
	}

	/**
	 * Count the clients that hold a place under the registration cap.
	 *
	 * Live clients, by the same test as {@see self::getLiveConnections()}, plus
	 * any registered in the last `$grace_hours`. Counting every row let clients
	 * that never authorised, or were disconnected long ago, fill the cap until
	 * no new assistant could connect. The grace window keeps a registration
	 * nobody completes counting for a while, so the cap still bounds abuse: one
	 * address can fill it within the registration rate limit, but only for a day.
	 *
	 * Client `created_at` is stamped in the database's own time zone, so it is
	 * compared with NOW(); token expiry is written as UTC, so with UTC_TIMESTAMP().
	 *
	 * @param int $grace_hours How long a new client counts without a token.
	 *
	 * @return int
	 * @since 1.5.0
	 */
	public function count_live_clients( int $grace_hours = 24 ): int {
		global $wpdb;

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, count for cap enforcement.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i c
				WHERE c.created_at >= DATE_SUB( NOW(), INTERVAL %d HOUR )
					OR EXISTS (
						SELECT 1 FROM %i t
						LEFT JOIN %i r
							ON r.access_token_id = t.token_id
							AND r.revoked = 0
							AND r.expires_at > UTC_TIMESTAMP()
						WHERE t.client_id = c.client_id
							AND ( ( t.revoked = 0 AND t.expires_at > UTC_TIMESTAMP() ) OR r.id IS NOT NULL )
					)',
				$tables['clients'],
				$grace_hours,
				$tables['access_tokens'],
				$tables['refresh_tokens']
			)
		);
	}

	/**
	 * Delete clients older than `$days` that hold no token at all.
	 *
	 * No token row, revoked or not: the client never authorised, or every token
	 * it had has expired and been cleaned up. A client holding an unexpired
	 * authorization code is kept, since somebody is signing in with it right now.
	 *
	 * @param int $days Minimum age in days.
	 *
	 * @return int How many clients were deleted.
	 * @since 1.5.0
	 */
	public function delete_stale_clients( int $days ): int {
		global $wpdb;

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, nightly cleanup.
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE c FROM %i c
				WHERE c.created_at < DATE_SUB( NOW(), INTERVAL %d DAY )
					AND NOT EXISTS ( SELECT 1 FROM %i t WHERE t.client_id = c.client_id )
					AND NOT EXISTS (
						SELECT 1 FROM %i a
						WHERE a.client_id = c.client_id AND a.revoked = 0 AND a.expires_at > UTC_TIMESTAMP()
					)',
				$tables['clients'],
				$days,
				$tables['access_tokens'],
				$tables['auth_codes']
			)
		);

		return (int) $result;
	}

	/**
	 * Revoke one user's tokens for a client, access and refresh, leaving
	 * anyone else who approved the same client connected.
	 *
	 * @param string $client_id The client.
	 * @param int    $user_id   The user whose tokens go.
	 *
	 * @return int How many access tokens were revoked; 0 when the user held no live one.
	 * @since 1.5.0
	 */
	public function revokeUserTokens( string $client_id, int $user_id ): int {
		global $wpdb;

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$token_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT token_id FROM %i WHERE client_id = %s AND user_id = %d AND revoked = 0',
				$tables['access_tokens'],
				$client_id,
				$user_id
			)
		);

		if ( empty( $token_ids ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			$tables['access_tokens'],
			[ 'revoked' => 1 ],
			[
				'client_id' => $client_id,
				'user_id'   => $user_id,
			],
			[ '%d' ],
			[ '%s', '%d' ]
		);

		( new RefreshTokenRepository() )->revokeForAccessTokens( (array) $token_ids );

		return count( $token_ids );
	}

	/**
	 * The newest connection a user approved at or after a moment, or null.
	 *
	 * Approved means an authorization code this user issued to the client from
	 * then on, redeemed for a live access token. A refresh issues tokens but
	 * never a code, so an older connection refreshing while the dialog is
	 * open does not count. The code table stamps `created_at` in the
	 * database's own time zone, so UNIX_TIMESTAMP() reads it back in that
	 * same zone.
	 *
	 * @param int $user_id The user who approved it.
	 * @param int $since   Unix timestamp; only connections first approved from here on count.
	 *
	 * @return array{client_id: string, name: string, label: string, connected_ts: int}|null
	 * @since 1.5.0
	 */
	public function firstConnectionSince( int $user_id, int $since ): ?array {
		global $wpdb;

		$tables = Tables::oauth();

		// Tokens are compared as UTC strings, the way their created_at is written.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, polled by the connect dialog.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT t.client_id, MIN( t.created_at ) AS first_at
				FROM %i t
				WHERE t.user_id = %d AND t.revoked = 0 AND t.created_at >= %s
					AND EXISTS (
						SELECT 1 FROM %i c
						WHERE c.client_id = t.client_id AND c.user_id = %d AND UNIX_TIMESTAMP( c.created_at ) >= %d
					)
				GROUP BY t.client_id
				ORDER BY first_at DESC
				LIMIT 1',
				$tables['access_tokens'],
				$user_id,
				gmdate( 'Y-m-d H:i:s', $since ),
				$tables['auth_codes'],
				$user_id,
				$since
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$client = $this->getClientEntity( (string) $row['client_id'] );

		return [
			'client_id'    => (string) $row['client_id'],
			'name'         => $client && $client->getName() !== '' ? (string) $client->getName() : __( 'Your assistant', 'albert-ai-butler' ),
			'label'        => $client ? (string) $client->getLabel() : '',
			'connected_ts' => (int) strtotime( $row['first_at'] . ' UTC' ),
		];
	}

	/**
	 * Every currently connected client, one entry each, most recently used
	 * first. The single source of truth for "what counts as a connection":
	 * used both by the Connections screen
	 * ({@see \Albert\Admin\Connections::get_connections()}) and by
	 * {@see \Albert\OAuth\ConnectionRetention}'s automatic sweeps, so a
	 * client is never dropped as "never used" or "idle" while it is still
	 * something an owner can see connected on screen.
	 *
	 * "Connected" is *either* a live access token *or* a live refresh token
	 * behind one. Access tokens last an hour, so a client that has been idle
	 * since lunchtime holds an expired one and would otherwise vanish from
	 * this list, while still being able to come back, unprompted, at any
	 * moment. A list that hides a connection which is about to make a
	 * request is not telling the truth about what is connected.
	 *
	 * Sorted by last used rather than created, so the assistants somebody
	 * actually relies on float to the top and the forgotten ones sink toward
	 * the scroll boundary, which is where "spot one you don't recognise"
	 * gets easier as the list grows rather than harder.
	 *
	 * Two queries rather than one join: the token aggregate, then each
	 * client row through {@see self::getClientEntity()}, which hydrates the
	 * newer columns defensively. The second query runs once per connected
	 * client, and the number of connected clients is single digits on every
	 * real site; a join would trade that for a `SELECT c.*` whose column
	 * names collide with the token table's.
	 *
	 * @return array<int, array<string, mixed>>
	 * @since 1.4.0
	 */
	public function getLiveConnections(): array {
		global $wpdb;

		$tables = Tables::oauth();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screen / cron read.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT
					t.client_id AS client_id,
					COUNT( DISTINCT t.id ) AS token_count,
					MAX( UNIX_TIMESTAMP( t.expires_at ) ) AS expires_ts,
					MIN( UNIX_TIMESTAMP( t.created_at ) ) AS first_token_ts,
					GROUP_CONCAT( DISTINCT t.user_id ) AS user_ids
				FROM %i t
				LEFT JOIN %i r
					ON r.access_token_id = t.token_id
					AND r.revoked = 0
					AND r.expires_at > UTC_TIMESTAMP()
				WHERE ( t.revoked = 0 AND t.expires_at > UTC_TIMESTAMP() )
					OR r.id IS NOT NULL
				GROUP BY t.client_id
				ORDER BY first_token_ts DESC',
				$tables['access_tokens'],
				$tables['refresh_tokens']
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return [];
		}

		$connections = [];

		foreach ( $rows as $row ) {
			$client_id = (string) $row['client_id'];
			$client    = $this->getClientEntity( $client_id );

			$created      = $client ? $client->getCreatedAt() : null;
			$last_used    = $client ? $client->getLastUsedAt() : null;
			$label_set_at = $client ? $client->getLabelSetAt() : null;

			$connections[] = [
				'client_id'    => $client_id,
				'name'         => $client && $client->getName() !== '' ? (string) $client->getName() : __( 'Unknown assistant', 'albert-ai-butler' ),
				'label'        => $client ? (string) $client->getLabel() : '',
				'label_set_by' => $client ? (int) $client->getLabelSetBy() : 0,
				'label_set_at' => $label_set_at instanceof \DateTimeImmutable ? $label_set_at->getTimestamp() : 0,
				'created_ts'   => $created instanceof \DateTimeImmutable ? $created->getTimestamp() : (int) $row['first_token_ts'],
				'last_used_ts' => $last_used instanceof \DateTimeImmutable ? $last_used->getTimestamp() : 0,
				'token_count'  => (int) $row['token_count'],
				'user_ids'     => array_map( 'intval', array_filter( explode( ',', (string) $row['user_ids'] ) ) ),
			];
		}

		usort(
			$connections,
			static function ( array $a, array $b ): int {
				if ( $a['last_used_ts'] === $b['last_used_ts'] ) {
					return $b['created_ts'] <=> $a['created_ts'];
				}

				return $b['last_used_ts'] <=> $a['last_used_ts'];
			}
		);

		return $connections;
	}

	/**
	 * Revoke every access and refresh token a client holds, in full.
	 *
	 * Unlike {@see \Albert\Admin\Connections::revoke_client_tokens()}, which
	 * offers the owner a choice between signing a client out (access token
	 * only) and disconnecting it completely, an automatic sweep only ever
	 * means the latter: there is no "sign out and let it quietly come back
	 * within the hour" reading of a connection nobody asked to keep.
	 *
	 * @param string $client_id The OAuth client identifier.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function revokeAllTokens( string $client_id ): void {
		global $wpdb;

		$tables = Tables::oauth();

		/*
		 * No `revoked = 0` filter here.
		 *
		 * Refresh tokens carry no client id, so an access-token row is the
		 * only link back to the client holding one. Filtering that lookup
		 * on unrevoked rows meant that once the access tokens had been
		 * revoked, by a prior "sign it out" or by anything else, this found
		 * nothing and revoked no refresh tokens at all, while the screen
		 * said the assistant had to be approved again. It refreshed itself
		 * back in within the hour.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$token_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT token_id FROM %i WHERE client_id = %s',
				$tables['access_tokens'],
				$client_id
			)
		);

		( new RefreshTokenRepository() )->revokeForAccessTokens( (array) $token_ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->update(
			$tables['access_tokens'],
			[ 'revoked' => 1 ],
			[ 'client_id' => $client_id ],
			[ '%d' ],
			[ '%s' ]
		);
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
