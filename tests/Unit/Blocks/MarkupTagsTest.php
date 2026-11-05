<?php
/**
 * Unit tests for MarkupTags.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';

use Albert\Blocks\MarkupTags;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * MarkupTags unit tests.
 */
class MarkupTagsTest extends TestCase {

	/**
	 * A paragraph holding a ThirstyAffiliates link.
	 *
	 * @var string
	 */
	private const TA_PARAGRAPH = '<!-- wp:paragraph --><p>Buy <ta href="https://example.com" linkid="1">here</ta>.</p><!-- /wp:paragraph -->';

	/**
	 * Names are lowercase, distinct and sorted; comments and closing tags are ignored.
	 *
	 * @return void
	 */
	public function test_names_lists_distinct_element_names(): void {
		$names = MarkupTags::names( '<!-- wp:paragraph {"a":1} --><P>A <TA linkid="1">b</TA> <ta>c</ta><br/></P><!-- /wp:paragraph -->' );

		$this->assertSame( [ 'br', 'p', 'ta' ], $names );
	}

	/**
	 * Text that only looks like a comparison is not a tag.
	 *
	 * @return void
	 */
	public function test_names_ignores_a_less_than_sign_in_text(): void {
		$this->assertSame( [], MarkupTags::names( 'a < b and 3<4' ) );
	}

	/**
	 * Dropping a tag the content had is refused, naming it.
	 *
	 * @return void
	 */
	public function test_dropping_a_tag_is_refused(): void {
		$error = MarkupTags::refuse_unconfirmed_drop( self::TA_PARAGRAPH, '<!-- wp:paragraph --><p>Buy here.</p><!-- /wp:paragraph -->', [] );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'tags_removed', $error->get_error_code() );
		$this->assertSame( [ 'ta' ], $error->get_error_data()['tags'] );
		$this->assertStringContainsString( '`<ta>`', $error->get_error_message() );
		$this->assertStringContainsString( 'remove_tags: ["ta"]', $error->get_error_message() );
	}

	/**
	 * `remove_tags` confirms the drop, in either spelling.
	 *
	 * @return void
	 */
	public function test_remove_tags_confirms_the_drop(): void {
		$after = '<!-- wp:paragraph --><p>Buy here.</p><!-- /wp:paragraph -->';

		$this->assertNull( MarkupTags::refuse_unconfirmed_drop( self::TA_PARAGRAPH, $after, [ 'remove_tags' => [ 'ta' ] ] ) );
		$this->assertNull( MarkupTags::refuse_unconfirmed_drop( self::TA_PARAGRAPH, $after, [ 'remove_tags' => [ '<TA>' ] ] ) );
	}

	/**
	 * Changing only attributes, or keeping one of several instances, drops nothing.
	 *
	 * @return void
	 */
	public function test_attribute_changes_drop_nothing(): void {
		$after = '<!-- wp:paragraph --><p>Buy <ta href="https://example.com/new" linkid="2">now</ta>.</p><!-- /wp:paragraph -->';

		$this->assertNull( MarkupTags::refuse_unconfirmed_drop( self::TA_PARAGRAPH, $after, [] ) );
	}

	/**
	 * A block's own wrapper changes with its type, so it does not count.
	 *
	 * @return void
	 */
	public function test_changing_the_block_type_does_not_count_the_wrapper(): void {
		$after = '<!-- wp:heading --><h2 class="wp-block-heading">Buy <ta href="https://example.com" linkid="1">here</ta>.</h2><!-- /wp:heading -->';

		$this->assertNull( MarkupTags::refuse_unconfirmed_drop( self::TA_PARAGRAPH, $after, [] ) );
	}

	/**
	 * In a core/html block every tag is content.
	 *
	 * @return void
	 */
	public function test_custom_html_counts_its_first_tag(): void {
		$before = '<!-- wp:html --><iframe src="https://example.com"></iframe><!-- /wp:html -->';
		$after  = '<!-- wp:html -->Gone<!-- /wp:html -->';

		$error = MarkupTags::refuse_unconfirmed_drop( $before, $after, [] );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( [ 'iframe' ], $error->get_error_data()['tags'] );
	}

	/**
	 * A user with unfiltered_html is never checked against KSES.
	 *
	 * @return void
	 */
	public function test_kses_check_skips_a_user_with_unfiltered_html(): void {
		$GLOBALS['albert_test_caps'] = [ 'unfiltered_html' ];

		$this->assertNull( MarkupTags::refuse_kses_loss( self::TA_PARAGRAPH ) );

		unset( $GLOBALS['albert_test_caps'] );
	}

}
