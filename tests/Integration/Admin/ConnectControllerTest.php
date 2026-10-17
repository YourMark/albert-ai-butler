<?php
/**
 * Integration tests for the connect dialog's REST routes.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\Connections\SiteLocation;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\Tests\TestCase;
use WP_REST_Request;

/**
 * ConnectController tests.
 *
 * @covers \Albert\Admin\Rest\ConnectController
 * @covers \Albert\OAuth\Repositories\ClientRepository::firstConnectionSince
 */
class ConnectControllerTest extends TestCase {

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Fresh tables, routes registered.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		global $wpdb;
		foreach ( [ 'clients', 'access_tokens', 'auth_codes' ] as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reset.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::oauth()[ $key ] ) );
		}

		delete_option( SiteLocation::OPTION );

		$this->admin_id = self::factory()->user->create(
			[
				'role'         => 'administrator',
				'display_name' => 'Mark Jansen',
			]
		);
		wp_set_current_user( $this->admin_id );

	}

	/**
	 * A client a user approved at a given time: its code, then its token.
	 *
	 * @param string $name    The client's name.
	 * @param int    $user_id The user.
	 * @param int    $issued  When it was approved.
	 *
	 * @return string The client id.
	 */
	private function connect( string $name, int $user_id, int $issued ): string {
		global $wpdb;

		$created = ( new ClientRepository() )->createClient( $name, 'https://example.test/cb' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->insert(
			Tables::oauth()['auth_codes'],
			[
				'code_id'    => 'code_' . wp_generate_password( 12, false ),
				'client_id'  => $created['client_id'],
				'user_id'    => $user_id,
				'scopes'     => '[]',
				'revoked'    => 1,
				'expires_at' => gmdate( 'Y-m-d H:i:s', $issued + 10 * MINUTE_IN_SECONDS ),
			]
		);
		$this->issue_token( (string) $created['client_id'], $user_id, $issued );

		return (string) $created['client_id'];
	}

	/**
	 * An access token for a client and user, as approval or a refresh issues.
	 *
	 * @param string $client_id The client.
	 * @param int    $user_id   The user.
	 * @param int    $issued    When it was issued.
	 * @param int    $revoked   1 once a refresh has replaced it.
	 *
	 * @return void
	 */
	private function issue_token( string $client_id, int $user_id, int $issued, int $revoked = 0 ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->insert(
			Tables::oauth()['access_tokens'],
			[
				'token_id'   => 'tok_' . wp_generate_password( 12, false ),
				'client_id'  => $client_id,
				'user_id'    => $user_id,
				'scopes'     => '[]',
				'revoked'    => $revoked,
				'created_at' => gmdate( 'Y-m-d H:i:s', $issued ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $issued + HOUR_IN_SECONDS ),
			]
		);
	}

	/**
	 * An older connection refreshing while the dialog is open is not new,
	 * also once its first token is revoked or cleaned up.
	 *
	 * @return void
	 */
	public function test_a_refresh_of_an_older_connection_does_not_count(): void {
		global $wpdb;

		$client = $this->connect( 'Claude', $this->admin_id, time() - DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: the refresh revokes the old token.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET revoked = 1', Tables::oauth()['access_tokens'] ) );
		$this->issue_token( $client, $this->admin_id, time() );

		$this->assertSame( [ 'connected' => false ], $this->status( time() - 60 ) );
	}

	/**
	 * Ask the status route.
	 *
	 * @param int $since Unix timestamp.
	 *
	 * @return array<string, mixed>
	 */
	private function status( int $since ): array {
		$request = new WP_REST_Request( 'GET', '/albert/v1/connect/status' );
		$request->set_param( 'since', $since );

		return (array) rest_do_request( $request )->get_data();
	}

	/**
	 * Nothing new yet: not connected, even with an older connection around.
	 *
	 * @return void
	 */
	public function test_an_older_connection_does_not_count(): void {
		$this->connect( 'Claude', $this->admin_id, time() - DAY_IN_SECONDS );

		$this->assertSame( [ 'connected' => false ], $this->status( time() - 60 ) );
	}

	/**
	 * A connection this user approved after the dialog opened is reported.
	 *
	 * @return void
	 */
	public function test_a_new_connection_by_this_user_is_reported(): void {
		$client = $this->connect( 'Cursor', $this->admin_id, time() );

		$status = $this->status( time() - 60 );

		$this->assertTrue( $status['connected'] );
		$this->assertSame( $client, $status['client'] );
		$this->assertSame( 'Cursor', $status['name'] );
		$this->assertSame( 'Mark Jansen', $status['user'] );
	}

	/**
	 * Somebody else's new connection is never reported to this user.
	 *
	 * @return void
	 */
	public function test_another_users_connection_is_not_reported(): void {
		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->connect( 'Claude', $other, time() );

		$this->assertSame( [ 'connected' => false ], $this->status( time() - 60 ) );
	}

	/**
	 * Saving the location writes the site's answer.
	 *
	 * @return void
	 */
	public function test_saving_the_location(): void {
		$request = new WP_REST_Request( 'POST', '/albert/v1/connect/location' );
		$request->set_param( 'location', SiteLocation::LOCAL );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( SiteLocation::LOCAL, SiteLocation::saved() );
	}

	/**
	 * Someone who may not manage options cannot use either route.
	 *
	 * @return void
	 */
	public function test_routes_need_permission(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request = new WP_REST_Request( 'GET', '/albert/v1/connect/status' );
		$request->set_param( 'since', 0 );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}
}
