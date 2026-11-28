<?php
/**
 * Integration tests for the owner's skills and the switch.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\MCP\Skills;

use Albert\Context\SkillIndex;
use Albert\Database\Installer;
use Albert\MCP\Skills\OwnerSkills;
use Albert\MCP\Skills\SkillLoader;
use Albert\MCP\Skills\SkillRegistry;
use Albert\MCP\Skills\SkillSwitch;
use Albert\OAuth\Server\ConnectionContext;
use Albert\Tests\TestCase;
use WP_REST_Request;

/**
 * Owner skills, additions and customised copies reach the registry; switched-off skills reach nothing;
 * an assistant can change none of it.
 *
 * @covers \Albert\MCP\Skills\OwnerSkills
 * @covers \Albert\MCP\Skills\SkillSwitch
 * @covers \Albert\SafeMode\SkillGuard
 */
class OwnerSkillsTest extends TestCase {

	/**
	 * Log in as an administrator with a fresh registry.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		OwnerSkills::register_post_type();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->fresh();
	}

	/**
	 * Close any connection.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		ConnectionContext::reset();
		$this->fresh();
		parent::tear_down();
	}

	/**
	 * An owner skill is a skill like any other, from "This site".
	 *
	 * @return void
	 */
	public function test_an_owner_skill_is_registered(): void {
		$id = OwnerSkills::save(
			'owner',
			'house-style',
			[
				'title'     => 'House style',
				'summary'   => 'How we write here.',
				'body'      => 'Use British spelling.',
				'abilities' => 'albert/find-posts albert/update-post',
			]
		);
		$this->assertIsInt( $id );
		$this->fresh();

		$skill = SkillRegistry::get( 'house-style' );
		$this->assertNotNull( $skill );
		$this->assertSame( 'Use British spelling.', $skill->body() );
		$this->assertSame( 'This site', $skill->source() );
		$this->assertSame( [ 'albert/find-posts', 'albert/update-post' ], $skill->abilities() );
		$this->assertContains( 'house-style', array_column( SkillIndex::entries(), 'slug' ) );
		$this->assertTrue( post_type_supports( OwnerSkills::POST_TYPE, 'revisions' ) );
	}

	/**
	 * The owner's skill wins a slug a plugin also registers.
	 *
	 * @return void
	 */
	public function test_the_owner_wins_a_slug(): void {
		add_filter(
			'albert/skills/registry',
			static fn( array $skills ): array => $skills + [
				'shared-slug' => [
					'summary' => 'From a plugin.',
					'body'    => 'Plugin text.',
				],
			]
		);
		OwnerSkills::save( 'owner', 'shared-slug', [ 'body' => 'Owner text.' ] );
		$this->fresh();

		$this->assertSame( 'Owner text.', SkillRegistry::get( 'shared-slug' )->body() );
	}

	/**
	 * Additions come under the original; a customised copy replaces it; deleting brings the original back.
	 *
	 * @return void
	 */
	public function test_additions_and_customised_copies(): void {
		$original = SkillRegistry::get( 'menus' )->body();

		OwnerSkills::save( 'additions', 'menus', [ 'body' => 'Our footer menu is "Legal".' ] );
		$this->fresh();
		$this->assertStringStartsWith( $original, SkillRegistry::get( 'menus' )->body() );
		$this->assertStringEndsWith( "## Additions for this site\n\nOur footer menu is \"Legal\".", SkillRegistry::get( 'menus' )->body() );

		OwnerSkills::save(
			'customised',
			'menus',
			[
				'body'     => 'Our own menu guide.',
				'original' => hash( 'sha256', $original ),
			]
		);
		$this->fresh();
		$this->assertSame( 'Our own menu guide.', SkillRegistry::get( 'menus' )->body() );

		OwnerSkills::delete( 'customised', 'menus' );
		OwnerSkills::delete( 'additions', 'menus' );
		$this->fresh();
		$this->assertSame( $original, SkillRegistry::get( 'menus' )->body() );
	}

