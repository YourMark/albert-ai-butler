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
		foreach ( [ 'clients', 'access_tokens' ] as $key ) {
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
	 * A client with one access token for a user, first issued at a given time.
	 *
	 * @param string $name    The client's name.
	 * @param int    $user_id The user.
	 * @param int    $issued  When the token was issued.
	 *
	 * @return string The client id.
	 */
	private function connect( string $name, int $user_id, int $issued ): string {
		global $wpdb;

		$created = ( new ClientRepository() )->createClient( $name, 'https://example.test/cb' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->insert(
			Tables::oauth()['access_tokens'],
			[
				'token_id'   => 'tok_' . wp_generate_password( 12, false ),
				'client_id'  => $created['client_id'],
				'user_id'    => $user_id,
				'scopes'     => '[]',
				'revoked'    => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s', $issued ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $issued + HOUR_IN_SECONDS ),
			]
		);

		return (string) $created['client_id'];
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
