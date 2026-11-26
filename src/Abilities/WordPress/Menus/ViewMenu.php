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
		$this->description = __( 'Read one menu\'s items as a tree, with where visitors see it. Call albert/find-menus first for its ID.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Pass menu_id and menu_type from albert/find-menus: a classic menu and a navigation menu can share an ID. The menu item abilities take a classic item\'s "id" as menu_item_id, and a navigation item\'s "path" as path.'
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
				'menu_id'   => [
					'type'        => 'integer',
					'description' => 'The menu ID from albert/find-menus.',
					'minimum'     => 1,
				],
				'menu_type' => [
					'type'        => 'string',
					'enum'        => [ 'classic', 'block' ],
					'description' => '"classic" for a menu from "classic_menus" in albert/find-menus, "block" for one from "navigation_menus".',
				],
			],
			'required'   => [ 'menu_id', 'menu_type' ],
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
					'description' => 'Classic items have id, title, url, type, object, object_id, parent, position and children. Navigation items have path, kind (link, submenu, page-list, home-link or block), block, label, url, open_in_new_tab and children. A page-list item\'s children are the pages it lists now, marked "automatic": changing, moving or removing one turns the list into separate links first, so new pages stop appearing by themselves.',
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
		$id = (int) ( $args['menu_id'] ?? 0 );

		return ( $args['menu_type'] ?? '' ) === 'block' ? $this->view_navigation( $id ) : $this->view_classic( $id );
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

		return [
			'id'     => (int) $post->ID,
			'type'   => 'block',
			'name'   => get_the_title( $post ),
			'status' => $post->post_status,
			'items'  => NavigationTree::items( NavigationWriter::tree( $post ) ),
		] + MenuSupport::navigation_visibility_fields( (int) $post->ID );
	}
}
