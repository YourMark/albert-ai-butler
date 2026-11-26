<?php
/**
 * Integration tests for `wp albert allowed-users`.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\CLI;

require_once dirname( __DIR__, 3 ) . '/tests-fakes/wp-cli.php';

use Albert\CLI\AllowedUsersCommand;
use Albert\CLI\Commands;
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
		WP_CLI::$output   = [];
		WP_CLI::$commands = [];
		WP_CLI::$deferred = [];
		Formatter::$items = [];
	}

	/**
	 * `wp albert allowed-users` exists once registered: WP-CLI drops a subcommand whose parent is missing.
	 *
	 * @return void
	 */
	public function test_the_command_is_registered(): void {
		$this->assertFalse( WP_CLI::add_command( 'albert allowed-users', AllowedUsersCommand::class ) );
		$this->assertNotContains( 'albert allowed-users', WP_CLI::$commands );

		WP_CLI::$deferred = [];
		Commands::add_commands();

		$this->assertContains( 'albert allowed-users', WP_CLI::$commands );
		$this->assertSame( [], WP_CLI::$deferred );
	}

	/**
	 * `--format=ids` gets bare IDs, which is what Formatter prints for that format.
	 *
	 * @return void
	 */
	public function test_ids_format_lists_bare_ids(): void {
		$jan = self::factory()->user->create( [ 'role' => 'editor' ] );
		AllowedUsers::add( $jan );

		( new AllowedUsersCommand() )->list_( [], [ 'format' => 'ids' ] );

		$this->assertSame( [ $jan ], Formatter::$items );
	}

	/**
	 * Adding someone whose invitation expired names that, rather than "already on the list".
	 *
	 * @return void
	 */
	public function test_add_names_an_expired_invitation(): void {
		$jan = self::factory()->user->create(
			[
				'user_login' => 'jan',
				'role'       => 'editor',
			]
		);
		update_option(
			AllowedUsers::OPTION,
			[
				$jan => [
					'added_at'      => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
					'authorised_at' => null,
					'expires_at'    => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				],
			]
		);

		( new AllowedUsersCommand() )->add( [ 'jan' ] );

		$warnings = array_column( array_filter( WP_CLI::$output, static fn( $line ) => $line[0] === 'warning' ), 1 );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'invitation expired', $warnings[ array_key_first( $warnings ) ] );
		$this->assertSame( [ 'success', 'Added 0 of 1.' ], end( WP_CLI::$output ) );
	}

	/**
	 * A refused save on add or remove errors, so the command exits non-zero.
	 *
	 * @return void
	 */
	public function test_a_refused_save_errors(): void {
		$command = new AllowedUsersCommand();
		$jan     = self::factory()->user->create(
			[
				'user_login' => 'jan',
				'role'       => 'editor',
			]
		);
		$refuse  = static fn( $value, $old ) => $old;

		add_filter( 'pre_update_option_' . AllowedUsers::OPTION, $refuse, 10, 2 );

		try {
			$command->add( [ 'jan' ] );
			$this->fail( 'Expected an error.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Added 0 of 1; 1 could not be saved.', $e->getMessage() );
		}

		remove_filter( 'pre_update_option_' . AllowedUsers::OPTION, $refuse, 10 );
		AllowedUsers::add( $jan );
		add_filter( 'pre_update_option_' . AllowedUsers::OPTION, $refuse, 10, 2 );

		try {
			$command->remove( [ 'jan' ] );
			$this->fail( 'Expected an error.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Removed 0 of 1; 1 could not be saved.', $e->getMessage() );
		}

		$this->assertSame( [ $jan ], AllowedUsers::ids() );
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
		$this->assertContains( [ 'warning', 'maria (ID ' . get_user_by( 'login', 'maria' )->ID . ') is not on the list. Any assistants they connected were signed out.' ], WP_CLI::$output );
		$this->assertSame( [ 'success', 'Removed 1 of 2.' ], end( WP_CLI::$output ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
		$live = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND revoked = 0', $table, $jan ) );
		$this->assertSame( 0, $live );
	}
}
