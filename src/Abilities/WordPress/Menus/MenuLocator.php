<?php
/**
 * Where the site shows its menus.
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Menus
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Menus;

use WP_Block_Patterns_Registry;
use WP_Block_Template;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which menu visitors see, read-only.
 *
 * On a block theme the header is a navigation block in a template part, and it
 * shows a `wp_navigation` post, not a classic menu. Tools that edit the classic
 * menu there change something nobody sees, so every menu ability asks this
 * class first. It reads the templates the way core renders them, without
 * calling core's fallback, which creates a post the first time it runs.
 *
 * @since 1.6.0
 */
class MenuLocator {

	/**
	 * How deep patterns are followed; a synced pattern can include itself.
	 *
	 * @since 1.6.0
	 * @var int
	 */
	private const MAX_DEPTH = 8;

	/**
	 * Navigation placements, built once per instance.
	 *
	 * @since 1.6.0
	 * @var list<array{surface: array{type: string, slug: string, title: string, area: string}, in_use: bool, renders: array{type: string, id: int, fallback: bool}}>|null
	 */
	private ?array $placements = null;

	/**
	 * The most recently published navigation post id, 0 for none, once looked up.
	 *
	 * @since 1.6.0
	 * @var int|null
	 */
	private ?int $recent_navigation = null;

	/**
	 * Whether the active theme is a block theme.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	public function is_block_theme(): bool {
		return wp_is_block_theme();
	}

	/**
	 * Every navigation block in the active theme's templates and template parts.
	 *
	 * `in_use` says whether a template shows the part it sits in; `renders`
	 * says what that block puts on the page.
	 *
	 * @return list<array{surface: array{type: string, slug: string, title: string, area: string}, in_use: bool, renders: array{type: string, id: int, fallback: bool}}>
	 * @since 1.6.0
	 */
	public function placements(): array {
		if ( $this->placements !== null ) {
			return $this->placements;
		}

		$templates  = get_block_templates( [], 'wp_template' );
		$parts      = get_block_templates( [], 'wp_template_part' );
		$found      = [];
		$used_parts = [];
		$part_scans = [];

		foreach ( $templates as $template ) {
			$scan       = $this->scan( $template->content );
			$used_parts = array_merge( $used_parts, $scan['parts'] );

			foreach ( $scan['navs'] as $nav ) {
				$found[] = [ $this->surface( 'template', $template ), true, $nav ];
			}
		}

		foreach ( $parts as $part ) {
			$part_scans[ $part->slug ] = $this->scan( $part->content );
		}

		$used_parts = $this->with_nested_parts( $used_parts, $part_scans );

		foreach ( $parts as $part ) {
			if ( $this->is_block_theme() ) {
				$in_use = $templates === [] || in_array( $part->slug, $used_parts, true );
			} else {
				// A classic theme pulls parts in from PHP, which cannot be read here.
				$in_use = current_theme_supports( 'block-template-parts' );
			}

			foreach ( $part_scans[ $part->slug ]['navs'] as $nav ) {
				$found[] = [ $this->surface( 'template_part', $part ), $in_use, $nav ];
			}
		}

		$this->placements = array_map(
			fn( array $entry ): array => [
				'surface' => $entry[0],
				'in_use'  => $entry[1],
				'renders' => $this->resolve( $entry[2] ),
			],
			$found
		);

		return $this->placements;
	}

	/**
	 * What a block theme's header shows: the first navigation block in a header part in use.
	 *
	 * A classic theme's PHP decides which location its header shows, so there it is always `none`.
	 *
	 * @return array{type: string, id: int, name: string, source: string, fallback: bool}
	 * @since 1.6.0
	 */
	public function header(): array {
		$placements = $this->is_block_theme() ? $this->placements() : [];

		foreach ( $placements as $placement ) {
			if ( $placement['in_use'] && $placement['surface']['area'] === 'header' ) {
				$renders = $placement['renders'];

				return [
					'type'     => $renders['type'],
					'id'       => $renders['id'],
					'name'     => $renders['type'] === 'block' ? (string) get_post_field( 'post_title', $renders['id'] ) : '',
					'source'   => $placement['surface']['slug'],
					'fallback' => $renders['fallback'],
				];
			}
		}

		return [
			'type'     => 'none',
			'id'       => 0,
			'name'     => '',
			'source'   => '',
			'fallback' => false,
		];
	}

