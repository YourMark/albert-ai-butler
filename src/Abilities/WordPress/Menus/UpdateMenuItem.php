<?php
/**
 * Update Menu Item Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Menus
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Menus;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Changes a classic menu item's label, link or attributes.
 *
 * @since 1.6.0
 */
class UpdateMenuItem extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/update-menu-item';
		$this->label       = __( 'Update Menu Item', 'albert-ai-butler' );
		$this->description = __( 'Change a classic menu item\'s label, link, tooltip, CSS classes, description or new-tab setting. To move it, use albert/move-menu-item.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update( MenuSupport::CHECK_FIRST . ' Only the fields you send change.' ),
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
				'id'              => [
					'type'        => 'integer',
					'description' => 'The menu item ID, from albert/view-menu.',
					'minimum'     => 1,
				],
				'title'           => [
					'type'        => 'string',
					'description' => 'The label. Empty uses the linked page\'s or term\'s own title.',
				],
				'url'             => [
					'type'        => 'string',
					'description' => 'The address. Only a custom link has one of its own.',
				],
				'attr_title'      => [
					'type'        => 'string',
					'description' => 'The title attribute (tooltip).',
				],
				'description'     => [ 'type' => 'string' ],
				'classes'         => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'CSS classes. Replaces the current list.',
				],
				'open_in_new_tab' => [ 'type' => 'boolean' ],
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
				'id'              => [ 'type' => 'integer' ],
				'menu_id'         => [ 'type' => 'integer' ],
				'title'           => [ 'type' => 'string' ],
				'url'             => [ 'type' => 'string' ],
				'open_in_new_tab' => [ 'type' => 'boolean' ],
			] + MenuSupport::visibility_schema(),
			'required'   => [ 'id', 'menu_id', 'visible', 'shown_in' ],
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
		$item = MenuSupport::menu_item( absint( $args['id'] ?? 0 ) );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$id     = (int) $item->ID;
		$params = [];

		if ( isset( $args['title'] ) ) {
			$params['title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( isset( $args['url'] ) ) {
			$params['url'] = esc_url_raw( (string) $args['url'] );
		}
		if ( isset( $args['attr_title'] ) ) {
			$params['attr_title'] = sanitize_text_field( (string) $args['attr_title'] );
		}
		if ( isset( $args['description'] ) ) {
			$params['description'] = sanitize_textarea_field( (string) $args['description'] );
		}
		if ( isset( $args['classes'] ) && is_array( $args['classes'] ) ) {
			$params['classes'] = array_values( array_map( 'sanitize_html_class', array_filter( $args['classes'], 'is_string' ) ) );
		}
		if ( isset( $args['open_in_new_tab'] ) ) {
			$params['target'] = $args['open_in_new_tab'] ? '_blank' : '';
		}

		if ( $params === [] ) {
			return new WP_Error( 'nothing_to_update', __( 'Send at least one field to change.', 'albert-ai-butler' ), [ 'status' => 400 ] );
		}

		$data = MenuSupport::rest( 'POST', '/wp/v2/menu-items/' . $id, $params, __( 'The menu item could not be updated.', 'albert-ai-butler' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$menu_id = MenuSupport::menu_of_item( $id );
		$post    = get_post( $id );
		$updated = $post instanceof WP_Post ? wp_setup_nav_menu_item( $post ) : null;

		return [
			'id'              => $id,
			'menu_id'         => $menu_id,
			'title'           => (string) ( $updated->title ?? '' ),
			'url'             => (string) ( $updated->url ?? '' ),
			'open_in_new_tab' => ( $updated->target ?? '' ) === '_blank',
		] + MenuSupport::visibility_fields( $menu_id );
	}
}
