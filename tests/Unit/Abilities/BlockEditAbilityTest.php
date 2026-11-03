<?php
/**
 * Unit tests for the granular per-block edit abilities.
 *
 * Exercises edit/add/remove/move on posts (the page variants share the same base
 * via AbstractBlockEdit, covered by one page smoke test): the correct REST POST
 * route is hit with the expected new content, block warnings surface, a fatal
 * block validation error aborts the write, classic targets are rejected, and the
 * refreshed block tree is returned.
 *
 * @package Albert\Tests\Unit\Abilities
 */

namespace Albert\Tests\Unit\Abilities;

// WordPress + block stubs and the REST harness used by the write abilities.
require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';
require_once __DIR__ . '/block-write-rest-stubs.php';

use Albert\Abilities\WordPress\Pages\EditBlock as EditPageBlock;
use Albert\Abilities\WordPress\Posts\AddBlock as AddPostBlock;
use Albert\Abilities\WordPress\Posts\EditBlock as EditPostBlock;
use Albert\Abilities\WordPress\Posts\MoveBlock as MovePostBlock;
use Albert\Abilities\WordPress\Posts\RemoveBlock as RemovePostBlock;
use Albert\Blocks\EditorMode;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Granular block-edit ability tests.
 */
class BlockEditAbilityTest extends TestCase {

	/**
	 * Reset REST harness, registry, editor-mode cache and posts before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_rest_calls']     = [];
		$GLOBALS['albert_test_rest_responses'] = [];
		$GLOBALS['albert_test_posts']          = [];

		unset(
			$GLOBALS['albert_test_block_editor_post_types'],
			$GLOBALS['albert_test_block_editor_posts'],
			$GLOBALS['albert_test_allowed_block_types']
		);

		albert_test_register_default_block_types();
		EditorMode::reset_cache();
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['albert_test_block_types'],
			$GLOBALS['albert_test_rest_calls'],
			$GLOBALS['albert_test_rest_responses'],
			$GLOBALS['albert_test_posts']
		);
		EditorMode::reset_cache();
		parent::tearDown();
	}

	/**
	 * Register a stub post with the given content and type.
	 *
	 * @param int    $id      Post ID.
	 * @param string $content Block markup.
	 * @param string $type    Post type.
	 * @return void
	 */
	private function set_post( int $id, string $content, string $type = 'post' ): void {
		$GLOBALS['albert_test_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => $type,
			'post_content' => $content,
			'post_status'  => 'publish',
		];
	}

	/**
	 * Queue a successful write response.
	 *
	 * @param array<string, mixed> $data Response body.
	 * @return void
	 */
	private function queue_write_success( array $data = [] ): void {
		$GLOBALS['albert_test_rest_responses']['write'] = [
			'is_error' => false,
			'data'     => array_merge(
				[
					'id'     => 7,
					'status' => 'publish',
					'link'   => 'https://example.com/?p=7',
				],
				$data
			),
		];
	}

	/**
	 * Get the recorded POST write calls (route + content param).
	 *
	 * @return array<int, array{route: string, content: string}>
	 */
	private function write_calls(): array {
		$calls = [];
		foreach ( (array) $GLOBALS['albert_test_rest_calls'] as $call ) {
			if ( $call['method'] === 'POST' ) {
				$calls[] = [
					'route'   => $call['route'],
					'content' => $call['params']['content'] ?? '',
				];
			}
		}

		return $calls;
	}

	/**
	 * Three-paragraph block markup with separators.
	 *
	 * @return string
	 */
	private function three_paragraphs(): string {
		return "<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->\n\n"
			. '<!-- wp:paragraph --><p>Three</p><!-- /wp:paragraph -->';
	}

	// =====================================================================
	// edit
	// =====================================================================

