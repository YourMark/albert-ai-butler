<?php
/**
 * Tests for the BlockConverter utility class.
 *
 * @package Albert\Tests\Unit\Utilities
 */

namespace Albert\Tests\Unit\Utilities;

use Albert\Utilities\BlockConverter;
use PHPUnit\Framework\TestCase;

// Load WordPress function stubs for unit testing.
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';

/**
 * BlockConverter unit tests.
 *
 * Verifies HTML-to-Gutenberg-block conversion for every supported
 * element type, edge cases, and error conditions.
 *
 * Every test uses assertSame for exact output matching so that any
 * change in the converter's output is caught immediately.
 */
class BlockConverterTest extends TestCase {

	/**
	 * Clean up oEmbed test globals after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['albert_test_oembed_urls'], $GLOBALS['albert_test_oembed_data'] );
		parent::tearDown();
	}

	// =========================================================================
	// Empty / whitespace input
	// =========================================================================

	public function test_empty_string_returns_empty(): void {
		$this->assertSame( '', ( new BlockConverter( '' ) )->convert() );
	}

	public function test_whitespace_only_returns_empty(): void {
		$this->assertSame( '', ( new BlockConverter( "   \n\t  " ) )->convert() );
	}

	// =========================================================================
	// Paragraphs
	// =========================================================================

	public function test_simple_paragraph(): void {
		$this->assertSame(
			'<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->',
			( new BlockConverter( '<p>Hello world</p>' ) )->convert()
		);
	}

	public function test_paragraph_with_inline_formatting(): void {
		$this->assertSame(
			'<!-- wp:paragraph --><p>Hello <strong>bold</strong> and <em>italic</em> text</p><!-- /wp:paragraph -->',
			( new BlockConverter( '<p>Hello <strong>bold</strong> and <em>italic</em> text</p>' ) )->convert()
		);
	}

	public function test_paragraph_with_link(): void {
		$this->assertSame(
			'<!-- wp:paragraph --><p>Visit <a href="https://example.com">our site</a> today</p><!-- /wp:paragraph -->',
			( new BlockConverter( '<p>Visit <a href="https://example.com">our site</a> today</p>' ) )->convert()
		);
	}

	public function test_multiple_paragraphs(): void {
		$this->assertSame(
			"<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->\n\n<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->",
			( new BlockConverter( '<p>First</p><p>Second</p>' ) )->convert()
		);
	}

	// =========================================================================
	// Inline elements wrapped in paragraph
	// =========================================================================

	public function test_standalone_strong_wrapped_in_paragraph(): void {
		$this->assertSame(
			'<!-- wp:paragraph --><p><strong>Bold text</strong></p><!-- /wp:paragraph -->',
			( new BlockConverter( '<strong>Bold text</strong>' ) )->convert()
		);
	}

	public function test_standalone_em_wrapped_in_paragraph(): void {
		$this->assertSame(
			'<!-- wp:paragraph --><p><em>Italic text</em></p><!-- /wp:paragraph -->',
			( new BlockConverter( '<em>Italic text</em>' ) )->convert()
		);
	}

	public function test_standalone_code_wrapped_in_paragraph(): void {
		$this->assertSame(
			'<!-- wp:paragraph --><p><code>var x = 1;</code></p><!-- /wp:paragraph -->',
			( new BlockConverter( '<code>var x = 1;</code>' ) )->convert()
		);
	}

	public function test_standalone_anchor_wrapped_in_paragraph(): void {
		$this->assertSame(
			'<!-- wp:paragraph --><p><a href="https://example.com">Link</a></p><!-- /wp:paragraph -->',
			( new BlockConverter( '<a href="https://example.com">Link</a>' ) )->convert()
		);
	}

	// =========================================================================
	// Headings
	// =========================================================================

	/**
	 * Test all heading levels h1-h6 produce correct block markup.
	 *
	 * @dataProvider heading_provider
	 *
	 * @param string $html     Input HTML.
	 * @param string $expected Expected block output.
	 */
	public function test_heading_levels( string $html, string $expected ): void {
		$this->assertSame( $expected, ( new BlockConverter( $html ) )->convert() );
	}

