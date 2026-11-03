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
 * Removes an item from a classic menu; its sub-items move up a level.
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
		$this->description = __( 'Remove an item from a classic menu. Its sub-items stay and move up a level. The linked page or post is not touched.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			// Destructive: menu items have no trash, and nothing can put one back yet.
			'annotations' => Annotations::delete( MenuSupport::CHECK_FIRST ),
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
				'id' => [
					'type'        => 'integer',
					'description' => 'The menu item ID, from albert/view-menu.',
					'minimum'     => 1,
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
				'id'       => [ 'type' => 'integer' ],
				'menu_id'  => [ 'type' => 'integer' ],
				'title'    => [ 'type' => 'string' ],
				'deleted'  => [ 'type' => 'boolean' ],
				'moved_up' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => 'Sub-items that moved up to the removed item\'s level.',
				],
			] + MenuSupport::visibility_schema(),
			'required'   => [ 'id', 'menu_id', 'deleted', 'moved_up', 'visible', 'shown_in' ],
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
		$post = MenuSupport::menu_item( absint( $args['id'] ?? 0 ) );
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

		$data = MenuSupport::rest( 'DELETE', '/wp/v2/menu-items/' . $id, [ 'force' => true ], __( 'The menu item could not be removed.', 'albert-ai-butler' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return [
			'id'       => $id,
			'menu_id'  => $menu_id,
			'title'    => (string) ( $item->title ?? '' ),
			'deleted'  => true,
			'moved_up' => $moved,
		] + MenuSupport::visibility_fields( $menu_id );
	}
}
