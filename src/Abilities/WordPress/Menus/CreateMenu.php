<?php
/**
 * Create Menu Ability
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
 * Creates a classic menu, optionally in one or more menu locations.
 *
 * @since 1.6.0
 */
class CreateMenu extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/create-menu';
		$this->label       = __( 'Create Menu', 'albert-ai-butler' );
		$this->description = __( 'Create a classic menu, optionally assigned to menu locations. Block themes do not show classic menus.', 'albert-ai-butler' );
		$this->category    = 'menus';
		$this->group       = 'menus';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::create( MenuSupport::CHECK_FIRST ),
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
				'name'        => [
					'type'        => 'string',
					'description' => 'The menu name. Must be unique.',
					'minLength'   => 1,
				],
				'description' => [
					'type'        => 'string',
					'description' => 'An optional description.',
				],
				'locations'   => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Menu location slugs to show this menu in, from albert/find-menus. A location keeps one menu, so this replaces the menu there.',
				],
			],
			'required'   => [ 'name' ],
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
				'id'        => [ 'type' => 'integer' ],
				'name'      => [ 'type' => 'string' ],
				'slug'      => [ 'type' => 'string' ],
				'locations' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			] + MenuSupport::visibility_schema(),
			'required'   => [ 'id', 'name', 'visible', 'shown_in' ],
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
		$params = [ 'name' => sanitize_text_field( (string) ( $args['name'] ?? '' ) ) ];

		if ( isset( $args['description'] ) ) {
			$params['description'] = sanitize_textarea_field( (string) $args['description'] );
		}

		if ( isset( $args['locations'] ) && is_array( $args['locations'] ) ) {
			$params['locations'] = array_values( array_map( 'sanitize_text_field', array_filter( $args['locations'], 'is_string' ) ) );
		}

		$data = MenuSupport::rest( 'POST', '/wp/v2/menus', $params, __( 'The menu could not be created.', 'albert-ai-butler' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$id = (int) ( $data['id'] ?? 0 );

		return [
			'id'        => $id,
			'name'      => (string) ( $data['name'] ?? '' ),
			'slug'      => (string) ( $data['slug'] ?? '' ),
			'locations' => array_values( array_map( 'strval', (array) ( $data['locations'] ?? [] ) ) ),
		] + MenuSupport::visibility_fields( $id );
	}
}