	/**
	 * Text over 32 KB is refused with the limit named.
	 *
	 * @return void
	 */
	public function test_oversize_text_is_refused(): void {
		$result = OwnerSkills::save( 'owner', 'too-long', [ 'body' => str_repeat( 'x', OwnerSkills::MAX_BYTES + 1 ) ] );

		$this->assertWPError( $result );
		$this->assertSame( 'albert_skill_too_large', $result->get_error_code() );
		$this->assertStringContainsString( '32 KB', $result->get_error_message() );
	}

	/**
	 * A switched-off skill is in no index or prompt, and get-skill refuses it by name.
	 *
	 * @return void
	 */
	public function test_a_switched_off_skill_reaches_nothing(): void {
		SkillSwitch::set( 'menus', false );
		$this->fresh();

		$prompts = array_map( static fn( $prompt ): string => $prompt->get_name(), ( new SkillLoader() )->prompts() );
		$result  = wp_get_ability( 'albert/get-skill' )->execute( [ 'slug' => 'menus' ] );

		$this->assertArrayNotHasKey( 'menus', SkillRegistry::available() );
		$this->assertNotContains( 'menus', array_column( SkillIndex::entries(), 'slug' ) );
		$this->assertNotContains( 'menus', $prompts );
		$this->assertWPError( $result );
		$this->assertSame( 'skill_switched_off', $result->get_error_code() );

		SkillSwitch::set( 'menus', true );
		$this->fresh();
		$this->assertArrayHasKey( 'menus', SkillRegistry::available() );
	}

	/**
	 * The Skills screen switches a skill through REST; only an administrator may.
	 *
	 * @return void
	 */
	public function test_the_rest_switch(): void {
		$request = new WP_REST_Request( 'POST', '/albert/v1/skills/menus' );
		$request->set_param( 'enabled', false );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['enabled'] );
		$this->assertFalse( SkillSwitch::is_on( 'menus' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$missing = new WP_REST_Request( 'POST', '/albert/v1/skills/no-such-skill' );
		$missing->set_param( 'enabled', false );
		$this->assertSame( 404, rest_get_server()->dispatch( $missing )->get_status() );
	}

	/**
	 * Over a connection, nothing about the owner's skills or the switch can be changed.
	 *
	 * @return void
	 */
	public function test_an_assistant_cannot_change_guidance(): void {
		$id = OwnerSkills::save( 'owner', 'house-style', [ 'body' => 'Original.' ] );

		ConnectionContext::set( 'client-1' );

		$this->assertWPError(
			wp_update_post(
				[
					'ID'           => $id,
					'post_content' => 'Injected.',
				],
				true
			)
		);
		$this->assertWPError( OwnerSkills::save( 'owner', 'sneaky', [ 'body' => 'Injected.' ] ) );
		$this->assertFalse( update_post_meta( $id, OwnerSkills::META_ABILITIES, 'albert/delete-user' ) );
		$this->assertFalse( (bool) wp_trash_post( $id ) );
		$this->assertFalse( (bool) wp_delete_post( $id, true ) );
		update_option( SkillSwitch::OPTION, [ 'block-editor' ] );

		ConnectionContext::reset();
		$this->assertSame( 'Original.', get_post( $id )->post_content );
		$this->assertSame( 'private', get_post_status( $id ) );
		$this->assertNull( OwnerSkills::find( 'owner', 'sneaky' ) );
		$this->assertSame( '', get_post_meta( $id, OwnerSkills::META_ABILITIES, true ) );
		$this->assertTrue( SkillSwitch::is_on( 'block-editor' ) );
	}

	/**
	 * The routes around the plain post and meta functions are refused too.
	 *
	 * @return void
	 */
	public function test_an_assistant_cannot_go_around_the_guard(): void {
		$id    = OwnerSkills::save( 'owner', 'house-style', [ 'body' => 'Original.' ] );
		$other = self::factory()->post->create();
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => 'Second.',
			]
		);
		$revisions = wp_get_post_revisions( $id );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test lookup.
		$mid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE post_id = %d AND meta_key = %s', $wpdb->postmeta, $id, OwnerSkills::META_TARGET ) );

		ConnectionContext::set( 'client-1' );