	public function test_edit_post_block_hits_post_route_with_new_content(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$ability = new EditPostBlock();
		$result  = $ability->execute(
			[
				'id'    => 7,
				'path'  => [ 1 ],
				'block' => [
					'name'       => 'core/heading',
					'attributes' => [
						'level'   => 2,
						'content' => 'New',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 7, $result['id'] );

		$calls = $this->write_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( '/wp/v2/posts/7', $calls[0]['route'] );

		// The persisted content edited only the middle paragraph.
		$this->assertStringContainsString( '<!-- wp:heading', $calls[0]['content'] );
		$this->assertStringContainsString( '<p>One</p>', $calls[0]['content'] );
		$this->assertStringContainsString( '<p>Three</p>', $calls[0]['content'] );
		$this->assertStringNotContainsString( '<p>Two</p>', $calls[0]['content'] );
	}

	/**
	 * A group holding two paragraphs.
	 *
	 * @return string
	 */
	private function group_with_children(): string {
		return '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Inner A</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>Inner B</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';
	}

	public function test_edit_without_inner_blocks_keeps_the_existing_children(): void {
		$this->set_post( 7, $this->group_with_children() );
		$this->queue_write_success();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => 7,
				'path'  => [ 0 ],
				'block' => [
					'name'       => 'core/group',
					'attributes' => [ 'tagName' => 'section' ],
				],
			]
		);

		$this->assertIsArray( $result );

		$content = $this->write_calls()[0]['content'];
		$this->assertStringContainsString( '<section class="wp-block-group">', $content );
		$this->assertStringContainsString( '<p>Inner A</p>', $content );
		$this->assertStringContainsString( '<p>Inner B</p>', $content );
	}

	public function test_edit_with_empty_inner_blocks_removes_the_children(): void {
		$this->set_post( 7, $this->group_with_children() );
		$this->queue_write_success();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => 7,
				'path'  => [ 0 ],
				'block' => [
					'name'        => 'core/group',
					'attributes'  => [],
					'innerBlocks' => [],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringNotContainsString( 'Inner A', $this->write_calls()[0]['content'] );
	}

	public function test_edit_into_a_leaf_without_inner_blocks_is_refused_without_writing(): void {
		$this->set_post( 7, $this->group_with_children() );
		$this->queue_write_success();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => 7,
				'path'  => [ 0 ],
				'block' => [
					'name'       => 'core/heading',
					'attributes' => [ 'content' => 'Title' ],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_children_unplaceable', $result->get_error_code() );
		$this->assertSame( [], $this->write_calls() );
	}

	public function test_edit_into_a_leaf_with_empty_inner_blocks_replaces_the_container(): void {
		$this->set_post( 7, $this->group_with_children() );
		$this->queue_write_success();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => 7,
				'path'  => [ 0 ],
				'block' => [
					'name'        => 'core/heading',
					'attributes'  => [ 'content' => 'Title' ],
					'innerBlocks' => [],
				],
			]
		);

		$this->assertIsArray( $result );

		$content = $this->write_calls()[0]['content'];
		$this->assertStringContainsString( 'Title', $content );
		$this->assertStringNotContainsString( 'Inner A', $content );
	}

	public function test_edit_returns_refreshed_blocks_tree_with_paths(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$ability = new EditPostBlock();
		$result  = $ability->execute(
			[
				'id'    => 7,
				'path'  => [ 0 ],
				'block' => [
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'Edited' ],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertNotEmpty( $result['blocks'] );
		$this->assertArrayHasKey( 'path', $result['blocks'][0] );
		$this->assertSame( [ 0 ], $result['blocks'][0]['path'] );
	}

	public function test_edit_with_unknown_block_aborts_without_writing(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$ability = new EditPostBlock();
		$result  = $ability->execute(
			[
				'id'    => 7,
				'path'  => [ 1 ],
				'block' => [ 'name' => 'core/hero' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertSame( [], $this->write_calls(), 'No write may run when a block error is present.' );
	}

	public function test_edit_with_warning_only_saves_and_surfaces_block_issues(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$ability = new EditPostBlock();
		$result  = $ability->execute(
			[
				'id'    => 7,
				'path'  => [ 1 ],
				'block' => [
					'name'       => 'core/image',
					'attributes' => [ 'alt' => 'No url yet' ],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->write_calls() );
		$this->assertArrayHasKey( 'block_issues', $result );
		$this->assertStringContainsString( 'core/image', implode( ' ', $result['block_issues'] ) );
	}

	public function test_edit_stale_expect_aborts_without_writing(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$ability = new EditPostBlock();
		$result  = $ability->execute(
			[
				'id'     => 7,
				'path'   => [ 1 ],
				'expect' => 'core/heading',
				'block'  => [
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'X' ],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_path_mismatch', $result->get_error_code() );
		$this->assertSame( [], $this->write_calls() );
	}

	/**
	 * A post whose second paragraph holds a ThirstyAffiliates link.
	 *
	 * @return string
	 */
	private function paragraph_with_ta(): string {
		return "<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->\n\n"
			. '<!-- wp:paragraph --><p>Buy <ta href="https://example.com" linkid="1">here</ta>.</p><!-- /wp:paragraph -->';
	}

	public function test_edit_that_drops_a_tag_is_refused_without_writing(): void {
		$this->set_post( 7, $this->paragraph_with_ta() );
		$this->queue_write_success();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => 7,
				'path'  => [ 1 ],
				'block' => [
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'Buy <a href="https://example.com">here</a>.' ],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'tags_removed', $result->get_error_code() );
		$this->assertSame( [], $this->write_calls() );
	}

	public function test_edit_that_drops_a_tag_saves_when_remove_tags_confirms_it(): void {
		$this->set_post( 7, $this->paragraph_with_ta() );
		$this->queue_write_success();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'          => 7,
				'path'        => [ 1 ],
				'remove_tags' => [ 'ta' ],
				'block'       => [
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'Buy here.' ],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->write_calls() );
	}

	public function test_edit_that_keeps_a_plugin_tag_writes_it_as_sent(): void {
		$this->set_post( 7, $this->paragraph_with_ta() );
		$this->queue_write_success();

		$content = 'Buy <ta href="https://example.com/new" linkid="2">now</ta>.';
		$result  = ( new EditPostBlock() )->execute(
			[
				'id'    => 7,
				'path'  => [ 1 ],
				'block' => [
					'name'       => 'core/heading',
					'attributes' => [
						'level'   => 2,
						'content' => $content,
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( $content, $this->write_calls()[0]['content'] );
	}

	public function test_removing_a_block_with_a_tag_needs_no_confirmation(): void {
		$this->set_post( 7, $this->paragraph_with_ta() );
		$this->queue_write_success();

		$result = ( new RemovePostBlock() )->execute(
			[
				'id'   => 7,
				'path' => [ 1 ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->write_calls() );
	}

	// =====================================================================
	// add / remove / move
	// =====================================================================

	public function test_add_post_block_inserts_and_writes(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$ability = new AddPostBlock();
		$result  = $ability->execute(
			[
				'id'       => 7,
				'path'     => [ 0 ],
				'position' => 'after',
				'block'    => [
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'Inserted' ],
				],
			]
		);

		$this->assertIsArray( $result );
		$calls = $this->write_calls();
		$this->assertSame( '/wp/v2/posts/7', $calls[0]['route'] );
		$this->assertStringContainsString( 'Inserted', $calls[0]['content'] );
	}

	public function test_remove_post_block_deletes_and_writes(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$ability = new RemovePostBlock();
		$result  = $ability->execute(
			[
				'id'   => 7,
				'path' => [ 1 ],
			]
		);

		$this->assertIsArray( $result );
		$calls = $this->write_calls();
		$this->assertCount( 1, $calls );
		$this->assertStringNotContainsString( '<p>Two</p>', $calls[0]['content'] );
		$this->assertStringContainsString( '<p>One</p>', $calls[0]['content'] );
	}

	public function test_remove_path_not_found_aborts_without_writing(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$ability = new RemovePostBlock();
		$result  = $ability->execute(
			[
				'id'   => 7,
				'path' => [ 9 ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_path_not_found', $result->get_error_code() );
		$this->assertSame( [], $this->write_calls() );
	}

	public function test_move_post_block_reorders_and_writes(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$ability = new MovePostBlock();
		$result  = $ability->execute(
			[
				'id'       => 7,
				'path'     => [ 0 ],
				'to_index' => 2,
			]
		);

		$this->assertIsArray( $result );
		$calls   = $this->write_calls();
		$content = $calls[0]['content'];

		// One moved to the end.
		$this->assertTrue( strpos( $content, '<p>Two</p>' ) < strpos( $content, '<p>One</p>' ) );
		$this->assertTrue( strpos( $content, '<p>Three</p>' ) < strpos( $content, '<p>One</p>' ) );
	}

	// =====================================================================
	// classic + not-found
	// =====================================================================

	public function test_classic_target_is_rejected(): void {
		$this->set_post( 7, '<p>Classic HTML</p>' );
		$GLOBALS['albert_test_block_editor_posts'] = [ 7 => false ];
		EditorMode::reset_cache();

		$ability = new EditPostBlock();
		$result  = $ability->execute(
			[
				'id'    => 7,
				'path'  => [ 0 ],
				'block' => [
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'X' ],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'classic_editor_no_block_edits', $result->get_error_code() );
		$this->assertSame( [], $this->write_calls() );
	}

	public function test_missing_post_returns_not_found(): void {
		$ability = new EditPostBlock();
		$result  = $ability->execute(
			[
				'id'    => 999,
				'path'  => [ 0 ],
				'block' => [ 'name' => 'core/paragraph' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	public function test_wrong_post_type_returns_not_found(): void {
		// A page id requested through the post ability.
		$this->set_post( 7, $this->three_paragraphs(), 'page' );

		$ability = new EditPostBlock();
		$result  = $ability->execute(
			[
				'id'    => 7,
				'path'  => [ 0 ],
				'block' => [ 'name' => 'core/paragraph' ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	// =====================================================================
	// page variant smoke test (shared base wires for pages)
	// =====================================================================

	public function test_edit_page_block_hits_page_route(): void {
		$this->set_post( 12, $this->three_paragraphs(), 'page' );
		$GLOBALS['albert_test_rest_responses']['write'] = [
			'is_error' => false,
			'data'     => [
				'id'     => 12,
				'status' => 'publish',
				'link'   => 'https://example.com/?page_id=12',
			],
		];

		$ability = new EditPageBlock();
		$result  = $ability->execute(
			[
				'id'    => 12,
				'path'  => [ 0 ],
				'block' => [
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'Edited page' ],
				],
			]
		);

		$this->assertIsArray( $result );
		$calls = $this->write_calls();
		$this->assertSame( '/wp/v2/pages/12', $calls[0]['route'] );
	}

	/**
	 * A paragraph spec.
	 *
	 * @param string $content Paragraph text.
	 * @return array<string, mixed>
	 */
	private function paragraph( string $content ): array {
		return [
			'name'       => 'core/paragraph',
			'attributes' => [ 'content' => $content ],
		];
	}

	public function test_edits_apply_in_order_and_save_once(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => 7,
				'edits' => [
					[
						'path'   => [ 0 ],
						'expect' => 'core/paragraph',
						'block'  => $this->paragraph( 'First' ),
					],
					[
						'path'  => [ 2 ],
						'block' => $this->paragraph( 'Third' ),
					],
				],
			]
		);

		$this->assertIsArray( $result );

		$calls = $this->write_calls();
		$this->assertCount( 1, $calls, 'Several edits save once.' );
		$this->assertStringContainsString( '<p>First</p>', $calls[0]['content'] );
		$this->assertStringContainsString( '<p>Two</p>', $calls[0]['content'] );
		$this->assertStringContainsString( '<p>Third</p>', $calls[0]['content'] );
		$this->assertStringNotContainsString( '<p>One</p>', $calls[0]['content'] );
	}

	public function test_edits_paths_resolve_against_the_tree_the_earlier_edits_left(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		// [0, 0] exists only once the first edit has turned [0] into a group.
		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => 7,
				'edits' => [
					[
						'path'  => [ 0 ],
						'block' => [
							'name'        => 'core/group',
							'innerBlocks' => [ $this->paragraph( 'Inside' ) ],
						],
					],
					[
						'path'   => [ 0, 0 ],
						'expect' => 'core/paragraph',
						'block'  => $this->paragraph( 'Changed inside' ),
					],
				],
			]
		);

		$this->assertIsArray( $result );

		$content = $this->write_calls()[0]['content'];
		$this->assertStringContainsString( '<p>Changed inside</p>', $content );
		$this->assertStringNotContainsString( '<p>Inside</p>', $content );
	}

	public function test_a_failing_edit_saves_nothing_and_names_its_index(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => 7,
				'edits' => [
					[
						'path'  => [ 0 ],
						'block' => $this->paragraph( 'First' ),
					],
					[
						'path'   => [ 1 ],
						'expect' => 'core/heading',
						'block'  => $this->paragraph( 'Second' ),
					],
					[
						'path'  => [ 2 ],
						'block' => $this->paragraph( 'Third' ),
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_path_mismatch', $result->get_error_code() );
		$this->assertStringStartsWith( 'edits[1] failed, so nothing was saved:', $result->get_error_message() );
		$this->assertSame( 1, $result->get_error_data()['edit_index'] );
		$this->assertSame( [], $this->write_calls() );
	}

	public function test_an_edit_with_a_disallowed_block_saves_nothing(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => 7,
				'edits' => [
					[
						'path'  => [ 0 ],
						'block' => $this->paragraph( 'First' ),
					],
					[
						'path'  => [ 1 ],
						'block' => [ 'name' => 'core/hero' ],
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertSame( 1, $result->get_error_data()['edit_index'] );
		$this->assertSame( [], $this->write_calls() );
	}

	public function test_an_edit_that_drops_a_tag_needs_remove_tags(): void {
		$this->set_post( 7, $this->paragraph_with_ta() );
		$this->queue_write_success();

		$args = [
			'id'    => 7,
			'edits' => [
				[
					'path'  => [ 0 ],
					'block' => $this->paragraph( 'First' ),
				],
				[
					'path'  => [ 1 ],
					'block' => $this->paragraph( 'Buy here.' ),
				],
			],
		];

		$refused = ( new EditPostBlock() )->execute( $args );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'tags_removed', $refused->get_error_code() );
		$this->assertSame( 1, $refused->get_error_data()['edit_index'] );
		$this->assertSame( [], $this->write_calls() );

		$args['remove_tags'] = [ 'ta' ];

		$this->assertIsArray( ( new EditPostBlock() )->execute( $args ) );
		$this->assertCount( 1, $this->write_calls() );
	}

	public function test_edits_and_single_edit_inputs_together_are_refused(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => 7,
				'path'  => [ 0 ],
				'block' => $this->paragraph( 'Single' ),
				'edits' => [
					[
						'path'  => [ 1 ],
						'block' => $this->paragraph( 'Batched' ),
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'edits_with_single_edit', $result->get_error_code() );
		$this->assertSame( [], $this->write_calls() );
	}

	public function test_an_edit_without_path_or_edits_is_refused(): void {
		$this->set_post( 7, $this->three_paragraphs() );
		$this->queue_write_success();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => 7,
				'block' => $this->paragraph( 'Where?' ),
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'path_required', $result->get_error_code() );
		$this->assertSame( [], $this->write_calls() );
	}

	public function test_edit_page_block_takes_edits(): void {
		$this->set_post( 12, $this->three_paragraphs(), 'page' );
		$this->queue_write_success( [ 'id' => 12 ] );

		$result = ( new EditPageBlock() )->execute(
			[
				'id'    => 12,
				'edits' => [
					[
						'path'  => [ 0 ],
						'block' => $this->paragraph( 'A' ),
					],
					[
						'path'  => [ 1 ],
						'block' => $this->paragraph( 'B' ),
					],
				],
			]
		);

		$this->assertIsArray( $result );

		$calls = $this->write_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( '/wp/v2/pages/12', $calls[0]['route'] );
	}
}
