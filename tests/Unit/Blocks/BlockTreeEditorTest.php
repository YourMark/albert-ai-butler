<?php
/**
 * Tests for the BlockTreeEditor.
 *
 * The editor performs pure positional tree operations (edit/add/remove/move) on
 * a parse_blocks() array. These tests assert that:
 *   - each op produces the expected serialized markup,
 *   - untouched sibling blocks survive byte-for-byte,
 *   - logical paths skip the empty-freeform separators parse_blocks() emits,
 *   - move reorders correctly in both directions (forward and backward),
 *   - staleness (`expect`) and out-of-range paths return the right WP_Errors.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

use Albert\Blocks\BlockTreeEditor;
use PHPUnit\Framework\TestCase;
use WP_Error;

// WordPress stubs (parse_blocks, serialize_blocks, is_wp_error, __()) and the
// WP_Error double the editor returns.
require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';

/**
 * BlockTreeEditor unit tests.
 */
class BlockTreeEditorTest extends TestCase {

	/**
	 * Editor under test.
	 *
	 * @var BlockTreeEditor
	 */
	private BlockTreeEditor $editor;

	/**
	 * Set up a fresh editor.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->editor = new BlockTreeEditor();
	}

	/**
	 * A simple replacement block (heading) for edit/add tests.
	 *
	 * @return array<string, mixed> ParsedBlock.
	 */
	private function heading_block(): array {
		return [
			'blockName'    => 'core/heading',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '<h2 class="wp-block-heading">Hi</h2>',
			'innerContent' => [ '<h2 class="wp-block-heading">Hi</h2>' ],
		];
	}

	/**
	 * Three top-level paragraphs separated by blank lines (so parse_blocks emits
	 * empty-freeform separators between them).
	 *
	 * @return array<int, array<string, mixed>> Parsed tree.
	 */
	private function three_paragraphs(): array {
		$markup = "<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->\n\n"
			. "<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->\n\n"
			. '<!-- wp:paragraph --><p>Three</p><!-- /wp:paragraph -->';

		return parse_blocks( $markup );
	}

	// =====================================================================
	// edit
	// =====================================================================

	public function test_edit_replaces_only_the_target_block(): void {
		$tree   = $this->three_paragraphs();
		$result = $this->editor->edit( $tree, [ 1 ], $this->heading_block() );

		$this->assertIsArray( $result );

		$markup = serialize_blocks( $result );

		// The edited block is the heading; the untouched siblings are verbatim.
		$this->assertStringContainsString( '<!-- wp:heading --><h2 class="wp-block-heading">Hi</h2><!-- /wp:heading -->', $markup );
		$this->assertStringContainsString( '<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->', $markup );
		$this->assertStringContainsString( '<!-- wp:paragraph --><p>Three</p><!-- /wp:paragraph -->', $markup );
		$this->assertStringNotContainsString( '<p>Two</p>', $markup );
	}

	public function test_edit_preserves_untouched_siblings_byte_for_byte(): void {
		$tree   = $this->three_paragraphs();
		$result = $this->editor->edit( $tree, [ 0 ], $this->heading_block() );

		$this->assertIsArray( $result );

		// Siblings 1 and 2 must serialize identically to their originals.
		$original   = serialize_blocks( array_slice( $tree, 2 ) ); // skip block 0 + its separator
		$new_markup = serialize_blocks( array_slice( $result, 1 ) );

		$this->assertStringContainsString( $original, $new_markup );
	}

	public function test_edit_nested_child_keeps_wrapper(): void {
		$markup = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Inner A</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>Inner B</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';
		$tree   = parse_blocks( $markup );

		$result = $this->editor->edit( $tree, [ 0, 1 ], $this->heading_block() );
		$this->assertIsArray( $result );

		$out = serialize_blocks( $result );
		$this->assertStringContainsString( '<div class="wp-block-group">', $out );
		$this->assertStringContainsString( '</div><!-- /wp:group -->', $out );
		$this->assertStringContainsString( '<p>Inner A</p>', $out );
		$this->assertStringContainsString( '<!-- wp:heading -->', $out );
		$this->assertStringNotContainsString( 'Inner B', $out );
	}

