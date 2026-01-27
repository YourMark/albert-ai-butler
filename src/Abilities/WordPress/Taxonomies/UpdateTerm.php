<?php
/**
 * Update Term Ability
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
 * Update Term Ability class
 *
 * Allows AI assistants to update taxonomy terms via the abilities API.
 *
 * @since 1.0.0
 */
class UpdateTerm extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/update-term';
		$this->label       = __( 'Update Term', 'albert' );
		$this->description = __( 'Update an existing term in a taxonomy (category, tag, etc).', 'albert' );
		$this->category    = 'taxonomy';
		$this->group       = 'terms';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(),
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
				'taxonomy'    => [
					'type'        => 'string',
					'description' => 'Taxonomy slug (e.g., "category", "post_tag")',
					'default'     => 'category',
				],
				'id'          => [
					'type'        => 'integer',
					'description' => 'The term ID to update (required)',
				],
				'name'        => [
					'type'        => 'string',
					'description' => 'The term name',
				],
				'slug'        => [
					'type'        => 'string',
					'description' => 'The term slug',
				],
				'description' => [
					'type'        => 'string',
					'description' => 'The term description',
				],
				'parent'      => [
					'type'        => 'integer',
					'description' => 'The parent term ID (for hierarchical taxonomies)',
				],
			],
			'required'   => [ 'id' ],
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
			'type'       => 'object',
			'properties' => [
				'id'          => [ 'type' => 'integer' ],
				'name'        => [ 'type' => 'string' ],
				'slug'        => [ 'type' => 'string' ],
				'description' => [ 'type' => 'string' ],
				'parent'      => [ 'type' => 'integer' ],
				'count'       => [ 'type' => 'integer' ],
			],
			'required'   => [ 'id', 'name', 'slug' ],
		];
	}

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * @return bool Whether user has permission.
	 * @since 1.0.0
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_categories' );
	}

	/**
	 * Execute the ability - update term using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $taxonomy    Taxonomy slug.
	 *     @type int    $id          Term ID.
	 *     @type string $name        Term name.
	 *     @type string $slug        Term slug.
	 *     @type string $description Term description.
	 *     @type int    $parent      Parent term ID.
	 * }
	 * @return array<string, mixed>|WP_Error Term data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Validate required fields.
		if ( empty( $args['id'] ) ) {
			return new WP_Error(
				'missing_id',
				__( 'Term ID is required.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		$taxonomy = $args['taxonomy'] ?? 'category';
		$term_id  = absint( $args['id'] );

		// Determine REST base for taxonomy.
		$rest_base = $this->get_taxonomy_rest_base( $taxonomy );
		if ( is_wp_error( $rest_base ) ) {
			return $rest_base;
		}

		// Create REST request.
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . $rest_base . '/' . $term_id );

		// Set parameters (only include provided fields).
		if ( isset( $args['name'] ) ) {
			$request->set_param( 'name', sanitize_text_field( $args['name'] ) );
		}

		if ( isset( $args['slug'] ) ) {
			$request->set_param( 'slug', sanitize_title( $args['slug'] ) );
		}

		if ( isset( $args['description'] ) ) {
			$request->set_param( 'description', sanitize_textarea_field( $args['description'] ) );
		}

		if ( isset( $args['parent'] ) ) {
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
				$data['message'] ?? __( 'An error occurred while updating the term.', 'albert' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Return formatted data.
		return [
			'id'          => $data['id'] ?? 0,
			'name'        => $data['name'] ?? '',
			'slug'        => $data['slug'] ?? '',
			'description' => $data['description'] ?? '',
			'parent'      => $data['parent'] ?? 0,
			'count'       => $data['count'] ?? 0,
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
