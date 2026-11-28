<?php
/**
 * Unit tests for per-block content hashes.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Undo;

use Albert\Undo\BlockTree;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';

/**
 * BlockTree tests.
 *
 * @covers \Albert\Undo\BlockTree
 */
class BlockTreeTest extends TestCase {

	/**
	 * A page with a nested Group, a heading and a list.
	 *
	 * @var string
	 */
	private const PAGE = '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Plans</h2><!-- /wp:heading -->'
		. "\n\n"
		. '<!-- wp:group {"layout":{"type":"constrained"},"align":"wide"} --><div class="wp-block-group alignwide">'
		. '<!-- wp:paragraph --><p>Inner one</p><!-- /wp:paragraph -->'
		. '<!-- wp:paragraph --><p>Inner two</p><!-- /wp:paragraph -->'
		. '</div><!-- /wp:group -->';

	/**
	 * The tree mirrors the nesting, one node per block, without the
	 * whitespace parse_blocks() puts between blocks.
	 *
	 * @return void
	 */
	public function test_the_tree_mirrors_the_nesting(): void {
		$tree = BlockTree::of( self::PAGE );

		$this->assertCount( 2, $tree );
		$this->assertSame( 'core/heading', $tree[0][0] );
		$this->assertArrayNotHasKey( 2, $tree[0], 'A leaf has no children entry.' );
		$this->assertSame( 'core/group', $tree[1][0] );
		$this->assertSame( [ 'core/paragraph', 'core/paragraph' ], array_column( $tree[1][2], 0 ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $tree[0][1] );
	}

	/**
	 * Attribute order and whitespace in the HTML do not change a hash.
	 *
	 * @return void
	 */
	public function test_attribute_order_and_whitespace_keep_the_hash(): void {
		$reordered = '<!-- wp:heading {"level":2} -->' . "\n" . '<h2 class="wp-block-heading">Plans</h2>' . "\n" . '<!-- /wp:heading -->'
			. '<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->' . "\n" . '<div class="wp-block-group alignwide">'
			. "\n\t" . '<!-- wp:paragraph --><p>Inner one</p><!-- /wp:paragraph -->'
			. "\n\t" . '<!-- wp:paragraph --><p>Inner two</p><!-- /wp:paragraph -->'
			. "\n" . '</div>' . "\n" . '<!-- /wp:group -->';

		$this->assertSame( BlockTree::of( self::PAGE ), BlockTree::of( $reordered ) );
	}

	/**
	 * A parse and serialize round trip, the way the editor re-saves, keeps every hash.
	 *
	 * @return void
	 */
	public function test_a_round_trip_keeps_every_hash(): void {
		$this->assertSame( BlockTree::of( self::PAGE ), BlockTree::of( serialize_blocks( parse_blocks( self::PAGE ) ) ) );
	}

	/**
	 * An edit to an inner block changes that block and every ancestor, and
	 * nothing beside them.
	 *
	 * @return void
	 */
	public function test_an_inner_edit_changes_the_block_and_its_ancestors(): void {
		$before = BlockTree::of( self::PAGE );
		$after  = BlockTree::of( str_replace( 'Inner two', 'Inner 2', self::PAGE ) );

		$this->assertSame( $before[0], $after[0], 'The heading is untouched.' );
		$this->assertSame( $before[1][2][0], $after[1][2][0], 'The first paragraph is untouched.' );
		$this->assertNotSame( $before[1][2][1][1], $after[1][2][1][1] );
		$this->assertNotSame( $before[1][1], $after[1][1], 'The Group changed with its child.' );
	}

	/**
	 * Identical blocks hash alike, wherever they are: matching tells them
	 * apart by position, not the hash.
	 *
	 * @return void
	 */
	public function test_duplicate_blocks_share_a_hash(): void {
		$spacer = '<!-- wp:spacer {"height":"40px"} --><div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->';
		$tree   = BlockTree::of( $spacer . "\n\n" . $spacer );

		$this->assertCount( 2, $tree );
		$this->assertSame( $tree[0][1], $tree[1][1] );
	}

	/**
	 * Content without block markup is one freeform node, and empty content no node.
	 *
	 * @return void
	 */
	public function test_classic_content_is_one_freeform_node(): void {
		$tree = BlockTree::of( "<p>Old\n post</p>" );

		$this->assertSame( BlockTree::FREEFORM, $tree[0][0] );
		$this->assertSame( [], BlockTree::of( '' ) );
	}

	/**
	 * The flat list holds every node, parents before their children, and the
	 * visitor sees each block with its hash.
	 *
	 * @return void
	 */
	public function test_hashes_and_visits_cover_every_block(): void {
		$seen = [];
		$tree = BlockTree::of(
			self::PAGE,
			static function ( string $hash, array $block ) use ( &$seen ): void {
				$seen[ $hash ] = $block['blockName'];
			}
		);

		$hashes = BlockTree::hashes( $tree );

		$this->assertSame( [ $tree[0][1], $tree[1][1], $tree[1][2][0][1], $tree[1][2][1][1] ], $hashes );
		$this->assertEqualsCanonicalizing( $hashes, array_keys( $seen ) );
		$this->assertSame( $tree[1][1], BlockTree::hash( parse_blocks( self::PAGE )[2] ) );
	}
}
