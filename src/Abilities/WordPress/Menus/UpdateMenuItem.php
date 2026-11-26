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
 * Changes a menu item's label, link or link attributes, in a classic or navigation menu.
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
		$this->description = __( 'Change how one menu item reads or links, leaving out fields to keep them; to move it use albert/move-menu-item.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update( MenuSupport::CHECK_FIRST . ' A classic item is named by menu_item_id; a navigation item by menu_type "block", menu_id and its path. Only the fields you send change.' ),
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
			'menu_item_id'       => [
				'type'        => 'integer',
				'description' => 'Classic menus only: the menu item ID from albert/view-menu.',
				'minimum'     => 1,
			],
			'menu_type'          => $navigation['menu_type'],
			'menu_id'            => [
				'type'        => 'integer',
				'description' => 'Navigation menus only: the menu ID from albert/find-menus.',
				'minimum'     => 1,
			],
			'path'               => $navigation['path'],
			'title'              => [
				'type'        => 'string',
				'description' => 'The label visitors see.',
				'minLength'   => 1,
			],
			'use_original_title' => [
				'type'        => 'boolean',
				'enum'        => [ true ],
				'description' => 'Drop the item\'s own label so it shows the linked page\'s or term\'s current title; a navigation menu item takes that title as its label now. Not for a custom link, which has no linked title.',
			],
			'url'                => [
				'type'        => 'string',
				'description' => 'The address. Only a custom link has one of its own; in a navigation menu a new address turns a page or term link into a custom link.',
				'minLength'   => 1,
			],
			'attr_title'         => [
				'type'        => 'string',
				'description' => 'The title attribute, shown as a tooltip. An empty string removes it.',
			],
			'description'        => [
				'type'        => 'string',
				'description' => 'The item\'s description, which only some themes show under the label. An empty string removes it.',
			],
			'open_in_new_tab'    => [
				'type'        => 'boolean',
				'description' => 'True opens the link in a new tab, false in the same tab.',
			],
		];
		$fields     = [ 'title', 'use_original_title', 'url', 'attr_title', 'description', 'open_in_new_tab' ];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'oneOf'      => [
				MenuSupport::menu_branch(
					'classic menu item by "menu_item_id"',
					$properties,
					array_merge( [ 'menu_item_id' ], $fields ),
					null,
					[
						'required'      => [ 'menu_item_id' ],
						// Only `menu_item_id` and the fields are allowed, so this means at least one field.
						'minProperties' => 2,
					]
				),
				MenuSupport::menu_branch(
					'navigation menu item by "menu_id" and "path"',
					$properties,
					array_merge( [ 'menu_type', 'menu_id', 'path' ], $fields ),
					'block',
					[
						'required'      => [ 'menu_type', 'menu_id', 'path' ],
						'minProperties' => 4,
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
				'id'              => [
					'type'        => 'integer',
					'description' => 'Classic menus: the item\'s ID.',
				],
				'menu_id'         => [ 'type' => 'integer' ],
				'menu_type'       => [
					'type' => 'string',
					'enum' => [ 'block' ],
				],
				'title'           => [ 'type' => 'string' ],
				'url'             => [ 'type' => 'string' ],
				'open_in_new_tab' => [ 'type' => 'boolean' ],
			] + MenuSupport::navigation_output_schema() + MenuSupport::visibility_schema(),
			'required'   => [ 'menu_id', 'visible', 'shown_in' ],
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
		if ( isset( $args['title'] ) && ! empty( $args['use_original_title'] ) ) {
			return new WP_Error(
				'albert_invalid_use_original_title',
				__( 'Send "title" or "use_original_title", not both.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		if ( ( $args['menu_type'] ?? '' ) === 'block' ) {
			return $this->update_navigation_item( $args );
		}

		$item = MenuSupport::menu_item( (int) ( $args['menu_item_id'] ?? 0 ) );
		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$id     = (int) $item->ID;
		$params = [];

		if ( isset( $args['title'] ) ) {
			$params['title'] = sanitize_text_field( (string) $args['title'] );
			if ( $params['title'] === '' ) {
				return new WP_Error(
					'albert_invalid_title',
					__( 'The "title" is empty once HTML and extra spaces are removed. Send the label as plain text.', 'albert-ai-butler' ),
					[ 'status' => 400 ]
				);
			}
		}
		if ( ! empty( $args['use_original_title'] ) ) {
			// An empty title is how core stores "use the linked item's title".
			$params['title'] = '';
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
		if ( isset( $args['open_in_new_tab'] ) ) {
			$params['target'] = $args['open_in_new_tab'] ? '_blank' : '';
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

	/**
	 * Change a link or submenu in a navigation menu.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	private function update_navigation_item( array $args ): array|WP_Error {
		$menu = MenuSupport::navigation_menu( (int) ( $args['menu_id'] ?? 0 ) );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$editor = new NavigationEditor();
		$opened = $editor->open_page_lists( NavigationWriter::tree( $menu ), [ 'item' => MenuSupport::path( $args['path'] ?? [] ) ] );
		if ( is_wp_error( $opened ) ) {
			return $opened;
		}

		[ $tree, $paths ] = $opened;
		$path             = (array) $paths['item'];

		$link = $editor->link_at( $tree, $path );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		$changes = NavigationWriter::changes( (array) $link['attrs'], $args );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}

		$tree = $editor->update( $tree, $path, $changes );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		return NavigationWriter::save( $menu, $tree, $path, $editor->notices() );
	}
}
