<?php
/**
 * Unit tests for the `format` selector on the read abilities.
 *
 * Covers view-post and find-posts (the page variants share the same
 * ContentFormatter path): default format is backward-compatible
 * (content+blocks+plaintext); a narrow selector returns only that key; and
 * requesting html/markdown adds those keys, with html reflecting rendered output.
 *
 * @package Albert\Tests\Unit\Abilities
 */

namespace Albert\Tests\Unit\Abilities;

// WordPress + block stubs, REST plumbing, and the read-ability post stubs.
require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once __DIR__ . '/block-write-rest-stubs.php';
require_once __DIR__ . '/read-ability-stubs.php';

use Albert\Abilities\WordPress\Posts\FindPosts;
use Albert\Abilities\WordPress\Posts\ViewPost;
use Albert\Blocks\EditorMode;
use PHPUnit\Framework\TestCase;

/**
 * Read-ability format-selector tests.
 */
class ReadAbilityFormatTest extends TestCase {

	private const MARKUP = '<!-- wp:heading --><h2 class="wp-block-heading">Hello</h2><!-- /wp:heading -->'
		. '<!-- wp:paragraph --><p>World body</p><!-- /wp:paragraph -->';

	/**
	 * Seed a post and reset REST recorders before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_posts'] = [
			5 => (object) [
				'ID'            => 5,
				'post_type'     => 'post',
				'post_title'    => 'Hello',
				'post_content'  => self::MARKUP,
				'post_excerpt'  => '',
				'post_status'   => 'publish',
				'post_author'   => 1,
				'post_date'     => '2026-01-01 00:00:00',
				'post_modified' => '2026-01-02 00:00:00',
				'post_name'     => 'hello',
			],
		];

		$GLOBALS['albert_test_rest_calls']     = [];
		$GLOBALS['albert_test_rest_responses'] = [];
		unset(
			$GLOBALS['albert_test_caps'],
			$GLOBALS['albert_test_block_editor_post_types'],
			$GLOBALS['albert_test_block_editor_posts']
		);
		EditorMode::reset_cache();
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['albert_test_posts'],
			$GLOBALS['albert_test_rest_calls'],
			$GLOBALS['albert_test_rest_responses']
		);
		parent::tearDown();
	}

	// =====================================================================
	// view-post
	// =====================================================================

	public function test_view_post_default_returns_content_blocks_plaintext(): void {
		$result = ( new ViewPost() )->execute( [ 'id' => 5 ] );

		$post = $result['post'];
		$this->assertArrayHasKey( 'content', $post );
		$this->assertArrayHasKey( 'blocks', $post );
		$this->assertArrayHasKey( 'plaintext', $post );
		$this->assertArrayNotHasKey( 'html', $post );
		$this->assertArrayNotHasKey( 'markdown', $post );

		$this->assertSame( self::MARKUP, $post['content'] );
		$this->assertSame( "Hello\n\nWorld body", $post['plaintext'] );

		// Surrounding metadata still present.
		$this->assertSame( 5, $post['id'] );
		$this->assertSame( 'Hello', $post['title'] );
		$this->assertSame( 'publish', $post['status'] );

		// Editor signal exposed: block-editor default, content is block markup.
		$this->assertArrayHasKey( 'editor', $post );
		$this->assertArrayHasKey( 'has_blocks', $post );
		$this->assertSame( 'block', $post['editor'] );
		$this->assertTrue( $post['has_blocks'] );
	}

	public function test_view_post_reports_classic_editor_and_has_blocks_mismatch(): void {
		// Classic post type, but the stored content is still block markup — the
		// editor signal and has_blocks are independent and both surface.
		$GLOBALS['albert_test_block_editor_post_types'] = [ 'post' => false ];

		$result = ( new ViewPost() )->execute( [ 'id' => 5 ] );

		$post = $result['post'];
		$this->assertSame( 'classic', $post['editor'] );
		$this->assertTrue( $post['has_blocks'] );
	}

	public function test_view_post_plaintext_only_returns_only_plaintext_content_field(): void {
		$result = ( new ViewPost() )->execute(
			[
				'id'     => 5,
				'format' => [ 'plaintext' ],
			]
		);

		$post = $result['post'];
		$this->assertArrayHasKey( 'plaintext', $post );
		$this->assertArrayNotHasKey( 'content', $post );
		$this->assertArrayNotHasKey( 'blocks', $post );
		$this->assertArrayNotHasKey( 'html', $post );
		$this->assertArrayNotHasKey( 'markdown', $post );

		// Metadata is unaffected by the content selector.
		$this->assertSame( 5, $post['id'] );
	}

	public function test_view_post_html_and_markdown_added_and_html_reflects_rendering(): void {
		$result = ( new ViewPost() )->execute(
			[
				'id'     => 5,
				'format' => [ 'html', 'markdown' ],
			]
		);

		$post = $result['post'];
		$this->assertArrayHasKey( 'html', $post );
		$this->assertArrayHasKey( 'markdown', $post );
		$this->assertArrayNotHasKey( 'content', $post );

		$this->assertStringContainsString( '<h2 class="wp-block-heading">Hello</h2>', $post['html'] );
		$this->assertStringContainsString( '<p>World body</p>', $post['html'] );
		$this->assertSame( "## Hello\n\nWorld body", $post['markdown'] );
	}

	public function test_view_post_surfaces_meta_window_in_result(): void {
		// The _meta window object must survive the array_merge into the post object.
		$result = ( new ViewPost() )->execute( [ 'id' => 5 ] );

		$post = $result['post'];
		$this->assertArrayHasKey( '_meta', $post );
		$this->assertSame( 2, $post['_meta']['total_blocks'] );
		$this->assertSame( 0, $post['_meta']['offset'] );
		$this->assertSame( 2, $post['_meta']['returned_blocks'] );
		$this->assertFalse( $post['_meta']['truncated'] );
		$this->assertArrayNotHasKey( 'next_offset', $post['_meta'] );
	}

	public function test_view_post_offset_limit_window_reports_next_offset(): void {
		$result = ( new ViewPost() )->execute(
			[
				'id'     => 5,
				'offset' => 0,
				'limit'  => 1,
			]
		);

		$post = $result['post'];
		$this->assertSame( 1, $post['_meta']['returned_blocks'] );
		$this->assertTrue( $post['_meta']['truncated'] );
		$this->assertSame( 1, $post['_meta']['next_offset'] );
		// Only the first block is in the window.
		$this->assertCount( 1, $post['blocks'] );
		$this->assertSame( 'Hello', $post['blocks'][0]['plaintext'] );
	}

	// =====================================================================
	// find-posts
	// =====================================================================

	public function test_find_posts_default_returns_content_blocks_plaintext_per_item(): void {
		$this->queue_find_response();

		$result = ( new FindPosts() )->execute( [] );

		$item = $result['posts'][0];
		$this->assertArrayHasKey( 'content', $item );
		$this->assertArrayHasKey( 'blocks', $item );
		$this->assertArrayHasKey( 'plaintext', $item );
		$this->assertArrayNotHasKey( 'html', $item );
		$this->assertArrayNotHasKey( 'markdown', $item );
		$this->assertSame( self::MARKUP, $item['content'] );

		// Editor signal exposed per item.
		$this->assertArrayHasKey( 'editor', $item );
		$this->assertArrayHasKey( 'has_blocks', $item );
		$this->assertSame( 'block', $item['editor'] );
		$this->assertTrue( $item['has_blocks'] );
	}

	public function test_find_posts_html_markdown_added_when_requested(): void {
		$this->queue_find_response();

		$result = ( new FindPosts() )->execute( [ 'format' => [ 'html', 'markdown' ] ] );

		$item = $result['posts'][0];
		$this->assertArrayHasKey( 'html', $item );
		$this->assertArrayHasKey( 'markdown', $item );
		$this->assertArrayNotHasKey( 'content', $item );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Hello</h2>', $item['html'] );
		$this->assertSame( "## Hello\n\nWorld body", $item['markdown'] );

		// Metadata still present.
		$this->assertSame( 5, $item['id'] );
		$this->assertSame( 'Hello', $item['title'] );
	}

	/**
	 * Queue the canned REST list response find-posts reads (a single post id 5).
	 *
	 * @return void
	 */
	public function test_view_post_withholds_password_protected_content_from_a_reader(): void {
		$GLOBALS['albert_test_posts'][5]->post_password = 'secret';
		$GLOBALS['albert_test_posts'][5]->post_excerpt  = 'Teaser';
		$GLOBALS['albert_test_caps']                    = [ 'read', 'read_post' ];

		$post = ( new ViewPost() )->execute( [ 'id' => 5 ] )['post'];

		$this->assertTrue( $post['protected'] );
		$this->assertSame( '', $post['content'] );
		$this->assertSame( '', $post['plaintext'] );
		$this->assertSame( [], $post['blocks'] );
		$this->assertSame( '', $post['excerpt'] );
	}

