<?php
/**
 * Assign Menu Location Ability
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
 * Shows a classic menu in a theme's menu location.
 *
 * Core's menu-locations route is read-only; a location is assigned through the
 * menus route's `locations` field, which replaces that menu's whole list.
 *
 * @since 1.6.0
 */
class AssignMenuLocation extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/assign-menu-location';
		$this->label       = __( 'Assign Menu Location', 'albert-ai-butler' );
		$this->description = __( 'Show a classic menu in one of the theme\'s menu locations, replacing the menu there; to empty a location use albert/unassign-menu-location.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(
				'This changes what visitors see straight away. Location slugs come from albert/find-menus; a block theme usually has none. To undo, assign "previous_menu" again.'
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
				'location' => [
					'type'        => 'string',
					'description' => 'The menu location slug from albert/find-menus.',
					'minLength'   => 1,
				],
				'menu_id'  => [
					'type'        => 'integer',
					'description' => 'The classic menu to show there, from albert/find-menus.',
					'minimum'     => 1,
				],
			],
			'required'   => [ 'location', 'menu_id' ],
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
				'location'      => [ 'type' => 'string' ],
				'menu'          => MenuSupport::menu_reference_schema( 'The menu now in the location.' ),
				'previous_menu' => MenuSupport::menu_reference_schema( 'The menu that was there before, null for none.' ),
			] + MenuSupport::visibility_schema(),
			'required'   => [ 'location', 'menu', 'previous_menu', 'visible', 'shown_in' ],
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
		$location = sanitize_text_field( (string) ( $args['location'] ?? '' ) );
		$missing  = MenuSupport::unregistered_location( $location );
		if ( $missing instanceof WP_Error ) {
			return $missing;
		}

		$menu = MenuSupport::classic_menu( (int) ( $args['menu_id'] ?? 0 ) );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$menu_id   = (int) $menu->term_id;
		$assigned  = get_nav_menu_locations();
		$previous  = MenuSupport::menu_reference( (int) ( $assigned[ $location ] ?? 0 ) );
		$locations = array_merge( MenuSupport::locations_of( $assigned, $menu_id ), [ $location ] );

		$data = MenuSupport::rest(
			'POST',
			'/wp/v2/menus/' . $menu_id,
			[ 'locations' => array_values( array_unique( $locations ) ) ],
			__( 'The menu location could not be assigned.', 'albert-ai-butler' )
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$saved = MenuSupport::location_saved( $location, $menu_id );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		return [
			'location'      => $location,
			'menu'          => MenuSupport::menu_reference( $menu_id ),
			'previous_menu' => $previous,
		] + MenuSupport::visibility_fields( $menu_id );
	}
}
