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
	 * REMOTE_ADDR before the test, null when unset.
	 *
	 * @var string|null
	 */
	private ?string $remote_addr = null;

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

		$this->remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
	}

	/**
	 * Put back what register() changed.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_transient( 'albert_dcr_' . md5( '127.0.0.1' ) );

		if ( $this->remote_addr === null ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		}

		parent::tear_down();
	}

	/**
	 * Insert a client registered `$hours_ago` hours ago.
	 *
	 * @param string $client_id Client id.
	 * @param int    $hours_ago Age in hours.
	 *
	 * @return string
	 */
	private function client( string $client_id, int $hours_ago ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture; created_at in the database's own time zone, as the column default writes it.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i ( client_id, name, redirect_uri, is_confidential, created_at )
				VALUES ( %s, %s, %s, 0, DATE_SUB( NOW(), INTERVAL %d HOUR ) )',
				Tables::oauth()['clients'],
				$client_id,
				'Client ' . $client_id,
				'http://localhost:5000/callback',
				$hours_ago
			)
		);

		return $client_id;
	}

	/**
	 * Give a client an access token, and optionally a refresh token.
	 *
	 * Each token is 'live', 'revoked' (unexpired but revoked) or 'expired'
	 * (unrevoked but past its expiry).
	 *
	 * @param string      $client_id Client id.
	 * @param string      $access    State of the access token.
	 * @param string|null $refresh   State of the refresh token, or null for none.
	 *
	 * @return void
	 */
	private function token( string $client_id, string $access, ?string $refresh = null ): void {
		global $wpdb;

		$tables   = Tables::oauth();
		$token_id = 'tok_' . $client_id;
		$expires  = static fn( string $state ): string => gmdate( 'Y-m-d H:i:s', $state === 'expired' ? time() - HOUR_IN_SECONDS : time() + HOUR_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$wpdb->insert(
			$tables['access_tokens'],
			[
				'token_id'   => $token_id,
				'client_id'  => $client_id,
				'user_id'    => 1,
				'revoked'    => $access === 'revoked' ? 1 : 0,
				'expires_at' => $expires( $access ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		if ( $refresh === null ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$wpdb->insert(
			$tables['refresh_tokens'],
			[
				'token_id'        => 'ref_' . $client_id,
				'access_token_id' => $token_id,
				'revoked'         => $refresh === 'revoked' ? 1 : 0,
				'expires_at'      => $expires( $refresh ),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}

	/**
	 * Give a client an unexpired authorization code, as during sign-in.
	 *
	 * @param string $client_id Client id.
	 *
	 * @return void
	 */
	private function auth_code( string $client_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$wpdb->insert(
			Tables::oauth()['auth_codes'],
			[
				'code_id'    => 'code_' . $client_id,
				'client_id'  => $client_id,
				'user_id'    => 1,
				'revoked'    => 0,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS ),
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
		$this->client( 'never-authorised', 240 );
		$this->token( $this->client( 'expired', 240 ), 'expired' );
		$this->token( $this->client( 'disconnected', 240 ), 'revoked', 'revoked' );

		$this->assertSame( 0, ( new ClientRepository() )->count_live_clients() );
		$this->assertNotInstanceOf( WP_Error::class, $this->register() );
	}

	/**
	 * A client with a live access token, or only a live refresh token, counts.
	 *
	 * @return void
	 */
	public function test_live_clients_count_against_the_cap(): void {
		$this->token( $this->client( 'live-access', 240 ), 'live' );
		$this->token( $this->client( 'signed-out', 240 ), 'revoked', 'live' );

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
		$this->client( 'old-tokenless', 240 );
		$this->client( 'new-tokenless', 48 );
		$this->token( $this->client( 'old-signed-out', 240 ), 'revoked', 'live' );
		$this->token( $this->client( 'old-live', 240 ), 'live' );

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
		$this->client( 'old-tokenless', 720 );
		add_filter( 'albert/oauth/stale_client_days', '__return_zero' );

		( new TokenCleanup() )->run();

		$this->assertTrue( $this->exists( 'old-tokenless' ) );
	}

	/**
	 * A disconnected client keeps its row, so the app can be approved again, but stops counting.
	 *
	 * @return void
	 */
	public function test_a_disconnected_client_is_kept_but_stops_counting(): void {
		$this->token( $this->client( 'disconnected', 48 ), 'live', 'live' );

		( new ClientRepository() )->revokeAllTokens( 'disconnected' );
		( new TokenCleanup() )->run();

		$this->assertTrue( $this->exists( 'disconnected' ) );
		$this->assertSame( 0, ( new ClientRepository() )->count_live_clients() );
	}

	/**
	 * Expired tokens are as dead as revoked ones, for access and refresh alike.
	 *
	 * @return void
	 */
	public function test_expired_tokens_do_not_count(): void {
		$this->token( $this->client( 'expired-access', 48 ), 'expired' );
		$this->token( $this->client( 'expired-refresh', 48 ), 'revoked', 'expired' );

		$this->assertSame( 0, ( new ClientRepository() )->count_live_clients() );
	}

	/**
	 * The grace window is 24 hours.
	 *
	 * @return void
	 */
	public function test_the_grace_window_ends_after_a_day(): void {
		$this->client( 'just-inside', 23 );
		$this->client( 'just-outside', 25 );

		$this->assertSame( 1, ( new ClientRepository() )->count_live_clients() );
	}

	/**
	 * A client somebody is signing in with right now is not cleaned up.
	 *
	 * @return void
	 */
	public function test_cleanup_keeps_a_client_mid_sign_in(): void {
		$this->auth_code( $this->client( 'signing-in', 240 ) );

		( new TokenCleanup() )->run();

		$this->assertTrue( $this->exists( 'signing-in' ) );
	}

	/**
	 * A refused registration is reported, with the client's name.
	 *
	 * @return void
	 */
	public function test_a_refusal_fires_registration_rejected(): void {
		$this->client( 'fresh-1', 0 );
		$this->client( 'fresh-2', 0 );
		$this->client( 'fresh-3', 0 );

		$seen = [];
		add_action(
			'albert/oauth/registration_rejected',
			static function ( string $reason, string $scheme, string $client_name ) use ( &$seen ): void {
				$seen[] = [ $reason, $client_name ];
			},
			10,
			3
		);

		$this->register();

		$this->assertSame( [ [ 'client_limit_reached', 'New assistant' ] ], $seen );
	}

	/**
	 * With the cap switched off, nothing is refused and nothing is shown.
	 *
	 * @return void
	 */
	public function test_no_cap_means_no_refusal_and_no_item(): void {
		add_filter( 'albert/oauth/max_clients', '__return_zero', 20 );
		$this->client( 'fresh-1', 0 );
		$this->client( 'fresh-2', 0 );
		$this->client( 'fresh-3', 0 );

		$this->assertNotInstanceOf( WP_Error::class, $this->register() );
		$this->assertNotContains( 'client-limit-reached', array_column( ( new Attention() )->items( 1 ), 'id' ) );
	}

	/**
	 * The Dashboard says so only while the cap is actually reached.
	 *
	 * @return void
	 */
	public function test_attention_item_shows_only_while_the_cap_is_reached(): void {
		$ids = static fn(): array => array_column( ( new Attention() )->items( 1 ), 'id' );

		$this->token( $this->client( 'a', 240 ), 'live' );
		$this->token( $this->client( 'b', 240 ), 'live' );
		$this->assertNotContains( 'client-limit-reached', $ids() );

		$this->token( $this->client( 'c', 240 ), 'live' );
		$this->assertContains( 'client-limit-reached', $ids() );
	}
}