	public function test_view_post_shows_password_protected_content_to_an_editor(): void {
		$GLOBALS['albert_test_posts'][5]->post_password = 'secret';
		$GLOBALS['albert_test_caps']                    = [ 'read', 'read_post', 'edit_post' ];

		$post = ( new ViewPost() )->execute( [ 'id' => 5 ] )['post'];

		$this->assertTrue( $post['protected'] );
		$this->assertSame( self::MARKUP, $post['content'] );
	}

	public function test_view_post_reports_unprotected_post(): void {
		$post = ( new ViewPost() )->execute( [ 'id' => 5 ] )['post'];

		$this->assertFalse( $post['protected'] );
	}

	public function test_find_posts_withholds_password_protected_content_from_a_reader(): void {
		$GLOBALS['albert_test_posts'][5]->post_password = 'secret';
		$GLOBALS['albert_test_caps']                    = [ 'read' ];
		$this->queue_find_response();

		$item = ( new FindPosts() )->execute( [] )['posts'][0];

		$this->assertTrue( $item['protected'] );
		$this->assertSame( '', $item['content'] );
		$this->assertSame( '', $item['plaintext'] );
	}

	private function queue_find_response(): void {
		$GLOBALS['albert_test_rest_responses']['get'] = [
			'is_error' => false,
			'data'     => [
				[
					'id'         => 5,
					'title'      => [ 'rendered' => 'Hello' ],
					'excerpt'    => [ 'rendered' => '' ],
					'status'     => 'publish',
					'date'       => '2026-01-01',
					'modified'   => '2026-01-02',
					'author'     => 1,
					'link'       => 'https://example.com/hello',
					'categories' => [],
					'tags'       => [],
				],
			],
		];
	}
}
