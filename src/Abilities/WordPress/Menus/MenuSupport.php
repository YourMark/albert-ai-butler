<?php
/**
 * Shared helpers for the menu abilities.
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Menus
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Menus;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Lookups, the REST round trip and the item tree every menu ability shares.
 *
 * @since 1.6.0
 */
class MenuSupport {

	/**
	 * The capability core's menu routes check, for reads as well as writes.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public const CAPABILITY = 'edit_theme_options';

	/**
	 * Annotation guidance shared by the classic writes.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public const CHECK_FIRST = 'Call albert/find-menus first and change the menu visitors see: on a block theme the header shows a navigation menu, not a classic one. The result says when the menu you changed is not shown on the site.';

	/**
	 * A classic menu by id.
	 *
	 * @param int $id Menu term id.
	 *
	 * @return WP_Term|WP_Error
	 * @since 1.6.0
	 */
	public static function classic_menu( int $id ): WP_Term|WP_Error {
		$menu = $id > 0 ? wp_get_nav_menu_object( $id ) : false;

		if ( ! $menu instanceof WP_Term ) {
			return new WP_Error(
				'menu_not_found',
				__( 'No classic menu has that ID. Call albert/find-menus to list them.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		return $menu;
	}

	/**
	 * A navigation menu (`wp_navigation` post) by id.
	 *
	 * @param int $id Post id.
	 *
	 * @return WP_Post|WP_Error
	 * @since 1.6.0
	 */
	public static function navigation_menu( int $id ): WP_Post|WP_Error {
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof WP_Post || $post->post_type !== 'wp_navigation' || $post->post_status === 'trash' ) {
			return new WP_Error(
				'menu_not_found',
				__( 'No navigation menu has that ID. Call albert/find-menus to list them.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		return $post;
	}

	/**
	 * A classic menu item by id.
	 *
	 * @param int $id Menu item post id.
	 *
	 * @return WP_Post|WP_Error
	 * @since 1.6.0
	 */
	public static function menu_item( int $id ): WP_Post|WP_Error {
		$post = $id > 0 ? get_post( $id ) : null;

		if ( ! $post instanceof WP_Post || $post->post_type !== 'nav_menu_item' ) {
			return new WP_Error(
				'menu_item_not_found',
				__( 'No menu item has that ID. Call albert/view-menu to see a menu\'s items.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		return $post;
	}

	/**
	 * The classic menu an item belongs to.
	 *
	 * @param int $item_id Menu item post id.
	 *
	 * @return int Menu term id, 0 when it belongs to none.
	 * @since 1.6.0
	 */
	public static function menu_of_item( int $item_id ): int {
		$terms = wp_get_object_terms( $item_id, 'nav_menu', [ 'fields' => 'ids' ] );

		return is_array( $terms ) && $terms !== [] ? (int) $terms[0] : 0;
	}

	/**
	 * Run one request against core's REST API, so core's own checks and hooks apply.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $route   Route, e.g. `/wp/v2/menus`.
	 * @param array<string, mixed> $params  Request parameters.
	 * @param string               $failure Message when core gives none.
	 *
	 * @return array<string, mixed>|WP_Error The response data.
	 * @since 1.6.0
	 */
	public static function rest( string $method, string $route, array $params, string $failure ): array|WP_Error {
		$request = new WP_REST_Request( $method, $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		if ( $response->is_error() ) {
			$data = is_array( $data ) ? $data : [];

			return new WP_Error(
				is_string( $data['code'] ?? null ) ? $data['code'] : 'rest_error',
				is_string( $data['message'] ?? null ) ? $data['message'] : $failure,
				[ 'status' => $response->get_status() ]
			);
		}

		return is_array( $data ) ? $data : [];
	}

	/**
	 * A classic menu's items, in menu order.
	 *
	 * @param int $menu_id Menu term id.
	 *
	 * @return list<WP_Post>
	 * @since 1.6.0
	 */
	public static function classic_items( int $menu_id ): array {
		$items = wp_get_nav_menu_items(
			$menu_id,
			[
				'post_status'            => 'publish,draft',
				'update_post_term_cache' => false,
			]
		);

		if ( ! is_array( $items ) ) {
			return [];
		}

		return array_values( array_filter( $items, static fn( $item ): bool => $item instanceof WP_Post ) );
	}

	/**
	 * A classic menu's items as a tree.
	 *
	 * An item whose parent is not in the menu is listed at the top level, which
	 * is where WordPress's walker shows it.
	 *
	 * @param int $menu_id Menu term id.
	 *
	 * @return list<array<string, mixed>>
	 * @since 1.6.0
	 */
	public static function classic_tree( int $menu_id ): array {
		$items    = self::classic_items( $menu_id );
		$ids      = array_map( static fn( WP_Post $item ): int => (int) $item->ID, $items );
		$children = [];

		foreach ( $items as $item ) {
			$parent = (int) ( $item->menu_item_parent ?? 0 );
			if ( ! in_array( $parent, $ids, true ) ) {
				$parent = 0;
			}
			$children[ $parent ][] = $item;
		}

		return self::branch( $children, 0, 0 );
	}

	/**
	 * One level of the classic tree.
	 *
	 * @param array<int, list<WP_Post>> $children Items keyed by parent id.
	 * @param int                       $parent_id Parent item id.
	 * @param int                       $depth    Guard against a parent loop.
	 *
	 * @return list<array<string, mixed>>
	 * @since 1.6.0
	 */
	private static function branch( array $children, int $parent_id, int $depth ): array {
		if ( $depth > 20 || empty( $children[ $parent_id ] ) ) {
			return [];
		}

		$branch   = [];
		$position = 0;

		foreach ( $children[ $parent_id ] as $item ) {
			$id       = (int) $item->ID;
			$branch[] = [
				'id'               => $id,
				'title'            => (string) ( $item->title ?? '' ),
				'url'              => (string) ( $item->url ?? '' ),
				'type'             => (string) ( $item->type ?? '' ),
				'object'           => (string) ( $item->object ?? '' ),
				'object_id'        => (int) ( $item->object_id ?? 0 ),
				'parent'           => $parent_id,
				'position'         => ++$position,
				'open_in_new_tab'  => ( $item->target ?? '' ) === '_blank',
				'missing_original' => ! empty( $item->_invalid ),
				'children'         => self::branch( $children, $id, $depth + 1 ),
			];
		}

		return $branch;
	}

	/**
	 * The visibility fields every classic write returns.
	 *
	 * @param int $menu_id Menu term id.
	 *
	 * @return array{visible: bool, shown_in: list<string>, notice?: string}
	 * @since 1.6.0
	 */
	public static function visibility_fields( int $menu_id ): array {
		$locator = new MenuLocator();
		$where   = $locator->classic_menu_shown_in( $menu_id );
		$fields  = [
			'visible'  => $where !== [],
			'shown_in' => $where,
		];

		if ( $where === [] ) {
			$fields['notice'] = $locator->classic_menu_hidden_notice();
		}

		return $fields;
	}

	/**
	 * Output schema properties for {@see self::visibility_fields()}.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.6.0
	 */
	public static function visibility_schema(): array {
		return [
			'visible'  => [
				'type'        => 'boolean',
				'description' => 'Whether visitors see this menu anywhere on the site.',
			],
			'shown_in' => [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'description' => 'Where the site shows this menu.',
			],
			'notice'   => [
				'type'        => 'string',
				'description' => 'Present when visitors do not see this menu. Tell the person.',
			],
		];
	}
}
