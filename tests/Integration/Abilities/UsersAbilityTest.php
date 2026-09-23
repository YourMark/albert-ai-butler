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

		// These are ability-behaviour tests, not privacy tests: turn privacy mode
		// off so the raw field values can be asserted. Default (anonymised) output
		// is covered by the Albert\Privacy unit tests and by a dedicated case below.
		update_option( 'albert_privacy_mode', 'off' );
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

		// The role filter should only return the 2 subscribers, not the editor.
		$this->assertSame( 2, $result['total'] );
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
	 * ViewUser anonymises personal data under the default (Balanced) privacy mode.
	 *
	 * @return void
	 */
	public function test_view_user_anonymises_by_default(): void {
		update_option( 'albert_privacy_mode', 'balanced' );

		$user_id = self::factory()->user->create(
			[
				'user_login'  => 'maskeduser',
				'user_email'  => 'masked@albert.test',
				'first_name'  => 'Mask',
				'last_name'   => 'Ed',
				'user_url'    => 'https://maskeduser.example',
				'description' => 'A personal bio that must not reach the LLM.',
			]
		);

		$result = ( new ViewUser() )->execute( [ 'id' => $user_id ] );

		$this->assertIsArray( $result );
		$this->assertNotSame( 'masked@albert.test', $result['user']['email'] );
		$this->assertStringContainsString( '***', $result['user']['email'] );

		// Previously-leaked account fields are now masked, not raw.
		$this->assertNotSame( 'maskeduser', $result['user']['username'] );
		$this->assertNotSame( 'https://maskeduser.example', $result['user']['url'] );
		$this->assertSame( '', $result['user']['url'] );
		$this->assertNotSame( 'A personal bio that must not reach the LLM.', $result['user']['description'] );
		$this->assertSame( '[redacted]', $result['user']['description'] );
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
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'user-edit.php', $result['edit_url'] );
	}

	/**
	 * CreateUser does not accept a password from the caller.
	 *
	 * The schema is the contract an assistant reads, so the absence has to be
	 * asserted there, not only in behaviour.
	 *
	 * @return void
	 */
	public function test_create_user_does_not_accept_a_password(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'wp_get_ability() not available.' );
		}

		$ability = wp_get_ability( 'albert/create-user' );

		if ( ! $ability ) {
			$this->markTestSkipped( 'albert/create-user is not registered.' );
		}

		$schema = $ability->get_input_schema();

		$this->assertArrayNotHasKey( 'password', $schema['properties'] );
		$this->assertNotContains( 'password', $schema['required'] ?? [] );
	}

	/**
	 * CreateUser returns a reset link that actually validates for the new user.
	 *
	 * Asserting the key round-trips through core is the point: a link that only
	 * looks right is the failure worth catching, because it is how an account
	 * gets created that nobody can ever sign in to.
	 *
	 * @return void
	 */
	public function test_create_user_returns_a_usable_password_reset_link(): void {
		$result = ( new CreateUser() )->execute(
			[
				'username' => 'resetlink_user',
				'email'    => 'resetlink@albert.test',
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'password_reset_url', $result );

		$query = [];
		parse_str( (string) wp_parse_url( $result['password_reset_url'], PHP_URL_QUERY ), $query );

		$this->assertSame( 'rp', $query['action'] ?? '' );
		$this->assertSame( 'resetlink_user', $query['login'] ?? '' );
		$this->assertInstanceOf(
			\WP_User::class,
			check_password_reset_key( $query['key'] ?? '', 'resetlink_user' )
		);
	}

	/**
	 * A site with password resets switched off says so instead of going quiet.
	 *
	 * The account is still created, because deleting it again would be worse,
	 * but nobody can sign in to it. Silence here would have the caller telling
	 * somebody their account is ready when it is not.
	 *
	 * @return void
	 */
	public function test_create_user_explains_itself_when_no_reset_link_can_be_issued(): void {
		add_filter( 'allow_password_reset', '__return_false' );

		try {
			$result = ( new CreateUser() )->execute(
				[
					'username' => 'noreset_user',
					'email'    => 'noreset@albert.test',
				]
			);
		} finally {
			remove_filter( 'allow_password_reset', '__return_false' );
		}

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayNotHasKey( 'password_reset_url', $result );
		$this->assertNotEmpty( $result['password_reset_note'] ?? '' );
	}

	/**
	 * The generated password is never disclosed to the caller.
	 *
	 * @return void
	 */
	public function test_create_user_never_returns_a_password(): void {
		$result = ( new CreateUser() )->execute(
			[
				'username' => 'nopass_user',
				'email'    => 'nopass@albert.test',
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'password', $result );
		$this->assertArrayNotHasKey( 'user_pass', $result );
	}

	/**
	 * The reset link is masked for observers but intact for the caller.
	 *
	 * @return void
	 */
	public function test_create_user_masks_the_reset_link_for_observers(): void {
		$captured = null;

		add_action(
			'albert/abilities/after_execute',
			static function ( $ability_name, $args, $result ) use ( &$captured ) {
				if ( $ability_name === 'albert/create-user' ) {
					$captured = $result;
				}
			},
			10,
			4
		);

		$result = ( new CreateUser() )->guarded_execute(
			[
				'username' => 'masked_user',
				'email'    => 'masked@albert.test',
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'action=rp', $result['password_reset_url'] );
		$this->assertSame( '[redacted]', $captured['password_reset_url'] ?? null );
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

	/**
	 * UpdateUser does not accept a password.
	 *
	 * @return void
	 */
	public function test_update_user_does_not_accept_a_password(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'wp_get_ability() not available.' );
		}

		$ability = wp_get_ability( 'albert/update-user' );

		if ( ! $ability ) {
			$this->markTestSkipped( 'albert/update-user is not registered.' );
		}

		$this->assertArrayNotHasKey( 'password', $ability->get_input_schema()['properties'] );
	}

	/**
	 * A password supplied anyway is refused, and the stored hash is untouched.
	 *
	 * The schema no longer declares the key, but the Abilities API does not
	 * forbid unrecognised ones, so the refusal has to hold at execution. The
	 * hash assertion is the one that matters: a refusal that still wrote the
	 * password would be worse than no refusal at all.
	 *
	 * @return void
	 */
	public function test_update_user_refuses_a_supplied_password(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'keeper@albert.test' ] );
		$before  = get_userdata( $user_id )->user_pass;

		$result = ( new UpdateUser() )->execute(
			[
				'id'       => $user_id,
				'password' => 'attacker-chosen-password-12345',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'password_change_refused', $result->get_error_code() );

		clean_user_cache( $user_id );
		$this->assertSame( $before, get_userdata( $user_id )->user_pass );
	}

	/**
	 * The refusal wins even when the request carries legitimate changes too.
	 *
	 * Nothing is applied: a partial write would leave the caller unsure which
	 * half landed.
	 *
	 * @return void
	 */
	public function test_update_user_refuses_the_whole_request_when_a_password_rides_along(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'partial@albert.test' ] );

		$result = ( new UpdateUser() )->execute(
			[
				'id'         => $user_id,
				'first_name' => 'Changed',
				'password'   => 'attacker-chosen-password-12345',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );

		clean_user_cache( $user_id );
		$this->assertNotSame( 'Changed', get_userdata( $user_id )->first_name );
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
