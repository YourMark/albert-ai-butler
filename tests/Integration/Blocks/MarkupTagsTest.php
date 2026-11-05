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
	 * A script tag KSES would strip is refused, and nothing is saved.
	 *
	 * @return void
	 */
	public function test_script_is_refused_without_unfiltered_html(): void {
		$this->log_in( 'author' );
		$before = wp_count_posts()->draft;

		$result = $this->create_paragraph( 'Hi <script>alert(1)</script>' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tags_not_allowed', $result->get_error_code() );
		$this->assertStringContainsString( '`<script>`', $result->get_error_message() );
		$this->assertSame( $before, wp_count_posts()->draft );
	}

	/**
	 * A plugin tag the user may not store is refused on a block edit, naming it.
	 *
	 * @return void
	 */
	public function test_plugin_tag_is_refused_on_block_edit_without_unfiltered_html(): void {
		$author  = $this->log_in( 'author' );
		$stored  = '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->';
		$post_id = self::factory()->post->create(
			[
				'post_author'  => $author,
				'post_content' => $stored,
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

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tags_not_allowed', $result->get_error_code() );
		$this->assertSame( [ 'ta' ], $result->get_error_data()['tags'] );
		$this->assertSame( $stored, get_post( $post_id )->post_content );
	}

	/**
	 * With `remove_tags`, a tag the user may not store is stripped on save, as core does.
	 *
	 * @return void
	 */
	public function test_remove_tags_lets_kses_strip_a_plugin_tag(): void {
		$author  = $this->log_in( 'author' );
		$post_id = self::factory()->post->create(
			[
				'post_author'  => $author,
				'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
			]
		);

		$result = ( new EditPostBlock() )->execute(
			[
				'id'          => $post_id,
				'path'        => [ 0 ],
				'remove_tags' => [ 'ta' ],
				'block'       => [
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'New ' . self::TA_LINK ],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( '<ta', get_post( $post_id )->post_content );
		$this->assertStringContainsString( 'New ', get_post( $post_id )->post_content );
	}
}
