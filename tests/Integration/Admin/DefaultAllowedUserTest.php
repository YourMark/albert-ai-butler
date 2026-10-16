<?php
/**
 * Integration tests for the default allowed administrator.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Admin;

use Albert\Admin\Connections\DefaultAllowedUser;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\AllowedUsers;
use Albert\OAuth\Repositories\ClientRepository;
use Albert\Tests\TestCase;

/**
 * DefaultAllowedUser integration tests.
 *
 * @covers \Albert\Admin\Connections\DefaultAllowedUser
 * @covers \Albert\OAuth\AllowedUsers::add_automatically
 */
class DefaultAllowedUserTest extends TestCase {

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Empty list, no clients, no decision yet.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reset.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::oauth()['clients'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reset.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::oauth()['access_tokens'] ) );

		delete_option( AllowedUsers::OPTION );
		delete_option( DefaultAllowedUser::DONE_OPTION );

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * Clear the screen so later tests do not inherit it.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$GLOBALS['current_screen'] = null;

		parent::tear_down();
	}

	/**
	 * Open a screen by id, as WordPress does before `current_screen` fires.
	 *
	 * @param string $id The screen id.
	 *
	 * @return void
	 */
	private function open_screen( string $id ): void {
		set_current_screen( $id );
		( new DefaultAllowedUser() )->maybe_add_on_albert_screen();
	}

	/**
	 * Activating adds the administrator, without an expiry, flagged automatic.
	 *
	 * @return void
	 */
	public function test_activation_adds_the_activating_administrator(): void {
		wp_set_current_user( $this->admin_id );

		DefaultAllowedUser::on_activation();

		$this->assertTrue( AllowedUsers::is_allowed( $this->admin_id ) );
		$this->assertNull( AllowedUsers::expires_at( $this->admin_id ) );
		$this->assertTrue( AllowedUsers::was_added_automatically( $this->admin_id ) );
	}

	/**
	 * A list somebody already chose is left alone.
	 *
	 * @return void
	 */
	public function test_activation_leaves_a_non_empty_list_alone(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		AllowedUsers::add( $editor );
		wp_set_current_user( $this->admin_id );

		DefaultAllowedUser::on_activation();

		$this->assertFalse( AllowedUsers::is_allowed( $this->admin_id ) );
		$this->assertSame( [ $editor ], AllowedUsers::ids() );
	}

	/**
	 * Only an administrator is added, never whoever happens to be signed in.
	 *
	 * @return void
	 */
	public function test_activation_never_adds_a_non_administrator(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		DefaultAllowedUser::on_activation();

		$this->assertFalse( AllowedUsers::has_any() );
	}

	/**
	 * A site activated without a user (WP-CLI) adds the first administrator
	 * who opens an Albert screen.
	 *
	 * @return void
	 */
	public function test_an_albert_screen_adds_the_administrator_once(): void {
		wp_set_current_user( $this->admin_id );

		$this->open_screen( 'toplevel_page_albert' );

		$this->assertTrue( AllowedUsers::is_allowed( $this->admin_id ) );

		// Removing yourself on purpose sticks.
		AllowedUsers::remove( $this->admin_id );
		$this->open_screen( 'toplevel_page_albert' );

		$this->assertFalse( AllowedUsers::has_any() );
	}

	/**
	 * Any other admin screen does nothing.
	 *
	 * @return void
	 */
	public function test_other_screens_do_nothing(): void {
		wp_set_current_user( $this->admin_id );

		$this->open_screen( 'edit-post' );

		$this->assertFalse( AllowedUsers::has_any() );
		$this->assertFalse( (bool) get_option( DefaultAllowedUser::DONE_OPTION ) );
	}

	/**
	 * A site that already has connections is in use: its owner manages the list.
	 *
	 * @return void
	 */
	public function test_a_site_with_connections_is_left_alone(): void {
		global $wpdb;

		$created = ( new ClientRepository() )->createClient( 'Claude', 'https://example.test/cb' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->insert(
			Tables::oauth()['access_tokens'],
			[
				'token_id'   => 'tok_' . wp_generate_password( 12, false ),
				'client_id'  => $created['client_id'],
				'user_id'    => $this->admin_id,
				'scopes'     => '[]',
				'revoked'    => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			]
		);
		wp_set_current_user( $this->admin_id );

		$this->open_screen( 'toplevel_page_albert' );

		$this->assertFalse( AllowedUsers::has_any() );
		$this->assertTrue( (bool) get_option( DefaultAllowedUser::DONE_OPTION ) );
	}
}
