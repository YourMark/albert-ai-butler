<?php
/**
 * Block Markdown
 *
 * Renders raw WordPress block markup (post_content) into Markdown. This is a
 * read-side companion to BlockReader: where BlockReader produces a structured
 * tree and BlockReader::plaintext_of() flattens it to bare text, BlockMarkdown
 * produces a Markdown rendering that keeps headings, lists, links, emphasis and
 * code so an AI assistant can read the content the way an author would.
 *
 * It is intentionally dependency-free — no Composer packages, no HTML parser —
 * and relies on parse_blocks() plus a small, well-tested inline pass. It does
 * not attempt to handle every possible inline-HTML edge case; it covers the
 * common blocks and a small set of inline tags faithfully and falls back to
 * stripped plaintext for anything it does not recognise.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Converts block markup to Markdown.
 *
 * Block-level mapping:
 *   - core/paragraph                       → text
 *   - core/heading                         → '#'..'######' by attributes.level
 *   - core/list / core/list-item           → '- ' (or '1. ' when ordered)
 *   - core/quote / core/pullquote          → '> ' prefixed lines
 *   - core/code                            → fenced ``` block
 *   - core/image                           → '![alt](url)'
 *   - core/separator                       → '---'
 *   - core/group / columns / column /
 *     core/buttons                         → render children only
 *   - everything else                      → stripped plaintext fallback
 *
 * Inline mapping (applied to each block's innerHTML before stripping):
 *   - <strong> / <b>  → **bold**
 *   - <em> / <i>      → _italic_
 *   - <a href="…">    → [text](href)
 *   - inline <code>   → `code`
 *
 * Blocks are separated by a blank line.
 *
 * @since 1.2.0
 */
class BlockMarkdown {

	/**
	 * Render raw block markup to Markdown.
	 *
	 * @param string $post_content Raw block markup.
	 * @return string Markdown rendering (no trailing newline).
	 *
	 * @since 1.2.0
	 */
	public function render( string $post_content ): string {
		if ( trim( $post_content ) === '' ) {
			return '';
		}

		$blocks = parse_blocks( $post_content );

		return $this->render_blocks( $blocks );
	}

