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
 * Shows a classic menu in a theme's menu location, or empties the location.
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
		$this->description = __( 'Show a classic menu in one of the theme\'s menu locations, replacing the menu there, or empty the location with menu_id 0.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(
				'This changes what visitors see straight away. Location slugs come from albert/find-menus; a block theme usually has none.'
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
					'description' => 'The menu location slug.',
					'minLength'   => 1,
				],
				'menu_id'  => [
					'type'        => 'integer',
					'description' => 'The classic menu to show there, or 0 to leave the location empty.',
					'minimum'     => 0,
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
				'location'         => [ 'type' => 'string' ],
				'menu_id'          => [ 'type' => 'integer' ],
				'previous_menu_id' => [
					'type'        => 'integer',
					'description' => 'The menu that was there before, 0 for none.',
				],
			] + MenuSupport::visibility_schema(),
			'required'   => [ 'location', 'menu_id', 'previous_menu_id', 'visible', 'shown_in' ],
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
		$location   = sanitize_text_field( (string) ( $args['location'] ?? '' ) );
		$menu_id    = absint( $args['menu_id'] ?? 0 );
		$registered = get_registered_nav_menus();

		if ( ! isset( $registered[ $location ] ) ) {
			$message = $registered === []
				? __( 'This theme has no menu locations. On a block theme the header is a navigation block; call albert/find-menus to see which menu it shows.', 'albert-ai-butler' )
				: sprintf(
					/* translators: %s: comma-separated location slugs */
					__( 'This theme has no menu location with that slug. Its locations are: %s.', 'albert-ai-butler' ),
					implode( ', ', array_map( 'strval', array_keys( $registered ) ) )
				);

			return new WP_Error( 'menu_location_not_found', $message, [ 'status' => 404 ] );
		}

		$assigned = get_nav_menu_locations();
		$previous = (int) ( $assigned[ $location ] ?? 0 );

		if ( $menu_id > 0 ) {
			$menu = MenuSupport::classic_menu( $menu_id );
			if ( is_wp_error( $menu ) ) {
				return $menu;
			}

			$target    = $menu_id;
			$locations = array_merge( $this->locations_of( $assigned, $menu_id ), [ $location ] );
		} else {
			$target    = $previous;
			$locations = array_values( array_diff( $this->locations_of( $assigned, $previous ), [ $location ] ) );
		}

		if ( $target > 0 ) {
			$data = MenuSupport::rest(
				'POST',
				'/wp/v2/menus/' . $target,
				[ 'locations' => array_values( array_unique( $locations ) ) ],
				__( 'The menu location could not be assigned.', 'albert-ai-butler' )
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
		}

		// Core compares stored ids strictly, so a location saved as a string can survive the write.
		if ( (int) ( get_nav_menu_locations()[ $location ] ?? 0 ) !== $menu_id ) {
			return new WP_Error( 'menu_location_not_assigned', __( 'WordPress did not save the menu location.', 'albert-ai-butler' ), [ 'status' => 500 ] );
		}

		$result = [
			'location'         => $location,
			'menu_id'          => $menu_id,
			'previous_menu_id' => $previous,
		];

		if ( $menu_id === 0 ) {
			return $result + [
				'visible'  => false,
				'shown_in' => [],
			];
		}

		return $result + MenuSupport::visibility_fields( $menu_id );
	}

	/**
	 * The locations a menu is in now.
	 *
	 * @param array<mixed> $assigned Location slug to menu id.
	 * @param int          $menu_id  Menu term id.
	 *
	 * @return list<string>
	 * @since 1.6.0
	 */
	private function locations_of( array $assigned, int $menu_id ): array {
		$locations = [];

		foreach ( $assigned as $slug => $id ) {
			if ( (int) $id === $menu_id ) {
				$locations[] = (string) $slug;
			}
		}

		return $locations;
	}
}
