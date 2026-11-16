<?php
/**
 * Rich-text tags through a real REST save.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Blocks;

use Albert\Abilities\WordPress\Posts\Create as CreatePost;
use Albert\Abilities\WordPress\Posts\EditBlock as EditPostBlock;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Albert leaves filtering to core's capability-aware KSES on save, and refuses
 * rather than letting that save strip a tag silently.
 *
 * @since 1.6.0
 */
class MarkupTagsTest extends TestCase {

	/**
	 * A ThirstyAffiliates link.
	 *
	 * @var string
	 */
	private const TA_LINK = '<ta href="https://example.com/go" linkid="1">this deal</ta>';

	/**
	 * Enable abilities.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * Log in a fresh user with the given role.
	 *
	 * @param string $role Role.
	 * @return int User ID.
	 */
	private function log_in( string $role ): int {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Create a post through the ability with one paragraph.
	 *
	 * @param string $content Paragraph content.
	 * @return array<string, mixed>|WP_Error
	 */
	private function create_paragraph( string $content ) {
		return ( new CreatePost() )->execute(
			[
				'title'  => 'Tags',
				'blocks' => [
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => $content ],
					],
				],
			]
		);
	}

	/**
	 * An administrator's plugin tag survives create.
	 *
	 * @return void
	 */
	public function test_admin_plugin_tag_survives_create(): void {
		$this->log_in( 'administrator' );

		$result = $this->create_paragraph( 'See ' . self::TA_LINK . '.' );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( self::TA_LINK, get_post( $result['id'] )->post_content );
	}

	/**
	 * An administrator's plugin tag survives a block edit.
	 *
	 * @return void
	 */
	public function test_admin_plugin_tag_survives_block_edit(): void {
		$author  = $this->log_in( 'administrator' );
		$post_id = self::factory()->post->create(
			[
				'post_author'  => $author,
				'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
			]
		);

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => $post_id,
				'path'  => [ 0 ],
				'block' => [
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'New ' . self::TA_LINK ],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( self::TA_LINK, get_post( $post_id )->post_content );
	}

	/**
	 * For a user without unfiltered_html, core's KSES still strips an event handler on save.
	 *
	 * @return void
	 */
	public function test_save_strips_event_handler_without_unfiltered_html(): void {
		$this->log_in( 'author' );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );

		$result = $this->create_paragraph( 'Click <a href="https://example.com" onclick="evil()">here</a>' );

		$this->assertIsArray( $result );
		$content = get_post( $result['id'] )->post_content;
		$this->assertStringNotContainsString( 'onclick', $content );
		$this->assertStringContainsString( '<a href="https://example.com">here</a>', $content );
	}

	/**
	 * A tag the user may not store is stripped on save without an error, as in the editor.
	 *
	 * @return void
	 */
	public function test_plugin_tag_is_stripped_on_block_edit_without_unfiltered_html(): void {
		$author  = $this->log_in( 'author' );
		$post_id = self::factory()->post->create(
			[
				'post_author'  => $author,
				'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
			]
		);

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => $post_id,
				'path'  => [ 0 ],
				'block' => [
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'New ' . self::TA_LINK ],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<ta', get_post( $post_id )->post_content );
		$this->assertStringContainsString( 'this deal', get_post( $post_id )->post_content );
	}

	/**
	 * An author who edits a block that already holds a plugin tag loses it on save without an
	 * error, as in the editor: the drop check reads the edit, not what survives KSES.
	 *
	 * @return void
	 */
	public function test_existing_plugin_tag_is_stripped_when_an_author_edits_its_block(): void {
		$this->log_in( 'administrator' );
		$author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$post_id = self::factory()->post->create(
			[
				'post_author'  => $author,
				'post_content' => '<!-- wp:paragraph --><p>See ' . self::TA_LINK . '.</p><!-- /wp:paragraph -->',
			]
		);
		$this->assertStringContainsString( self::TA_LINK, get_post( $post_id )->post_content );

		wp_set_current_user( $author );
		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => $post_id,
				'path'  => [ 0 ],
				'block' => [
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'Read ' . self::TA_LINK . '.' ],
				],
			]
		);

		$this->assertIsArray( $result );
		$content = get_post( $post_id )->post_content;
		$this->assertStringNotContainsString( '<ta', $content );
		$this->assertStringContainsString( 'Read', $content );
		$this->assertStringContainsString( 'this deal', $content );
	}
}
