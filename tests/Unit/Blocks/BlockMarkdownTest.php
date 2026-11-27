<?php
/**
 * Tests for the BlockMarkdown renderer.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

use Albert\Blocks\BlockMarkdown;
use PHPUnit\Framework\TestCase;

// Load WordPress function stubs (parse_blocks, wp_strip_all_tags, etc.).
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';

/**
 * BlockMarkdown unit tests.
 *
 * Verifies the block-level and inline mappings, plus the unknown-block fallback.
 */
class BlockMarkdownTest extends TestCase {

	/**
	 * Renderer under test.
	 *
	 * @var BlockMarkdown
	 */
	private BlockMarkdown $markdown;

	/**
	 * Set up a fresh renderer for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->markdown = new BlockMarkdown();
	}

	public function test_empty_content_returns_empty_string(): void {
		$this->assertSame( '', $this->markdown->render( '' ) );
		$this->assertSame( '', $this->markdown->render( "  \n " ) );
	}

	public function test_paragraph_becomes_text(): void {
		$out = $this->markdown->render( '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->' );

		$this->assertSame( 'Hello world', $out );
	}

	public function test_heading_levels_map_to_hashes(): void {
		$h2 = $this->markdown->render( '<!-- wp:heading --><h2 class="wp-block-heading">Title</h2><!-- /wp:heading -->' );
		$this->assertSame( '## Title', $h2 );

		$h4 = $this->markdown->render( '<!-- wp:heading {"level":4} --><h4 class="wp-block-heading">Sub</h4><!-- /wp:heading -->' );
		$this->assertSame( '#### Sub', $h4 );
	}

	public function test_heading_out_of_range_level_defaults_to_two(): void {
		$out = $this->markdown->render( '<!-- wp:heading {"level":9} --><h2>Bad</h2><!-- /wp:heading -->' );
		$this->assertSame( '## Bad', $out );
	}

	public function test_unordered_list(): void {
		$markup = '<!-- wp:list --><ul class="wp-block-list">'
			. '<!-- wp:list-item --><li>One</li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Two</li><!-- /wp:list-item -->'
			. '</ul><!-- /wp:list -->';

		$out = $this->markdown->render( $markup );

		$this->assertSame( "- One\n- Two", $out );
	}

	public function test_ordered_list_uses_numbers(): void {
		$markup = '<!-- wp:list {"ordered":true} --><ol class="wp-block-list">'
			. '<!-- wp:list-item --><li>First</li><!-- /wp:list-item -->'
			. '<!-- wp:list-item --><li>Second</li><!-- /wp:list-item -->'
			. '</ol><!-- /wp:list -->';

		$out = $this->markdown->render( $markup );

		$this->assertSame( "1. First\n2. Second", $out );
	}

	public function test_quote_becomes_blockquote(): void {
		$markup = '<!-- wp:quote --><blockquote class="wp-block-quote">'
			. '<!-- wp:paragraph --><p>Be excellent.</p><!-- /wp:paragraph -->'
			. '</blockquote><!-- /wp:quote -->';

		$out = $this->markdown->render( $markup );

		$this->assertSame( '> Be excellent.', $out );
	}

	public function test_pullquote_becomes_blockquote(): void {
		$markup = '<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>Big idea</p></blockquote></figure><!-- /wp:pullquote -->';

		$out = $this->markdown->render( $markup );

		$this->assertSame( '> Big idea', $out );
	}

	public function test_quote_with_citation_attribute_renders_author_line(): void {
		$markup = '<!-- wp:quote {"citation":"Jane Doe"} --><blockquote class="wp-block-quote">'
			. '<!-- wp:paragraph --><p>Be excellent.</p><!-- /wp:paragraph -->'
			. '</blockquote><!-- /wp:quote -->';

		$out = $this->markdown->render( $markup );

		$this->assertSame( "> Be excellent.\n>\n> Jane Doe", $out );
	}

	public function test_pullquote_with_cite_tag_renders_author_line(): void {
		$markup = '<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote>'
			. '<p>Big idea</p><cite>Ada Lovelace</cite></blockquote></figure><!-- /wp:pullquote -->';

		$out = $this->markdown->render( $markup );

		// The citation is appended as a Markdown attribution line.
		$this->assertStringContainsString( "\n>\n> Ada Lovelace", $out );
		$this->assertStringStartsWith( '> Big idea', $out );
	}

	public function test_nested_list_is_indented(): void {
		$markup = '<!-- wp:list --><ul class="wp-block-list">'
			. '<!-- wp:list-item --><li>Parent'
			. '<!-- wp:list --><ul class="wp-block-list">'
			. '<!-- wp:list-item --><li>Child</li><!-- /wp:list-item -->'
			. '</ul><!-- /wp:list -->'
			. '</li><!-- /wp:list-item -->'
			. '</ul><!-- /wp:list -->';

		$out = $this->markdown->render( $markup );

		// The nested item is indented two spaces (render_list at depth > 0).
		$this->assertStringContainsString( '- Parent', $out );
		$this->assertStringContainsString( '  - Child', $out );
	}

	public function test_code_block_is_fenced(): void {
		$markup = '<!-- wp:code --><pre class="wp-block-code"><code>echo 1;</code></pre><!-- /wp:code -->';

		$out = $this->markdown->render( $markup );

		$this->assertSame( "```\necho 1;\n```", $out );
	}

	public function test_image_becomes_markdown_image(): void {
		$markup = '<!-- wp:image {"id":12,"url":"https://example.com/cat.jpg","alt":"A cat"} -->'
			. '<figure class="wp-block-image"><img src="https://example.com/cat.jpg" alt="A cat" class="wp-image-12"/></figure>'
			. '<!-- /wp:image -->';

		$out = $this->markdown->render( $markup );

		$this->assertSame( '![A cat](https://example.com/cat.jpg)', $out );
	}

	public function test_image_reads_url_and_alt_from_img_tag_when_attrs_missing(): void {
		$markup = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/x.png" alt="From tag"/></figure><!-- /wp:image -->';

		$out = $this->markdown->render( $markup );

		$this->assertSame( '![From tag](https://example.com/x.png)', $out );
	}

	public function test_separator_becomes_rule(): void {
		$out = $this->markdown->render( '<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->' );

		$this->assertSame( '---', $out );
	}

	public function test_group_renders_children_only(): void {
		$markup = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:heading --><h2>Inside</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';

		$out = $this->markdown->render( $markup );

		$this->assertSame( "## Inside\n\nBody", $out );
	}

	public function test_columns_render_children_only(): void {
		$markup = '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:paragraph --><p>Left</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:column -->'
			. '<!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:paragraph --><p>Right</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';

		$out = $this->markdown->render( $markup );

		$this->assertSame( "Left\n\nRight", $out );
	}

	public function test_unknown_block_falls_back_to_plaintext(): void {
		$markup = '<!-- wp:acme/widget --><div class="acme">Special <strong>thing</strong></div><!-- /wp:acme/widget -->';

		$out = $this->markdown->render( $markup );

		// No inner blocks → stripped plaintext fallback (inline conversion not applied).
		$this->assertSame( 'Special thing', $out );
	}

	public function test_blocks_separated_by_blank_line(): void {
		$markup = '<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->';

		$out = $this->markdown->render( $markup );

		$this->assertSame( "One\n\nTwo", $out );
	}

	// =====================================================================
	// Inline conversion.
	// =====================================================================

	public function test_inline_bold(): void {
		$out = $this->markdown->render( '<!-- wp:paragraph --><p>A <strong>bold</strong> and <b>strong</b> move</p><!-- /wp:paragraph -->' );

		$this->assertSame( 'A **bold** and **strong** move', $out );
	}

	public function test_inline_italic(): void {
		$out = $this->markdown->render( '<!-- wp:paragraph --><p>An <em>emphatic</em> and <i>slanted</i> note</p><!-- /wp:paragraph -->' );

		$this->assertSame( 'An _emphatic_ and _slanted_ note', $out );
	}

	public function test_inline_link(): void {
		$out = $this->markdown->render( '<!-- wp:paragraph --><p>Visit <a href="https://example.com">the site</a> now</p><!-- /wp:paragraph -->' );

		$this->assertSame( 'Visit [the site](https://example.com) now', $out );
	}

	public function test_inline_code(): void {
		$out = $this->markdown->render( '<!-- wp:paragraph --><p>Run <code>wp cli</code> here</p><!-- /wp:paragraph -->' );

		$this->assertSame( 'Run `wp cli` here', $out );
	}

	public function test_inline_combination_and_remaining_tags_stripped(): void {
		$markup = '<!-- wp:paragraph --><p>See <a href="https://x.test"><strong>bold link</strong></a> and <span class="x">plain</span></p><!-- /wp:paragraph -->';

		$out = $this->markdown->render( $markup );

		$this->assertSame( 'See [bold link](https://x.test) and plain', $out );
	}

	public function test_entities_are_decoded(): void {
		$out = $this->markdown->render( '<!-- wp:paragraph --><p>Tom &amp; Jerry &lt;3</p><!-- /wp:paragraph -->' );

		$this->assertSame( 'Tom & Jerry <3', $out );
	}
}