	/**
	 * Where visitors see a classic menu, as short phrases: its locations and widgets on a classic theme.
	 *
	 * @param int $menu_id Menu term id.
	 *
	 * @return list<string> Empty when visitors do not see it, and always on a block theme.
	 * @since 1.6.0
	 */
	public function classic_menu_shown_in( int $menu_id ): array {
		if ( $this->is_block_theme() ) {
			return [];
		}

		$where      = [];
		$registered = get_registered_nav_menus();

		foreach ( MenuSupport::locations_of( get_nav_menu_locations(), $menu_id ) as $location ) {
			if ( isset( $registered[ $location ] ) ) {
				/* translators: %s: menu location name */
				$where[] = sprintf( __( 'the "%s" menu location', 'albert-ai-butler' ), $registered[ $location ] );
			}
		}

		return array_merge( $where, $this->widgets_showing( $menu_id ) );
	}

	/**
	 * Where visitors see a navigation menu, as short phrases.
	 *
	 * @param int $post_id `wp_navigation` post id.
	 *
	 * @return list<string> Empty when visitors do not see it.
	 * @since 1.6.0
	 */
	public function navigation_shown_in( int $post_id ): array {
		$where = [];

		foreach ( $this->navigation_used_in( $post_id ) as $used ) {
			if ( $used['in_use'] ) {
				$where[] = $used['type'] === 'template'
					/* translators: %s: template slug */
					? sprintf( __( 'the "%s" template', 'albert-ai-butler' ), $used['slug'] )
					/* translators: %s: template part slug */
					: sprintf( __( 'the "%s" template part', 'albert-ai-butler' ), $used['slug'] );
			}
		}

		return array_values( array_unique( $where ) );
	}

	/**
	 * Every template part and template a navigation menu is placed in, shown or not.
	 *
	 * @param int $post_id `wp_navigation` post id.
	 *
	 * @return list<array{type: string, slug: string, title: string, area: string, in_use: bool, fallback: bool}>
	 * @since 1.6.0
	 */
	public function navigation_used_in( int $post_id ): array {
		$used = [];

		foreach ( $this->placements() as $placement ) {
			if ( $placement['renders']['type'] === 'block' && $placement['renders']['id'] === $post_id ) {
				$used[] = $placement['surface'] + [
					'in_use'   => $placement['in_use'],
					'fallback' => $placement['renders']['fallback'],
				];
			}
		}

		return $used;
	}

	/**
	 * What to tell the person when a classic menu they changed is not shown.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	public function classic_menu_hidden_notice(): string {
		if ( $this->is_block_theme() ) {
			return __( 'Visitors do not see this classic menu: this site uses a block theme, whose header is a navigation block. Call albert/find-menus to see which navigation menu the header shows, and change that one with menu_type "block".', 'albert-ai-butler' );
		}

		return __( 'Visitors do not see this menu: it is not assigned to a menu location or used in a widget. Assign it with albert/assign-menu-location, or change the menu that is assigned instead.', 'albert-ai-butler' );
	}

	/**
	 * What to tell the person about where a navigation menu shows, or '' when nothing needs saying.
	 *
	 * @param int          $post_id  `wp_navigation` post id.
	 * @param list<string> $shown_in Where it shows, from {@see self::navigation_shown_in()}.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	public function navigation_notice( int $post_id, array $shown_in ): string {
		if ( $shown_in === [] ) {
			$notice = __( 'Visitors do not see this navigation menu: no navigation block in a template the theme uses shows it. Call albert/find-menus to see which menu the header shows.', 'albert-ai-butler' );

			if ( $this->header()['type'] === 'inline' ) {
				$notice .= ' ' . __( 'The header keeps its links inside its template part instead of a navigation menu; Albert cannot change those; the person can edit the header in Appearance > Editor.', 'albert-ai-butler' );
			}

			return $notice;
		}

		foreach ( $this->navigation_used_in( $post_id ) as $used ) {
			if ( $used['in_use'] && $used['fallback'] ) {
				return __( 'The site shows this menu only because its navigation block names no menu and this is the newest published navigation menu. Publishing another navigation menu would take its place.', 'albert-ai-butler' );
			}
		}

		return '';
	}

	/**
	 * The classic menu WordPress copies into the first navigation menu, as core's fallback picks it.
	 *
	 * Only meaningful while no navigation menu is published: the primary
	 * location's menu, else one with the slug "primary", else the newest.
	 *
	 * @return array{id: int, name: string}|null Null when there is none, or when the pick is
	 *                                           empty: core then refuses to convert it and lists the pages.
	 * @since 1.6.0
	 */
	public function classic_fallback_source(): ?array {
		$menus = wp_get_nav_menus();
		if ( $menus === [] ) {
			return null;
		}

		$primary = (int) ( get_nav_menu_locations()['primary'] ?? 0 );
		$pick    = $primary > 0 ? MenuSupport::menu_reference( $primary ) : null;

		foreach ( $menus as $menu ) {
			if ( $pick === null && $menu->slug === 'primary' ) {
				$pick = MenuSupport::menu_reference( (int) $menu->term_id );
				break;
			}
		}

		if ( $pick === null ) {
			usort( $menus, static fn( $a, $b ): int => (int) $b->term_id - (int) $a->term_id );
			$pick = MenuSupport::menu_reference( (int) $menus[0]->term_id );
		}

		return $pick !== null && MenuSupport::classic_items( $pick['id'] ) !== [] ? $pick : null;
	}

