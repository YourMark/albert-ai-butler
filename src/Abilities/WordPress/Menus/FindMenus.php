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
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Lists classic menus and navigation menus, and says which one visitors see.
 *
 * @since 1.6.0
 */
class FindMenus extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-menus';
		$this->label       = __( 'Find Menus', 'albert-ai-butler' );
		$this->description = __( 'List the site\'s menus, classic menus and block theme navigation menus, with where each is shown and which one the header uses.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Start here before changing any menu. Read "header" and "summary": on a block theme the header shows a navigation menu (type "block"), and classic menus are not shown at all.'
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
				'summary'          => [
					'type'        => 'string',
					'description' => 'Which menu visitors see, in plain words.',
				],
				'theme'            => [
					'type'       => 'object',
					'properties' => [
						'name'        => [ 'type' => 'string' ],
						'stylesheet'  => [ 'type' => 'string' ],
						'block_theme' => [ 'type' => 'boolean' ],
					],
				],
				'header'           => [
					'type'        => 'object',
					'description' => 'What the header shows. "type": "block" is a navigation menu, "classic" a classic menu, "classic_copy" a classic menu core will copy into a navigation menu, "inline" links kept in the template part itself, "page_list" the published pages, "none" nothing found.',
					'properties'  => [
						'type'        => [
							'type' => 'string',
							'enum' => [ 'block', 'classic', 'classic_copy', 'inline', 'page_list', 'none' ],
						],
						'id'          => [ 'type' => 'integer' ],
						'name'        => [ 'type' => 'string' ],
						'source'      => [
							'type'        => 'string',
							'description' => 'The template part slug on a block theme, the menu location on a classic theme.',
						],
						'fallback'    => [ 'type' => 'boolean' ],
						'description' => [ 'type' => 'string' ],
					],
				],
				'classic_menus'    => [
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
				'navigation_menus' => [
					'type'  => 'array',
					'items' => [
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
				'locations'        => [
					'type'        => 'array',
					'description' => 'Menu locations the theme registers. A block theme usually has none, and does not show them.',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'slug'    => [ 'type' => 'string' ],
							'name'    => [ 'type' => 'string' ],
							'menu_id' => [
								'type'        => 'integer',
								'description' => 'The classic menu assigned, 0 for none.',
							],
						],
					],
				],
			],
			'required'   => [ 'summary', 'theme', 'header', 'classic_menus', 'navigation_menus', 'locations' ],
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
				'slug'    => (string) $slug,
				'name'    => (string) $name,
				'menu_id' => (int) ( $assigned[ $slug ] ?? 0 ),
			];
		}

		$classic = [];
		$menus   = wp_get_nav_menus();
		foreach ( is_array( $menus ) ? $menus : [] as $menu ) {
			if ( ! $menu instanceof WP_Term ) {
				continue;
			}

			$id        = (int) $menu->term_id;
			$shown_in  = $locator->classic_menu_shown_in( $id );
			$classic[] = [
				'id'        => $id,
				'name'      => $menu->name,
				'slug'      => $menu->slug,
				'items'     => (int) $menu->count,
				'locations' => array_values( array_map( 'strval', array_keys( array_filter( $assigned, static fn( $assigned_id ): bool => (int) $assigned_id === $id ) ) ) ),
				'visible'   => $shown_in !== [],
				'shown_in'  => $shown_in,
			];
		}

		$navigation = [];
		$posts      = get_posts(
			[
				'post_type'      => 'wp_navigation',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

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
		$header      = $locator->header();

		return [
			'summary'          => $this->summary( $block_theme, $header['description'], $classic ),
			'theme'            => [
				'name'        => (string) $theme->get( 'Name' ),
				'stylesheet'  => $theme->get_stylesheet(),
				'block_theme' => $block_theme,
			],
			'header'           => $header,
			'classic_menus'    => $classic,
			'navigation_menus' => $navigation,
			'locations'        => $locations,
		];
	}

	/**
	 * Which menu to change, in plain words.
	 *
	 * @param bool                       $block_theme Whether the theme is a block theme.
	 * @param string                     $header      What the header shows.
	 * @param list<array<string, mixed>> $classic     The classic menus.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	private function summary( bool $block_theme, string $header, array $classic ): string {
		if ( ! $block_theme ) {
			return sprintf(
				/* translators: %s: what the header shows */
				__( 'This is a classic theme: visitors see the classic menus assigned to its menu locations. %s', 'albert-ai-butler' ),
				$header
			);
		}

		$summary = sprintf(
			/* translators: %s: what the header shows */
			__( 'This is a block theme: its header is a navigation block. %s', 'albert-ai-butler' ),
			$header
		);

		if ( $classic !== [] && ! in_array( true, array_column( $classic, 'visible' ), true ) ) {
			$summary .= ' ' . __( 'Its classic menus are not shown to visitors; do not edit them to change the site\'s navigation.', 'albert-ai-butler' );
		}

		return $summary;
	}
}
