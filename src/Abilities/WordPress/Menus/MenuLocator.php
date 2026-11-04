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
use WP_Post;
use WP_Query;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which menu visitors see, read-only.
 *
 * On a block theme the header is a navigation block in a template part, and it
 * shows a `wp_navigation` post, not a classic menu. A navigation block that
 * names no menu falls back to the most recently published one. Tools that edit
 * the classic menu there change something nobody sees, so every menu ability
 * asks this class first. It follows core's render rules
 * (`WP_Navigation_Block_Renderer`, `WP_Navigation_Fallback`) without calling
 * them, because core's fallback creates a post the first time it runs.
 *
 * @since 1.6.0
 */
class MenuLocator {

	/**
	 * How deep patterns and synced patterns are followed.
	 *
	 * @since 1.6.0
	 * @var int
	 */
	private const MAX_DEPTH = 8;

	/**
	 * Navigation placements, built once per instance.
	 *
	 * @since 1.6.0
	 * @var list<array{surface: array{type: string, slug: string, title: string, area: string}, in_use: bool, renders: array{type: string, id: int, fallback: bool, location: string}}>|null
	 */
	private ?array $placements = null;

	/**
	 * The navigation post core falls back to, once looked up.
	 *
	 * @since 1.6.0
	 * @var WP_Post|false|null
	 */
	private WP_Post|false|null $recent_navigation = null;