	/**
	 * Part slugs plus every part nested inside them.
	 *
	 * @param list<string>                              $parts      Starting part slugs.
	 * @param array<string, array{parts: list<string>}> $part_scans Each part's scan, by slug.
	 *
	 * @return list<string>
	 * @since 1.6.0
	 */
	private function with_nested_parts( array $parts, array $part_scans ): array {
		$parts = array_values( array_unique( $parts ) );
		$queue = $parts;

		while ( $queue !== [] ) {
			$slug = array_shift( $queue );
			foreach ( $part_scans[ $slug ]['parts'] ?? [] as $nested ) {
				if ( ! in_array( $nested, $parts, true ) ) {
					$parts[] = $nested;
					$queue[] = $nested;
				}
			}
		}

		return $parts;
	}

	/**
	 * What one navigation block puts on the page, in core's order of precedence.
	 *
	 * @param array{ref: int, inline: bool} $nav The block's relevant attributes.
	 *
	 * @return array{type: string, id: int, fallback: bool}
	 * @since 1.6.0
	 */
	private function resolve( array $nav ): array {
		if ( $nav['ref'] > 0 ) {
			$post = get_post( $nav['ref'] );

			// Core renders only a published menu with blocks in it, and falls back otherwise.
			if ( $post instanceof WP_Post && $post->post_type === 'wp_navigation' && $post->post_status === 'publish' && $this->has_blocks_in( parse_blocks( $post->post_content ) ) ) {
				return [
					'type'     => 'block',
					'id'       => (int) $post->ID,
					'fallback' => false,
				];
			}
		} elseif ( $nav['inline'] ) {
			return [
				'type'     => 'inline',
				'id'       => 0,
				'fallback' => false,
			];
		}

		// Core shows the newest published navigation menu, or creates one when there is none.
		$recent = $this->recent_navigation();

		return [
			'type'     => $recent > 0 ? 'block' : 'none',
			'id'       => $recent,
			'fallback' => true,
		];
	}

	/**
	 * The most recently published navigation menu, as core's fallback picks it.
	 *
	 * @return int Post id, 0 for none.
	 * @since 1.6.0
	 */
	private function recent_navigation(): int {
		if ( $this->recent_navigation === null ) {
			$ids = get_posts(
				[
					'post_type'      => 'wp_navigation',
					'post_status'    => 'publish',
					'orderby'        => 'date',
					'order'          => 'DESC',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				]
			);

			$this->recent_navigation = (int) ( $ids[0] ?? 0 );
		}

		return $this->recent_navigation;
	}

