<?php
/**
 * Add Menu Item Ability
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
 * Adds a link, page, post, category or archive to a classic menu.
 *
 * @since 1.6.0
 */
class AddMenuItem extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/add-menu-item';
		$this->label       = __( 'Add Menu Item', 'albert-ai-butler' );
		$this->description = __( 'Add one link to a classic menu, pointing at content on the site or at any URL; read the menu with albert/get-menu first.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::create(
				MenuSupport::CHECK_FIRST . ' For a page or post send "item_type": "post_type" with its "object_id"; a custom link needs "title" and "url".'
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
			'type'        => 'object',
			'description' => 'A custom link needs "title" and "url"; a page, post or term needs "object_id"; a post type archive needs "object_type".',
			'properties'  => [
				'menu_id'         => [
					'type'        => 'integer',
					'description' => 'The classic menu ID from albert/find-menus.',
					'minimum'     => 1,
				],
				'item_type'       => [
					'type'        => 'string',
					'enum'        => [ 'custom', 'post_type', 'taxonomy', 'post_type_archive' ],
					'description' => '"post_type" links a page or post, "taxonomy" a category or other term, "post_type_archive" a post type\'s archive, "custom" any URL. Default "custom".',
					'default'     => 'custom',
				],
				'object_id'       => [
					'type'        => 'integer',
					'description' => 'The page, post or term ID, for "post_type" and "taxonomy".',
					'minimum'     => 1,
				],
				'object_type'     => [
					'type'        => 'string',
					'description' => 'The post type or taxonomy slug, such as "page" or "category". Worked out from object_id when left out; required for "post_type_archive".',
					'minLength'   => 1,
				],
				'title'           => [
					'type'        => 'string',
					'description' => 'The label. Required for a custom link; leave it out to use the linked item\'s own title.',
					'minLength'   => 1,
				],
				'url'             => [
					'type'        => 'string',
					'description' => 'The address, for a custom link.',
					'minLength'   => 1,
				],
				'parent_item_id'  => [
					'type'        => 'integer',
					'description' => 'The menu item to nest this under, from albert/get-menu. Leave it out for the top level.',
					'minimum'     => 1,
				],
				'position'        => [
					'type'        => 'integer',
					'description' => '1-based position among the parent\'s items. Default: last.',
					'minimum'     => 1,
				],
				'open_in_new_tab' => [
					'type'        => 'boolean',
					'description' => 'Open the link in a new tab. Default false.',
				],
			],
			'required'    => [ 'menu_id' ],
			// Core's validator has no if/then, so each item type is one branch. Each lists
			// its properties so a refusal names the field missing for the type that was sent.
			'anyOf'       => [
				[
					'title'      => 'custom link with "title" and "url"',
					'properties' => [
						'item_type' => [
							'type' => 'string',
							'enum' => [ 'custom' ],
						],
						'title'     => [ 'type' => 'string' ],
						'url'       => [ 'type' => 'string' ],
					],
					'required'   => [ 'title', 'url' ],
				],
				[
					'title'      => 'page, post or term link with "object_id"',
					'properties' => [
						'item_type' => [
							'type' => 'string',
							'enum' => [ 'post_type', 'taxonomy' ],
						],
						'object_id' => [ 'type' => 'integer' ],
					],
					'required'   => [ 'item_type', 'object_id' ],
				],
				[
					'title'      => 'post type archive with "object_type"',
					'properties' => [
						'item_type'   => [
							'type' => 'string',
							'enum' => [ 'post_type_archive' ],
						],
						'object_type' => [ 'type' => 'string' ],
					],
					'required'   => [ 'item_type', 'object_type' ],
				],
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
				'id'             => [ 'type' => 'integer' ],
				'menu_id'        => [ 'type' => 'integer' ],
				'title'          => [ 'type' => 'string' ],
				'url'            => [ 'type' => 'string' ],
				'parent_item_id' => [
					'type'        => 'integer',
					'description' => 'The item it is nested under, 0 for the top level.',
				],
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
		$menu = MenuSupport::classic_menu( (int) ( $args['menu_id'] ?? 0 ) );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$menu_id = (int) $menu->term_id;
		$parent  = (int) ( $args['parent_item_id'] ?? 0 );

		if ( $parent > 0 && MenuSupport::menu_of_item( $parent ) !== $menu_id ) {
			return new WP_Error(
				'albert_invalid_parent_item_id',
				__( 'The "parent_item_id" must be an item in the same menu. Call albert/get-menu to see its items, or leave it out for the top level.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		// Core's route puts a new item at position 1 unless told otherwise.
		$last = 0;
		foreach ( MenuSupport::classic_items( $menu_id ) as $item ) {
			$last = max( $last, (int) $item->menu_order );
		}

		$params = [
			'menus'      => $menu_id,
			'type'       => is_string( $args['item_type'] ?? null ) ? $args['item_type'] : 'custom',
			'status'     => 'publish',
			'parent'     => $parent,
			'menu_order' => $last + 1,
		];

		if ( isset( $args['object_id'] ) ) {
			$params['object_id'] = (int) $args['object_id'];
		}
		if ( isset( $args['object_type'] ) ) {
			$params['object'] = sanitize_key( (string) $args['object_type'] );
		}
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
		if ( isset( $args['url'] ) ) {
			$params['url'] = esc_url_raw( (string) $args['url'] );
		}
		if ( isset( $args['open_in_new_tab'] ) ) {
			$params['target'] = $args['open_in_new_tab'] ? '_blank' : '';
		}

		$data = MenuSupport::rest( 'POST', '/wp/v2/menu-items', $params, __( 'The menu item could not be added.', 'albert-ai-butler' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$id = (int) ( $data['id'] ?? 0 );

		if ( isset( $args['position'] ) ) {
			$placed = MenuItemOrder::place( $menu_id, $id, $parent, (int) $args['position'] );
			// No rollback, as in remove-menu-item: say what was saved so a retry does not add it twice.
			if ( is_wp_error( $placed ) ) {
				return new WP_Error(
					$placed->get_error_code(),
					/* translators: 1: why placing failed, 2: menu item ID */
					sprintf( __( '%1$s The item was added at the end of the menu (ID %2$d): move it with albert/move-menu-item, do not add it again.', 'albert-ai-butler' ), $placed->get_error_message(), $id ),
					$placed->get_error_data()
				);
			}
		}

		$post = get_post( $id );
		$item = $post instanceof WP_Post ? wp_setup_nav_menu_item( $post ) : null;

		return [
			'id'             => $id,
			'menu_id'        => $menu_id,
			'title'          => (string) ( $item->title ?? '' ),
			'url'            => (string) ( $item->url ?? '' ),
			'parent_item_id' => $parent,
		] + MenuSupport::visibility_fields( $menu_id );
	}
}
