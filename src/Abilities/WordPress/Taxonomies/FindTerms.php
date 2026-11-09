<?php
/**
 * Find Terms Ability
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
 * Find Terms Ability class
 *
 * Allows AI assistants to find terms from a taxonomy via the abilities API.
 *
 * @since 1.0.0
 */
class FindTerms extends BaseAbility {
	use ResolvesTaxonomyRoute;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-terms';
		$this->label       = __( 'Find Terms', 'albert-ai-butler' );
		$this->description = __( 'Find terms from a specific taxonomy (categories, tags, custom terms).', 'albert-ai-butler' );
		$this->category    = 'taxonomy';
		$this->group       = 'terms';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Terms are per-taxonomy, so pass the `taxonomy` you mean. `category` and `post_tag` are '
				. 'the built-in ones, and this site may have others. Use `albert/find-taxonomies` first '
				. 'when you are not sure which exist here.'
			),
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
				'taxonomy' => [
					'type'        => 'string',
					'description' => 'Taxonomy slug (e.g., "category", "post_tag")',
					'default'     => 'category',
				],
				'per_page' => [
					'type'        => 'integer',
					'description' => 'Maximum number of items to return (1-100)',
					'default'     => 10,
				],
				'page'     => [
					'type'        => 'integer',
					'description' => 'Current page of the collection',
					'default'     => 1,
				],
				'search'   => [
					'type'        => 'string',
					'description' => 'Search terms',
					'default'     => '',
				],
				'parent'   => [
					'type'        => 'integer',
					'description' => 'Limit result set to terms with a specific parent',
					'default'     => 0,
				],
			],
			'required'   => [],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * Describes the object {@see self::execute()} returns. Until 1.4.0 this
	 * declared a bare `array` of term items while execute() returned
	 * `{ terms: [...], total: int }`. WordPress 7.1 validates ability output, so
	 * the mismatch was not cosmetic: every call came back as
	 * `ability_invalid_output` — "output is not of type array" — and the
	 * assistant never saw a term. The list moved under `terms`; the item shape
	 * is unchanged.
	 *
	 * @return array<string, mixed> Output schema.
	 * @since 1.0.0
	 * @since 1.4.0 Describes the returned object rather than a bare array.
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'terms' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'          => [ 'type' => 'integer' ],
							'name'        => [ 'type' => 'string' ],
							'slug'        => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
							'parent'      => [ 'type' => 'integer' ],
							'count'       => [ 'type' => 'integer' ],
						],
					],
				],
				// How many are in `terms` — the size of this page, not the size of
				// the whole taxonomy. This ability paginates, so a caller that wants
				// the next page asks for it rather than comparing against a total.
				'total' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'terms', 'total' ],
		];
	}

	/**
	 * Execute the ability - list terms using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $taxonomy Taxonomy slug.
	 *     @type int    $per_page Number of items per page.
	 *     @type int    $page     Page number.
	 *     @type string $search   Search query.
	 *     @type int    $parent   Parent term ID.
	 * }
	 * @return array<string, mixed>|WP_Error Terms data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		$taxonomy = $args['taxonomy'] ?? 'category';

		// Determine the REST route WordPress serves this taxonomy's terms on.
		$route = $this->get_taxonomy_route( $taxonomy );
		if ( is_wp_error( $route ) ) {
			return $route;
		}

		// Create REST request.
		$request = new WP_REST_Request( 'GET', $route );

		// Set parameters.
		$request->set_param( 'per_page', min( absint( $args['per_page'] ?? 10 ), 100 ) );
		$request->set_param( 'page', absint( $args['page'] ?? 1 ) );

		if ( ! empty( $args['search'] ) ) {
			$request->set_param( 'search', sanitize_text_field( $args['search'] ) );
		}

		if ( ! empty( $args['parent'] ) ) {
			$request->set_param( 'parent', absint( $args['parent'] ) );
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
				$data['message'] ?? __( 'An error occurred while retrieving terms.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Format the response.
		$terms = [];
		foreach ( $data as $term_data ) {
			$terms[] = [
				'id'          => $term_data['id'] ?? 0,
				'name'        => $term_data['name'] ?? '',
				'slug'        => $term_data['slug'] ?? '',
				'description' => $term_data['description'] ?? '',
				'parent'      => $term_data['parent'] ?? 0,
				'count'       => $term_data['count'] ?? 0,
			];
		}

		return [
			'terms' => $terms,
			'total' => count( $terms ),
		];
	}
}