	public static function heading_provider(): array {
		return [
			'h1' => [
				'<h1>Main Title</h1>',
				'<!-- wp:heading {"level":1} --><h1>Main Title</h1><!-- /wp:heading -->',
			],
			'h2' => [
				'<h2>Section Title</h2>',
				'<!-- wp:heading {"level":2} --><h2>Section Title</h2><!-- /wp:heading -->',
			],
			'h3' => [
				'<h3>Subsection</h3>',
				'<!-- wp:heading {"level":3} --><h3>Subsection</h3><!-- /wp:heading -->',
			],
			'h4' => [
				'<h4>Minor Heading</h4>',
				'<!-- wp:heading {"level":4} --><h4>Minor Heading</h4><!-- /wp:heading -->',
			],
			'h5' => [
				'<h5>Small Heading</h5>',
				'<!-- wp:heading {"level":5} --><h5>Small Heading</h5><!-- /wp:heading -->',
			],
			'h6' => [
				'<h6>Tiny Heading</h6>',
				'<!-- wp:heading {"level":6} --><h6>Tiny Heading</h6><!-- /wp:heading -->',
			],
		];
	}

	public function test_heading_with_inline_formatting(): void {
		$this->assertSame(
			'<!-- wp:heading {"level":2} --><h2>Hello <em>world</em></h2><!-- /wp:heading -->',
			( new BlockConverter( '<h2>Hello <em>world</em></h2>' ) )->convert()
		);
	}

	// =========================================================================
	// Lists
	// =========================================================================

	public function test_unordered_list(): void {
		$this->assertSame(
			'<!-- wp:list --><ul><li>Alpha</li><li>Beta</li><li>Gamma</li></ul><!-- /wp:list -->',
			( new BlockConverter( '<ul><li>Alpha</li><li>Beta</li><li>Gamma</li></ul>' ) )->convert()
		);
	}

	public function test_ordered_list(): void {
		$this->assertSame(
			'<!-- wp:list {"ordered":true} --><ol><li>First</li><li>Second</li><li>Third</li></ol><!-- /wp:list -->',
			( new BlockConverter( '<ol><li>First</li><li>Second</li><li>Third</li></ol>' ) )->convert()
		);
	}

	// =========================================================================
	// Images
	// =========================================================================

	public function test_standalone_image(): void {
		$this->assertSame(
			'<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/photo.jpg" alt="A photo"></figure><!-- /wp:image -->',
			( new BlockConverter( '<img src="https://example.com/photo.jpg" alt="A photo">' ) )->convert()
		);
	}

	public function test_figure_with_image(): void {
		$this->assertSame(
			'<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/photo.jpg" alt="A photo"></figure><!-- /wp:image -->',
			( new BlockConverter( '<figure><img src="https://example.com/photo.jpg" alt="A photo"></figure>' ) )->convert()
		);
	}

	public function test_figure_with_image_and_caption(): void {
		$this->assertSame(
			'<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/photo.jpg" alt="A photo"><figcaption>My caption</figcaption></figure><!-- /wp:image -->',
			( new BlockConverter( '<figure><img src="https://example.com/photo.jpg" alt="A photo"><figcaption>My caption</figcaption></figure>' ) )->convert()
		);
	}

	public function test_figure_without_image_falls_back_to_html_block(): void {
		$result = ( new BlockConverter( '<figure><video src="movie.mp4"></video></figure>' ) )->convert();

		$this->assertStringContainsString( '<!-- wp:html -->', $result );
		$this->assertStringNotContainsString( '<!-- wp:image -->', $result );
	}

	// =========================================================================
	// Blockquote
	// =========================================================================

	public function test_blockquote_with_paragraph(): void {
		$this->assertSame(
			'<!-- wp:quote --><blockquote><!-- wp:paragraph --><p>To be or not to be</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->',
			( new BlockConverter( '<blockquote><p>To be or not to be</p></blockquote>' ) )->convert()
		);
	}