	/**
	 * Whether the active theme is a block theme.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	public function is_block_theme(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * Every navigation block in the active theme's templates and template parts.
	 *
	 * `in_use` says whether a template shows the part it sits in; `renders`
	 * says what that block puts on the page.
	 *
	 * @return list<array{surface: array{type: string, slug: string, title: string, area: string}, in_use: bool, renders: array{type: string, id: int, fallback: bool, location: string}}>
	 * @since 1.6.0
	 */
	public function placements(): array {
		if ( $this->placements !== null ) {
			return $this->placements;
		}

		$templates = function_exists( 'get_block_templates' ) ? get_block_templates( [], 'wp_template' ) : [];
		$parts     = function_exists( 'get_block_templates' ) ? get_block_templates( [], 'wp_template_part' ) : [];

		$found      = [];
		$used_parts = [];
		$part_scans = [];

		foreach ( $templates as $template ) {
			$scan       = $this->scan( (string) $template->content );
			$used_parts = array_merge( $used_parts, $scan['parts'] );

			foreach ( $scan['navs'] as $nav ) {
				$found[] = [ $this->surface( 'template', $template ), true, $nav ];
			}
		}

		foreach ( $parts as $part ) {
			$part_scans[ (string) $part->slug ] = $this->scan( (string) $part->content );
		}

		// A part can sit inside another part, so follow the parts in use until no new one turns up.
		$queue = array_unique( $used_parts );
		while ( $queue !== [] ) {
			$slug = (string) array_shift( $queue );
			foreach ( $part_scans[ $slug ]['parts'] ?? [] as $nested ) {
				if ( ! in_array( $nested, $used_parts, true ) ) {
					$used_parts[] = $nested;
					$queue[]      = $nested;
				}
			}
		}

		foreach ( $parts as $part ) {
			if ( $this->is_block_theme() ) {
				$in_use = $templates === [] || in_array( (string) $part->slug, $used_parts, true );
			} else {
				// A classic theme pulls parts in from PHP, which cannot be read here.
				$in_use = current_theme_supports( 'block-template-parts' );
			}

			foreach ( $part_scans[ (string) $part->slug ]['navs'] as $nav ) {
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
	 * What the site's header shows.
	 *
	 * @return array{type: string, id: int, name: string, source: string, fallback: bool, description: string}
	 * @since 1.6.0
	 */
	public function header(): array {
		return $this->is_block_theme() ? $this->block_theme_header() : $this->classic_theme_header();
	}

	/**
	 * Where visitors see a classic menu, as short phrases.
	 *
	 * @param int $menu_id Menu term id.
	 *
	 * @return list<string> Empty when visitors do not see it.
	 * @since 1.6.0
	 */
	public function classic_menu_shown_in( int $menu_id ): array {
		$where = [];

		if ( ! $this->is_block_theme() ) {
			$registered = get_registered_nav_menus();

			foreach ( get_nav_menu_locations() as $location => $assigned ) {
				if ( (int) $assigned === $menu_id && isset( $registered[ $location ] ) ) {
					/* translators: %s: menu location name */
					$where[] = sprintf( __( 'the "%s" menu location', 'albert-ai-butler' ), $registered[ $location ] );
				}
			}

			$where = array_merge( $where, $this->widgets_showing( $menu_id ) );
		}

		foreach ( $this->placements() as $placement ) {
			if ( $placement['in_use'] && $placement['renders']['type'] === 'classic' && $placement['renders']['id'] === $menu_id ) {
				$where[] = $this->surface_phrase( $placement['surface'] );
			}
		}

		return array_values( array_unique( $where ) );
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

		foreach ( $this->placements() as $placement ) {
			if ( $placement['in_use'] && $placement['renders']['type'] === 'block' && $placement['renders']['id'] === $post_id ) {
				$phrase = $this->surface_phrase( $placement['surface'] );
				if ( $placement['renders']['fallback'] ) {
					/* translators: %s: where the menu is shown, e.g. the "header" template part */
					$phrase = sprintf( __( '%s (as the fallback for a navigation block that names no menu)', 'albert-ai-butler' ), $phrase );
				}
				$where[] = $phrase;
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
			return sprintf(
				/* translators: %s: what the header shows */
				__( 'Visitors do not see this classic menu. This site uses a block theme, whose header is a navigation block, not a classic menu. %s Albert cannot edit navigation menus yet: the person can change it in Appearance > Editor > Navigation.', 'albert-ai-butler' ),
				$this->header()['description']
			);
		}

		return __( 'Visitors do not see this menu: it is not assigned to a menu location or used in a widget. Assign it with albert/assign-menu-location, or change the menu that is assigned instead.', 'albert-ai-butler' );
	}

	/**
	 * The header on a block theme: the first navigation block in a header part a template shows.
	 *
	 * @return array{type: string, id: int, name: string, source: string, fallback: bool, description: string}
	 * @since 1.6.0
	 */
	private function block_theme_header(): array {
		foreach ( $this->placements() as $placement ) {
			if ( ! $placement['in_use'] || $placement['surface']['area'] !== 'header' ) {
				continue;
			}

			$renders = $placement['renders'];
			$source  = $placement['surface']['slug'];

			return [
				'type'        => $renders['type'],
				'id'          => $renders['id'],
				'name'        => $this->menu_name( $renders['type'], $renders['id'] ),
				'source'      => $source,
				'fallback'    => $renders['fallback'],
				'description' => $this->describe_header( $renders, $source ),
			];
		}

		return [
			'type'        => 'none',
			'id'          => 0,
			'name'        => '',
			'source'      => '',
			'fallback'    => false,
			'description' => __( 'No navigation block was found in a header template part this theme shows.', 'albert-ai-butler' ),
		];
	}

	/**
	 * The header on a classic theme: the menu in the location it most likely shows.
	 *
	 * The theme's PHP decides which location the header renders, so this is the
	 * location named like a primary menu, or the first one registered.
	 *
	 * @return array{type: string, id: int, name: string, source: string, fallback: bool, description: string}
	 * @since 1.6.0
	 */
	private function classic_theme_header(): array {
		$registered = get_registered_nav_menus();
		$none       = [
			'type'     => 'none',
			'id'       => 0,
			'name'     => '',
			'source'   => '',
			'fallback' => false,
		];

		if ( $registered === [] ) {
			return $none + [ 'description' => __( 'This theme registers no menu locations, so there is no menu location to show a classic menu in.', 'albert-ai-butler' ) ];
		}

		$location = $this->primary_location( array_map( 'strval', array_keys( $registered ) ) );
		$assigned = get_nav_menu_locations();
		$menu     = isset( $assigned[ $location ] ) ? wp_get_nav_menu_object( (int) $assigned[ $location ] ) : false;

		if ( ! $menu instanceof WP_Term ) {
			return [ 'source' => $location ] + $none + [
				/* translators: %s: menu location name */
				'description' => sprintf( __( 'No menu is assigned to the "%s" location, the one this theme most likely shows in its header.', 'albert-ai-butler' ), $registered[ $location ] ),
			];
		}

		return [
			'type'        => 'classic',
			'id'          => (int) $menu->term_id,
			'name'        => $menu->name,
			'source'      => $location,
			'fallback'    => false,
			'description' => sprintf(
				/* translators: 1: menu name, 2: menu ID, 3: menu location name */
				__( 'The header most likely shows the classic menu "%1$s" (ID %2$d), assigned to the "%3$s" location.', 'albert-ai-butler' ),
				$menu->name,
				(int) $menu->term_id,
				$registered[ $location ]
			),
		];
	}

	/**
	 * The location a classic theme most likely renders in its header.
	 *
	 * @param list<string> $locations Registered location slugs, in registration order.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	private function primary_location( array $locations ): string {
		foreach ( [ 'primary', 'main', 'header', 'menu-1' ] as $slug ) {
			if ( in_array( $slug, $locations, true ) ) {
				return $slug;
			}
		}

		foreach ( $locations as $location ) {
			if ( preg_match( '/primary|main|header/i', $location ) ) {
				return $location;
			}
		}

		return $locations[0];
	}

	/**
	 * One sentence on what a header navigation block shows.
	 *
	 * @param array{type: string, id: int, fallback: bool, location: string} $renders What it renders.
	 * @param string                                                         $part    Template part slug.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	private function describe_header( array $renders, string $part ): string {
		$name = $this->menu_name( $renders['type'], $renders['id'] );

		switch ( $renders['type'] ) {
			case 'block':
				if ( $renders['fallback'] ) {
					/* translators: 1: menu title, 2: menu ID, 3: template part slug */
					$format = __( 'The navigation block in the "%3$s" template part names no menu, so WordPress shows the most recently published navigation menu: "%1$s" (ID %2$d).', 'albert-ai-butler' );
				} else {
					/* translators: 1: menu title, 2: menu ID, 3: template part slug */
					$format = __( 'The header shows the navigation menu "%1$s" (ID %2$d), from the "%3$s" template part.', 'albert-ai-butler' );
				}
				return sprintf( $format, $name, $renders['id'], $part );

			case 'classic':
				return sprintf(
					/* translators: 1: menu name, 2: menu ID, 3: menu location slug */
					__( 'The header shows the classic menu "%1$s" (ID %2$d), through the "%3$s" menu location.', 'albert-ai-butler' ),
					$name,
					$renders['id'],
					$renders['location']
				);

			case 'classic_copy':
				return sprintf(
					/* translators: 1: menu name, 2: menu ID */
					__( 'No navigation menu exists yet, so the first time the header is shown WordPress copies the classic menu "%1$s" (ID %2$d) into a new navigation menu. Changes to the classic menu after that do not reach the header.', 'albert-ai-butler' ),
					$name,
					$renders['id']
				);

			case 'inline':
				/* translators: %s: template part slug */
				return sprintf( __( 'The navigation block in the "%s" template part holds its links itself, not in a separate menu.', 'albert-ai-butler' ), $part );

			default:
				return __( 'The header lists the site\'s published pages automatically; there is no menu to edit.', 'albert-ai-butler' );
		}
	}

	/**
	 * A menu's display name.
	 *
	 * @param string $type What a navigation block renders.
	 * @param int    $id   Menu id.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	private function menu_name( string $type, int $id ): string {
		if ( $type === 'block' ) {
			return get_the_title( $id );
		}

		if ( $type === 'classic' || $type === 'classic_copy' ) {
			$menu = wp_get_nav_menu_object( $id );
			return $menu instanceof WP_Term ? $menu->name : '';
		}

		return '';
	}

	/**
	 * What one navigation block puts on the page, in core's order of precedence.
	 *
	 * @param array{ref: int, inline: bool, location: string} $nav The block's relevant attributes.
	 *
	 * @return array{type: string, id: int, fallback: bool, location: string}
	 * @since 1.6.0
	 */
	private function resolve( array $nav ): array {
		if ( $nav['ref'] > 0 ) {
			$post = get_post( $nav['ref'] );

			// Core renders only a published menu with blocks in it, and falls back otherwise.
			if ( $post instanceof WP_Post && $post->post_type === 'wp_navigation' && $post->post_status === 'publish' && $this->has_blocks( $post->post_content ) ) {
				return [
					'type'     => 'block',
					'id'       => (int) $post->ID,
					'fallback' => false,
					'location' => '',
				];
			}

			return $this->fallback();
		}

		// Core reads `__unstableLocation` only while the Gutenberg plugin is active.
		if ( $nav['location'] !== '' && defined( 'IS_GUTENBERG_PLUGIN' ) && IS_GUTENBERG_PLUGIN ) {
			$locations = get_nav_menu_locations();
			$menu_id   = (int) ( $locations[ $nav['location'] ] ?? 0 );

			if ( $menu_id > 0 && MenuSupport::classic_items( $menu_id ) !== [] ) {
				return [
					'type'     => 'classic',
					'id'       => $menu_id,
					'fallback' => false,
					'location' => $nav['location'],
				];
			}
		}

		if ( $nav['inline'] ) {
			return [
				'type'     => 'inline',
				'id'       => 0,
				'fallback' => false,
				'location' => '',
			];
		}

		return $this->fallback();
	}

	/**
	 * What a navigation block with nothing of its own shows.
	 *
	 * @return array{type: string, id: int, fallback: bool, location: string}
	 * @since 1.6.0
	 */
	private function fallback(): array {
		$recent = $this->recent_navigation();

		if ( $recent instanceof WP_Post ) {
			return [
				'type'     => 'block',
				'id'       => (int) $recent->ID,
				'fallback' => true,
				'location' => '',
			];
		}

		/** This filter is documented in wp-includes/class-wp-navigation-fallback.php */
		if ( apply_filters( 'wp_navigation_should_create_fallback', true ) ) {
			$classic = $this->fallback_classic_menu();

			// Core copies only a menu with published items, and lists the pages otherwise.
			if ( $classic instanceof WP_Term && ! empty( wp_get_nav_menu_items( $classic->term_id, [ 'update_post_term_cache' => false ] ) ) ) {
				return [
					'type'     => 'classic_copy',
					'id'       => (int) $classic->term_id,
					'fallback' => true,
					'location' => '',
				];
			}
		}

		return [
			'type'     => 'page_list',
			'id'       => 0,
			'fallback' => true,
			'location' => '',
		];
	}

	/**
	 * The most recently published navigation menu, as core picks it.
	 *
	 * @return WP_Post|null
	 * @since 1.6.0
	 */
	private function recent_navigation(): ?WP_Post {
		if ( $this->recent_navigation === null ) {
			$query = new WP_Query(
				[
					'post_type'              => 'wp_navigation',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'order'                  => 'DESC',
					'orderby'                => 'date',
					'post_status'            => 'publish',
					'posts_per_page'         => 1,
				]
			);

			$first                   = $query->posts[0] ?? null;
			$this->recent_navigation = $first instanceof WP_Post ? $first : false;
		}

		return $this->recent_navigation instanceof WP_Post ? $this->recent_navigation : null;
	}

	/**
	 * The classic menu core would copy into a first navigation menu.
	 *
	 * @return WP_Term|null
	 * @since 1.6.0
	 */
	private function fallback_classic_menu(): ?WP_Term {
		$menus = wp_get_nav_menus();

		if ( ! is_array( $menus ) || $menus === [] ) {
			return null;
		}

		$locations = get_nav_menu_locations();
		if ( isset( $locations['primary'] ) ) {
			$primary = wp_get_nav_menu_object( (int) $locations['primary'] );
			if ( $primary instanceof WP_Term ) {
				return $primary;
			}
		}

		foreach ( $menus as $menu ) {
			if ( $menu instanceof WP_Term && $menu->slug === 'primary' ) {
				return $menu;
			}
		}

		usort( $menus, static fn( WP_Term $a, WP_Term $b ): int => $b->term_id - $a->term_id );

		return $menus[0];
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

		$settings = get_option( 'widget_nav_menu', [] );
		$sidebars = function_exists( 'wp_get_sidebars_widgets' ) ? wp_get_sidebars_widgets() : [];
		$where    = [];

		if ( ! is_array( $settings ) ) {
			return [];
		}

		foreach ( $sidebars as $sidebar => $widget_ids ) {
			if ( $sidebar === 'wp_inactive_widgets' || ! is_array( $widget_ids ) || ! isset( $wp_registered_sidebars[ $sidebar ] ) ) {
				continue;
			}

			foreach ( $widget_ids as $widget_id ) {
				if ( ! is_string( $widget_id ) || ! preg_match( '/^nav_menu-(\d+)$/', $widget_id, $match ) ) {
					continue;
				}

				$instance = $settings[ (int) $match[1] ] ?? [];
				if ( is_array( $instance ) && (int) ( $instance['nav_menu'] ?? 0 ) === $menu_id ) {
					/* translators: %s: sidebar name */
					$where[] = sprintf( __( 'a Navigation Menu widget in the "%s" sidebar', 'albert-ai-butler' ), (string) ( $wp_registered_sidebars[ $sidebar ]['name'] ?? $sidebar ) );
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
	 * @return array{navs: list<array{ref: int, inline: bool, location: string}>, parts: list<string>}
	 * @since 1.6.0
	 */
	private function scan( string $content ): array {
		$found = [
			'navs'  => [],
			'parts' => [],
		];

		if ( str_contains( $content, '<!-- wp:' ) ) {
			$this->walk( parse_blocks( $content ), 0, $found );
		}

		return $found;
	}

	/**
	 * Collect navigation blocks and template parts, following patterns.
	 *
	 * Template parts are recorded, not entered: each part is scanned on its own.
	 *
	 * @param array<mixed>                                                                            $blocks Parsed blocks.
	 * @param int                                                                                     $depth  Pattern nesting so far.
	 * @param array{navs: list<array{ref: int, inline: bool, location: string}>, parts: list<string>} $found  Collected so far.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private function walk( array $blocks, int $depth, array &$found ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! is_string( $block['blockName'] ?? null ) ) {
				continue;
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			switch ( $block['blockName'] ) {
				case 'core/navigation':
					$found['navs'][] = [
						// Older blocks saved the reference as `navigationMenuId`; core still reads it, ahead of `ref`.
						'ref'      => (int) ( $attrs['navigationMenuId'] ?? $attrs['ref'] ?? 0 ),
						'inline'   => $this->has_blocks_in( $inner ),
						'location' => is_string( $attrs['__unstableLocation'] ?? null ) ? $attrs['__unstableLocation'] : '',
					];
					continue 2;

				case 'core/template-part':
					// Core renders nothing for a part that names another theme.
					$theme = $attrs['theme'] ?? get_stylesheet();
					if ( is_string( $attrs['slug'] ?? null ) && $attrs['slug'] !== '' && $theme === get_stylesheet() ) {
						$found['parts'][] = $attrs['slug'];
					}
					continue 2;

				case 'core/pattern':
					$slug = is_string( $attrs['slug'] ?? null ) ? $attrs['slug'] : '';
					if ( $slug !== '' && $depth < self::MAX_DEPTH ) {
						$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
						if ( is_array( $pattern ) && is_string( $pattern['content'] ?? null ) ) {
							$this->walk( parse_blocks( $pattern['content'] ), $depth + 1, $found );
						}
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

			$this->walk( $inner, $depth, $found );
		}
	}

	/**
	 * Whether markup holds at least one real block.
	 *
	 * @param string $content Block markup.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	private function has_blocks( string $content ): bool {
		return $this->has_blocks_in( parse_blocks( $content ) );
	}

	/**
	 * Whether a parsed list holds at least one named block, not only whitespace.
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	private function has_blocks_in( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) && is_string( $block['blockName'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A template or template part as the result describes it.
	 *
	 * @param string $type     `template` or `template_part`.
	 * @param object $template A `WP_Block_Template`.
	 *
	 * @return array{type: string, slug: string, title: string, area: string}
	 * @since 1.6.0
	 */
	private function surface( string $type, object $template ): array {
		return [
			'type'  => $type,
			'slug'  => (string) ( $template->slug ?? '' ),
			'title' => (string) ( $template->title ?? '' ),
			'area'  => $type === 'template_part' ? (string) ( $template->area ?? '' ) : '',
		];
	}

	/**
	 * A surface as a phrase.
	 *
	 * @param array{type: string, slug: string, title: string, area: string} $surface The surface.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	private function surface_phrase( array $surface ): string {
		if ( $surface['type'] === 'template' ) {
			/* translators: %s: template slug */
			return sprintf( __( 'the "%s" template', 'albert-ai-butler' ), $surface['slug'] );
		}

		/* translators: %s: template part slug */
		return sprintf( __( 'the "%s" template part', 'albert-ai-butler' ), $surface['slug'] );
	}
}