	/**
	 * Legacy Navigation Menu widgets that show a menu, on a classic theme.
	 *
	 * @param int $menu_id Menu term id.
	 *
	 * @return list<string>
	 * @since 1.6.0
	 */
	private function widgets_showing( int $menu_id ): array {
		global $wp_registered_sidebars;

		$settings = (array) get_option( 'widget_nav_menu', [] );
		$where    = [];

		foreach ( wp_get_sidebars_widgets() as $sidebar => $widget_ids ) {
			if ( $sidebar === 'wp_inactive_widgets' || ! isset( $wp_registered_sidebars[ $sidebar ] ) ) {
				continue;
			}

			foreach ( (array) $widget_ids as $widget_id ) {
				if ( preg_match( '/^nav_menu-(\d+)$/', (string) $widget_id, $match ) && (int) ( $settings[ (int) $match[1] ]['nav_menu'] ?? 0 ) === $menu_id ) {
					/* translators: %s: sidebar name */
					$where[] = sprintf( __( 'a Navigation Menu widget in the "%s" sidebar', 'albert-ai-butler' ), (string) $wp_registered_sidebars[ $sidebar ]['name'] );
				}
			}
		}

		return $where;
	}

	/**
	 * The navigation blocks and template parts in some block markup.
	 *
	 * @param string $content Block markup.
	 *
	 * @return array{navs: list<array{ref: int, inline: bool}>, parts: list<string>}
	 * @since 1.6.0
	 */
	private function scan( string $content ): array {
		$found = [
			'navs'  => [],
			'parts' => [],
		];

		$this->walk( parse_blocks( $content ), 0, $found );

		return $found;
	}

	/**
	 * Collect navigation blocks and template parts, following patterns.
	 *
	 * Template parts are recorded, not entered: each part is scanned on its own.
	 *
	 * @param array<array<string, mixed>>                                           $blocks Parsed blocks.
	 * @param int                                                                   $depth  Pattern nesting so far.
	 * @param array{navs: list<array{ref: int, inline: bool}>, parts: list<string>} $found  Collected so far.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private function walk( array $blocks, int $depth, array &$found ): void {
		foreach ( $blocks as $block ) {
			$attrs = $block['attrs'];

			switch ( $block['blockName'] ) {
				case 'core/navigation':
					$found['navs'][] = [
						// Older blocks saved the reference as `navigationMenuId`; core still reads it, ahead of `ref`.
						'ref'    => (int) ( $attrs['navigationMenuId'] ?? $attrs['ref'] ?? 0 ),
						'inline' => $this->has_blocks_in( $block['innerBlocks'] ),
					];
					continue 2;

				case 'core/template-part':
					// Core renders nothing for a part that names another theme.
					$slug = (string) ( $attrs['slug'] ?? '' );
					if ( $slug !== '' && ( $attrs['theme'] ?? get_stylesheet() ) === get_stylesheet() ) {
						$found['parts'][] = $slug;
					}
					continue 2;

				case 'core/pattern':
					$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( (string) ( $attrs['slug'] ?? '' ) );
					if ( $pattern !== null && $depth < self::MAX_DEPTH ) {
						$this->walk( parse_blocks( (string) $pattern['content'] ), $depth + 1, $found );
					}
					continue 2;

				case 'core/block':
					$synced = get_post( (int) ( $attrs['ref'] ?? 0 ) );
					// Core renders a synced pattern only when it is published without a password.
					if ( $synced instanceof WP_Post && $synced->post_type === 'wp_block' && $synced->post_status === 'publish' && $synced->post_password === '' && $depth < self::MAX_DEPTH ) {
						$this->walk( parse_blocks( $synced->post_content ), $depth + 1, $found );
					}
					continue 2;
			}

			$this->walk( $block['innerBlocks'], $depth, $found );
		}
	}

	/**
	 * Whether a parsed list holds at least one named block, not only whitespace.
	 *
	 * @param array<array<string, mixed>> $blocks Parsed blocks.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	private function has_blocks_in( array $blocks ): bool {
		return in_array( true, array_map( static fn( array $block ): bool => $block['blockName'] !== null, $blocks ), true );
	}

	/**
	 * A template or template part as the result describes it.
	 *
	 * @param string            $type     `template` or `template_part`.
	 * @param WP_Block_Template $template The template.
	 *
	 * @return array{type: string, slug: string, title: string, area: string}
	 * @since 1.6.0
	 */
	private function surface( string $type, WP_Block_Template $template ): array {
		return [
			'type'  => $type,
			'slug'  => $template->slug,
			'title' => $template->title,
			'area'  => $type === 'template_part' ? (string) $template->area : '',
		];
	}
}