	public function test_blockquote_with_citation(): void {
		$this->assertSame(
			'<!-- wp:quote --><blockquote><!-- wp:paragraph --><p>The only thing we have to fear is fear itself</p><!-- /wp:paragraph --><cite>FDR</cite></blockquote><!-- /wp:quote -->',
			( new BlockConverter( '<blockquote><p>The only thing we have to fear is fear itself</p><cite>FDR</cite></blockquote>' ) )->convert()
		);
	}

	// =========================================================================
	// Separator
	// =========================================================================

	public function test_horizontal_rule(): void {
		$this->assertSame(
			'<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->',
			( new BlockConverter( '<hr>' ) )->convert()
		);
	}

	// =========================================================================
	// Skipped elements → empty output
	// =========================================================================

	public function test_br_is_skipped(): void {
		$this->assertSame( '', ( new BlockConverter( '<br>' ) )->convert() );
	}

	public function test_cite_at_top_level_is_skipped(): void {
		$this->assertSame( '', ( new BlockConverter( '<cite>Author</cite>' ) )->convert() );
	}

	public function test_source_is_skipped(): void {
		$this->assertSame( '', ( new BlockConverter( '<source src="video.mp4">' ) )->convert() );
	}

	// =========================================================================
	// Fallback → HTML block
	// =========================================================================

	public function test_div_becomes_html_block(): void {
		$this->assertSame(
			'<!-- wp:html --><div>Custom content</div><!-- /wp:html -->',
			( new BlockConverter( '<div>Custom content</div>' ) )->convert()
		);
	}

	public function test_table_becomes_html_block(): void {
		$this->assertSame(
			'<!-- wp:html --><table><tr><td>Cell 1</td><td>Cell 2</td></tr></table><!-- /wp:html -->',
			( new BlockConverter( '<table><tr><td>Cell 1</td><td>Cell 2</td></tr></table>' ) )->convert()
		);
	}

	public function test_pre_becomes_html_block(): void {
		$this->assertSame(
			'<!-- wp:html --><pre>code here</pre><!-- /wp:html -->',
			( new BlockConverter( '<pre>code here</pre>' ) )->convert()
		);
	}

	// =========================================================================
	// Empty blocks are stripped
	// =========================================================================

	public function test_empty_paragraph_is_removed(): void {
		$this->assertSame( '', ( new BlockConverter( '<p></p>' ) )->convert() );
	}

	public function test_empty_heading_is_removed(): void {
		$this->assertSame( '', ( new BlockConverter( '<h2></h2>' ) )->convert() );
	}

	public function test_empty_div_is_removed(): void {
		$this->assertSame( '', ( new BlockConverter( '<div></div>' ) )->convert() );
	}

	public function test_nonempty_content_survives_adjacent_empty_blocks(): void {
		$result = ( new BlockConverter( '<p>Keep me</p><p></p><h2></h2>' ) )->convert();

		$this->assertStringContainsString( 'Keep me', $result );
		$this->assertSame( 1, substr_count( $result, '<!-- wp:paragraph -->' ) );
	}

	// =========================================================================
	// Mixed content & ordering
	// =========================================================================

	public function test_mixed_elements_exact_output(): void {
		$expected = '<!-- wp:heading {"level":2} --><h2>Title</h2><!-- /wp:heading -->'
			. "\n\n"
			. '<!-- wp:paragraph --><p>A paragraph.</p><!-- /wp:paragraph -->'
			. "\n\n"
			. '<!-- wp:list --><ul><li>Item one</li><li>Item two</li></ul><!-- /wp:list -->'
			. "\n\n"
			. '<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->';

		$this->assertSame(
			$expected,
			( new BlockConverter( '<h2>Title</h2><p>A paragraph.</p><ul><li>Item one</li><li>Item two</li></ul><hr>' ) )->convert()
		);
	}

