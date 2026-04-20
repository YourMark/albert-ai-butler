<?php
/**
 * Parameter-level integration tests for User abilities.
 *
 * Verifies that every input parameter on FindUsers, ViewUser, CreateUser,
 * UpdateUser, and DeleteUser actually works as documented.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Users\Create as CreateUser;
use Albert\Abilities\WordPress\Users\Delete as DeleteUser;
use Albert\Abilities\WordPress\Users\FindUsers;
use Albert\Abilities\WordPress\Users\Update as UpdateUser;
use Albert\Abilities\WordPress\Users\ViewUser;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Users ability parameter tests.
 *
 * @since 1.1.0
 */
class UsersAbilityTest extends TestCase {

	/**
	 * Run as administrator with all abilities enabled.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	// ─── FindUsers ──────────────────────────────────────────────────

	/**
	 * Search parameter filters users by name or email.
	 *
	 * @return void
	 */
	public function test_find_users_search(): void {
		self::factory()->user->create(
			[
				'user_login' => 'findme_unique',
				'user_email' => 'findme@albert.test',
			]
		);
		self::factory()->user->create(
			[
				'user_login' => 'someone_else',
				'user_email' => 'else@albert.test',
			]
		);

		$result = ( new FindUsers() )->execute( [ 'search' => 'findme_unique' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'findme_unique', $result['users'][0]['username'] );
	}

	/**
	 * Role parameter filters users by role.
	 *
	 * @return void
	 */
	public function test_find_users_role_filter(): void {
		self::factory()->user->create( [ 'role' => 'editor' ] );
		self::factory()->user->create( [ 'role' => 'subscriber' ] );
		self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$result = ( new FindUsers() )->execute( [ 'role' => 'subscriber' ] );

		$this->assertIsArray( $result );
		foreach ( $result['users'] as $user ) {
			$this->assertContains( 'subscriber', $user['roles'] );
		}
	}

	/**
	 * Pagination works correctly.
	 *
	 * @return void
	 */
	public function test_find_users_pagination(): void {
		self::factory()->user->create_many( 5, [ 'role' => 'subscriber' ] );

		$page1 = ( new FindUsers() )->execute(
			[
				'per_page' => 2,
				'page'     => 1,
			]
		);
		$page2 = ( new FindUsers() )->execute(
			[
				'per_page' => 2,
				'page'     => 2,
			]
		);

		$this->assertCount( 2, $page1['users'] );
		$this->assertCount( 2, $page2['users'] );
		$this->assertNotSame( $page1['users'][0]['id'], $page2['users'][0]['id'] );
	}

	// ─── ViewUser ───────────────────────────────────────────────────

	/**
	 * ViewUser returns all expected fields.
	 *
	 * @return void
	 */
	public function test_view_user_returns_all_fields(): void {
		$user_id = self::factory()->user->create(
			[
				'user_login'  => 'viewme_user',
				'user_email'  => 'viewme@albert.test',
				'first_name'  => 'View',
				'last_name'   => 'Me',
				'role'        => 'editor',
				'user_url'    => 'https://example.com',
				'description' => 'Test bio',
			]
		);

		$result = ( new ViewUser() )->execute( [ 'id' => $user_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $user_id, $result['user']['id'] );
		$this->assertSame( 'viewme_user', $result['user']['username'] );
		$this->assertSame( 'viewme@albert.test', $result['user']['email'] );
		$this->assertSame( 'View', $result['user']['first_name'] );
		$this->assertSame( 'Me', $result['user']['last_name'] );
		$this->assertContains( 'editor', $result['user']['roles'] );
		$this->assertSame( 'https://example.com', $result['user']['url'] );
		$this->assertSame( 'Test bio', $result['user']['description'] );
	}

	/**
	 * ViewUser returns error for non-existent user.
	 *
	 * @return void
	 */
	public function test_view_user_not_found(): void {
		$result = ( new ViewUser() )->execute( [ 'id' => 99999 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'user_not_found', $result->get_error_code() );
	}

	// ─── CreateUser ─────────────────────────────────────────────────

	/**
	 * CreateUser with all optional parameters.
	 *
	 * @return void
	 */
	public function test_create_user_with_all_params(): void {
		$result = ( new CreateUser() )->execute(
			[
				'username'    => 'fulluser',
				'email'       => 'fulluser@albert.test',
				'password'    => 'strong-password-xyz-12345',
				'first_name'  => 'Full',
				'last_name'   => 'User',
				'roles'       => [ 'editor' ],
				'url'         => 'https://fulluser.test',
				'description' => 'Full user bio',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'fulluser', $result['username'] );
		$this->assertSame( 'fulluser@albert.test', $result['email'] );
		$this->assertContains( 'editor', $result['roles'] );

		$user = get_userdata( $result['id'] );
		$this->assertSame( 'Full', $user->first_name );
		$this->assertSame( 'User', $user->last_name );
		$this->assertSame( 'https://fulluser.test', $user->user_url );
		$this->assertSame( 'Full user bio', $user->description );
	}

	/**
	 * CreateUser defaults to subscriber role.
	 *
	 * @return void
	 */
	public function test_create_user_defaults_to_subscriber(): void {
		$result = ( new CreateUser() )->execute(
			[
				'username' => 'defaultrole',
				'email'    => 'defaultrole@albert.test',
				'password' => 'strong-password-xyz-12345',
			]
		);

		$this->assertIsArray( $result );
		$user = get_userdata( $result['id'] );
		$this->assertContains( 'subscriber', $user->roles );
	}

	/**
	 * CreateUser returns edit_url.
	 *
	 * @return void
	 */
	public function test_create_user_returns_edit_url(): void {
		$result = ( new CreateUser() )->execute(
			[
				'username' => 'editurl_user',
				'email'    => 'editurl@albert.test',
				'password' => 'strong-password-xyz-12345',
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'user-edit.php', $result['edit_url'] );
	}

	// ─── UpdateUser ─────────────────────────────────────────────────

	/**
	 * UpdateUser changes email.
	 *
	 * @return void
	 */
	public function test_update_user_email(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'old@albert.test' ] );

		$result = ( new UpdateUser() )->execute(
			[
				'id'    => $user_id,
				'email' => 'new@albert.test',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'new@albert.test', get_userdata( $user_id )->user_email );
	}

	/**
	 * UpdateUser changes name fields.
	 *
	 * @return void
	 */
	public function test_update_user_name_fields(): void {
		$user_id = self::factory()->user->create();

		$result = ( new UpdateUser() )->execute(
			[
				'id'         => $user_id,
				'first_name' => 'Updated',
				'last_name'  => 'Name',
			]
		);

		$this->assertIsArray( $result );
		$user = get_userdata( $user_id );
		$this->assertSame( 'Updated', $user->first_name );
		$this->assertSame( 'Name', $user->last_name );
	}

	/**
	 * UpdateUser changes roles.
	 *
	 * @return void
	 */
	public function test_update_user_roles(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$result = ( new UpdateUser() )->execute(
			[
				'id'    => $user_id,
				'roles' => [ 'editor' ],
			]
		);

		$this->assertIsArray( $result );
		$user = get_userdata( $user_id );
		$this->assertContains( 'editor', $user->roles );
		$this->assertNotContains( 'subscriber', $user->roles );
	}

	/**
	 * UpdateUser returns error for non-existent user.
	 *
	 * @return void
	 */
	public function test_update_user_not_found(): void {
		$result = ( new UpdateUser() )->execute(
			[
				'id'    => 99999,
				'email' => 'ghost@albert.test',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'user_not_found', $result->get_error_code() );
	}

	// ─── DeleteUser ─────────────────────────────────────────────────

	/**
	 * DeleteUser with reassign moves content to another user.
	 *
	 * @return void
	 */
	public function test_delete_user_with_reassign(): void {
		$inheritor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$victim    = self::factory()->user->create( [ 'role' => 'author' ] );
		$post_id   = self::factory()->post->create( [ 'post_author' => $victim ] );

		$result = ( new DeleteUser() )->execute(
			[
				'id'       => $victim,
				'reassign' => $inheritor,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertFalse( get_userdata( $victim ) );

		// Post should now belong to the inheritor.
		$this->assertEquals( $inheritor, get_post( $post_id )->post_author );
	}

	/**
	 * DeleteUser returns error for non-existent user.
	 *
	 * @return void
	 */
	public function test_delete_user_not_found(): void {
		$result = ( new DeleteUser() )->execute( [ 'id' => 99999 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'user_not_found', $result->get_error_code() );
	}
}
