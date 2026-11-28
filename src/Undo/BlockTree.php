<?php
/**
 * Per-block hashes of post content.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.7.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

/**
 * Post content as a tree of `[ name, hash, children? ]`, so a revert can tell
 * which blocks an assistant left and which a person changed since.
 *
 * Blocks have no stable id, so a block is known by its content. The hash is
 * taken over the parsed block, not its markup: attributes sorted at every
 * depth, whitespace runs in the HTML collapsed, and whitespace next to an
 * inner block dropped, so the editor re-saving a block it did not change
 * (it puts each inner block on its own line) keeps its hash. For a registered
 * block type, attributes the editor leaves out of the comment are left out
 * too: those read from the HTML (`source`), which the HTML already carries,
 * and those equal to the type's default. A child's hash is part of its
 * parent's, which is why every level is stored: a flat list could not say
 * that only an inner paragraph of a Group changed.
 *
 * @since 1.7.0
 */
final class BlockTree {

	/**
	 * Hex characters of sha256 kept per block: collisions among one post's
	 * blocks are what matters, and 64 bits is plenty for that.
	 *
	 * @var int
	 */
	public const HASH_LENGTH = 16;

	/**
	 * Name stored for classic content between or without blocks.
	 *
	 * @var string
	 */
	public const FREEFORM = 'core/freeform';

	/**
	 * The tree of a post's content.
	 *
	 * @param string        $content Post content.
	 * @param callable|null $visit   Called as `( string $hash, array $block )` for every block, parents after their children.
	 *
	 * @return array<int, array{0: string, 1: string, 2?: array<int, mixed>}>
	 * @since 1.7.0
	 */
	public static function of( string $content, ?callable $visit = null ): array {
		return self::from_blocks( parse_blocks( $content ), $visit );
	}

	/**
	 * The tree of already parsed blocks.
	 *
	 * @param array<int|string, mixed> $blocks Blocks as `parse_blocks()` returns them; anything not a block is skipped.
	 * @param callable|null            $visit  See {@see self::of()}.
	 *
	 * @return array<int, array{0: string, 1: string, 2?: array<int, mixed>}>
	 * @since 1.7.0
	 */
	public static function from_blocks( array $blocks, ?callable $visit = null ): array {
		$tree = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || self::is_separator( $block ) ) {
				continue;
			}

			$children = self::from_blocks( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [], $visit );
			$name     = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : self::FREEFORM;
			$hash     = self::digest( $name, $block, array_column( $children, 1 ) );

			if ( $visit !== null ) {
				$visit( $hash, $block );
			}

			$tree[] = $children === [] ? [ $name, $hash ] : [ $name, $hash, $children ];
		}

		return $tree;
	}

	/**
	 * One block's hash, its inner blocks included.
	 *
	 * @param array<string, mixed> $block A block as `parse_blocks()` returns it.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public static function hash( array $block ): string {
		$tree = self::from_blocks( [ $block ] );

		return $tree === [] ? '' : $tree[0][1];
	}

	/**
	 * Every hash in a tree, in document order, parents before their children.
	 *
	 * @param array<int, array<int, mixed>> $tree A tree from {@see self::of()}.
	 *
	 * @return array<int, string>
	 * @since 1.7.0
	 */
	public static function hashes( array $tree ): array {
		$hashes = [];

		foreach ( $tree as $node ) {
			$hashes[] = (string) $node[1];
			if ( isset( $node[2] ) && is_array( $node[2] ) ) {
				array_push( $hashes, ...self::hashes( $node[2] ) );
			}
		}

		return $hashes;
	}

	/**
	 * HTML with whitespace runs collapsed and trimmed.
	 *
	 * @param string $html The HTML.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public static function normalise_html( string $html ): string {
		$collapsed = preg_replace( '/\s+/u', ' ', $html );

		// Invalid UTF-8 defeats the /u modifier; fall back to bytes.
		return trim( $collapsed ?? (string) preg_replace( '/\s+/', ' ', $html ) );
	}

	/**
	 * Whether a block is the whitespace parse_blocks() emits between blocks.
	 *
	 * @param array<string, mixed> $block The block.
	 *
	 * @return bool
	 */
	private static function is_separator( array $block ): bool {
		return ! is_string( $block['blockName'] ?? null ) && self::normalise_html( (string) ( $block['innerHTML'] ?? '' ) ) === '';
	}

	/**
	 * Hash a block from its normalised parts.
	 *
	 * @param string               $name     Block name.
	 * @param array<string, mixed> $block    The block.
	 * @param array<int, string>   $children Its children's hashes, in order.
	 *
	 * @return string
	 */
	private static function digest( string $name, array $block, array $children ): string {
		$attrs = is_array( $block['attrs'] ?? null ) ? self::sorted( self::significant( $name, $block['attrs'] ) ) : [];
		$json  = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Hash input only, for attributes JSON cannot encode.
		$attrs = $json === false ? serialize( $attrs ) : $json;

		$input = implode( "\0", [ $name, $attrs, self::own_html( $block ), implode( ',', $children ) ] );

		return substr( hash( 'sha256', $input ), 0, self::HASH_LENGTH );
	}

	/**
	 * The attributes the editor would keep in the block comment.
	 *
	 * Measured on real pages: without this the editor's re-save changed the
	 * hash of 3 in 10 blocks, mostly a heading's default `level` and a
	 * `content` attribute older output stored in the comment.
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Its attributes as parsed.
	 *
	 * @return array<string, mixed>
	 */
	private static function significant( string $name, array $attrs ): array {
		$type = class_exists( \WP_Block_Type_Registry::class ) ? \WP_Block_Type_Registry::get_instance()->get_registered( $name ) : null;

		// An unregistered type: nothing says which attributes are derived, so all count.
		if ( ! is_object( $type ) || ! is_array( $type->attributes ?? null ) ) {
			return $attrs;
		}

		foreach ( $attrs as $key => $value ) {
			$definition = $type->attributes[ $key ] ?? null;
			if ( ! is_array( $definition ) ) {
				continue;
			}

			if ( isset( $definition['source'] ) || ( array_key_exists( 'default', $definition ) && $definition['default'] === $value ) ) {
				unset( $attrs[ $key ] );
			}
		}

		return $attrs;
	}

	/**
	 * A block's own HTML, normalised, with a marker where each inner block sits.
	 *
	 * @param array<string, mixed> $block The block.
	 *
	 * @return string
	 */
	private static function own_html( array $block ): string {
		$chunks = is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : [];

		if ( ! in_array( null, $chunks, true ) ) {
			return self::normalise_html( (string) ( $block['innerHTML'] ?? '' ) );
		}

		$html = '';
		foreach ( $chunks as $chunk ) {
			$html .= is_string( $chunk ) ? self::normalise_html( $chunk ) : "\x1f";
		}

		return $html;
	}

	/**
	 * An array with its keys sorted at every depth; lists keep their order.
	 *
	 * @param array<mixed> $data The data.
	 *
	 * @return array<mixed>
	 */
	private static function sorted( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::sorted( $value );
			}
		}

		if ( ! array_is_list( $data ) ) {
			ksort( $data, SORT_STRING );
		}

		return $data;
	}
}
