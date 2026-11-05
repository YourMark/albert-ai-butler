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
 * Moves a classic menu item, with its sub-items, to a new parent or position.
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
		$this->description = __( 'Move a classic menu item, with its sub-items, to another position or under another item in the same menu.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update( MenuSupport::CHECK_FIRST ),
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
				'id'       => [
					'type'        => 'integer',
					'description' => 'The menu item ID, from albert/view-menu.',
					'minimum'     => 1,
				],
				'parent'   => [
					'type'        => 'integer',
					'description' => 'The item to nest it under, 0 for the top level. Default: where it is now.',
					'minimum'     => 0,
				],
				'position' => [
					'type'        => 'integer',
					'description' => '1-based position among the parent\'s items. Default: last.',
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
				'id'      => [ 'type' => 'integer' ],
				'menu_id' => [ 'type' => 'integer' ],
				'items'   => [
					'type'        => 'array',
					'description' => 'The menu\'s items after the move, as albert/view-menu returns them.',
					'items'       => [ 'type' => 'object' ],
				],
			] + MenuSupport::visibility_schema(),
			'required'   => [ 'id', 'menu_id', 'items', 'visible', 'shown_in' ],
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

		if ( ! isset( $args['parent'] ) && ! isset( $args['position'] ) ) {
			return new WP_Error( 'nothing_to_update', __( 'Send "parent", "position" or both.', 'albert-ai-butler' ), [ 'status' => 400 ] );
		}

		$id      = (int) $post->ID;
		$menu_id = MenuSupport::menu_of_item( $id );
		$parent  = isset( $args['parent'] ) ? absint( $args['parent'] ) : (int) get_post_meta( $id, '_menu_item_menu_item_parent', true );

		// A stored parent outside the menu is shown at the top level, so that is where it stays.
		if ( ! isset( $args['parent'] ) && $parent > 0 && MenuSupport::menu_of_item( $parent ) !== $menu_id ) {
			$parent = 0;
		}

		$placed = MenuItemOrder::place( $menu_id, $id, $parent, absint( $args['position'] ?? 0 ) );
		if ( is_wp_error( $placed ) ) {
			return $placed;
		}

		return [
			'id'      => $id,
			'menu_id' => $menu_id,
			'items'   => MenuSupport::classic_tree( $menu_id ),
		] + MenuSupport::visibility_fields( $menu_id );
	}
}
