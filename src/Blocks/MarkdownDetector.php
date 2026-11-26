<?php
/**
 * Markdown detector for the `content` write field.
 *
 * @package Albert
 * @subpackage Blocks
 * @since      1.6.0
 */

namespace Albert\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Tells Markdown apart from plain text, so it is refused instead of saved as one paragraph.
 *
 * @since 1.6.0
 */
class MarkdownDetector {

	/**
	 * Whether the string reads as Markdown.
	 *
	 * Rule: no block delimiters, no HTML tags, and either a `#` heading line, a
	 * line opening with `**bold**`, or at least two list lines. One list line
	 * alone is too common in plain prose to count.
	 *
	 * @param string $content The `content` value as sent.
	 * @return bool
	 * @since 1.6.0
	 */
	public static function looks_like_markdown( string $content ): bool {
		if ( str_contains( $content, '<!-- wp:' ) || preg_match( '#</?[a-z][a-z0-9-]*(\s[^>]*)?/?>#i', $content ) ) {
			return false;
		}

		if ( preg_match( '/^ {0,3}#{1,6}[ \t]+\S/m', $content ) || preg_match( '/^[ \t]*\*\*[^*\n]+\*\*/m', $content ) ) {
			return true;
		}

		return preg_match_all( '/^[ \t]*(?:[-*+]|\d+[.)])[ \t]+\S/m', $content ) >= 2;
	}
}
