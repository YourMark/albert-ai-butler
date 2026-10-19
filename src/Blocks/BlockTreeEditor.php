<?php
/**
 * Block Tree Editor
 *
 * Pure, WordPress-persistence-free tree operations on a parse_blocks() array.
 * The granular block-edit abilities use it to change exactly one block while
 * every other block is preserved byte-for-byte: a parse_blocks() → mutate-one →
 * serialize_blocks() round trip rewrites untouched blocks verbatim (core walks
 * each block's innerContent string chunks unchanged), so editing one node can
 * never corrupt its siblings.
 *
 * Blocks are addressed by their LOGICAL path — an array of ints over the same
 * separator-filtered tree {@see BlockReader} exposes — not by IDs (stored blocks
 * have none). `[2]` is the third top-level block, `[2,0]` its first child. The
 * editor maps each logical index to the ACTUAL parsed-array index at every level
 * (skipping the empty-freeform separators parse_blocks() emits, via
 * {@see BlockReader::is_empty_separator()}), so paths line up with reads AND the
 * separators are preserved through the mutation.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Applies a single positional block operation to a parse_blocks() tree.
 *
 * Every public method returns the NEW tree array (the original is never mutated)
 * or a WP_Error describing why the path could not be resolved:
 *   - block_path_not_found — the path is out of range or unreachable.
 *   - block_path_mismatch  — an `$expect`ed block name did not match the node at
 *                            the path (the post changed since it was read).
 *
 * @phpstan-type ParsedBlock array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<int, mixed>, innerHTML: string, innerContent: array<int, string|null>}
 *
 * @since 1.2.0
 */
class BlockTreeEditor {

	/**
	 * Core blocks that can hold inner blocks (valid `inside_*` targets).
	 *
	 * Used to reject inserting a child into a leaf block (e.g. a paragraph), which
	 * would store markup the block editor cannot parse. A node that already holds
	 * inner blocks is treated as a container too, so a non-empty custom container
	 * is accepted; extend the set for empty custom containers via the
	 * `albert/blocks/container_blocks` filter.
	 *
	 * @var array<int, string>
	 *
	 * @since 1.2.0
	 */
	private const DEFAULT_CONTAINER_BLOCKS = [
		'core/group',
		'core/columns',
		'core/column',
		'core/buttons',
		'core/cover',
		'core/media-text',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/details',
		'core/navigation',
	];

	/**
	 * Replace the block at a path with a new block.
	 *
	 * @param array<int, array<string, mixed>> $tree          Parsed block tree.
	 * @param array<int, int>                  $path          Logical path of the target block.
	 * @param array<string, mixed>             $new_block     New ParsedBlock to put in its place.
	 * @param string|null                      $expect        Optional expected block name at the path.
	 * @param bool                             $keep_children Carry the target node's inner blocks onto the replacement.
	 * @return array<int, array<string, mixed>>|WP_Error New tree, or an error.
	 *
	 * @since 1.2.0
	 * @since 1.5.0 Added `$keep_children`.
	 */
	public function edit( array $tree, array $path, array $new_block, ?string $expect = null, bool $keep_children = false ): array|WP_Error {
		return $this->mutate(
			$tree,
			$path,
			$expect,
			static function ( array $siblings, int $actual_index ) use ( $new_block, $keep_children, $path ): array|WP_Error {
				$replacement = $new_block;
				$target      = $siblings[ $actual_index ];

				if ( $keep_children && ! empty( $target['innerBlocks'] ) && is_array( $target['innerBlocks'] ) ) {
					// Kept children need a wrapper to sit in. Under a leaf they would land
					// inside its markup (a heading's <h2>); under a wrapper the serializer
					// could not build (an untemplated cover) they would be stored bare
					// without the warning the create path gives. Dropping them would lose
					// content, so ask the caller to send them.
					$same_type = ( $replacement['blockName'] ?? null ) === ( $target['blockName'] ?? null );
					$hollow    = implode( '', array_filter( (array) ( $replacement['innerContent'] ?? [] ), 'is_string' ) ) === '';

					if ( $hollow || ( ! $same_type && ! self::is_container_block( $replacement ) ) ) {
						return new WP_Error(
							'block_children_unplaceable',
							sprintf(
								/* translators: 1: block name at the path, 2: the path, e.g. [2], 3: replacement block name. */
								__( "%1\$s at %2\$s has inner blocks, and they can't be kept automatically under %3\$s. Send innerBlocks explicitly: the blocks to keep, or [] to remove them.", 'albert-ai-butler' ),
								(string) ( $target['blockName'] ?? '' ),
								self::format_path( $path ),
								(string) ( $replacement['blockName'] ?? '' )
							),
							[ 'status' => 400 ]
						);
					}

					$replacement['innerBlocks'] = array_values( $target['innerBlocks'] );
					$replacement                = self::sync_inner_content( $replacement );
				}

				$siblings[ $actual_index ] = $replacement;

				return $siblings;
			}
		);
	}