	public function test_block_order_is_preserved(): void {
		$result = ( new BlockConverter( '<h1>First</h1><p>Second</p><h2>Third</h2>' ) )->convert();

		$pos_h1 = strpos( $result, 'First' );
		$pos_p  = strpos( $result, 'Second' );
		$pos_h2 = strpos( $result, 'Third' );

		$this->assertLessThan( $pos_p, $pos_h1, 'h1 should come before p' );
		$this->assertLessThan( $pos_h2, $pos_p, 'p should come before h2' );
	}

	public function test_blocks_separated_by_double_newlines(): void {
		$result = ( new BlockConverter( '<p>A</p><p>B</p>' ) )->convert();

		$this->assertStringContainsString( "<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->", $result );
	}

	// =========================================================================
	// Bare text nodes
	// =========================================================================

	public function test_bare_text_becomes_paragraph(): void {
		$this->assertSame(
			'<!-- wp:paragraph --><p>Just text</p><!-- /wp:paragraph -->',
			( new BlockConverter( 'Just text' ) )->convert()
		);
	}

	// =========================================================================
	// UTF-8 / entities
	// =========================================================================

	public function test_utf8_latin_preserved(): void {
		$this->assertSame(
			'<!-- wp:paragraph --><p>Héllo wörld</p><!-- /wp:paragraph -->',
			( new BlockConverter( '<p>Héllo wörld</p>' ) )->convert()
		);
	}

	public function test_utf8_cjk_preserved(): void {
		$this->assertSame(
			'<!-- wp:paragraph --><p>日本語テスト</p><!-- /wp:paragraph -->',
			( new BlockConverter( '<p>日本語テスト</p>' ) )->convert()
		);
	}

	public function test_html_entities_preserved(): void {
		$this->assertSame(
			'<!-- wp:paragraph --><p>Fish &amp; chips</p><!-- /wp:paragraph -->',
			( new BlockConverter( '<p>Fish &amp; chips</p>' ) )->convert()
		);
	}

	// =========================================================================
	// oEmbed / Embeds — exact output
	// =========================================================================

	public function test_instagram_embed_exact_output(): void {
		$url = 'https://www.instagram.com/p/ABC123/';

		$this->assertSame(
			'<!-- wp:embed {"url":"' . $url . '","type":"rich","providerNameSlug":"instagram","responsive":true} -->'
			. '<figure class="wp-block-embed is-type-rich is-provider-instagram wp-block-embed-instagram">'
			. '<div class="wp-block-embed__wrapper">' . "\n"
			. $url . "\n"
			. '</div></figure>'
			. '<!-- /wp:embed -->',
			( new BlockConverter( "<p>{$url}</p>" ) )->convert()
		);
	}

	public function test_facebook_embed_exact_output(): void {
		$url = 'https://www.facebook.com/user/posts/123';

		$this->assertSame(
			'<!-- wp:embed {"url":"' . $url . '","type":"rich","providerNameSlug":"embed-handler","responsive":true,"previewable":false} -->'
			. '<figure class="wp-block-embed is-type-rich is-provider-embed-handler wp-block-embed-embed-handler">'
			. '<div class="wp-block-embed__wrapper">' . "\n"
			. $url . "\n"
			. '</div></figure>'
			. '<!-- /wp:embed -->',
			( new BlockConverter( "<p>{$url}</p>" ) )->convert()
		);
	}

	public function test_youtube_embed_with_16_9_aspect_ratio(): void {
		$url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

		$GLOBALS['albert_test_oembed_urls'][ $url ] = '<iframe></iframe>';
		$GLOBALS['albert_test_oembed_data'][ $url ] = (object) [
			'type'          => 'video',
			'provider_name' => 'YouTube',
			'width'         => 1920,
			'height'        => 1080,
		];

		$this->assertSame(
			'<!-- wp:embed {"url":"' . $url . '","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->'
			. '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube 16-9">'
			. '<div class="wp-block-embed__wrapper">' . "\n"
			. $url . "\n"
			. '</div></figure>'
			. '<!-- /wp:embed -->',
			( new BlockConverter( "<p>{$url}</p>" ) )->convert()
		);
	}

