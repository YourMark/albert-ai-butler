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
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-terms';
		$this->label       = __( 'Find Terms', 'albert' );
		$this->description = __( 'Find terms from a specific taxonomy (categories, tags, custom terms).', 'albert' );
		$this->category    = 'taxonomy';
		$this->group       = 'terms';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(),
		];

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
	 * @return array<string, mixed> Output schema.
	 * @since 1.0.0
	 */
	protected function get_output_schema(): array {
		return [
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
		];
	}

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * @return true|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): true|WP_Error {
		return $this->require_capability( 'manage_categories' );
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

		// Determine REST base for taxonomy.
		$rest_base = $this->get_taxonomy_rest_base( $taxonomy );
		if ( is_wp_error( $rest_base ) ) {
			return $rest_base;
		}

		// Create REST request.
		$request = new WP_REST_Request( 'GET', '/wp/v2/' . $rest_base );

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
				$data['message'] ?? __( 'An error occurred while retrieving terms.', 'albert' ),
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

	/**
	 * Get the REST base for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string|WP_Error REST base or error.
	 * @since 1.0.0
	 */
	private function get_taxonomy_rest_base( string $taxonomy ): string|WP_Error {
		// Map common taxonomies to their REST bases.
		$rest_bases = [
			'category'  => 'categories',
			'post_tag'  => 'tags',
			'post_tags' => 'tags',
		];

		if ( isset( $rest_bases[ $taxonomy ] ) ) {
			return $rest_bases[ $taxonomy ];
		}

		// Get taxonomy object.
		$taxonomy_obj = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_obj ) {
			return new WP_Error(
				'invalid_taxonomy',
				__( 'Invalid taxonomy.', 'albert' ),
				[ 'status' => 404 ]
			);
		}

		if ( empty( $taxonomy_obj->rest_base ) ) {
			return new WP_Error(
				'taxonomy_not_rest_enabled',
				__( 'This taxonomy is not available via REST API.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		// If rest_base is true, use the taxonomy name as the REST base.
		if ( $taxonomy_obj->rest_base === true ) {
			return $taxonomy_obj->name;
		}

		return $taxonomy_obj->rest_base;
	}
}
