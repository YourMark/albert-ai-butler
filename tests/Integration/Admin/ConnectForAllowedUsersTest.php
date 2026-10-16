<?php
/**
 * Integration tests for connecting as someone on the allowed list.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\Connections\ConnectCapability;
use Albert\Admin\Connections\SiteLocation;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\Tests\TestCase;
use WP_REST_Request;

/**
 * Connect capability, own-connection revoke and per-user location tests.
 *
 * @covers \Albert\Admin\Connections\ConnectCapability
 * @covers \Albert\OAuth\Repositories\ClientRepository::revokeUserTokens
 * @covers \Albert\Admin\Rest\ConnectController
 */
class ConnectForAllowedUsersTest extends TestCase {

	/**
	 * An editor.
	 *
	 * @var int
	 */
	private int $editor;

	/**
	 * Fresh tables and options.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		global $wpdb;
		foreach ( [ 'clients', 'access_tokens', 'refresh_tokens' ] as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reset.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::oauth()[ $key ] ) );
		}

		delete_option( AllowedUsers::OPTION );
		delete_option( SiteLocation::OPTION );

		$this->editor = self::factory()->user->create( [ 'role' => 'editor' ] );
	}

	/**
	 * Give a user a live token for a client.
	 *
	 * @param string $client_id The client.
	 * @param int    $user_id   The user.
	 *
	 * @return void
	 */
	private function token( string $client_id, int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->insert(
			Tables::oauth()['access_tokens'],
			[
				'token_id'   => 'tok_' . wp_generate_password( 12, false ),
				'client_id'  => $client_id,
				'user_id'    => $user_id,
				'scopes'     => '[]',
				'revoked'    => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			]
		);
	}

	/**
	 * Being on the list grants the capability, whatever the role; leaving it takes it away.
	 *
	 * @return void
	 */
	public function test_the_list_decides_who_may_connect(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->assertFalse( user_can( $this->editor, ConnectCapability::CAPABILITY ) );
		$this->assertTrue( user_can( $admin, ConnectCapability::CAPABILITY ) );

		AllowedUsers::add( $this->editor );
		$this->assertTrue( user_can( $this->editor, ConnectCapability::CAPABILITY ) );

		AllowedUsers::remove( $this->editor );
		$this->assertFalse( user_can( $this->editor, ConnectCapability::CAPABILITY ) );
	}

	/**
	 * Revoking your own connection leaves someone else's on the same app alone.
	 *
	 * @return void
	 */
	public function test_revoking_your_own_tokens_keeps_others_connected(): void {
		$other   = self::factory()->user->create( [ 'role' => 'editor' ] );
		$created = ( new ClientRepository() )->createClient( 'Claude', 'https://example.test/cb' );
		$client  = (string) $created['client_id'];

		$this->token( $client, $this->editor );
		$this->token( $client, $other );

		$this->assertSame( 1, ( new ClientRepository() )->revokeUserTokens( $client, $this->editor ) );

		$live = ( new ClientRepository() )->getLiveConnections();
		$this->assertSame( [ $other ], $live[0]['user_ids'] );
		$this->assertSame( 0, ( new ClientRepository() )->revokeUserTokens( $client, $this->editor ) );
	}

	/**
	 * Someone who is not an administrator saves the location for themselves only.
	 *
	 * @return void
	 */
	public function test_a_non_administrator_saves_the_location_for_themselves(): void {
		AllowedUsers::add( $this->editor );
		wp_set_current_user( $this->editor );

		$request = new WP_REST_Request( 'POST', '/albert/v1/connect/location' );
		$request->set_param( 'location', SiteLocation::LOCAL );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( '', SiteLocation::saved() );
		$this->assertSame( SiteLocation::LOCAL, SiteLocation::current( $this->editor ) );
	}
}