	/**
	 * Render a list of parsed blocks, joining each rendered block with a blank line.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @return string Markdown.
	 *
	 * @since 1.2.0
	 */
	private function render_blocks( array $blocks ): string {
		$parts = [];

		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;

			// Skip the empty whitespace "freeform" separators parse_blocks emits.
			if ( $name === null && trim( (string) ( $block['innerHTML'] ?? '' ) ) === '' ) {
				continue;
			}

			$rendered = $this->render_block( $block );

			if ( trim( $rendered ) !== '' ) {
				$parts[] = $rendered;
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Render a single parsed block to Markdown.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string Markdown.
	 *
	 * @since 1.2.0
	 */
	private function render_block( array $block ): string {
		$name         = $block['blockName'] ?? null;
		$attrs        = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
		$inner_html   = (string) ( $block['innerHTML'] ?? '' );
		$inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];

		switch ( $name ) {
			case 'core/heading':
				$level = isset( $attrs['level'] ) ? (int) $attrs['level'] : 2;
				if ( $level < 1 || $level > 6 ) {
					$level = 2;
				}
				return str_repeat( '#', $level ) . ' ' . $this->inline( $inner_html );

			case 'core/list':
				return $this->render_list( $block );

			case 'core/list-item':
				// A list-item rendered on its own (defensive) — emit one bullet.
				return '- ' . $this->inline( $inner_html );

			case 'core/quote':
			case 'core/pullquote':
				return $this->render_quote( $block, $attrs, $inner_html, $inner_blocks );

			case 'core/code':
			case 'core/preformatted':
				return $this->render_code( $inner_html );

			case 'core/image':
				return $this->render_image( $attrs, $inner_html );

			case 'core/separator':
				return '---';

			case 'core/group':
			case 'core/columns':
			case 'core/column':
			case 'core/buttons':
				// Structural wrappers — render their children only.
				return $this->render_blocks( $inner_blocks );

			case 'core/paragraph':
				return $this->inline( $inner_html );

			default:
				// Unknown / unmapped block: render children if any, else fall
				// back to the block's own stripped plaintext.
				if ( $inner_blocks !== [] ) {
					$children = $this->render_blocks( $inner_blocks );
					if ( trim( $children ) !== '' ) {
						return $children;
					}
				}
				return $this->plaintext( $inner_html );
		}
	}

	/**
	 * Render a core/list block (and its list-item children) to Markdown.
	 *
	 * Ordered lists use '1.' markers; unordered lists use '-'. Nested lists are
	 * indented by two spaces per level.
	 *
	 * @param array<string, mixed> $block  The list block.
	 * @param int                  $depth  Current nesting depth (0 = top level).
	 * @return string Markdown list.
	 *
	 * @since 1.2.0
	 */
	private function render_list( array $block, int $depth = 0 ): string {
		$attrs   = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
		$ordered = ! empty( $attrs['ordered'] );
		$items   = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];

		$lines  = [];
		$number = 1;
		$indent = str_repeat( '  ', $depth );

		foreach ( $items as $item ) {
			if ( ( $item['blockName'] ?? null ) !== 'core/list-item' ) {
				continue;
			}

			$marker = $ordered ? ( $number . '. ' ) : '- ';
			$text   = $this->inline( (string) ( $item['innerHTML'] ?? '' ) );

			$lines[] = $indent . $marker . $text;
			++$number;

			// A list-item may contain a nested core/list.
			$nested = isset( $item['innerBlocks'] ) && is_array( $item['innerBlocks'] ) ? $item['innerBlocks'] : [];
			foreach ( $nested as $child ) {
				if ( ( $child['blockName'] ?? null ) === 'core/list' ) {
					$lines[] = $this->render_list( $child, $depth + 1 );
				}
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Render a quote/pullquote block to a Markdown blockquote.
	 *
	 * @param array<string, mixed>             $block        The quote block.
	 * @param array<string, mixed>             $attrs        Block attributes.
	 * @param string                           $inner_html   The block's inner HTML.
	 * @param array<int, array<string, mixed>> $inner_blocks Inner blocks (paragraphs).
	 * @return string Markdown blockquote.
	 *
	 * @since 1.2.0
	 */
	private function render_quote( array $block, array $attrs, string $inner_html, array $inner_blocks ): string {
		// Quotes wrap inner paragraph blocks; pullquotes store text inline.
		if ( $inner_blocks !== [] ) {
			$body = $this->render_blocks( $inner_blocks );
		} else {
			$body = $this->inline( $inner_html );
		}

		$lines = [];
		foreach ( explode( "\n", $body ) as $line ) {
			$lines[] = trim( $line ) === '' ? '>' : '> ' . $line;
		}

		$citation = isset( $attrs['citation'] ) ? trim( (string) $attrs['citation'] ) : '';
		if ( $citation === '' ) {
			// Pullquote/quote can also carry the citation in a <cite> tag.
			$citation = $this->cite_from_html( $inner_html );
		}
		if ( $citation !== '' ) {
			$lines[] = '>';
			$lines[] = '> ' . $citation;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Render a code/preformatted block as a fenced Markdown code block.
	 *
	 * @param string $inner_html The block's inner HTML.
	 * @return string Fenced code block.
	 *
	 * @since 1.2.0
	 */
	private function render_code( string $inner_html ): string {
		$code = $this->plaintext( $inner_html );

		return "```\n" . $code . "\n```";
	}

	/**
	 * Render an image block as Markdown image syntax.
	 *
	 * The url/alt come from attributes when present, otherwise they are read
	 * from the inner <img> tag.
	 *
	 * @param array<string, mixed> $attrs      Block attributes.
	 * @param string               $inner_html The block's inner HTML.
	 * @return string Markdown image (with optional caption line).
	 *
	 * @since 1.2.0
	 */
	private function render_image( array $attrs, string $inner_html ): string {
		$url = isset( $attrs['url'] ) ? (string) $attrs['url'] : '';
		$alt = isset( $attrs['alt'] ) ? (string) $attrs['alt'] : '';

		if ( $url === '' && preg_match( '/<img[^>]*\bsrc="([^"]*)"/i', $inner_html, $m ) ) {
			$url = $m[1];
		}
		if ( $alt === '' && preg_match( '/<img[^>]*\balt="([^"]*)"/i', $inner_html, $m ) ) {
			$alt = $m[1];
		}

		$markdown = '![' . $alt . '](' . $url . ')';

		$caption = isset( $attrs['caption'] ) ? trim( (string) $attrs['caption'] ) : '';
		if ( $caption === '' && preg_match( '/<figcaption[^>]*>(.*?)<\/figcaption>/is', $inner_html, $m ) ) {
			$caption = $this->plaintext( $m[1] );
		}
		if ( $caption !== '' ) {
			$markdown .= "\n\n" . $this->inline( $caption );
		}

		return $markdown;
	}

	/**
	 * Extract a citation from a trailing <cite> tag in the HTML.
	 *
	 * @param string $html Inner HTML.
	 * @return string Citation text, or an empty string.
	 *
	 * @since 1.2.0
	 */
	private function cite_from_html( string $html ): string {
		if ( preg_match( '/<cite[^>]*>(.*?)<\/cite>/is', $html, $m ) ) {
			return $this->plaintext( $m[1] );
		}

		return '';
	}

	/**
	 * Convert a small set of inline HTML tags in a fragment to Markdown, then
	 * strip any remaining tags and decode entities.
	 *
	 * Block-level boundaries (</p>, <br>, </li>, </h1-6>) collapse to a single
	 * space so multi-paragraph innerHTML does not run words together.
	 *
	 * @param string $html Block inner HTML.
	 * @return string Inline Markdown text.
	 *
	 * @since 1.2.0
	 */
	private function inline( string $html ): string {
		$text = $html;

		// Links: capture href and inner text. Run before generic tag stripping.
		$text = preg_replace_callback(
			'/<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)<\/a>/is',
			static function ( array $m ): string {
				$label = trim( wp_strip_all_tags( $m[2] ) );
				$href  = $m[1];
				if ( $label === '' ) {
					return $href;
				}
				return '[' . $label . '](' . $href . ')';
			},
			$text
		) ?? $text;

		// Inline code.
		$text = preg_replace( '/<code\b[^>]*>(.*?)<\/code>/is', '`$1`', $text ) ?? $text;

		// Bold.
		$text = preg_replace( '/<(strong|b)\b[^>]*>(.*?)<\/\1>/is', '**$2**', $text ) ?? $text;

		// Italic.
		$text = preg_replace( '/<(em|i)\b[^>]*>(.*?)<\/\1>/is', '_$2_', $text ) ?? $text;

		// Collapse block boundaries to spaces so words don't fuse together.
		$text = preg_replace( '/<\/(p|li|h[1-6]|blockquote|figcaption)>/i', ' ', $text ) ?? $text;
		$text = preg_replace( '/<br\s*\/?>/i', ' ', $text ) ?? $text;

		// Strip any remaining tags.
		$text = wp_strip_all_tags( $text );

		// Decode entities the inner HTML carried (e.g. &amp;, &lt;).
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Collapse runs of whitespace introduced by boundary replacement.
		$text = preg_replace( '/[ \t]+/', ' ', $text ) ?? $text;

		return trim( $text );
	}

	/**
	 * Strip all tags and decode entities to bare text (the unknown-block fallback).
	 *
	 * @param string $html Inner HTML.
	 * @return string Plain text.
	 *
	 * @since 1.2.0
	 */
	private function plaintext( string $html ): string {
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( $text );
	}
}