	public function test_youtube_embed_with_4_3_aspect_ratio(): void {
		$url = 'https://www.youtube.com/watch?v=test4x3';

		$GLOBALS['albert_test_oembed_urls'][ $url ] = '<iframe></iframe>';
		$GLOBALS['albert_test_oembed_data'][ $url ] = (object) [
			'type'          => 'video',
			'provider_name' => 'YouTube',
			'width'         => 800,
			'height'        => 600,
		];

		$result = ( new BlockConverter( "<p>{$url}</p>" ) )->convert();

		$this->assertStringContainsString( 'wp-embed-aspect-4-3', $result );
		$this->assertStringContainsString( '"className":"wp-embed-aspect-4-3 wp-has-aspect-ratio"', $result );
	}

	public function test_x_com_url_normalised_to_twitter(): void {
		$twitter_url = 'https://twitter.com/user/status/123';

		$GLOBALS['albert_test_oembed_urls'][ $twitter_url ] = '<blockquote></blockquote>';
		$GLOBALS['albert_test_oembed_data'][ $twitter_url ] = (object) [
			'type'          => 'rich',
			'provider_name' => 'Twitter',
			'width'         => 550,
			'height'        => 0,
		];

		$result = ( new BlockConverter( '<p>https://x.com/user/status/123</p>' ) )->convert();

		// The x.com URL should be rewritten to twitter.com in the block output.
		$this->assertStringContainsString( '"url":"' . $twitter_url . '"', $result );
		$this->assertStringContainsString( '"providerNameSlug":"twitter"', $result );
		$this->assertStringNotContainsString( 'x.com', $result );
	}

	public function test_non_embeddable_url_stays_as_paragraph(): void {
		$this->assertSame(
			'<!-- wp:paragraph --><p>https://example.com/page</p><!-- /wp:paragraph -->',
			( new BlockConverter( '<p>https://example.com/page</p>' ) )->convert()
		);
	}

	public function test_paragraph_with_mixed_text_and_url_stays_paragraph(): void {
		$result = ( new BlockConverter( '<p>Check out https://youtube.com/watch?v=abc</p>' ) )->convert();

		$this->assertStringContainsString( '<!-- wp:paragraph -->', $result );
		$this->assertStringNotContainsString( '<!-- wp:embed', $result );
	}

	// =========================================================================
	// Malformed / edge-case HTML
	// =========================================================================

	public function test_unclosed_tags_are_handled(): void {
		$result = ( new BlockConverter( '<p>Unclosed paragraph' ) )->convert();

		$this->assertSame(
			'<!-- wp:paragraph --><p>Unclosed paragraph</p><!-- /wp:paragraph -->',
			$result
		);
	}

	public function test_nested_paragraphs_restructured_by_parser(): void {
		// Invalid nesting — DOMDocument splits into sibling paragraphs.
		$result = ( new BlockConverter( '<p>Outer <p>Inner</p></p>' ) )->convert();

		$this->assertStringContainsString( 'Outer', $result );
		$this->assertStringContainsString( 'Inner', $result );
		// Both should be paragraph blocks.
		$this->assertGreaterThanOrEqual( 2, substr_count( $result, '<!-- wp:paragraph -->' ) );
	}

	public function test_script_tag_does_not_become_paragraph(): void {
		$result = ( new BlockConverter( '<script>alert("xss")</script>' ) )->convert();

		$this->assertStringNotContainsString( '<!-- wp:paragraph -->', $result );
	}

	// =========================================================================
	// API
	// =========================================================================

	public function test_images_keep_their_source(): void {
		$result = ( new BlockConverter( '<img src="https://example.com/photo.jpg" alt="test">' ) )->convert();

		$this->assertStringContainsString( 'src="https://example.com/photo.jpg"', $result );
	}

	public function test_convert_is_idempotent(): void {
		$converter = new BlockConverter( '<p>Hello</p>' );

		$this->assertSame( $converter->convert(), $converter->convert() );
	}
}
