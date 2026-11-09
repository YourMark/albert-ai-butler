<?php
/**
 * Find Taxonomies Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Taxonomies
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Taxonomies;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

/**
 * Find Taxonomies Ability class
 *
 * Allows AI assistants to find available taxonomies via the abilities API.
 *
 * @since 1.0.0
 */
class FindTaxonomies extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-taxonomies';
		$this->label       = __( 'Find Taxonomies', 'albert-ai-butler' );
		$this->description = __( 'Find all registered taxonomies (categories, tags, custom taxonomies).', 'albert-ai-butler' );
		$this->category    = 'taxonomy';
		$this->group       = 'taxonomies';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(),
		];

		$this->capability = 'manage_categories';

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> Input schema.
	 * @since 1.0.0
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'type' => [
					'type'        => 'string',
					'description' => 'Limit results to taxonomies associated with a specific post type',
					'default'     => '',
				],
			],
			'required'   => [],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * Describes the object {@see self::execute()} returns. Until 1.4.0 this
	 * declared a bare `array` of taxonomy items while execute() returned
	 * `{ taxonomies: [...], total: int }`. WordPress 7.1 validates ability
	 * output, so the mismatch was not cosmetic: every call to this ability came
	 * back as `ability_invalid_output` — "output is not of type array" — and the
	 * assistant never saw a taxonomy. The list moved under `taxonomies`; the
	 * item shape is unchanged.
	 *
	 * @return array<string, mixed> Output schema.
	 * @since 1.0.0
	 * @since 1.4.0 Describes the returned object rather than a bare array.
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'taxonomies' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'slug'         => [ 'type' => 'string' ],
							'name'         => [ 'type' => 'string' ],
							'description'  => [ 'type' => 'string' ],
							'hierarchical' => [ 'type' => 'boolean' ],
							'rest_base'    => [ 'type' => 'string' ],
						],
					],
				],
				// How many are in `taxonomies`, which for this ability is all of
				// them: it does not paginate.
				'total'      => [ 'type' => 'integer' ],
			],
			'required'   => [ 'taxonomies', 'total' ],
		];
	}

	/**
	 * Execute the ability - list taxonomies using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $type Optional post type to filter by.
	 * }
	 * @return array<string, mixed>|WP_Error Taxonomy data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Create REST request.
		$request = new WP_REST_Request( 'GET', '/wp/v2/taxonomies' );

		if ( ! empty( $args['type'] ) ) {
			$request->set_param( 'type', sanitize_key( $args['type'] ) );
		}

		// Execute the request.
		$response = rest_do_request( $request );
		$server   = rest_get_server();
		$data     = $server->response_to_data( $response, false );

		// Check for errors.
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $response->is_error() ) {
			return new WP_Error(
				$data['code'] ?? 'rest_error',
				$data['message'] ?? __( 'An error occurred while retrieving taxonomies.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Format the response.
		$taxonomies = [];
		foreach ( $data as $taxonomy_slug => $taxonomy_data ) {
			$taxonomies[] = [
				'slug'         => $taxonomy_slug,
				'name'         => $taxonomy_data['name'] ?? '',
				'description'  => $taxonomy_data['description'] ?? '',
				'hierarchical' => $taxonomy_data['hierarchical'] ?? false,
				'rest_base'    => $taxonomy_data['rest_base'] ?? '',
			];
		}

		return [
			'taxonomies' => $taxonomies,
			'total'      => count( $taxonomies ),
		];
	}
}
