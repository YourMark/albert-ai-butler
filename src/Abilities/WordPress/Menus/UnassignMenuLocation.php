<?php
/**
 * Unassign Menu Location Ability
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
 * Empties a theme's menu location.
 *
 * Like assigning, this goes through the menus route's `locations` field of the
 * menu that is there now, since core's menu-locations route is read-only.
 *
 * @since 1.6.0
 */
class UnassignMenuLocation extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/unassign-menu-location';
		$this->label       = __( 'Unassign Menu Location', 'albert-ai-butler' );
		$this->description = __( 'Empty one of the theme\'s menu locations, keeping the menu itself; call albert/find-menus first to see what the location shows.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => [ 'idempotent' => true ] + Annotations::update(
				'This changes what visitors see straight away: the theme may show its own fallback there. To undo, pass "previous_menu" to albert/assign-menu-location.'
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
			],
			'required'   => [ 'location' ],
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
				'previous_menu' => MenuSupport::menu_reference_schema( 'The menu that was there, null when the location was already empty.' ),
			],
			'required'   => [ 'location', 'previous_menu' ],
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

		$assigned = get_nav_menu_locations();
		$previous = MenuSupport::menu_reference( (int) ( $assigned[ $location ] ?? 0 ) );

		// Empty already, or pointing at a deleted menu: there is no menu to write through.
		if ( $previous === null ) {
			return [
				'location'      => $location,
				'previous_menu' => null,
			];
		}

		$data = MenuSupport::rest(
			'POST',
			'/wp/v2/menus/' . $previous['id'],
			[ 'locations' => array_values( array_diff( MenuSupport::locations_of( $assigned, $previous['id'] ), [ $location ] ) ) ],
			__( 'The menu location could not be emptied.', 'albert-ai-butler' )
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return [
			'location'      => $location,
			'previous_menu' => $previous,
		];
	}
}