	/**
	 * Remove the block at a path.
	 *
	 * @param array<int, array<string, mixed>> $tree   Parsed block tree.
	 * @param array<int, int>                  $path   Logical path of the target block.
	 * @param string|null                      $expect Optional expected block name at the path.
	 * @return array<int, array<string, mixed>>|WP_Error New tree, or an error.
	 *
	 * @since 1.2.0
	 */
	public function remove( array $tree, array $path, ?string $expect = null ): array|WP_Error {
		return $this->mutate(
			$tree,
			$path,
			$expect,
			static function ( array $siblings, int $actual_index ): array {
				array_splice( $siblings, $actual_index, 1 );

				return $siblings;
			}
		);
	}

	/**
	 * Insert a new block relative to the block at a path.
	 *
	 * `before`/`after` insert as a sibling of the target; `inside_start`/
	 * `inside_end` insert into the target's innerBlocks (prepend/append). The
	 * target's innerBlocks are normalised to a list before inserting.
	 *
	 * @param array<int, array<string, mixed>> $tree      Parsed block tree.
	 * @param array<int, int>                  $path      Logical path of the reference block.
	 * @param string                           $position  One of before|after|inside_start|inside_end.
	 * @param array<string, mixed>             $new_block New ParsedBlock to insert.
	 * @return array<int, array<string, mixed>>|WP_Error New tree, or an error.
	 *
	 * @since 1.2.0
	 */
	public function add( array $tree, array $path, string $position, array $new_block ): array|WP_Error {
		$allowed = [ 'before', 'after', 'inside_start', 'inside_end' ];
		if ( ! in_array( $position, $allowed, true ) ) {
			return new WP_Error(
				'block_position_invalid',
				sprintf(
					/* translators: 1: supplied position, 2: comma-separated allowed positions. */
					__( "Invalid position '%1\$s'. Use one of: %2\$s.", 'albert-ai-butler' ),
					$position,
					implode( ', ', $allowed )
				),
				[ 'status' => 400 ]
			);
		}

		if ( $position === 'before' || $position === 'after' ) {
			return $this->mutate(
				$tree,
				$path,
				null,
				static function ( array $siblings, int $actual_index ) use ( $position, $new_block ): array {
					$at = $position === 'after' ? $actual_index + 1 : $actual_index;
					array_splice( $siblings, $at, 0, [ $new_block ] );

					return $siblings;
				}
			);
		}

		// inside_start / inside_end: insert into the target node's innerBlocks.
		return $this->mutate_node(
			$tree,
			$path,
			null,
			static function ( array $node ) use ( $position, $new_block, $path ) {
				if ( ! self::is_container_block( $node ) ) {
					return new WP_Error(
						'block_not_container',
						sprintf(
							/* translators: 1: block name, 2: the path, e.g. [2]. */
							__( "%1\$s at %2\$s can't contain inner blocks. Use position 'before' or 'after' to place a sibling, or target a container block such as core/group, core/columns or core/column.", 'albert-ai-butler' ),
							(string) ( $node['blockName'] ?? 'This block' ),
							self::format_path( $path )
						),
						[ 'status' => 400 ]
					);
				}

				$inner = isset( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] )
					? array_values( $node['innerBlocks'] )
					: [];

				if ( $position === 'inside_start' ) {
					array_unshift( $inner, $new_block );
				} else {
					$inner[] = $new_block;
				}

				$node['innerBlocks'] = $inner;

				return self::sync_inner_content( $node );
			}
		);
	}

	/**
	 * Reorder the block at a path among its siblings.
	 *
	 * `$to_index` is the block's FINAL logical position among its siblings — a
	 * separator-filtered index matching the paths reads expose. The block is
	 * removed and re-inserted at that logical position in the post-removal index
	 * space, so it lands exactly at `$to_index` whether it moved forward or back;
	 * an index at or past the end appends.
	 *
	 * @param array<int, array<string, mixed>> $tree     Parsed block tree.
	 * @param array<int, int>                  $path     Logical path of the target block.
	 * @param int                              $to_index Target logical sibling index.
	 * @param string|null                      $expect   Optional expected block name at the path.
	 * @return array<int, array<string, mixed>>|WP_Error New tree, or an error.
	 *
	 * @since 1.2.0
	 */
	public function move( array $tree, array $path, int $to_index, ?string $expect = null ): array|WP_Error {
		if ( $path === [] ) {
			return $this->path_not_found( $path );
		}

		$to_index = max( 0, $to_index );

		return $this->mutate(
			$tree,
			$path,
			$expect,
			static function ( array $siblings, int $actual_index ) use ( $to_index ): array {
				// The block being moved.
				$moved = $siblings[ $actual_index ];
				array_splice( $siblings, $actual_index, 1 );

				// $to_index is the block's FINAL logical position among its
				// siblings. After the removal above, the remaining real blocks
				// re-index, so inserting at logical $to_index in this shortened list
				// lands the block exactly there — whether it moved forward or back.
				// A $to_index at or past the end appends after any trailing separator.
				$actual_target = self::logical_to_actual_or_end( $siblings, $to_index );

				array_splice( $siblings, $actual_target, 0, [ $moved ] );

				return $siblings;
			}
		);
	}

	/**
	 * Resolve a path, optionally check the expected name, then replace the parent
	 * sibling list with the result of $apply.
	 *
	 * Walks every level except the last to reach the parent sibling list, then
	 * runs $apply with that list and the ACTUAL array index of the target so the
	 * caller can splice/replace/remove around it. The mutated list is written back
	 * up the tree (the parents along the path are reconstructed, never aliased).
	 *
	 * @param array<int, array<string, mixed>>                               $tree   Parsed block tree.
	 * @param array<int, int>                                                $path   Logical path of the target.
	 * @param string|null                                                    $expect Optional expected block name.
	 * @param callable(array<int, mixed>, int): (array<int, mixed>|WP_Error) $apply  Mutator: (siblings, actual index) → siblings, or a WP_Error to reject.
	 * @return array<int, array<string, mixed>>|WP_Error New tree, or an error.
	 *
	 * @since 1.2.0
	 */
	private function mutate( array $tree, array $path, ?string $expect, callable $apply ): array|WP_Error {
		if ( $path === [] ) {
			return $this->path_not_found( $path );
		}

		$siblings = array_values( $tree );
		$logical  = $path[0];
		$actual   = self::logical_to_actual( $siblings, $logical );

		if ( $actual === null ) {
			return $this->path_not_found( $path );
		}

		// Last segment: this level holds the target.
		if ( count( $path ) === 1 ) {
			$mismatch = $this->check_expect( $siblings[ $actual ], $expect, $path );
			if ( $mismatch instanceof WP_Error ) {
				return $mismatch;
			}

			$out = $apply( $siblings, $actual );
			if ( $out instanceof WP_Error ) {
				return $out;
			}

			return array_values( $out );
		}

		// Descend into the target's innerBlocks and recurse on the rest of the path.
		$node  = $siblings[ $actual ];
		$inner = isset( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] )
			? array_values( $node['innerBlocks'] )
			: [];

		$result = $this->mutate( $inner, array_slice( $path, 1 ), $expect, $apply );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$node['innerBlocks'] = $result;
		$siblings[ $actual ] = self::sync_inner_content( $node );

		return array_values( $siblings );
	}

	/**
	 * Resolve a path to its node, optionally check the expected name, then replace
	 * the node itself with the result of $apply.
	 *
	 * Like {@see mutate()} but the mutator operates on the resolved NODE (used by
	 * inside_* inserts, which modify the target's own innerBlocks rather than its
	 * sibling list).
	 *
	 * @param array<int, array<string, mixed>>                                $tree   Parsed block tree.
	 * @param array<int, int>                                                 $path   Logical path of the target.
	 * @param string|null                                                     $expect Optional expected block name.
	 * @param callable(array<string, mixed>): (array<string, mixed>|WP_Error) $apply Node mutator (may reject with a WP_Error).
	 * @return array<int, array<string, mixed>>|WP_Error New tree, or an error.
	 *
	 * @since 1.2.0
	 */
	private function mutate_node( array $tree, array $path, ?string $expect, callable $apply ): array|WP_Error {
		return $this->mutate(
			$tree,
			$path,
			$expect,
			static function ( array $siblings, int $actual_index ) use ( $apply ) {
				$result = $apply( $siblings[ $actual_index ] );
				if ( $result instanceof WP_Error ) {
					return $result;
				}

				$siblings[ $actual_index ] = $result;

				return $siblings;
			}
		);
	}

	/**
	 * Reject when the node at the path is not the expected block name.
	 *
	 * @param array<string, mixed> $node   Resolved node.
	 * @param string|null          $expect Expected block name (null = no check).
	 * @param array<int, int>      $path   Path being resolved (for the message).
	 * @return WP_Error|null Error on mismatch, null when ok.
	 *
	 * @since 1.2.0
	 */
	private function check_expect( array $node, ?string $expect, array $path ): ?WP_Error {
		if ( $expect === null || $expect === '' ) {
			return null;
		}

		$actual_name = $node['blockName'] ?? null;
		if ( (string) $actual_name === $expect ) {
			return null;
		}

		return new WP_Error(
			'block_path_mismatch',
			sprintf(
				/* translators: 1: expected block name, 2: path, 3: actual block name. */
				__( 'Expected %1$s at %2$s but found %3$s. The post changed since you read it. Re-read and retry.', 'albert-ai-butler' ),
				$expect,
				self::format_path( $path ),
				$actual_name === null ? 'classic/freeform content' : (string) $actual_name
			),
			[ 'status' => 409 ]
		);
	}

	/**
	 * Build the standard "no block at path" error.
	 *
	 * @param array<int, int> $path Path that could not be resolved.
	 * @return WP_Error
	 *
	 * @since 1.2.0
	 */
	private function path_not_found( array $path ): WP_Error {
		return new WP_Error(
			'block_path_not_found',
			sprintf(
				/* translators: %s: the path, e.g. [2,0]. */
				__( 'No block at path %s. Re-read the post for current paths.', 'albert-ai-butler' ),
				self::format_path( $path )
			),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Map a LOGICAL sibling index to the ACTUAL parsed-array index at one level.
	 *
	 * The logical index counts only non-separator blocks (the same tree reads
	 * expose); empty-freeform separators are skipped but kept in the array, so the
	 * returned actual index points past any separators preceding the target.
	 *
	 * @param array<int, mixed> $siblings Parsed blocks at one level.
	 * @param int               $logical  Logical (separator-filtered) index.
	 * @return int|null Actual array index, or null when out of range.
	 *
	 * @since 1.2.0
	 */
	private static function logical_to_actual( array $siblings, int $logical ): ?int {
		if ( $logical < 0 ) {
			return null;
		}

		$seen = 0;
		foreach ( $siblings as $actual => $block ) {
			if ( is_array( $block ) && BlockReader::is_empty_separator( $block ) ) {
				continue;
			}

			if ( $seen === $logical ) {
				return (int) $actual;
			}

			++$seen;
		}

		return null;
	}

	/**
	 * Map a LOGICAL sibling index to an ACTUAL insert index, allowing one past the
	 * end (append).
	 *
	 * Used by {@see move()} to position the moved block: when the logical target is
	 * at or beyond the number of real blocks, the block is appended at the end of
	 * the array (after any trailing separator), preserving separators.
	 *
	 * @param array<int, mixed> $siblings Parsed blocks at one level (after removal).
	 * @param int               $logical  Logical target index.
	 * @return int Actual array index suitable for array_splice() insertion.
	 *
	 * @since 1.2.0
	 */
	private static function logical_to_actual_or_end( array $siblings, int $logical ): int {
		$actual = self::logical_to_actual( $siblings, $logical );
		if ( $actual !== null ) {
			return $actual;
		}

		// Logical index is past the last real block — append at the end.
		return count( $siblings );
	}

	/**
	 * Recompute a node's innerHTML/innerContent so serialize_blocks() interleaves
	 * its (possibly changed) innerBlocks correctly.
	 *
	 * When a node's innerBlocks change (insert/remove/edit a child, or an
	 * inside_* insert), the stored innerContent — the alternating list of literal
	 * HTML chunks and null child markers — must carry exactly one null per inner
	 * block. We preserve the node's own literal wrapper HTML (the leading and
	 * trailing string chunks, e.g. a group's `<div class="wp-block-group">` …
	 * `</div>`) and rebuild the markers between them so serialize_blocks() emits
	 * each child in order. A node with no inner blocks keeps a flat innerContent.
	 *
	 * Assumption: the wrapper carries at most one leading and one trailing literal
	 * chunk in innerContent — true for every core layout block. Literal HTML placed
	 * *between* children (a non-standard custom block) is not preserved; such blocks
	 * should be edited via whole-content update rather than child-level inserts.
	 *
	 * @param array<string, mixed> $node Parsed block whose innerBlocks just changed.
	 * @return array<string, mixed> Node with innerHTML/innerContent in sync.
	 *
	 * @since 1.2.0
	 */
	private static function sync_inner_content( array $node ): array {
		$inner = isset( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] )
			? array_values( $node['innerBlocks'] )
			: [];

		$node['innerBlocks'] = $inner;

		// Split the existing innerContent into the literal wrapper chunks (strings)
		// so the wrapper markup is preserved; the null markers are rebuilt to match
		// the new child count.
		$existing = isset( $node['innerContent'] ) && is_array( $node['innerContent'] )
			? $node['innerContent']
			: [];

		$strings = [];
		foreach ( $existing as $chunk ) {
			if ( is_string( $chunk ) ) {
				$strings[] = $chunk;
			}
		}

		if ( $inner === [] ) {
			// No children: a single literal chunk (the flattened wrapper HTML).
			$flat                 = implode( '', $strings );
			$node['innerHTML']    = $flat;
			$node['innerContent'] = $flat === '' ? [] : [ $flat ];

			return $node;
		}

		// Wrapper opening = everything before the first child marker; wrapper
		// closing = everything after the last. With the literal chunks collapsed
		// to before/after, rebuild: before, null, null, …, after.
		if ( count( $strings ) > 1 ) {
			$before = $strings[0];
			$after  = (string) end( $strings );
		} else {
			// A single literal chunk holds the whole wrapper — e.g. a container the
			// block editor stored empty as "<div class="wp-block-group"></div>".
			// Split it at the final close tag so inserted children land INSIDE the
			// wrapper, not after its closing tag.
			$chunk = $strings[0] ?? '';
			$split = strrpos( $chunk, '</' );
			if ( $split !== false ) {
				$before = substr( $chunk, 0, $split );
				$after  = substr( $chunk, $split );
			} else {
				$before = $chunk;
				$after  = '';
			}
		}

		$content = [ $before ];
		foreach ( $inner as $unused ) {
			$content[] = null;
		}
		$content[] = $after;

		$node['innerContent'] = $content;
		$node['innerHTML']    = $before . $after;

		return $node;
	}

	/**
	 * Whether a node can hold inner blocks (a valid `inside_*` insert target).
	 *
	 * A node that already holds inner blocks is a container; otherwise the block
	 * name must be a known container. Inserting a child into a leaf block (e.g. a
	 * paragraph) would store markup the block editor cannot parse, so `inside_*`
	 * rejects non-containers.
	 *
	 * @param array<string, mixed> $node Parsed block.
	 * @return bool True when the node may receive inner blocks.
	 *
	 * @since 1.2.0
	 */
	private static function is_container_block( array $node ): bool {
		if ( ! empty( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ) {
			return true;
		}

		$name = (string) ( $node['blockName'] ?? '' );

		/**
		 * Filters the block names treated as containers for `inside_*` inserts.
		 *
		 * Add empty custom container blocks here so a child can be inserted into
		 * them before they hold any inner blocks.
		 *
		 * @since 1.2.0
		 *
		 * @param array<int, string> $containers Container block names. Default {@see DEFAULT_CONTAINER_BLOCKS}.
		 */
		$containers = apply_filters( 'albert/blocks/container_blocks', self::DEFAULT_CONTAINER_BLOCKS );

		return is_array( $containers ) && in_array( $name, $containers, true );
	}

	/**
	 * Format a logical path as a bracketed list for messages, e.g. [2,0].
	 *
	 * @param array<int, int> $path Logical path.
	 * @return string Formatted path.
	 *
	 * @since 1.2.0
	 */
	private static function format_path( array $path ): string {
		return '[' . implode( ',', array_map( 'intval', $path ) ) . ']';
	}
}
