<?php
/**
 * Move Menu Item Ability
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
 * Moves a menu item, with its sub-items, to a new parent or position in a classic or navigation menu.
 *
 * @since 1.6.0
 */
class MoveMenuItem extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/move-menu-item';
		$this->label       = __( 'Move Menu Item', 'albert-ai-butler' );
		$this->description = __( 'Move a menu item with its sub-items to a new place in the same menu; read the menu with albert/view-menu first.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update( MenuSupport::CHECK_FIRST . ' A classic item is named by menu_item_id; a navigation item by menu_type "block", menu_id and its path.' ),
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
		$navigation = MenuSupport::navigation_properties();
		$properties = [
			'menu_item_id'   => [
				'type'        => 'integer',
				'description' => 'Classic menus only: the menu item ID from albert/view-menu.',
				'minimum'     => 1,
			],
			'parent_item_id' => [
				'type'        => 'integer',
				'description' => 'Classic menus only: the item to nest it under, 0 for the top level. Leave it out to keep its parent.',
				'minimum'     => 0,
			],
			'menu_type'      => $navigation['menu_type'],
			'menu_id'        => [
				'type'        => 'integer',
				'description' => 'Navigation menus only: the menu ID from albert/find-menus.',
				'minimum'     => 1,
			],
			'path'           => $navigation['path'],
			'parent_path'    => array_merge(
				$navigation['parent_path'],
				[ 'description' => 'Navigation menus only: the "path" of the item to nest it under, [] for the top level. Leave it out to keep its parent.' ]
			),
			'position'       => [
				'type'        => 'integer',
				'description' => '1-based position among the parent\'s items. Default: last.',
				'minimum'     => 1,
			],
		];

		return [
			'type'        => 'object',
			'description' => 'Send a new parent, "position" or both.',
			'properties'  => $properties,
			'oneOf'       => [
				MenuSupport::menu_branch(
					'classic menu item by "menu_item_id"',
					$properties,
					[ 'menu_item_id', 'parent_item_id', 'position' ],
					'classic',
					[
						'required' => [ 'menu_item_id' ],
						'anyOf'    => [
							[ 'required' => [ 'parent_item_id' ] ],
							[ 'required' => [ 'position' ] ],
						],
					]
				),
				MenuSupport::menu_branch(
					'navigation menu item by "menu_id" and "path"',
					$properties,
					[ 'menu_type', 'menu_id', 'path', 'parent_path', 'position' ],
					'block',
					[
						'required' => [ 'menu_type', 'menu_id', 'path' ],
						'anyOf'    => [
							[ 'required' => [ 'parent_path' ] ],
							[ 'required' => [ 'position' ] ],
						],
					]
				),
			],
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
				'id'        => [
					'type'        => 'integer',
					'description' => 'Classic menus: the item\'s ID.',
				],
				'menu_id'   => [ 'type' => 'integer' ],
				'menu_type' => [
					'type' => 'string',
					'enum' => [ 'block' ],
				],
				'title'     => [ 'type' => 'string' ],
				'url'       => [ 'type' => 'string' ],
				'items'     => [
					'type'        => 'array',
					'description' => 'The menu\'s items after the move, as albert/view-menu returns them. Use these paths for the next change.',
					'items'       => [ 'type' => 'object' ],
				],
			] + MenuSupport::navigation_output_schema() + MenuSupport::visibility_schema(),
			'required'   => [ 'menu_id', 'items', 'visible', 'shown_in' ],
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
		if ( ( $args['menu_type'] ?? '' ) === 'block' ) {
			return $this->move_navigation_item( $args );
		}

		$post = MenuSupport::menu_item( (int) ( $args['menu_item_id'] ?? 0 ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$id      = (int) $post->ID;
		$menu_id = MenuSupport::menu_of_item( $id );
		$parent  = isset( $args['parent_item_id'] ) ? (int) $args['parent_item_id'] : (int) get_post_meta( $id, '_menu_item_menu_item_parent', true );

		// A stored parent outside the menu is shown at the top level, so that is where it stays.
		if ( ! isset( $args['parent_item_id'] ) && $parent > 0 && MenuSupport::menu_of_item( $parent ) !== $menu_id ) {
			$parent = 0;
		}

		$placed = MenuItemOrder::place( $menu_id, $id, $parent, (int) ( $args['position'] ?? 0 ) );
		if ( is_wp_error( $placed ) ) {
			return $placed;
		}

		return [
			'id'      => $id,
			'menu_id' => $menu_id,
			'items'   => MenuSupport::classic_tree( $menu_id ),
		] + MenuSupport::visibility_fields( $menu_id );
	}

	/**
	 * Move an item in a navigation menu.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	private function move_navigation_item( array $args ): array|WP_Error {
		$menu = MenuSupport::navigation_menu( (int) ( $args['menu_id'] ?? 0 ) );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$editor = new NavigationEditor();
		$opened = $editor->open_page_lists(
			NavigationWriter::tree( $menu ),
			[
				'item'   => MenuSupport::path( $args['path'] ?? [] ),
				'parent' => isset( $args['parent_path'] ) ? MenuSupport::path( $args['parent_path'] ) : null,
			]
		);
		if ( is_wp_error( $opened ) ) {
			return $opened;
		}

		[ $tree, $paths ] = $opened;

		$moved = $editor->move( $tree, (array) $paths['item'], $paths['parent'], (int) ( $args['position'] ?? 0 ) );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		return NavigationWriter::save( $menu, $moved[0], $moved[1], $editor->notices() );
	}
}
