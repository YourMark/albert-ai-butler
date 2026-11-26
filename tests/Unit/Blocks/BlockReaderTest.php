<?php
/**
 * Tests for the BlockReader.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

use Albert\Blocks\BlockReader;
use PHPUnit\Framework\TestCase;

// Load WordPress function stubs (parse_blocks, wp_strip_all_tags, etc.).
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';

/**
 * BlockReader unit tests.
 *
 * Verifies that raw block markup is parsed into the compact shaped tree
 * `[ name, attributes, innerBlocks, plaintext ]` consumed by read abilities.
 */
class BlockReaderTest extends TestCase {

	/**
	 * Reader instance under test.
	 *
	 * @var BlockReader
	 */
	private BlockReader $reader;

	/**
	 * Set up a fresh reader for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reader = new BlockReader();
	}

	public function test_empty_content_returns_empty_array(): void {
		$this->assertSame( [], $this->reader->read( '' ) );
	}

	public function test_single_paragraph_is_shaped(): void {
		$tree = $this->reader->read( '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->' );

		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/paragraph', $tree[0]['name'] );
		$this->assertSame( [], $tree[0]['attributes'] );
		$this->assertSame( [], $tree[0]['innerBlocks'] );
		$this->assertSame( 'Hello world', $tree[0]['plaintext'] );
	}

	public function test_attributes_are_mapped_from_attrs(): void {
		$tree = $this->reader->read( '<!-- wp:heading {"level":3} --><h3>Title</h3><!-- /wp:heading -->' );

		$this->assertSame( 'core/heading', $tree[0]['name'] );
		$this->assertSame( [ 'level' => 3 ], $tree[0]['attributes'] );
		$this->assertSame( 'Title', $tree[0]['plaintext'] );
	}

	public function test_plaintext_strips_inline_tags(): void {
		$tree = $this->reader->read( '<!-- wp:paragraph --><p>Hello <strong>bold</strong> world</p><!-- /wp:paragraph -->' );

		$this->assertSame( 'Hello bold world', $tree[0]['plaintext'] );
	}

	public function test_nested_columns_recurse(): void {
		$markup = '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:paragraph --><p>Left</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:column -->'
			. '<!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:paragraph --><p>Right</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';

		$tree = $this->reader->read( $markup );

		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/columns', $tree[0]['name'] );
		$this->assertCount( 2, $tree[0]['innerBlocks'] );

		$left = $tree[0]['innerBlocks'][0];
		$this->assertSame( 'core/column', $left['name'] );
		$this->assertCount( 1, $left['innerBlocks'] );
		$this->assertSame( 'core/paragraph', $left['innerBlocks'][0]['name'] );
		$this->assertSame( 'Left', $left['innerBlocks'][0]['plaintext'] );

		$right = $tree[0]['innerBlocks'][1];
		$this->assertSame( 'Right', $right['innerBlocks'][0]['plaintext'] );

		// Additive `path` field addresses the separator-filtered tree.
		$this->assertSame( [ 0 ], $tree[0]['path'] );
		$this->assertSame( [ 0, 0 ], $left['path'] );
		$this->assertSame( [ 0, 0, 0 ], $left['innerBlocks'][0]['path'] );
		$this->assertSame( [ 0, 1 ], $right['path'] );
		$this->assertSame( [ 0, 1, 0 ], $right['innerBlocks'][0]['path'] );
	}

	public function test_freeform_whitespace_blocks_are_skipped(): void {
		// parse_blocks emits null-name blocks for the whitespace between blocks.
		$markup = '<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->'
			. "\n\n"
			. '<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->';

		$tree = $this->reader->read( $markup );

		$this->assertCount( 2, $tree );
		$this->assertSame( 'One', $tree[0]['plaintext'] );
		$this->assertSame( 'Two', $tree[1]['plaintext'] );
	}

	public function test_top_level_paths_skip_separators(): void {
		// The separator between the two paragraphs must NOT advance the logical
		// path, so the second paragraph is [1] (matching what BlockTreeEditor
		// navigates), not [2].
		$markup = '<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->'
			. "\n\n"
			. '<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->';

		$tree = $this->reader->read( $markup );

		$this->assertSame( [ 0 ], $tree[0]['path'] );
		$this->assertSame( [ 1 ], $tree[1]['path'] );
	}

	public function test_classic_freeform_content_is_preserved(): void {
		// Non-whitespace classic content (no block delimiters) should survive
		// as a null-name block, not be silently dropped.
		$tree = $this->reader->read( 'Just some classic text.' );

		$this->assertCount( 1, $tree );
		$this->assertNull( $tree[0]['name'] );
		$this->assertSame( 'Just some classic text.', $tree[0]['plaintext'] );
	}

	public function test_self_closing_block_has_empty_plaintext(): void {
		$tree = $this->reader->read( '<!-- wp:spacer {"height":"40px"} /-->' );

		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/spacer', $tree[0]['name'] );
		$this->assertSame( [ 'height' => '40px' ], $tree[0]['attributes'] );
		$this->assertSame( '', $tree[0]['plaintext'] );
	}

	public function test_shape_shapes_a_preparsed_list(): void {
		$markup = '<!-- wp:paragraph --><p>Pre-parsed</p><!-- /wp:paragraph -->';
		$parsed = parse_blocks( $markup );

		$tree = $this->reader->shape( $parsed );

		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/paragraph', $tree[0]['name'] );
		$this->assertSame( 'Pre-parsed', $tree[0]['plaintext'] );
	}

	public function test_shape_and_read_produce_the_same_tree(): void {
		$markup = '<!-- wp:heading {"level":3} --><h3>Title</h3><!-- /wp:heading -->'
			. "\n\n"
			. '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->';

		$via_read  = $this->reader->read( $markup );
		$via_shape = $this->reader->shape( parse_blocks( $markup ) );

		$this->assertSame( $via_read, $via_shape );
	}

	public function test_shape_skips_empty_freeform_separators(): void {
		// A sliced list may carry the whitespace separators parse_blocks emits.
		$parsed = parse_blocks(
			'<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->'
			. "\n\n"
			. '<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->'
		);

		$tree = $this->reader->shape( $parsed );

		$this->assertCount( 2, $tree );
	}

	public function test_leaf_blocks_carry_their_saved_markup(): void {
		$tree = $this->reader->read( '<!-- wp:paragraph --><p>Hello <strong>bold</strong> <a href="/x">link</a></p><!-- /wp:paragraph -->' );

		$this->assertSame( '<p>Hello <strong>bold</strong> <a href="/x">link</a></p>', $tree[0]['html'] );
	}

	public function test_containers_and_self_closing_blocks_carry_no_markup(): void {
		$tree = $this->reader->read(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Inner</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
			. '<!-- wp:latest-posts /-->'
		);

		$this->assertArrayNotHasKey( 'html', $tree[0] );
		$this->assertSame( '<p>Inner</p>', $tree[0]['innerBlocks'][0]['html'] );
		$this->assertArrayNotHasKey( 'html', $tree[1] );
	}

	public function test_classic_content_carries_its_markup(): void {
		$tree = $this->reader->read( '<p>Classic <em>text</em>.</p>' );

		$this->assertNull( $tree[0]['name'] );
		$this->assertSame( '<p>Classic <em>text</em>.</p>', $tree[0]['html'] );
	}
}
