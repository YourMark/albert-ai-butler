<?php
/**
 * View Menu Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Menus
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Menus;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One menu's items as a tree, for a classic menu or a navigation menu.
 *
 * @since 1.6.0
 */
class ViewMenu extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/view-menu';
		$this->label       = __( 'View Menu', 'albert-ai-butler' );
		$this->description = __( 'Show one menu\'s items as a tree, for a classic menu or a block theme navigation menu, and whether visitors see it.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Pass the id and type from albert/find-menus. A classic menu and a navigation menu can share an id, so send "type" when you know it.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'   => [
					'type'        => 'integer',
					'description' => 'The menu ID from albert/find-menus.',
					'minimum'     => 1,
				],
				'type' => [
					'type'        => 'string',
					'enum'        => [ 'classic', 'block' ],
					'description' => '"classic" for a classic menu, "block" for a block theme navigation menu. Optional when only one kind has this ID.',
				],
			],
			'required'   => [ 'id' ],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'     => [ 'type' => 'integer' ],
				'type'   => [
					'type' => 'string',
					'enum' => [ 'classic', 'block' ],
				],
				'name'   => [ 'type' => 'string' ],
				'status' => [
					'type'        => 'string',
					'description' => 'A navigation menu\'s post status. Only a published one is shown.',
				],
				'items'  => [
					'type'        => 'array',
					'description' => 'Classic items have id, title, url, type, object, object_id, parent, position and children. Navigation items have path, kind (link, submenu, page-list, home-link or block), block, label, url and children.',
					'items'       => [ 'type' => 'object' ],
				],
			] + MenuSupport::visibility_schema(),
			'required'   => [ 'id', 'type', 'name', 'items', 'visible', 'shown_in' ],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.6.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( MenuSupport::CAPABILITY );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.6.0
	 */
	public function execute( array $args ): array|WP_Error {
		$id   = absint( $args['id'] ?? 0 );
		$type = is_string( $args['type'] ?? null ) ? $args['type'] : '';

		if ( $type === '' ) {
			$classic = MenuSupport::classic_menu( $id );
			$block   = MenuSupport::navigation_menu( $id );

			if ( ! is_wp_error( $classic ) && ! is_wp_error( $block ) ) {
				return new WP_Error(
					'menu_type_ambiguous',
					__( 'A classic menu and a navigation menu both have this ID. Send "type": "classic" or "block".', 'albert-ai-butler' ),
					[ 'status' => 400 ]
				);
			}

			$type = is_wp_error( $block ) ? 'classic' : 'block';
		}

		return $type === 'block' ? $this->view_navigation( $id ) : $this->view_classic( $id );
	}

	/**
	 * A classic menu.
	 *
	 * @param int $id Menu term id.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	private function view_classic( int $id ): array|WP_Error {
		$menu = MenuSupport::classic_menu( $id );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		return [
			'id'    => (int) $menu->term_id,
			'type'  => 'classic',
			'name'  => $menu->name,
			'items' => MenuSupport::classic_tree( (int) $menu->term_id ),
		] + MenuSupport::visibility_fields( (int) $menu->term_id );
	}

	/**
	 * A navigation menu, its blocks read as a tree.
	 *
	 * @param int $id Post id.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	private function view_navigation( int $id ): array|WP_Error {
		$post = MenuSupport::navigation_menu( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$shown_in = ( new MenuLocator() )->navigation_shown_in( (int) $post->ID );
		$result   = [
			'id'       => (int) $post->ID,
			'type'     => 'block',
			'name'     => get_the_title( $post ),
			'status'   => $post->post_status,
			'items'    => $this->navigation_tree( parse_blocks( $post->post_content ), [] ),
			'visible'  => $shown_in !== [],
			'shown_in' => $shown_in,
		];

		if ( $shown_in === [] ) {
			$result['notice'] = __( 'Visitors do not see this navigation menu: no navigation block in a template the theme uses shows it. Call albert/find-menus to see which menu the header shows.', 'albert-ai-butler' );
		}

		return $result;
	}

	/**
	 * Navigation blocks as menu items, with the block path a later edit can target.
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 * @param list<int>    $path   Path of the parent.
	 *
	 * @return list<array<string, mixed>>
	 * @since 1.6.0
	 */
	private function navigation_tree( array $blocks, array $path ): array {
		$items = [];
		$index = 0;

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! is_string( $block['blockName'] ?? null ) ) {
				continue;
			}

			$name  = $block['blockName'];
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			$here  = array_merge( $path, [ $index++ ] );
			$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];

			$item = [
				'path'  => $here,
				'kind'  => $this->kind( $name ),
				'block' => $name,
				'label' => wp_strip_all_tags( (string) ( $attrs['label'] ?? '' ) ),
				'url'   => (string) ( $attrs['url'] ?? '' ),
			];

			if ( $name === 'core/home-link' && $item['url'] === '' ) {
				$item['url'] = home_url( '/' );
			}

			if ( isset( $attrs['id'] ) ) {
				$item['object_id'] = (int) $attrs['id'];
			}

			if ( $name === 'core/page-list' ) {
				$item['label'] = __( 'All published pages, listed automatically', 'albert-ai-butler' );
			}

			$item['children'] = $this->navigation_tree( $inner, $here );
			$items[]          = $item;
		}

		return $items;
	}

	/**
	 * The menu role of a block inside a navigation menu.
	 *
	 * @param string $name Block name.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	private function kind( string $name ): string {
		return match ( $name ) {
			'core/navigation-link'    => 'link',
			'core/navigation-submenu' => 'submenu',
			'core/page-list'          => 'page-list',
			'core/home-link'          => 'home-link',
			default                   => 'block',
		};
	}
}
