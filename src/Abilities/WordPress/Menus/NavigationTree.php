<?php
/**
 * A navigation menu's blocks read as menu items.
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Menus
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Menus;

use Albert\Blocks\BlockReader;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `wp_navigation` blocks as an item tree, and builds the link blocks the Site Editor writes.
 *
 * Paths index the same separator-filtered tree {@see \Albert\Blocks\BlockTreeEditor} edits.
 *
 * @since 1.6.0
 */
class NavigationTree {

	/**
	 * Block names.
	 *
	 * @since 1.6.0
	 * @var string
	 */
	public const LINK      = 'core/navigation-link';
	public const SUBMENU   = 'core/navigation-submenu';
	public const PAGE_LIST = 'core/page-list';

	/**
	 * Most pages a page list is turned into links for, as in the Site Editor.
	 *
	 * @since 1.6.0
	 * @var int
	 */
	public const MAX_PAGE_LIST = 100;

	/**
	 * Navigation blocks as menu items, with the path a later edit targets.
	 *
	 * A page list's children are the pages it shows now, at the paths they get
	 * once it is turned into links.
	 *
	 * @param array<array<string, mixed>> $blocks    Parsed blocks.
	 * @param list<int>                   $path      Path of the parent.
	 * @param bool                        $automatic Whether these come from a page list.
	 *
	 * @return list<array<string, mixed>>
	 * @since 1.6.0
	 */
	public static function items( array $blocks, array $path = [], bool $automatic = false ): array {
		$items = [];
		$index = 0;

		foreach ( $blocks as $block ) {
			if ( BlockReader::is_empty_separator( $block ) ) {
				continue;
			}

			$name  = (string) ( $block['blockName'] ?? 'core/freeform' );
			$attrs = (array) $block['attrs'];
			$here  = array_merge( $path, [ $index++ ] );

			$item = [
				'path'            => $here,
				'kind'            => self::kind( $name ),
				'block'           => $name,
				'label'           => self::plain( (string) ( $attrs['label'] ?? '' ) ),
				'url'             => (string) ( $attrs['url'] ?? '' ),
				'open_in_new_tab' => ! empty( $attrs['opensInNewTab'] ),
			];

			if ( $name === 'core/home-link' && $item['url'] === '' ) {
				$item['url'] = home_url( '/' );
			}

			if ( isset( $attrs['id'] ) ) {
				$item['object_id'] = (int) $attrs['id'];
			}

			if ( $automatic ) {
				$item['automatic'] = true;
			}

			$children = self::items( (array) $block['innerBlocks'], $here, $automatic );

			if ( $name === self::PAGE_LIST ) {
				$links         = self::page_list_links( $attrs );
				$item['label'] = $links === null
					/* translators: %d: most pages listed */
					? sprintf( __( 'All published pages, listed automatically: more than %d, so they are not listed here', 'albert-ai-butler' ), self::MAX_PAGE_LIST )
					: __( 'All published pages, listed automatically', 'albert-ai-butler' );
				$children = $links === null ? [] : self::items( $links, $here, true );
			}

			$item['children'] = $children;
			$items[]          = $item;
		}

		return $items;
	}

	/**
	 * The link blocks a page list becomes, nested as it shows the pages.
	 *
	 * Mirrors core's page list render: published pages by menu order then
	 * title, from the top level or from `parentPageID` down.
	 *
	 * @param array<string, mixed> $attrs The page list's attributes.
	 *
	 * @return list<array<string, mixed>>|null Null when it shows more than {@see self::MAX_PAGE_LIST} pages; pages outside its `parentPageID` branch do not count.
	 * @since 1.6.0
	 */
	public static function page_list_links( array $attrs ): ?array {
		// The same query core's render runs, so the list matches what visitors see.
		$pages = get_pages(
			[
				'sort_column' => 'menu_order,post_title',
				'order'       => 'asc',
			]
		);

		$by_parent = [];
		foreach ( is_array( $pages ) ? $pages : [] as $page ) {
			$by_parent[ (int) $page->post_parent ][] = $page;
		}

		$parent_id = (int) ( $attrs['parentPageID'] ?? 0 );

		// Counted before any link is built, so a large site never gets a permalink per page.
		if ( self::count_pages( $by_parent, $parent_id ) > self::MAX_PAGE_LIST ) {
			return null;
		}

		return self::page_branch( $by_parent, $parent_id );
	}

	/**
	 * How many pages sit below a parent, at any depth.
	 *
	 * @param array<int, list<WP_Post>> $by_parent Pages keyed by parent id.
	 * @param int                       $parent_id Parent page id.
	 *
	 * @return int
	 * @since 1.6.0
	 */
	private static function count_pages( array $by_parent, int $parent_id ): int {
		$count = 0;

		foreach ( $by_parent[ $parent_id ] ?? [] as $page ) {
			$count += 1 + self::count_pages( $by_parent, (int) $page->ID );
		}

		return $count;
	}

	/**
	 * A new link block.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 *
	 * @return array<string, mixed> Parsed block.
	 * @since 1.6.0
	 */
	public static function link_block( array $attrs ): array {
		return [
			'blockName'    => self::LINK,
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
	}

	/**
	 * A label as the editor stores it: rich text, so `&` and `<` escaped.
	 *
	 * @param string $text Plain text.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	public static function label_markup( string $text ): string {
		return htmlspecialchars( $text, ENT_NOQUOTES, 'UTF-8', false );
	}

	/**
	 * A stored label as plain text.
	 *
	 * @param string $label Rich-text label.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	public static function plain( string $label ): string {
		return html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * One level of page links.
	 *
	 * Each page has one parent, so the recursion ends.
	 *
	 * @param array<int, list<WP_Post>> $by_parent Pages keyed by parent id.
	 * @param int                       $parent_id Parent page id.
	 *
	 * @return list<array<string, mixed>>
	 * @since 1.6.0
	 */
	private static function page_branch( array $by_parent, int $parent_id ): array {
		$links = [];

		foreach ( $by_parent[ $parent_id ] ?? [] as $page ) {
			$block    = self::link_block(
				[
					'label' => self::label_markup( $page->post_title ),
					'type'  => 'page',
					'id'    => (int) $page->ID,
					'url'   => (string) get_permalink( $page ),
					'kind'  => 'post-type',
				]
			);
			$children = self::page_branch( $by_parent, (int) $page->ID );

			if ( $children !== [] ) {
				$block['blockName']    = self::SUBMENU;
				$block['innerBlocks']  = $children;
				$block['innerContent'] = array_fill( 0, count( $children ), null );
			}

			$links[] = $block;
		}

		return $links;
	}

	/**
	 * The menu role of a block inside a navigation menu.
	 *
	 * @param string $name Block name.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	public static function kind( string $name ): string {
		return match ( $name ) {
			self::LINK      => 'link',
			self::SUBMENU   => 'submenu',
			self::PAGE_LIST => 'page-list',
			'core/home-link' => 'home-link',
			default         => 'block',
		};
	}
}