		$this->assertFalse( update_metadata_by_mid( 'post', $mid, 'renamed' ) );
		$this->assertFalse( delete_metadata_by_mid( 'post', $mid ) );
		$this->assertFalse( delete_post_meta_by_key( OwnerSkills::META_KIND ) );
		$this->assertFalse( delete_post_meta( $id, OwnerSkills::META_TARGET ) );
		$this->assertFalse( add_post_meta( $id, '_extra', 'x' ) );
		$this->assertWPError(
			wp_insert_post(
				[
					'post_type'    => 'revision',
					'post_status'  => 'inherit',
					'post_parent'  => $id,
					'post_content' => 'Forged.',
				],
				true
			)
		);
		$this->assertWPError(
			wp_update_post(
				[
					'ID'        => $other,
					'post_type' => OwnerSkills::POST_TYPE,
				],
				true
			)
		);
		$this->assertFalse( (bool) wp_restore_post_revision( array_key_last( $revisions ) ) );
		$this->assertFalse( (bool) wp_untrash_post( $id ) );

		ConnectionContext::reset();
		$this->assertSame( 'house-style', get_post_meta( $id, OwnerSkills::META_TARGET, true ) );
		$this->assertSame( 'owner', get_post_meta( $id, OwnerSkills::META_KIND, true ) );
		$this->assertSame( 'Second.', get_post( $id )->post_content );
		$this->assertCount( count( $revisions ), wp_get_post_revisions( $id ) );
		$this->assertSame( 'post', get_post_type( $other ) );
		$this->assertNotNull( OwnerSkills::find( 'owner', 'house-style' ) );
	}

	/**
	 * Only an administrator can read or change a skill post.
	 *
	 * @return void
	 */
	public function test_only_administrators_reach_skill_posts(): void {
		$id = OwnerSkills::save( 'owner', 'house-style', [ 'body' => 'Text.' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		foreach ( [ 'read_post', 'edit_post', 'delete_post' ] as $cap ) {
			$this->assertFalse( current_user_can( $cap, $id ), $cap );
		}
		$this->assertTrue( isset( get_post_type_object( OwnerSkills::POST_TYPE )->cap->read ) );
	}

	/**
	 * A slug WordPress would rename, or one shaped like another kind's post, is refused; a re-save updates one post.
	 *
	 * @return void
	 */
	public function test_slugs_are_stable(): void {
		$this->assertWPError( OwnerSkills::save( 'owner', 'feed', [ 'body' => 'x' ] ) );
		$this->assertWPError( OwnerSkills::save( 'owner', 'additions-menus', [ 'body' => 'x' ] ) );
		$this->assertWPError( OwnerSkills::save( 'owner', 'Not Valid', [ 'body' => 'x' ] ) );

		$first  = OwnerSkills::save( 'owner', 'house-style', [ 'body' => 'One.' ] );
		$second = OwnerSkills::save( 'owner', 'house-style', [ 'body' => 'Two.' ] );
		$this->assertSame( $first, $second );
	}

	/**
	 * Get-skill's list of names leaves out a switched-off skill.
	 *
	 * @return void
	 */
	public function test_a_switched_off_name_is_not_suggested(): void {
		SkillSwitch::set( 'menus', false );
		$this->fresh();

		$result = wp_get_ability( 'albert/get-skill' )->execute( [ 'slug' => 'no-such-skill' ] );

		$this->assertStringNotContainsString( 'menus', $result->get_error_message() );
		$this->assertStringContainsString( 'block-editor', $result->get_error_message() );
	}

	/**
	 * Uninstall leaves no skill post and no switch.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_everything(): void {
		$id = OwnerSkills::save( 'owner', 'house-style', [ 'body' => 'Text.' ] );
		SkillSwitch::set( 'menus', false );

		Installer::uninstall();

		$this->assertNull( get_post( $id ) );
		$this->assertFalse( get_option( SkillSwitch::OPTION ) );

		// Uninstall drops tables, which the test transaction cannot roll back.
		Installer::install();
	}

	/**
	 * Drop the registry memo.
	 *
	 * @return void
	 */
	private function fresh(): void {
		SkillRegistry::reset_cache();
		SkillIndex::reset_cache();
	}
}
