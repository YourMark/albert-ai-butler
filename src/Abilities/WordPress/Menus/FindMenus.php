<?php
/**
 * Find Menus Ability
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
 * Lists classic menus and navigation menus, and says which one visitors see.
 *
 * @since 1.6.0
 */
class FindMenus extends BaseAbility {

	/**
	 * Most navigation menus listed.
	 *
	 * @since 1.6.0
	 * @var int
	 */
	private const NAVIGATION_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-menus';
		$this->label       = __( 'Find Menus', 'albert-ai-butler' );
		$this->description = __( 'List every menu on the site with where visitors see it, starting from the menu the header shows; call this before changing any menu.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Read "header" and "summary" first: on a block theme the header shows a navigation menu (type "block"), not a classic menu. Pass a menu\'s "id" to albert/get-menu as menu_id to read its items.'
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
			'properties' => [],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_output_schema(): array {
		$used_in = [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'type'     => [
						'type' => 'string',
						'enum' => [ 'template', 'template_part' ],
					],
					'slug'     => [ 'type' => 'string' ],
					'title'    => [ 'type' => 'string' ],
					'area'     => [ 'type' => 'string' ],
					'in_use'   => [
						'type'        => 'boolean',
						'description' => 'Whether a template the theme uses shows this part.',
					],
					'fallback' => [
						'type'        => 'boolean',
						'description' => 'True when the navigation block names no menu and shows this one as the most recently published.',
					],
				],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'summary'                    => [
					'type'        => 'string',
					'description' => 'Which menu visitors see, in plain words.',
				],
				'theme'                      => [
					'type'       => 'object',
					'properties' => [
						'name'        => [ 'type' => 'string' ],
						'stylesheet'  => [ 'type' => 'string' ],
						'block_theme' => [ 'type' => 'boolean' ],
					],
				],
				'header'                     => [
					'type'        => 'object',
					'description' => 'What a block theme\'s header shows. "type": "block" is a navigation menu, "inline" links kept in the template part itself, "none" nothing found. "fallback" true means the navigation block names no menu: it shows the newest published navigation menu, or with "none" WordPress makes one the first time the header is shown. Always "none" on a classic theme; see "locations".',
					'properties'  => [
						'type'     => [
							'type' => 'string',
							'enum' => [ 'block', 'inline', 'none' ],
						],
						'id'       => [ 'type' => 'integer' ],
						'name'     => [ 'type' => 'string' ],
						'source'   => [
							'type'        => 'string',
							'description' => 'The template part slug.',
						],
						'fallback' => [ 'type' => 'boolean' ],
					],
				],
				'classic_menus'              => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'        => [ 'type' => 'integer' ],
							'name'      => [ 'type' => 'string' ],
							'slug'      => [ 'type' => 'string' ],
							'items'     => [ 'type' => 'integer' ],
							'locations' => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
							'visible'   => [ 'type' => 'boolean' ],
							'shown_in'  => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
						],
					],
				],
				'navigation_menus'           => [
					'type'        => 'array',
					'description' => 'Block theme navigation menus, newest first. At most ' . self::NAVIGATION_LIMIT . ' are listed; see "navigation_menus_truncated".',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'       => [ 'type' => 'integer' ],
							'title'    => [ 'type' => 'string' ],
							'status'   => [ 'type' => 'string' ],
							'date'     => [ 'type' => 'string' ],
							'visible'  => [ 'type' => 'boolean' ],
							'shown_in' => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
							'used_in'  => $used_in,
						],
					],
				],
				'navigation_menus_truncated' => [
					'type'        => 'boolean',
					'description' => 'True when the site has more navigation menus than are listed; the oldest are left out. "header" still names the menu the header shows.',
				],
				'locations'                  => [
					'type'        => 'array',
					'description' => 'Menu locations the theme registers. A block theme usually has none, and does not show them.',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'slug' => [ 'type' => 'string' ],
							'name' => [ 'type' => 'string' ],
							'menu' => MenuSupport::menu_reference_schema( 'The classic menu assigned, null for none.' ),
						],
					],
				],
			],
			'required'   => [ 'summary', 'theme', 'header', 'classic_menus', 'navigation_menus', 'navigation_menus_truncated', 'locations' ],
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
		$locator    = new MenuLocator();
		$theme      = wp_get_theme();
		$registered = get_registered_nav_menus();
		$assigned   = get_nav_menu_locations();

		$locations = [];
		foreach ( $registered as $slug => $name ) {
			$locations[] = [
				'slug' => (string) $slug,
				'name' => (string) $name,
				'menu' => MenuSupport::menu_reference( (int) ( $assigned[ $slug ] ?? 0 ) ),
			];
		}

		$classic = [];
		foreach ( wp_get_nav_menus() as $menu ) {
			$id        = (int) $menu->term_id;
			$shown_in  = $locator->classic_menu_shown_in( $id );
			$classic[] = [
				'id'        => $id,
				'name'      => $menu->name,
				'slug'      => $menu->slug,
				'items'     => (int) $menu->count,
				'locations' => MenuSupport::locations_of( $assigned, $id ),
				'visible'   => $shown_in !== [],
				'shown_in'  => $shown_in,
			];
		}

		$navigation = [];
		$posts      = get_posts(
			[
				'post_type'      => 'wp_navigation',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				// One more than listed, to know whether any were left out.
				'posts_per_page' => self::NAVIGATION_LIMIT + 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);
		$truncated = count( $posts ) > self::NAVIGATION_LIMIT;
		foreach ( array_slice( $posts, 0, self::NAVIGATION_LIMIT ) as $post ) {
			$id           = (int) $post->ID;
			$shown_in     = $locator->navigation_shown_in( $id );
			$navigation[] = [
				'id'       => $id,
				'title'    => get_the_title( $post ),
				'status'   => $post->post_status,
				'date'     => (string) $post->post_date,
				'visible'  => $shown_in !== [],
				'shown_in' => $shown_in,
				'used_in'  => $locator->navigation_used_in( $id ),
			];
		}

		$block_theme = $locator->is_block_theme();

		return [
			'summary'                    => $block_theme
				? __( 'Block theme: its header is a navigation block, shown in "header". Classic menus are not shown to visitors; do not edit them to change the site\'s navigation.', 'albert-ai-butler' )
				: __( 'Classic theme: visitors see the classic menus assigned to its menu locations or used in widgets, as "visible" says. Which location is in the header is up to the theme.', 'albert-ai-butler' ),
			'theme'                      => [
				'name'        => (string) $theme->get( 'Name' ),
				'stylesheet'  => $theme->get_stylesheet(),
				'block_theme' => $block_theme,
			],
			'header'                     => $locator->header(),
			'classic_menus'              => $classic,
			'navigation_menus'           => $navigation,
			'navigation_menus_truncated' => $truncated,
			'locations'                  => $locations,
		];
	}
}
