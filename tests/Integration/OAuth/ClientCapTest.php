<?php
/**
 * Integration tests for the registration cap and stale client cleanup.
 *
 * The cap used to count every client row, and nothing deleted clients that
 * never authorised or were disconnected, so the table filled until every new
 * assistant was refused with `client_limit_reached`.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\OAuth;

use Albert\Admin\Connections;
use Albert\Admin\Dashboard\Attention;
use Albert\Cron\TokenCleanup;
use Albert\Database\Tables;
use Albert\OAuth\Endpoints\ClientRegistration;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Client cap tests.
 *
 * @covers \Albert\OAuth\Repositories\ClientRepository::count_live_clients
 * @covers \Albert\OAuth\Repositories\ClientRepository::delete_stale_clients
 * @covers \Albert\OAuth\Endpoints\ClientRegistration::limit_reached
 */
class ClientCapTest extends TestCase {

	/**
	 * Empty the OAuth tables and set a small cap.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		foreach ( [ 'clients', 'access_tokens', 'refresh_tokens' ] as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reset.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::oauth()[ $key ] ) );
		}

		add_filter( 'albert/oauth/max_clients', fn(): int => 3 );
	}

	/**
	 * Insert a client registered `$days_ago` days ago.
	 *
	 * @param string $client_id Client id.
	 * @param int    $days_ago  Age in days.
	 *
	 * @return string
	 */
	private function client( string $client_id, int $days_ago ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture; created_at in the database's own time zone, as the column default writes it.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i ( client_id, name, redirect_uri, is_confidential, created_at )
				VALUES ( %s, %s, %s, 0, DATE_SUB( NOW(), INTERVAL %d DAY ) )',
				Tables::oauth()['clients'],
				$client_id,
				'Client ' . $client_id,
				'http://localhost:5000/callback',
				$days_ago
			)
		);

		return $client_id;
	}

	/**
	 * Give a client an access token, and optionally a refresh token.
	 *
	 * @param string    $client_id      Client id.
	 * @param bool      $access_live    Whether the access token is unrevoked and unexpired.
	 * @param bool|null $refresh_live   Live refresh token, dead one, or none (null).
	 *
	 * @return void
	 */
	private function token( string $client_id, bool $access_live, ?bool $refresh_live = null ): void {
		global $wpdb;

		$tables   = Tables::oauth();
		$token_id = 'tok_' . $client_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$wpdb->insert(
			$tables['access_tokens'],
			[
				'token_id'   => $token_id,
				'client_id'  => $client_id,
				'user_id'    => 1,
				'revoked'    => $access_live ? 0 : 1,
				'expires_at' => gmdate( 'Y-m-d H:i:s', $access_live ? time() + HOUR_IN_SECONDS : time() - HOUR_IN_SECONDS ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		if ( $refresh_live === null ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$wpdb->insert(
			$tables['refresh_tokens'],
			[
				'token_id'        => 'ref_' . $client_id,
				'access_token_id' => $token_id,
				'revoked'         => $refresh_live ? 0 : 1,
				'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + MONTH_IN_SECONDS ),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}

	/**
	 * Whether a client row still exists.
	 *
	 * @param string $client_id Client id.
	 *
	 * @return bool
	 */
	private function exists( string $client_id ): bool {
		return ( new ClientRepository() )->getClientEntity( $client_id ) !== null;
	}

	/**
	 * Register a client through the real endpoint.
	 *
	 * @return mixed
	 */
	private function register() {
		delete_transient( 'albert_dcr_' . md5( '127.0.0.1' ) );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		$request = new WP_REST_Request( 'POST', '/albert/v1/oauth/register' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				[
					'client_name'   => 'New assistant',
					'redirect_uris' => [ 'http://localhost:5000/callback' ],
				]
			)
		);

		return ( new ClientRegistration() )->handle_registration( $request );
	}

	/**
	 * Old clients without a live token no longer block registration.
	 *
	 * @return void
	 */
	public function test_dead_clients_do_not_count_against_the_cap(): void {
		$this->client( 'never-authorised', 10 );
		$this->token( $this->client( 'expired', 10 ), false );
		$this->token( $this->client( 'disconnected', 10 ), false, false );

		$this->assertSame( 0, ( new ClientRepository() )->count_live_clients() );
		$this->assertNotInstanceOf( WP_Error::class, $this->register() );
	}

	/**
	 * A client with a live access token, or only a live refresh token, counts.
	 *
	 * @return void
	 */
	public function test_live_clients_count_against_the_cap(): void {
		$this->token( $this->client( 'live-access', 10 ), true );
		$this->token( $this->client( 'signed-out', 10 ), false, true );

		$this->assertSame( 2, ( new ClientRepository() )->count_live_clients() );
	}

	/**
	 * A fresh registration nobody completed still counts, so the cap bounds abuse.
	 *
	 * @return void
	 */
	public function test_a_fresh_unauthorised_client_counts_for_a_day(): void {
		$this->client( 'fresh-1', 0 );
		$this->client( 'fresh-2', 0 );
		$this->client( 'fresh-3', 0 );

		$result = $this->register();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'client_limit_reached', $result->get_error_code() );
	}

	/**
	 * Cleanup deletes old clients without any token, and nothing else.
	 *
	 * @return void
	 */
	public function test_cleanup_deletes_only_old_tokenless_clients(): void {
		$this->client( 'old-tokenless', 10 );
		$this->client( 'new-tokenless', 2 );
		$this->token( $this->client( 'old-signed-out', 10 ), false, true );
		$this->token( $this->client( 'old-live', 10 ), true );

		( new TokenCleanup() )->run();

		$this->assertFalse( $this->exists( 'old-tokenless' ) );
		$this->assertTrue( $this->exists( 'new-tokenless' ) );
		$this->assertTrue( $this->exists( 'old-signed-out' ), 'A client with a live refresh token must never be deleted.' );
		$this->assertTrue( $this->exists( 'old-live' ) );
	}

	/**
	 * Returning 0 from the filter keeps every client.
	 *
	 * @return void
	 */
	public function test_cleanup_can_be_switched_off(): void {
		$this->client( 'old-tokenless', 30 );
		add_filter( 'albert/oauth/stale_client_days', '__return_zero' );

		( new TokenCleanup() )->run();

		$this->assertTrue( $this->exists( 'old-tokenless' ) );
	}

	/**
	 * A full disconnect on the Connections screen deletes the client row.
	 *
	 * @return void
	 */
	public function test_full_revoke_deletes_the_client(): void {
		$this->token( $this->client( 'to-disconnect', 10 ), true, true );
		$this->token( $this->client( 'to-sign-out', 10 ), true, true );

		$revoke = new \ReflectionMethod( Connections::class, 'revoke_client_tokens' );
		$revoke->setAccessible( true );
		$revoke->invoke( new Connections(), 'to-disconnect', true );
		$revoke->invoke( new Connections(), 'to-sign-out', false );

		$this->assertFalse( $this->exists( 'to-disconnect' ) );
		$this->assertTrue( $this->exists( 'to-sign-out' ), 'Signing out keeps the client: it reconnects on its own.' );
	}

	/**
	 * The Dashboard says so only while the cap is actually reached.
	 *
	 * @return void
	 */
	public function test_attention_item_shows_only_while_the_cap_is_reached(): void {
		$ids = static fn(): array => array_column( ( new Attention() )->items( 1 ), 'id' );

		$this->token( $this->client( 'a', 10 ), true );
		$this->token( $this->client( 'b', 10 ), true );
		$this->assertNotContains( 'client-limit-reached', $ids() );

		$this->token( $this->client( 'c', 10 ), true );
		$this->assertContains( 'client-limit-reached', $ids() );
	}
}
