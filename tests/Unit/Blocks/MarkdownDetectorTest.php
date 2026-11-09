<?php
/**
 * Unit tests for the MarkdownDetector.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

use Albert\Blocks\MarkdownDetector;
use PHPUnit\Framework\TestCase;

/**
 * MarkdownDetector unit tests.
 */
class MarkdownDetectorTest extends TestCase {

	/**
	 * Strings that read as Markdown.
	 *
	 * @return array<string, array{string}>
	 */
	public static function markdown_provider(): array {
		return [
			'heading'            => [ "## Intro\n\nSome text." ],
			'heading level one'  => [ '# Title' ],
			'bold line start'    => [ "**Note:** read this.\n\nMore text." ],
			'two dash list'      => [ "Shopping:\n- eggs\n- milk" ],
			'two star list'      => [ "* one\n* two" ],
			'two numbered lines' => [ "1. First\n2. Second" ],
		];
	}

	/**
	 * Strings that must not be refused.
	 *
	 * @return array<string, array{string}>
	 */
	public static function not_markdown_provider(): array {
		return [
			'plain prose'          => [ 'Just a sentence about **nothing** in particular.' ],
			'one dash line'        => [ "We met up.\n- Mark said hello.\nThen we left." ],
			'hashtag'              => [ '#WordPress is fun' ],
			'html'                 => [ "<h2>Intro</h2>\n<p>## not a heading</p>" ],
			'block markup'         => [ "<!-- wp:paragraph -->\n## text\n<!-- /wp:paragraph -->" ],
			'html with list lines' => [ "<ul>\n- a\n- b\n</ul>" ],
			'empty'                => [ '' ],
		];
	}

	/**
	 * Markdown is detected.
	 *
	 * @dataProvider markdown_provider
	 *
	 * @param string $content Input.
	 * @return void
	 */
	public function test_detects_markdown( string $content ): void {
		$this->assertTrue( MarkdownDetector::looks_like_markdown( $content ) );
	}

	/**
	 * Plain text, HTML and block markup pass.
	 *
	 * @dataProvider not_markdown_provider
	 *
	 * @param string $content Input.
	 * @return void
	 */
	public function test_ignores_non_markdown( string $content ): void {
		$this->assertFalse( MarkdownDetector::looks_like_markdown( $content ) );
	}
}
