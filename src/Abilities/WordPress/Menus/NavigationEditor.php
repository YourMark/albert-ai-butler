<?php
/**
 * Menu item edits on a navigation menu's block tree.
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Menus
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Menus;

use Albert\Blocks\BlockReader;
use Albert\Blocks\BlockTreeEditor;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Adds, changes, moves and removes items in parsed `wp_navigation` blocks.
 *
 * Works the way the Site Editor does: a link becomes a submenu when it gets a
 * child and turns back into a link when its last child leaves, and a page list
 * is turned into separate links before one of its pages is changed. Paths are
 * the ones {@see NavigationTree::items()} returns. Nothing is saved here.
 *
 * @since 1.6.0
 */
class NavigationEditor {

	/**
	 * The block engine underneath.
	 *
	 * @since 1.6.0
	 * @var BlockTreeEditor
	 */
	private BlockTreeEditor $blocks;

	/**
	 * Things the person should be told about the edit.
	 *
	 * @since 1.6.0
	 * @var list<string>
	 */
	private array $notices = [];

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->blocks = new BlockTreeEditor();
	}

	/**
	 * What the person should be told, such as a page list that no longer grows by itself.
	 *
	 * @return list<string>
	 * @since 1.6.0
	 */
	public function notices(): array {
		return $this->notices;
	}

	/**
	 * Turn any page list a path points inside into links, and move the paths to match.
	 *
	 * @param array<int, mixed>         $tree  Parsed blocks.
	 * @param array<string, ?list<int>> $paths Paths by name; null entries are left alone.
	 *
	 * @return array{0: array<int, mixed>, 1: array<string, ?list<int>>}|WP_Error
	 * @since 1.6.0
	 */
	public function open_page_lists( array $tree, array $paths ): array|WP_Error {
		foreach ( $paths as $path ) {
			$list_path = $path === null ? null : $this->page_list_above( $tree, $path );
			if ( $list_path === null ) {
				continue;
			}

			$links = NavigationTree::page_list_links( (array) ( $this->blocks->find( $tree, $list_path )['attrs'] ?? [] ) );
			if ( $links === null ) {
				return new WP_Error(
					'albert_page_list_too_long',
					/* translators: %d: most pages */
					sprintf( __( 'This page list shows more than %d pages, too many to turn into separate links. Remove the page list with albert/remove-menu-item and add the pages that matter with albert/add-menu-item.', 'albert-ai-butler' ), NavigationTree::MAX_PAGE_LIST ),
					[ 'status' => 400 ]
				);
			}

			$tree = $this->blocks->remove( $tree, $list_path );
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}

			$parent_path = array_slice( $list_path, 0, -1 );
			$index       = (int) end( $list_path );
			foreach ( $links as $offset => $link ) {
				$tree = $this->insert_at( $tree, $parent_path, $index + $offset, $link );
				if ( is_wp_error( $tree ) ) {
					return $tree;
				}
			}

			$paths = array_map(
				static fn( ?array $path ): ?array => $path === null ? null : self::path_after_opening( $path, $list_path, count( $links ) ),
				$paths
			);

			$this->notices[] = __( 'The automatic page list was turned into separate links, as the Site Editor does before one page is changed. New pages are no longer added to this menu by themselves; add them with albert/add-menu-item.', 'albert-ai-butler' );

			// A path can only pass through one page list, but another path may name a second one.
			return $this->open_page_lists( $tree, $paths );
		}

		return [ $tree, $paths ];
	}

	/**
	 * Add an item.
	 *
	 * @param array<int, mixed>    $tree     Parsed blocks.
	 * @param list<int>            $parent_path   Parent item path, [] for the top level.
	 * @param int                  $position 1-based position among the parent's items, 0 for last.
	 * @param array<string, mixed> $block    The new block.
	 *
	 * @return array{0: array<int, mixed>, 1: list<int>}|WP_Error The tree and the new item's path.
	 * @since 1.6.0
	 */
	public function add( array $tree, array $parent_path, int $position, array $block ): array|WP_Error {
		$tree = $this->open_parent( $tree, $parent_path );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$count = $this->child_count( $tree, $parent_path );
		$index = $position > 0 ? min( $position - 1, $count ) : $count;

		$tree = $this->insert_at( $tree, $parent_path, $index, $block );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		return [ $tree, array_merge( $parent_path, [ $index ] ) ];
	}

	/**
	 * Change a link's or submenu's attributes.
	 *
	 * @param array<int, mixed>    $tree    Parsed blocks.
	 * @param list<int>            $path    Item path.
	 * @param array<string, mixed> $changes Attributes to set; a null value removes one.
	 *
	 * @return array<int, mixed>|WP_Error
	 * @since 1.6.0
	 */
	public function update( array $tree, array $path, array $changes ): array|WP_Error {
		$node = $this->link_at( $tree, $path );
		if ( is_wp_error( $node ) ) {
			return $node;
		}

		foreach ( $changes as $key => $value ) {
			if ( $value === null ) {
				unset( $node['attrs'][ $key ] );
			} else {
				$node['attrs'][ $key ] = $value;
			}
		}

		return $this->blocks->edit( $tree, $path, $node );
	}

	/**
	 * Move an item, with its sub-items.
	 *
	 * @param array<int, mixed> $tree     Parsed blocks.
	 * @param list<int>         $path     Item path.
	 * @param list<int>|null    $parent_path   New parent path, [] for the top level, null to keep its parent.
	 * @param int               $position 1-based position among the parent's items, 0 for last.
	 *
	 * @return array{0: array<int, mixed>, 1: list<int>}|WP_Error The tree and the item's new path.
	 * @since 1.6.0
	 */
	public function move( array $tree, array $path, ?array $parent_path, int $position ): array|WP_Error {
		$node = $this->item_at( $tree, $path );
		if ( is_wp_error( $node ) ) {
			return $node;
		}

		$old_parent    = array_slice( $path, 0, -1 );
		$parent_path ??= $old_parent;

		if ( array_slice( $parent_path, 0, count( $path ) ) === $path ) {
			return new WP_Error(
				'albert_invalid_parent_path',
				__( 'An item cannot move under itself or one of its own sub-items. Choose another "parent_path".', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$tree = $this->blocks->remove( $tree, $path );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$parent_path = self::path_after_removal( $parent_path, $path );
		$tree        = $this->open_parent( $tree, $parent_path );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$count = $this->child_count( $tree, $parent_path );
		$index = $position > 0 ? min( $position - 1, $count ) : $count;

		$tree = $this->insert_at( $tree, $parent_path, $index, self::level_flag( $node, $parent_path === [] ) );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$old_parent = self::path_after_insertion( self::path_after_removal( $old_parent, $path ), $parent_path, $index );
		$tree       = $this->close_if_empty( $tree, $old_parent );

		return is_wp_error( $tree ) ? $tree : [ $tree, array_merge( $parent_path, [ $index ] ) ];
	}

	/**
	 * Remove an item; its sub-items move up into its place.
	 *
	 * @param array<int, mixed> $tree Parsed blocks.
	 * @param list<int>         $path Item path.
	 *
	 * @return array{0: array<int, mixed>, 1: array<string, mixed>, 2: list<list<int>>}|WP_Error The tree, the removed block and the sub-items' new paths.
	 * @since 1.6.0
	 */
	public function remove( array $tree, array $path ): array|WP_Error {
		$node = $this->item_at( $tree, $path );
		if ( is_wp_error( $node ) ) {
			return $node;
		}

		$tree = $this->blocks->remove( $tree, $path );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$parent_path = array_slice( $path, 0, -1 );
		$index       = (int) end( $path );
		$moved_up    = [];

		// A page list's pages are not blocks of its own, so it has nothing to move up.
		$children = ( $node['blockName'] ?? '' ) === NavigationTree::PAGE_LIST ? [] : self::named( (array) $node['innerBlocks'] );
		foreach ( $children as $offset => $child ) {
			$tree = $this->insert_at( $tree, $parent_path, $index + $offset, self::level_flag( $child, $parent_path === [] ) );
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}
			$moved_up[] = array_merge( $parent_path, [ $index + $offset ] );
		}

		$tree = $this->close_if_empty( $tree, $parent_path );

		return is_wp_error( $tree ) ? $tree : [ $tree, $node, $moved_up ];
	}

	/**
	 * The link or submenu at a path.
	 *
	 * @param array<int, mixed> $tree Parsed blocks.
	 * @param list<int>         $path Item path.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	public function link_at( array $tree, array $path ): array|WP_Error {
		$node = $this->item_at( $tree, $path );
		if ( is_wp_error( $node ) ) {
			return $node;
		}

		if ( ! in_array( $node['blockName'] ?? '', [ NavigationTree::LINK, NavigationTree::SUBMENU ], true ) ) {
			return new WP_Error(
				'albert_invalid_path',
				/* translators: %s: block name */
				sprintf( __( 'The item at "path" is a %s block, not a link or submenu, so it has no label or link to change. The person can change it in Appearance > Editor > Navigation.', 'albert-ai-butler' ), (string) ( $node['blockName'] ?? 'freeform' ) ),
				[ 'status' => 400 ]
			);
		}

		return $node;
	}

	/**
	 * The item at a path.
	 *
	 * @param array<int, mixed> $tree Parsed blocks.
	 * @param list<int>         $path Item path.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	private function item_at( array $tree, array $path ): array|WP_Error {
		$node = $path === [] ? null : $this->blocks->find( $tree, $path );

		if ( $node === null ) {
			return new WP_Error(
				'albert_invalid_path',
				__( 'No menu item has that "path". Call albert/view-menu for the current paths.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		return $node;
	}

	/**
	 * Make the item at a parent path able to hold sub-items: a link becomes a submenu.
	 *
	 * @param array<int, mixed> $tree   Parsed blocks.
	 * @param list<int>         $parent_path Parent path, [] for the top level.
	 *
	 * @return array<int, mixed>|WP_Error
	 * @since 1.6.0
	 */
	private function open_parent( array $tree, array $parent_path ): array|WP_Error {
		if ( $parent_path === [] ) {
			return $tree;
		}

		$node = $this->blocks->find( $tree, $parent_path );
		$name = (string) ( $node['blockName'] ?? '' );

		if ( $node === null || ! in_array( $name, [ NavigationTree::LINK, NavigationTree::SUBMENU ], true ) ) {
			return new WP_Error(
				'albert_invalid_parent_path',
				$name === NavigationTree::PAGE_LIST
					? __( 'A page list adds its pages by itself and cannot hold other items. Use the path of one of its pages, or of another link, as "parent_path".', 'albert-ai-butler' )
					: __( 'The "parent_path" must be a link or submenu in this menu, or [] for the top level. Call albert/view-menu for the current paths.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		if ( $name === NavigationTree::SUBMENU ) {
			return $tree;
		}

		$node['blockName'] = NavigationTree::SUBMENU;
		unset( $node['attrs']['isTopLevelLink'] );

		return $this->blocks->edit( $tree, $parent_path, $node );
	}

	/**
	 * Turn a submenu whose last item left back into a link.
	 *
	 * @param array<int, mixed> $tree   Parsed blocks.
	 * @param list<int>         $parent_path Path of the item that lost a child, [] for the top level.
	 *
	 * @return array<int, mixed>|WP_Error
	 * @since 1.6.0
	 */
	private function close_if_empty( array $tree, array $parent_path ): array|WP_Error {
		$node = $parent_path === [] ? null : $this->blocks->find( $tree, $parent_path );

		if ( $node === null || ( $node['blockName'] ?? '' ) !== NavigationTree::SUBMENU || self::named( (array) $node['innerBlocks'] ) !== [] ) {
			return $tree;
		}

		unset( $node['attrs']['isTopLevelItem'] );

		return $this->blocks->edit( $tree, $parent_path, NavigationTree::link_block( (array) $node['attrs'] ) );
	}

	/**
	 * Put a block at a logical index among a parent's items.
	 *
	 * @param array<int, mixed>    $tree   Parsed blocks.
	 * @param list<int>            $parent_path Parent path, [] for the top level.
	 * @param int                  $index  0-based index, at most the item count.
	 * @param array<string, mixed> $block  Block to insert.
	 *
	 * @return array<int, mixed>|WP_Error
	 * @since 1.6.0
	 */
	private function insert_at( array $tree, array $parent_path, int $index, array $block ): array|WP_Error {
		$count = $this->child_count( $tree, $parent_path );

		if ( $index < $count ) {
			return $this->blocks->add( $tree, array_merge( $parent_path, [ $index ] ), 'before', $block );
		}

		if ( $count > 0 ) {
			return $this->blocks->add( $tree, array_merge( $parent_path, [ $count - 1 ] ), 'after', $block );
		}

		if ( $parent_path === [] ) {
			return array_merge( $tree, [ $block ] );
		}

		return $this->blocks->add( $tree, $parent_path, 'inside_end', $block );
	}

	/**
	 * How many items a parent holds.
	 *
	 * @param array<int, mixed> $tree   Parsed blocks.
	 * @param list<int>         $parent_path Parent path, [] for the top level.
	 *
	 * @return int
	 * @since 1.6.0
	 */
	private function child_count( array $tree, array $parent_path ): int {
		$siblings = $parent_path === [] ? $tree : (array) ( $this->blocks->find( $tree, $parent_path )['innerBlocks'] ?? [] );

		return count( self::named( $siblings ) );
	}

	/**
	 * The path of a page list a path points inside, if any.
	 *
	 * @param array<int, mixed> $tree Parsed blocks.
	 * @param list<int>         $path Item path.
	 *
	 * @return list<int>|null
	 * @since 1.6.0
	 */
	private function page_list_above( array $tree, array $path ): ?array {
		$depth = count( $path );

		for ( $length = 1; $length < $depth; $length++ ) {
			$above = array_slice( $path, 0, $length );
			if ( ( $this->blocks->find( $tree, $above )['blockName'] ?? '' ) === NavigationTree::PAGE_LIST ) {
				return $above;
			}
		}

		return null;
	}

	/**
	 * Blocks without the whitespace between them.
	 *
	 * @param array<int, mixed> $blocks Parsed blocks.
	 *
	 * @return list<array<string, mixed>>
	 * @since 1.6.0
	 */
	private static function named( array $blocks ): array {
		return array_values(
			array_filter(
				$blocks,
				static fn( $block ): bool => is_array( $block ) && ! BlockReader::is_empty_separator( $block )
			)
		);
	}

	/**
	 * Drop a top-level flag the editor sets, when a block changes level.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param bool                 $top   Whether it now sits at the top level.
	 *
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	private static function level_flag( array $block, bool $top ): array {
		if ( ! $top ) {
			unset( $block['attrs']['isTopLevelLink'], $block['attrs']['isTopLevelItem'] );
		}

		return $block;
	}

	/**
	 * Where a path ends up once the page list at `$list_path` is replaced by its links.
	 *
	 * @param list<int> $path  Path to move.
	 * @param list<int> $list_path  The page list's path.
	 * @param int       $links How many top links replaced it.
	 *
	 * @return list<int>
	 * @since 1.6.0
	 */
	private static function path_after_opening( array $path, array $list_path, int $links ): array {
		$depth = count( $list_path ) - 1;
		$index = $list_path[ $depth ];

		if ( count( $path ) > count( $list_path ) && array_slice( $path, 0, count( $list_path ) ) === $list_path ) {
			return array_merge( array_slice( $list_path, 0, $depth ), [ $index + $path[ $depth + 1 ] ], array_slice( $path, $depth + 2 ) );
		}

		if ( count( $path ) > $depth && array_slice( $path, 0, $depth ) === array_slice( $list_path, 0, $depth ) && $path[ $depth ] > $index ) {
			$path[ $depth ] += $links - 1;
		}

		return $path;
	}

	/**
	 * Where a path ends up once the item at `$removed` is taken out.
	 *
	 * @param list<int> $path    Path to move.
	 * @param list<int> $removed Removed item's path.
	 *
	 * @return list<int>
	 * @since 1.6.0
	 */
	private static function path_after_removal( array $path, array $removed ): array {
		$depth = count( $removed ) - 1;

		if ( count( $path ) > $depth && array_slice( $path, 0, $depth ) === array_slice( $removed, 0, $depth ) && $path[ $depth ] > $removed[ $depth ] ) {
			--$path[ $depth ];
		}

		return $path;
	}

	/**
	 * Where a path ends up once an item is inserted at `$index` under `$parent_path`.
	 *
	 * @param list<int> $path   Path to move.
	 * @param list<int> $parent_path Parent the item went into.
	 * @param int       $index  Its index there.
	 *
	 * @return list<int>
	 * @since 1.6.0
	 */
	private static function path_after_insertion( array $path, array $parent_path, int $index ): array {
		$depth = count( $parent_path );

		if ( count( $path ) > $depth && array_slice( $path, 0, $depth ) === $parent_path && $path[ $depth ] >= $index ) {
			++$path[ $depth ];
		}

		return $path;
	}
}
