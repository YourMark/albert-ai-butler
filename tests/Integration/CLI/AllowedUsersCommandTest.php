<?php
/**
 * Integration tests for `wp albert allowed-users`.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\CLI;

require_once __DIR__ . '/wp-cli-fake.php';

use Albert\CLI\AllowedUsersCommand;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\AllowedUsers;
use Albert\Tests\TestCase;
use RuntimeException;
use WP_CLI;
use WP_CLI\Formatter;

/**
 * Smoke tests; AllowedUsersTest covers the list itself.
 *
 * @covers \Albert\CLI\AllowedUsersCommand
 */
class AllowedUsersCommandTest extends TestCase {

	/**
	 * A clean list and output.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
		delete_option( AllowedUsers::OPTION );
		WP_CLI::$output = [];
	}

	/**
	 * Add takes several users; an unknown user or one the picker would not offer changes nothing; list shows status.
	 *
	 * @return void
	 */
	public function test_add_and_list(): void {
		$command = new AllowedUsersCommand();
		$jan     = self::factory()->user->create(
			[
				'user_login' => 'jan',
				'role'       => 'editor',
			]
		);
		$maria   = self::factory()->user->create(
			[
				'user_email' => 'maria@example.test',
				'role'       => 'author',
			]
		);
		self::factory()->user->create(
			[
				'user_login' => 'sub',
				'role'       => 'subscriber',
			]
		);

		foreach ( [ [ 'jan', 'nobody' ], [ 'jan', 'sub' ] ] as $users ) {
			try {
				$command->add( $users );
				$this->fail( 'Expected an error.' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( [], AllowedUsers::ids() );
			}
		}

		$command->add( [ 'jan', 'maria@example.test', (string) $jan ] );
		AllowedUsers::mark_authorised( $maria );

		$this->assertSame( [ 'success', 'Added 2 of 2.' ], end( WP_CLI::$output ) );

		$command->add( [ 'jan' ] );

		$this->assertSame( [ 'success', 'Added 0 of 1.' ], end( WP_CLI::$output ) );

		$command->list_( [], [] );

		$rows = array_column( Formatter::$items, null, 'ID' );
		$this->assertSame( 'invited', $rows[ $jan ]['status'] );
		$this->assertSame( (string) wp_date( 'Y-m-d H:i', (int) AllowedUsers::expires_at( $jan ) ), $rows[ $jan ]['expires'] );
		$this->assertSame( 'authorised', $rows[ $maria ]['status'] );
	}

	/**
	 * Remove takes the person off the list and revokes their tokens.
	 *
	 * @return void
	 */
	public function test_remove_revokes_their_tokens(): void {
		global $wpdb;

		$jan   = self::factory()->user->create( [ 'user_login' => 'jan' ] );
		$table = Tables::oauth()['access_tokens'];
		self::factory()->user->create( [ 'user_login' => 'maria' ] );
		AllowedUsers::add( $jan );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$wpdb->insert(
			$table,
			[
				'token_id'   => 'tok_' . wp_generate_password( 12, false ),
				'client_id'  => 'client-a',
				'user_id'    => $jan,
				'scopes'     => '[]',
				'revoked'    => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			]
		);

		( new AllowedUsersCommand() )->remove( [ 'jan', 'maria' ] );

		$this->assertSame( [], AllowedUsers::ids() );
		$this->assertContains( [ 'warning', 'maria (ID ' . get_user_by( 'login', 'maria' )->ID . ') is not on the list.' ], WP_CLI::$output );
		$this->assertSame( [ 'success', 'Removed 1 of 2.' ], end( WP_CLI::$output ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
		$live = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND revoked = 0', $table, $jan ) );
		$this->assertSame( 0, $live );
	}
}
