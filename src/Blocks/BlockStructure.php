<?php
/**
 * Block Structure
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.6.0
 */

namespace Albert\Blocks;

use WP_HTML_Tag_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Tells whether a text edit to block markup left its blocks intact.
 *
 * A text edit may change words, links and other attribute values. It may not
 * add, remove, reorder or rename blocks, change their settings, or change the
 * tags, classes and styles those settings are written into: the editor would
 * then find the block's markup no longer matching its settings.
 *
 * @since 1.6.0
 */
class BlockStructure {

	/**
	 * Inline tags a text edit may add or remove inside a block, as rich text does.
	 *
	 * @var array<int, string>
	 */
	private const INLINE_TAGS = [ 'A', 'ABBR', 'B', 'BDI', 'BDO', 'BR', 'CITE', 'CODE', 'DATA', 'DEL', 'DFN', 'EM', 'I', 'IMG', 'INS', 'KBD', 'MARK', 'Q', 'S', 'SAMP', 'SMALL', 'SPAN', 'STRONG', 'SUB', 'SUP', 'TIME', 'U', 'VAR' ];

	/**
	 * Describe how the blocks of $after differ from those of $before.
	 *
	 * @param string $before Markup before the edit.
	 * @param string $after  Markup after the edit.
	 * @return string|null What changed and where, or null when the blocks are intact.
	 * @since 1.6.0
	 */
	public static function difference( string $before, string $after ): ?string {
		return self::compare( self::blocks( parse_blocks( $before ) ), self::blocks( parse_blocks( $after ) ), [] );
	}

	/**
	 * Compare two block lists, depth first.
	 *
	 * @param array<int, array<string, mixed>> $before Blocks before.
	 * @param array<int, array<string, mixed>> $after  Blocks after.
	 * @param array<int, int>                  $path   Path of the parent block.
	 * @return string|null
	 * @since 1.6.0
	 */
	private static function compare( array $before, array $after, array $path ): ?string {
		$where = $path === [] ? 'the top level' : 'the block at [' . implode( ',', $path ) . ']';

		if ( count( $before ) !== count( $after ) ) {
			return sprintf( 'it adds or removes a block inside %s', $where );
		}

		foreach ( $before as $index => $block ) {
			$other = $after[ $index ];
			$at    = '[' . implode( ',', array_merge( $path, [ $index ] ) ) . ']';
			$name  = (string) ( $block['blockName'] ?? 'classic content' );

			if ( $block['blockName'] !== $other['blockName'] ) {
				return sprintf( 'it turns %1$s at %2$s into %3$s', $name, $at, (string) ( $other['blockName'] ?? 'classic content' ) );
			}

			if ( $block['attrs'] !== $other['attrs'] ) {
				return sprintf( 'it changes the settings of %1$s at %2$s', $name, $at );
			}

			if ( self::signature( $block ) !== self::signature( $other ) ) {
				return sprintf( 'it changes an element, class or style of %1$s at %2$s, which must match the block\'s settings', $name, $at );
			}

			$inner = self::compare( self::blocks( $block['innerBlocks'] ), self::blocks( $other['innerBlocks'] ), array_merge( $path, [ $index ] ) );
			if ( $inner !== null ) {
				return $inner;
			}
		}

		return null;
	}

	/**
	 * The blocks of a parsed list, without the whitespace between them.
	 *
	 * @param array<int|string, array<string, mixed>> $parsed Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 * @since 1.6.0
	 */
	private static function blocks( array $parsed ): array {
		return array_values( array_filter( $parsed, static fn ( array $block ): bool => ! BlockReader::is_empty_separator( $block ) ) );
	}


	/**
	 * The parts of a block's own markup the editor checks against its settings:
	 * the wrapper, every element that is not inline text formatting, and every
	 * element carrying a class or style.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<int, string>
	 * @since 1.6.0
	 */
	private static function signature( array $block ): array {
		$processor = new WP_HTML_Tag_Processor( (string) ( $block['innerHTML'] ?? '' ) );
		$signature = [];

		while ( $processor->next_tag() ) {
			$tag   = (string) $processor->get_tag();
			$class = $processor->get_attribute( 'class' );
			$style = $processor->get_attribute( 'style' );

			if ( $signature === [] || ! in_array( $tag, self::INLINE_TAGS, true ) || is_string( $class ) || is_string( $style ) ) {
				$signature[] = $tag . '|' . ( is_string( $class ) ? $class : '' ) . '|' . ( is_string( $style ) ? $style : '' );
			}
		}

		return $signature;
	}
}
