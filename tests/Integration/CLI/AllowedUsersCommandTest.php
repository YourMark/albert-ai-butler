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

/**
 * List, add and remove, by id, login or email.
 *
 * @covers \Albert\CLI\AllowedUsersCommand
 * @covers \Albert\OAuth\AllowedUsers::add
 * @covers \Albert\OAuth\AllowedUsers::remove
 */
class AllowedUsersCommandTest extends TestCase {

	/**
	 * The command.
	 *
	 * @var AllowedUsersCommand
	 */
	private AllowedUsersCommand $command;

	/**
	 * An editor.
	 *
	 * @var \WP_User
	 */
	private \WP_User $jan;

	/**
	 * Every albert/allowed_users/* action fired, as `[ hook, args ]`.
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private array $fired = [];

	/**
	 * An empty list, an editor and a recorder for the actions.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();
		delete_option( AllowedUsers::OPTION );
		WP_CLI::reset();

		$this->command = new AllowedUsersCommand();
		$this->jan     = self::factory()->user->create_and_get(
			[
				'role'       => 'editor',
				'user_login' => 'jan',
				'user_email' => 'jan@example.test',
			]
		);

		foreach ( [ 'added', 'removed' ] as $event ) {
			add_action(
				'albert/allowed_users/' . $event,
				function ( ...$args ) use ( $event ): void {
					$this->fired[] = [ $event, $args ];
				},
				10,
				2
			);
		}
	}

	/**
	 * Add takes an id, a login or an email.
	 *
	 * @return void
	 */
	public function test_add_finds_people_by_id_login_or_email(): void {
		$maria = self::factory()->user->create( [ 'user_email' => 'maria@example.test' ] );
		$piet  = self::factory()->user->create( [ 'user_login' => 'piet' ] );

		$this->command->add( [ (string) $maria, 'piet', 'jan@example.test' ], [] );

		$this->assertEqualsCanonicalizing( [ $maria, $piet, $this->jan->ID ], AllowedUsers::ids() );
		$this->assertSame( [ 'success', 'Added 3 of 3.' ], end( WP_CLI::$output ) );
	}

	/**
	 * --expires sets this invitation's window; 0 means it never lapses.
	 *
	 * @return void
	 */
	public function test_add_with_expires(): void {
		$other = self::factory()->user->create();

		$this->command->add( [ 'jan' ], [ 'expires' => '7' ] );
		$this->command->add( [ (string) $other ], [ 'expires' => '0' ] );

		$this->assertEqualsWithDelta( time() + 7 * DAY_IN_SECONDS, AllowedUsers::expires_at( $this->jan->ID ), 5 );
		$this->assertNull( AllowedUsers::expires_at( $other ) );
		$this->assertTrue( AllowedUsers::is_allowed( $other ) );
	}

	/**
	 * A bad --expires or an unknown user changes nothing.
	 *
	 * @return void
	 */
	public function test_bad_input_changes_nothing(): void {
		foreach ( [ [ [ 'jan' ], [ 'expires' => 'soon' ] ], [ [ 'jan' ], [ 'expires' => '3651' ] ], [ [ 'jan', 'nobody' ], [] ] ] as [ $args, $assoc ] ) {
			try {
				$this->command->add( $args, $assoc );
				$this->fail( 'Expected an error.' );
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'error', end( WP_CLI::$output )[0] );
			}
		}

		$this->assertSame( [], AllowedUsers::ids() );
		$this->assertSame( [], $this->fired );
	}

	/**
	 * Adding someone already listed warns and fires nothing.
	 *
	 * @return void
	 */
	public function test_adding_twice_warns(): void {
		$this->command->add( [ 'jan' ], [] );
		$this->command->add( [ 'jan' ], [] );

		$this->assertSame( 'warning', WP_CLI::$output[2][0] );
		$this->assertCount( 1, $this->fired );
		$this->assertSame( 'added', $this->fired[0][0] );
		$this->assertSame( $this->jan->ID, $this->fired[0][1][0] );
		$this->assertSame( AllowedUsers::expires_at( $this->jan->ID ), $this->fired[0][1][1] );
	}

	/**
	 * Remove takes the person off the list, revokes their tokens and fires the action.
	 *
	 * @return void
	 */
	public function test_remove_revokes_their_tokens(): void {
		global $wpdb;

		AllowedUsers::add( $this->jan->ID );
		$this->fired = [];
		$table       = Tables::oauth()['access_tokens'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$wpdb->insert(
			$table,
			[
				'token_id'   => 'tok_' . wp_generate_password( 12, false ),
				'client_id'  => 'client-a',
				'user_id'    => $this->jan->ID,
				'scopes'     => '[]',
				'revoked'    => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			]
		);

		$this->command->remove( [ 'jan@example.test' ] );

		$this->assertSame( [], AllowedUsers::ids() );
		$this->assertSame( [ [ 'removed', [ $this->jan->ID ] ] ], $this->fired );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
		$live = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND revoked = 0', $table, $this->jan->ID ) );
		$this->assertSame( 0, $live );
	}

	/**
	 * Removing someone not listed warns and fires nothing.
	 *
	 * @return void
	 */
	public function test_removing_someone_not_listed_warns(): void {
		$this->command->remove( [ 'jan' ] );

		$this->assertSame( 'warning', WP_CLI::$output[0][0] );
		$this->assertSame( [], $this->fired );
	}

	/**
	 * List shows each person's status and when their invitation lapses.
	 *
	 * @return void
	 */
	public function test_list_shows_status(): void {
		$used    = self::factory()->user->create();
		$expired = self::factory()->user->create();

		AllowedUsers::add( $this->jan->ID, 0 );
		AllowedUsers::add( $used );
		AllowedUsers::mark_authorised( $used );
		AllowedUsers::add( $expired );

		$all                           = get_option( AllowedUsers::OPTION );
		$all[ $expired ]['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		update_option( AllowedUsers::OPTION, $all );

		$this->command->list_( [], [] );

		$rows = array_column( WP_CLI::$items, null, 'ID' );
		$this->assertSame( 'invited', $rows[ $this->jan->ID ]['status'] );
		$this->assertSame( 'never', $rows[ $this->jan->ID ]['expires'] );
		$this->assertSame( 'jan', $rows[ $this->jan->ID ]['user_login'] );
		$this->assertSame( 'authorised', $rows[ $used ]['status'] );
		$this->assertSame( 'never', $rows[ $used ]['expires'] );
		$this->assertSame( 'expired', $rows[ $expired ]['status'] );

		WP_CLI::reset();
		$this->command->list_( [], [ 'format' => 'count' ] );
		$this->assertSame( [ [ 'log', '3' ] ], WP_CLI::$output );
	}
}