	public function test_edit_container_keeps_children_when_asked(): void {
		$tree = parse_blocks(
			'<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Inner A</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>Inner B</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
		);

		// The replacement as the serializer builds it from a spec without innerBlocks.
		$replacement = [
			'blockName'    => 'core/group',
			'attrs'        => [ 'tagName' => 'section' ],
			'innerBlocks'  => [],
			'innerHTML'    => '<section class="wp-block-group"></section>',
			'innerContent' => [ '<section class="wp-block-group"></section>' ],
		];

		$result = $this->editor->edit( $tree, [ 0 ], $replacement, null, true );
		$this->assertIsArray( $result );

		$out = serialize_blocks( $result );
		$this->assertStringContainsString( '<!-- wp:group {"tagName":"section"} --><section class="wp-block-group"><!-- wp:paragraph --><p>Inner A</p>', $out );
		$this->assertStringContainsString( '<p>Inner B</p><!-- /wp:paragraph --></section><!-- /wp:group -->', $out );
	}

	public function test_edit_refuses_to_keep_children_under_a_leaf_block(): void {
		$tree = parse_blocks(
			'<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Inner A</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
		);

		$result = $this->editor->edit( $tree, [ 0 ], $this->heading_block(), null, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_children_unplaceable', $result->get_error_code() );
	}

	public function test_edit_refuses_to_keep_children_under_a_hollow_wrapper(): void {
		$tree = parse_blocks(
			'<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Inner A</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
		);

		// An untemplated block the serializer self-closed: no wrapper markup to hold children.
		$cover = [
			'blockName'    => 'core/cover',
			'attrs'        => [ 'dimRatio' => 50 ],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];

		$result = $this->editor->edit( $tree, [ 0 ], $cover, null, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_children_unplaceable', $result->get_error_code() );
	}

	public function test_edit_keeps_children_when_switching_container_type(): void {
		$tree = parse_blocks(
			'<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Inner A</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
		);

		$quote = [
			'blockName'    => 'core/quote',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '<blockquote class="wp-block-quote"></blockquote>',
			'innerContent' => [ '<blockquote class="wp-block-quote"></blockquote>' ],
		];

		$result = $this->editor->edit( $tree, [ 0 ], $quote, null, true );
		$this->assertIsArray( $result );

		$this->assertStringContainsString(
			'<blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Inner A</p><!-- /wp:paragraph --></blockquote>',
			serialize_blocks( $result )
		);
	}

	public function test_edit_container_replaces_children_by_default(): void {
		$tree = parse_blocks(
			'<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Inner A</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->'
		);

		$result = $this->editor->edit( $tree, [ 0 ], $this->heading_block() );
		$this->assertIsArray( $result );

		$this->assertStringNotContainsString( 'Inner A', serialize_blocks( $result ) );
	}

	public function test_edit_three_levels_deep_bubbles_through_wrappers(): void {
		// group[0] > columns[0,0] > column[0,0,0] > paragraph[0,0,0,0]; edit the
		// paragraph. Exercises sync_inner_content bubbling up through THREE wrappers.
		$markup = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:paragraph --><p>Deep</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->'
			. '</div><!-- /wp:group -->';
		$tree   = parse_blocks( $markup );

		$result = $this->editor->edit( $tree, [ 0, 0, 0, 0 ], $this->heading_block() );
		$this->assertIsArray( $result );

		$out = serialize_blocks( $result );
		// All three wrappers survive and only the deep child was replaced.
		$this->assertStringContainsString( '<div class="wp-block-group">', $out );
		$this->assertStringContainsString( '<div class="wp-block-columns">', $out );
		$this->assertStringContainsString( '<div class="wp-block-column">', $out );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Hi</h2>', $out );
		$this->assertStringNotContainsString( '<p>Deep</p>', $out );
	}

	// =====================================================================
	// add
	// =====================================================================

	public function test_add_after_inserts_as_following_sibling(): void {
		$tree   = $this->three_paragraphs();
		$result = $this->editor->add( $tree, [ 0 ], 'after', $this->heading_block() );

		$this->assertIsArray( $result );
		$out = serialize_blocks( $result );

		$this->assertSame(
			strpos( $out, '<p>One</p>' ) < strpos( $out, '<h2 class="wp-block-heading">Hi</h2>' ),
			true
		);
		$this->assertTrue( strpos( $out, '<h2 class="wp-block-heading">Hi</h2>' ) < strpos( $out, '<p>Two</p>' ) );
	}

	public function test_add_before_inserts_as_preceding_sibling(): void {
		$tree   = $this->three_paragraphs();
		$result = $this->editor->add( $tree, [ 1 ], 'before', $this->heading_block() );

		$this->assertIsArray( $result );
		$out = serialize_blocks( $result );

		$this->assertTrue( strpos( $out, '<h2 class="wp-block-heading">Hi</h2>' ) < strpos( $out, '<p>Two</p>' ) );
		$this->assertTrue( strpos( $out, '<p>One</p>' ) < strpos( $out, '<h2 class="wp-block-heading">Hi</h2>' ) );
	}

	public function test_add_inside_end_appends_to_inner_blocks(): void {
		$markup = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Inner A</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';
		$tree   = parse_blocks( $markup );

		$result = $this->editor->add( $tree, [ 0 ], 'inside_end', $this->heading_block() );
		$this->assertIsArray( $result );

		$out = serialize_blocks( $result );
		$this->assertTrue( strpos( $out, '<p>Inner A</p>' ) < strpos( $out, '<h2 class="wp-block-heading">Hi</h2>' ) );
		$this->assertStringContainsString( '</div><!-- /wp:group -->', $out );
	}

	public function test_add_inside_start_prepends_to_inner_blocks(): void {
		$markup = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:paragraph --><p>Inner A</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';
		$tree   = parse_blocks( $markup );

		$result = $this->editor->add( $tree, [ 0 ], 'inside_start', $this->heading_block() );
		$this->assertIsArray( $result );

		$out = serialize_blocks( $result );
		$this->assertTrue( strpos( $out, '<h2 class="wp-block-heading">Hi</h2>' ) < strpos( $out, '<p>Inner A</p>' ) );
	}

	public function test_add_invalid_position_returns_error(): void {
		$tree   = $this->three_paragraphs();
		$result = $this->editor->add( $tree, [ 0 ], 'sideways', $this->heading_block() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_position_invalid', $result->get_error_code() );
	}

	public function test_add_inside_leaf_block_is_rejected(): void {
		// A paragraph can't hold inner blocks; inside_* must reject, not store
		// markup the editor cannot parse.
		$tree   = $this->three_paragraphs();
		$result = $this->editor->add( $tree, [ 0 ], 'inside_end', $this->heading_block() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_not_container', $result->get_error_code() );
	}

	public function test_add_inside_empty_container_is_allowed(): void {
		// An empty group (stored by the editor as one merged "<div…></div>" chunk)
		// is a known container; the inserted child must land INSIDE the wrapper.
		$markup = '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->';
		$tree   = parse_blocks( $markup );

		$result = $this->editor->add( $tree, [ 0 ], 'inside_end', $this->heading_block() );

		$this->assertIsArray( $result );
		$out = serialize_blocks( $result );

		$open  = strpos( $out, '<div class="wp-block-group">' );
		$child = strpos( $out, '<h2 class="wp-block-heading">Hi</h2>' );
		$close = strpos( $out, '</div>' );

		$this->assertNotFalse( $open );
		$this->assertNotFalse( $child );
		$this->assertNotFalse( $close );
		// The child must sit between the opening and closing wrapper tags.
		$this->assertTrue( $open < $child && $child < $close, 'Inserted child must be inside the wrapper, not after it.' );
	}

	// =====================================================================
	// remove
	// =====================================================================

	public function test_remove_deletes_only_the_target(): void {
		$tree   = $this->three_paragraphs();
		$result = $this->editor->remove( $tree, [ 1 ] );

		$this->assertIsArray( $result );
		$out = serialize_blocks( $result );

		$this->assertStringContainsString( '<p>One</p>', $out );
		$this->assertStringContainsString( '<p>Three</p>', $out );
		$this->assertStringNotContainsString( '<p>Two</p>', $out );
	}

	// =====================================================================
	// move
	// =====================================================================

	public function test_move_forward_reorders_correctly(): void {
		// Move block 0 (One) to logical index 2 (last). Expect order: Two, Three, One.
		$tree   = $this->three_paragraphs();
		$result = $this->editor->move( $tree, [ 0 ], 2 );

		$this->assertIsArray( $result );
		$out = serialize_blocks( $result );

		$this->assertTrue( strpos( $out, '<p>Two</p>' ) < strpos( $out, '<p>Three</p>' ) );
		$this->assertTrue( strpos( $out, '<p>Three</p>' ) < strpos( $out, '<p>One</p>' ) );
	}

	public function test_move_backward_reorders_correctly(): void {
		// Move block 2 (Three) to logical index 0. Expect: Three, One, Two.
		$tree   = $this->three_paragraphs();
		$result = $this->editor->move( $tree, [ 2 ], 0 );

		$this->assertIsArray( $result );
		$out = serialize_blocks( $result );

		$this->assertTrue( strpos( $out, '<p>Three</p>' ) < strpos( $out, '<p>One</p>' ) );
		$this->assertTrue( strpos( $out, '<p>One</p>' ) < strpos( $out, '<p>Two</p>' ) );
	}

	public function test_move_to_index_past_end_appends(): void {
		// A to_index beyond the last block appends it at the end.
		$tree   = $this->three_paragraphs();
		$result = $this->editor->move( $tree, [ 0 ], 999 );

		$this->assertIsArray( $result );
		$out = serialize_blocks( $result );

		// One moved last; Two and Three keep their order ahead of it.
		$this->assertTrue( strpos( $out, '<p>Two</p>' ) < strpos( $out, '<p>One</p>' ) );
		$this->assertTrue( strpos( $out, '<p>Three</p>' ) < strpos( $out, '<p>One</p>' ) );
	}

	// =====================================================================
	// logical → actual index mapping over separators
	// =====================================================================

	public function test_paths_skip_empty_separators(): void {
		$tree = $this->three_paragraphs();

		// The parsed array interleaves separators, so logical [1] is NOT actual
		// index 1. Editing logical [1] must hit the SECOND paragraph (Two).
		$this->assertNull( $tree[1]['blockName'], 'Sanity: actual index 1 is a separator.' );

		$result = $this->editor->edit( $tree, [ 1 ], $this->heading_block() );
		$this->assertIsArray( $result );

		$out = serialize_blocks( $result );
		$this->assertStringNotContainsString( '<p>Two</p>', $out );
		$this->assertStringContainsString( '<p>One</p>', $out );
		$this->assertStringContainsString( '<p>Three</p>', $out );

		// The separators themselves survive (the markup still has blank lines).
		$this->assertStringContainsString( "\n\n", $out );
	}

	// =====================================================================
	// errors
	// =====================================================================

	public function test_out_of_range_path_returns_not_found(): void {
		$tree   = $this->three_paragraphs();
		$result = $this->editor->edit( $tree, [ 9 ], $this->heading_block() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_path_not_found', $result->get_error_code() );
		$this->assertStringContainsString( '[9]', $result->get_error_message() );
	}

	public function test_unreachable_nested_path_returns_not_found(): void {
		// Block 0 has no inner blocks, so [0,0] is unreachable.
		$tree   = $this->three_paragraphs();
		$result = $this->editor->remove( $tree, [ 0, 0 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_path_not_found', $result->get_error_code() );
	}

	public function test_expect_mismatch_returns_conflict(): void {
		$tree = $this->three_paragraphs();

		// Block [1] is a paragraph; expecting a heading is a mismatch.
		$result = $this->editor->edit( $tree, [ 1 ], $this->heading_block(), 'core/heading' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_path_mismatch', $result->get_error_code() );
		$this->assertStringContainsString( 'core/heading', $result->get_error_message() );
		$this->assertStringContainsString( 'core/paragraph', $result->get_error_message() );
	}

	public function test_expect_match_allows_the_edit(): void {
		$tree   = $this->three_paragraphs();
		$result = $this->editor->edit( $tree, [ 1 ], $this->heading_block(), 'core/paragraph' );

		$this->assertIsArray( $result );
	}

	public function test_empty_path_returns_not_found(): void {
		$tree   = $this->three_paragraphs();
		$result = $this->editor->edit( $tree, [], $this->heading_block() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_path_not_found', $result->get_error_code() );
	}
}
