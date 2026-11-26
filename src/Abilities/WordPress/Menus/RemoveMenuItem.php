<?php
/**
 * Remove Menu Item Ability
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
 * Removes an item from a classic or navigation menu; its sub-items move up a level.
 *
 * @since 1.6.0
 */
class RemoveMenuItem extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/remove-menu-item';
		$this->label       = __( 'Remove Menu Item', 'albert-ai-butler' );
		$this->description = __( 'Remove one item from a menu, moving its sub-items up a level without touching the linked content; read the menu with albert/view-menu first.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			// Destructive: menu items have no trash, and nothing can put one back yet.
			'annotations' => Annotations::delete( MenuSupport::CHECK_FIRST . ' A classic item is named by menu_item_id; a navigation item by menu_type "block", menu_id and its path.' ),
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
			'menu_item_id' => [
				'type'        => 'integer',
				'description' => 'Classic menus only: the menu item ID from albert/view-menu.',
				'minimum'     => 1,
			],
			'menu_type'    => $navigation['menu_type'],
			'menu_id'      => [
				'type'        => 'integer',
				'description' => 'Navigation menus only: the menu ID from albert/find-menus.',
				'minimum'     => 1,
			],
			'path'         => $navigation['path'],
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'oneOf'      => [
				MenuSupport::menu_branch( 'classic menu item by "menu_item_id"', $properties, [ 'menu_item_id' ], null, [ 'required' => [ 'menu_item_id' ] ] ),
				MenuSupport::menu_branch( 'navigation menu item by "menu_id" and "path"', $properties, [ 'menu_type', 'menu_id', 'path' ], 'block', [ 'required' => [ 'menu_type', 'menu_id', 'path' ] ] ),
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
					'description' => 'Classic menus: the removed item\'s ID.',
				],
				'menu_id'   => [
					'type'        => 'integer',
					'description' => 'The menu it was in, 0 for none. With 0 the result has no "visible" or "shown_in".',
				],
				'menu_type' => [
					'type' => 'string',
					'enum' => [ 'block' ],
				],
				'title'     => [ 'type' => 'string' ],
				'deleted'   => [ 'type' => 'boolean' ],
				'moved_up'  => [
					'type'        => 'array',
					'items'       => [ 'type' => [ 'integer', 'array' ] ],
					'description' => 'Sub-items that moved up to the removed item\'s level: item IDs in a classic menu, their new paths in a navigation menu.',
				],
			] + MenuSupport::navigation_output_schema() + MenuSupport::visibility_schema(),
			'required'   => [ 'menu_id', 'deleted', 'moved_up' ],
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
			return $this->remove_navigation_item( $args );
		}

		$post = MenuSupport::menu_item( (int) ( $args['menu_item_id'] ?? 0 ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$id      = (int) $post->ID;
		$menu_id = MenuSupport::menu_of_item( $id );
		$item    = wp_setup_nav_menu_item( $post );
		$parent  = (int) ( $item->menu_item_parent ?? 0 );

		// Core leaves sub-items pointing at a parent that no longer exists; wp-admin moves them up first.
		$moved = [];
		foreach ( MenuSupport::classic_items( $menu_id ) as $child ) {
			if ( (int) ( $child->menu_item_parent ?? 0 ) !== $id ) {
				continue;
			}

			$saved = MenuSupport::rest( 'POST', '/wp/v2/menu-items/' . (int) $child->ID, [ 'parent' => $parent ], __( 'A sub-item could not be moved up.', 'albert-ai-butler' ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$moved[] = (int) $child->ID;
		}

		// No rollback, as in add-menu-item: the sub-items have moved up, so a retry only deletes.
		$data = MenuSupport::rest( 'DELETE', '/wp/v2/menu-items/' . $id, [ 'force' => true ], __( 'The menu item could not be removed.', 'albert-ai-butler' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$result = [
			'id'       => $id,
			'menu_id'  => $menu_id,
			'title'    => (string) ( $item->title ?? '' ),
			'deleted'  => true,
			'moved_up' => $moved,
		];

		// An item in no menu was never shown, so there is no visibility to report.
		return $menu_id > 0 ? $result + MenuSupport::visibility_fields( $menu_id ) : $result;
	}

	/**
	 * Remove an item from a navigation menu.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	private function remove_navigation_item( array $args ): array|WP_Error {
		$menu = MenuSupport::navigation_menu( (int) ( $args['menu_id'] ?? 0 ) );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$editor = new NavigationEditor();
		$opened = $editor->open_page_lists( NavigationWriter::tree( $menu ), [ 'item' => MenuSupport::path( $args['path'] ?? [] ) ] );
		if ( is_wp_error( $opened ) ) {
			return $opened;
		}

		$removed = $editor->remove( $opened[0], (array) $opened[1]['item'] );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		[ $tree, $block, $moved_up ] = $removed;
		$label                       = (string) ( $block['attrs']['label'] ?? '' );

		return NavigationWriter::save(
			$menu,
			$tree,
			null,
			$editor->notices(),
			[
				'path'     => $opened[1]['item'],
				'title'    => NavigationTree::plain( $label ),
				'deleted'  => true,
				'moved_up' => $moved_up,
			]
		);
	}
}
