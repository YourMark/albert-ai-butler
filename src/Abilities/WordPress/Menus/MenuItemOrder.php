<?php
/**
 * Places a classic menu item in its menu's tree.
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Menus
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Menus;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Moves an item, with its sub-items, under a parent at a position.
 *
 * A classic menu orders all its items by one `menu_order` sequence, not per
 * level, and core's REST route writes the number it is given without shifting
 * the others. So the whole menu is renumbered in tree order and only the items
 * whose number or parent changed are written, each through core's REST route.
 *
 * @since 1.6.0
 */
class MenuItemOrder {

	/**
	 * Place an item.
	 *
	 * @param int $menu_id  Menu term id.
	 * @param int $item_id  Item to move.
	 * @param int $parent_id   New parent item id, 0 for the top level.
	 * @param int $position 1-based position among the new parent's children; 0 for last.
	 *
	 * @return true|WP_Error True once placed.
	 * @since 1.6.0
	 */
	public static function place( int $menu_id, int $item_id, int $parent_id, int $position ): bool|WP_Error {
		$items = MenuSupport::classic_items( $menu_id );
		$by_id = [];
		foreach ( $items as $item ) {
			$by_id[ (int) $item->ID ] = $item;
		}

		if ( ! isset( $by_id[ $item_id ] ) ) {
			return new WP_Error( 'menu_item_not_found', __( 'That item is not in this menu.', 'albert-ai-butler' ), [ 'status' => 404 ] );
		}

		if ( $parent_id !== 0 && ! isset( $by_id[ $parent_id ] ) ) {
			return new WP_Error( 'menu_item_parent_invalid', __( 'The parent must be an item in the same menu, or 0 for the top level.', 'albert-ai-butler' ), [ 'status' => 400 ] );
		}

		$children = [];
		foreach ( $items as $item ) {
			$id = (int) $item->ID;
			if ( $id === $item_id ) {
				continue;
			}
			$children[ self::parent_of( $item, $by_id ) ][] = $id;
		}

		if ( $parent_id === $item_id || in_array( $parent_id, self::descendants( $children, $item_id ), true ) ) {
			return new WP_Error( 'menu_item_parent_invalid', __( 'An item cannot move under itself or one of its own sub-items.', 'albert-ai-butler' ), [ 'status' => 400 ] );
		}

		$siblings = $children[ $parent_id ] ?? [];
		$index    = $position > 0 ? min( $position - 1, count( $siblings ) ) : count( $siblings );
		array_splice( $siblings, $index, 0, [ $item_id ] );
		$children[ $parent_id ] = $siblings;

		$order = [];
		self::flatten( $children, 0, $order );

		foreach ( $order as $i => $id ) {
			$params = [];

			if ( (int) $by_id[ $id ]->menu_order !== $i + 1 ) {
				$params['menu_order'] = $i + 1;
			}

			if ( $id === $item_id && self::parent_of( $by_id[ $id ], $by_id ) !== $parent_id ) {
				$params['parent'] = $parent_id;
			}

			if ( $params === [] ) {
				continue;
			}

			$saved = MenuSupport::rest( 'POST', '/wp/v2/menu-items/' . $id, $params, __( 'The menu item could not be moved.', 'albert-ai-butler' ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		return true;
	}

	/**
	 * An item's parent as the walker sees it: one not in the menu is the top level.
	 *
	 * @param WP_Post             $item  The item.
	 * @param array<int, WP_Post> $by_id The menu's items.
	 *
	 * @return int
	 * @since 1.6.0
	 */
	private static function parent_of( WP_Post $item, array $by_id ): int {
		$parent_id = (int) ( $item->menu_item_parent ?? 0 );

		return isset( $by_id[ $parent_id ] ) ? $parent_id : 0;
	}

	/**
	 * Every item below one.
	 *
	 * @param array<int, list<int>> $children Child ids keyed by parent.
	 * @param int                   $id       Item id.
	 *
	 * @return list<int>
	 * @since 1.6.0
	 */
	private static function descendants( array $children, int $id ): array {
		$found = [];
		$queue = $children[ $id ] ?? [];

		while ( $queue !== [] ) {
			$next = (int) array_shift( $queue );
			if ( in_array( $next, $found, true ) ) {
				continue;
			}
			$found[] = $next;
			$queue   = array_merge( $queue, $children[ $next ] ?? [] );
		}

		return $found;
	}

	/**
	 * Item ids in tree order.
	 *
	 * @param array<int, list<int>> $children Child ids keyed by parent.
	 * @param int                   $parent_id   Where to start.
	 * @param list<int>             $order    Collected so far.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private static function flatten( array $children, int $parent_id, array &$order ): void {
		foreach ( $children[ $parent_id ] ?? [] as $id ) {
			if ( in_array( $id, $order, true ) ) {
				continue;
			}
			$order[] = $id;
			self::flatten( $children, $id, $order );
		}
	}
}
