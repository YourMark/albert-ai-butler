<?php
/**
 * Domain-change suspension for OAuth connections.
 *
 * @package Albert
 * @subpackage OAuth\Server
 * @since      1.4.0
 */

namespace Albert\OAuth\Server;

defined( 'ABSPATH' ) || exit;

use Albert\Database\Tables;
use Albert\OAuth\Repositories\ClientRepository;

/**
 * Suspends a connection when the site's address changes underneath it.
 *
 * Cloning production to staging copies the database, and the database is where
 * the access and refresh tokens live. Without this, the clone answers a live
 * assistant's requests with real data and real write access, and nobody is told.
 * That is not a convenience problem; it is the single way an authorised
 * connection ends up pointed at a site its owner never authorised.
 *
 * So each client records the host it was authorised against, and every token
 * validation compares that to the host the site answers as now. A mismatch
 * **suspends** rather than revokes, and the distinction is the whole design:
 *
 *  - *Revoked* is a person's decision, made on the Connections screen, and
 *    reconnecting means going through the whole authorisation again.
 *  - *Suspended* is the site noticing something it cannot interpret on its own.
 *    The fix is an administrator saying "yes, that move was us", so it has to be
 *    reversible without the assistant re-authorising, or every legitimate domain
 *    migration would cost every connected user a reconnection.
 *
 * A client with no recorded host is never suspended: there is no baseline to
 * compare against, and treating a missing one as a mismatch would punish every
 * connection that predates the recording for a migration that never happened.
 * They record their host the next time they are authorised.
 *
 * @since 1.4.0
 */
class DomainGuard {

	/**
	 * The host this site currently answers as.
	 *
	 * @return string Lower-cased host of `home_url()`, or '' when unavailable.
	 * @since 1.4.0
	 */
	public static function current_host(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Record the current host against a client, at authorisation time.
	 *
	 * Best-effort: a failed write leaves the client unrecorded, which means
	 * "never suspended" rather than "suspended by accident".
	 *
	 * @param string $client_id The OAuth client identifier.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public static function record_connection( string $client_id ): void {
		global $wpdb;

		$host = self::current_host();

		if ( $client_id === '' || $host === '' || ! self::column_exists() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, write path.
		$wpdb->update(
			Tables::oauth()['clients'],
			[ 'connect_host' => $host ],
			[ 'client_id' => $client_id ],
			[ '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * The host a client was authorised against, if one was recorded.
	 *
	 * @param string $client_id The OAuth client identifier.
	 *
	 * @return string The recorded host, or '' when none is on file.
	 * @since 1.4.0
	 */
	public static function recorded_host( string $client_id ): string {
		if ( $client_id === '' ) {
			return '';
		}

		// Read through the repository rather than naming the column in a SELECT.
		// The repository selects the whole row and hydrates what it finds, so on
		// an install whose schema has not caught up yet this returns "unrecorded"
		// instead of erroring, and this runs on every authenticated MCP call,
		// which is the last place that should depend on a migration having run.
		$client = ( new ClientRepository() )->getClientEntity( $client_id );

		if ( $client === null ) {
			return '';
		}

		return strtolower( (string) $client->getConnectHost() );
	}

	/**
	 * Whether a client is suspended because the site moved.
	 *
	 * @param string $client_id The OAuth client identifier.
	 *
	 * @return bool True when a recorded host disagrees with the current one.
	 * @since 1.4.0
	 */
	public static function is_suspended( string $client_id ): bool {
		$recorded = self::recorded_host( $client_id );

		if ( $recorded === '' ) {
			return false;
		}

		$current = self::current_host();

		return $current !== '' && $recorded !== $current;
	}

	/**
	 * Every client whose recorded host disagrees with the current one.
	 *
	 * @return array<int, array{client_id: string, name: string, connect_host: string}>
	 * @since 1.4.0
	 */
	public static function suspended_clients(): array {
		global $wpdb;

		$current = self::current_host();

		if ( $current === '' || ! self::column_exists() ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screen read.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT client_id, name, connect_host FROM %i WHERE connect_host IS NOT NULL AND connect_host <> %s AND connect_host <> %s ORDER BY name ASC',
				Tables::oauth()['clients'],
				$current,
				''
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$clients = [];

		foreach ( $rows as $row ) {
			$clients[] = [
				'client_id'    => (string) $row['client_id'],
				'name'         => (string) $row['name'],
				'connect_host' => (string) $row['connect_host'],
			];
		}

		return $clients;
	}

	/**
	 * Whether the clients table has caught up with the `connect_host` column.
	 *
	 * The schema is rebuilt by `dbDelta` when the plugin version advances, so
	 * between a schema change landing and the version bump that ships it, the
	 * column does not exist. Naming it in a write or an aggregate query would
	 * raise a database error on every affected request; the answer is cheap to
	 * establish once and never changes within a request.
	 *
	 * @return bool True when the column is present.
	 * @since 1.4.0
	 */
	private static function column_exists(): bool {
		global $wpdb;

		static $exists = null;

		if ( $exists !== null ) {
			return $exists;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reading the schema, not changing it.
		$column = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW COLUMNS FROM %i LIKE %s',
				Tables::oauth()['clients'],
				'connect_host'
			)
		);

		$exists = $column !== null;

		return $exists;
	}

	/**
	 * Lift the suspension on every affected client by adopting the current host.
	 *
	 * @return int The number of clients re-confirmed.
	 * @since 1.4.0
	 */
	public static function reconfirm_all(): int {
		$confirmed = 0;

		foreach ( self::suspended_clients() as $client ) {
			self::record_connection( $client['client_id'] );
			++$confirmed;
		}

		return $confirmed;
	}
}
