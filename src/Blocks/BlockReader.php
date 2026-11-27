<?php
/**
 * Block Reader
 *
 * Parses raw WordPress block markup (post_content) into a compact, shaped
 * block tree that is consistent across every read ability and easy for an
 * AI assistant to reason about.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Reads post content into a structured block tree.
 *
 * Each node has the shape:
 *   [
 *     'name'        => string|null,             // e.g. 'core/paragraph' (null for classic/freeform content)
 *     'attributes'  => array<string, mixed>,    // block attributes
 *     'innerBlocks' => array<int, array>,       // recursive list of the same shape
 *     'plaintext'   => string,                  // human-readable text derived from innerHTML
 *     'path'        => array<int, int>,         // logical position over the separator-filtered tree
 *     'html'        => string,                  // saved markup; leaf blocks only, absent when empty
 *   ]
 *
 * The `path` is the address the granular block-edit abilities use: `[0]` is the
 * first top-level block, `[2]` the third, `[2,0]` the first child of the third,
 * and so on. It indexes the SAME separator-filtered tree the reader exposes, so
 * a path read here addresses exactly the block that {@see BlockTreeEditor} edits.
 *
 * @since 1.2.0
 */
class BlockReader {

	/**
	 * Parse post content into the shaped block tree.
	 *
	 * Empty freeform separators emitted by parse_blocks() (a null block name
	 * with only whitespace inner HTML) are skipped. Genuine classic content
	 * (a null block name with real text) is preserved with name === null.
	 *
	 * @param string $post_content Raw block markup.
	 * @return array<int, array<string, mixed>> Shaped block tree.
	 *
	 * @since 1.2.0
	 */
	public function read( string $post_content ): array {
		if ( trim( $post_content ) === '' ) {
			return [];
		}

		return $this->shape( parse_blocks( $post_content ) );
	}

	/**
	 * Shape an already-parsed block list into the shaped tree.
	 *
	 * Exposes the same shaping code path used by {@see read()} so a caller that
	 * has already run parse_blocks() (and possibly sliced the result, e.g. the
	 * ContentFormatter block window) can shape it without re-serialising and
	 * re-parsing. The empty-freeform-separator skip rule is applied identically.
	 *
	 * @param array<int|string, array<string, mixed>> $parsed Parsed blocks from parse_blocks().
	 * @return array<int, array<string, mixed>> Shaped block tree.
	 *
	 * @since 1.2.0
	 */
	public function shape( array $parsed ): array {
		return $this->shape_blocks( $parsed );
	}

	/**
	 * Map a list of parsed blocks into the shaped tree, skipping empty freeform.
	 *
	 * The key type is int|string to match the shape parse_blocks() declares in
	 * the WordPress stub; numeric string keys never actually occur at runtime.
	 *
	 * The logical index advances only for kept (non-separator) blocks, so the
	 * `path` it builds addresses the same separator-filtered tree the granular
	 * block-edit abilities navigate.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks from parse_blocks().
	 * @param array<int, int>                         $prefix Logical path prefix for this level.
	 * @return array<int, array<string, mixed>> Shaped block tree.
	 *
	 * @since 1.2.0
	 */
	private function shape_blocks( array $blocks, array $prefix = [] ): array {
		$shaped = [];
		$index  = 0;

		foreach ( $blocks as $block ) {
			// Skip the empty whitespace "freeform" separators parse_blocks emits
			// between real blocks. Keep genuine classic content (null name + text).
			if ( self::is_empty_separator( $block ) ) {
				continue;
			}

			$shaped[] = $this->shape_block( $block, array_merge( $prefix, [ $index ] ) );
			++$index;
		}

		return $shaped;
	}

	/**
	 * Whether a parsed block is an empty whitespace "freeform" separator.
	 *
	 * The parser emits these (a null block name with only whitespace inner HTML)
	 * between real blocks. They are skipped from the shaped tree, and the
	 * ContentFormatter block window applies the SAME rule so its block index lines
	 * up with the `blocks` representation — keeping the predicate here means the two
	 * cannot drift apart. Genuine classic content (null name + real text) is not a
	 * separator and is preserved.
	 *
	 * @param array<string, mixed> $block Parsed block from parse_blocks().
	 * @return bool True when the block is an empty freeform separator.
	 *
	 * @since 1.2.0
	 */
	public static function is_empty_separator( array $block ): bool {
		$name = $block['blockName'] ?? null;

		return $name === null && trim( (string) ( $block['innerHTML'] ?? '' ) ) === '';
	}

	/**
	 * Shape a single parsed block (recursing into inner blocks).
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param array<int, int>      $path  Logical path of this node over the separator-filtered tree.
	 * @return array<string, mixed> Shaped block node.
	 *
	 * @since 1.2.0
	 */
	private function shape_block( array $block, array $path = [] ): array {
		$inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] )
			? $block['innerBlocks']
			: [];

		$shaped = [
			'name'        => $block['blockName'] ?? null,
			'attributes'  => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [],
			'innerBlocks' => $this->shape_blocks( $inner, $path ),
			'plaintext'   => $this->plaintext( (string) ( $block['innerHTML'] ?? '' ) ),
			'path'        => $path,
		];

		// Text, links and image sources live in the saved HTML, not the comment
		// attributes, so a leaf without its markup cannot be written back intact.
		// A container's markup is its children's, which carry their own.
		$html = trim( (string) ( $block['innerHTML'] ?? '' ) );
		if ( $inner === [] && $html !== '' ) {
			$shaped['html'] = $html;
		}

		return $shaped;
	}

	/**
	 * Derive a trimmed plaintext rendering of a block's inner HTML.
	 *
	 * @param string $inner_html The block's inner HTML.
	 * @return string Plaintext.
	 *
	 * @since 1.2.0
	 */
	private function plaintext( string $inner_html ): string {
		return trim( wp_strip_all_tags( $inner_html ) );
	}

	/**
	 * Aggregate the plaintext of a shaped block tree into one string.
	 *
	 * Walks the tree depth-first, joining each block's plaintext (and its inner
	 * blocks' plaintext) with blank lines so the result reads as the post body.
	 *
	 * @param array<int, array<string, mixed>> $blocks Shaped block tree from {@see read()}.
	 * @return string Concatenated plaintext.
	 *
	 * @since 1.2.0
	 */
	public static function plaintext_of( array $blocks ): string {
		$parts = [];

		foreach ( $blocks as $block ) {
			$text = isset( $block['plaintext'] ) ? trim( (string) $block['plaintext'] ) : '';
			if ( $text !== '' ) {
				$parts[] = $text;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$inner = self::plaintext_of( $block['innerBlocks'] );
				if ( $inner !== '' ) {
					$parts[] = $inner;
				}
			}
		}

		return implode( "\n\n", $parts );
	}
}
