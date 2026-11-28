<?php
/**
 * Integration tests for block hashing against WordPress's registered block types.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Undo;

use Albert\Tests\TestCase;
use Albert\Undo\BlockTree;

/**
 * BlockTree tests with the real block type registry.
 *
 * @covers \Albert\Undo\BlockTree
 */
class BlockTreeTest extends TestCase {

	/**
	 * A heading's default level, written or left out, hashes the same.
	 *
	 * @return void
	 */
	public function test_a_default_attribute_does_not_change_the_hash(): void {
		$written = '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Plans</h2><!-- /wp:heading -->';
		$omitted = '<!-- wp:heading --><h2 class="wp-block-heading">Plans</h2><!-- /wp:heading -->';
		$level_3 = '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Plans</h3><!-- /wp:heading -->';

		$this->assertSame( BlockTree::of( $written ), BlockTree::of( $omitted ) );
		$this->assertNotSame( BlockTree::of( $written ), BlockTree::of( $level_3 ) );
	}

	/**
	 * An attribute read from the HTML, stored in the comment or not, hashes the same.
	 *
	 * @return void
	 */
	public function test_a_sourced_attribute_does_not_change_the_hash(): void {
		$in_comment = '<!-- wp:paragraph {"content":"Hello"} --><p>Hello</p><!-- /wp:paragraph -->';
		$in_html    = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';

		$this->assertSame( BlockTree::of( $in_comment ), BlockTree::of( $in_html ) );
		$this->assertNotSame( BlockTree::of( $in_html ), BlockTree::of( '<!-- wp:paragraph --><p>Bye</p><!-- /wp:paragraph -->' ), 'The HTML still counts.' );
	}

	/**
	 * An unregistered block type keeps every attribute.
	 *
	 * @return void
	 */
	public function test_an_unregistered_type_keeps_every_attribute(): void {
		$with    = '<!-- wp:acme/box {"level":2} --><div>Box</div><!-- /wp:acme/box -->';
		$without = '<!-- wp:acme/box --><div>Box</div><!-- /wp:acme/box -->';

		$this->assertNotSame( BlockTree::of( $with ), BlockTree::of( $without ) );
	}
}
