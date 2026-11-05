<?php
/**
 * Delete Menu Ability
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
 * Permanently deletes a classic menu and its items.
 *
 * @since 1.6.0
 */
class DeleteMenu extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/delete-menu';
		$this->label       = __( 'Delete Menu', 'albert-ai-butler' );
		$this->description = __( 'Permanently delete a classic menu and all its items.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::delete(
				'This cannot be undone: menus have no trash. Check "visible" in albert/find-menus first, and tell the person when visitors see this menu.'
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
				'id' => [
					'type'        => 'integer',
					'description' => 'The classic menu ID to delete.',
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
				'id'          => [ 'type' => 'integer' ],
				'name'        => [ 'type' => 'string' ],
				'deleted'     => [ 'type' => 'boolean' ],
				'was_visible' => [
					'type'        => 'boolean',
					'description' => 'Whether visitors saw the menu before it was deleted.',
				],
			],
			'required'   => [ 'id', 'deleted', 'was_visible' ],
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
		$menu = MenuSupport::classic_menu( absint( $args['id'] ?? 0 ) );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$id          = (int) $menu->term_id;
		$was_visible = ( new MenuLocator() )->classic_menu_shown_in( $id ) !== [];

		$data = MenuSupport::rest( 'DELETE', '/wp/v2/menus/' . $id, [ 'force' => true ], __( 'The menu could not be deleted.', 'albert-ai-butler' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return [
			'id'          => $id,
			'name'        => $menu->name,
			'deleted'     => (bool) ( $data['deleted'] ?? true ),
			'was_visible' => $was_visible,
		];
	}
}
