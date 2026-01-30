<?php
/**
 * Delete Term Ability
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
 * Delete Term Ability class
 *
 * Allows AI assistants to delete taxonomy terms via the abilities API.
 *
 * @since 1.0.0
 */
class DeleteTerm extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/delete-term';
		$this->label       = __( 'Delete Term', 'albert-ai-butler' );
		$this->description = __( 'Delete a term from a taxonomy (category, tag, etc).', 'albert-ai-butler' );
		$this->category    = 'taxonomy';
		$this->group       = 'terms';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::delete(),
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
				'id'       => [
					'type'        => 'integer',
					'description' => 'The term ID to delete (required)',
				],
				'force'    => [
					'type'        => 'boolean',
					'description' => 'Whether to bypass trash and force deletion',
					'default'     => false,
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
				'deleted'  => [ 'type' => 'boolean' ],
				'previous' => [
					'type'       => 'object',
					'properties' => [
						'id'   => [ 'type' => 'integer' ],
						'name' => [ 'type' => 'string' ],
						'slug' => [ 'type' => 'string' ],
					],
				],
			],
			'required'   => [ 'deleted' ],
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
	 * Execute the ability - delete term using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $taxonomy Taxonomy slug.
	 *     @type int    $id       Term ID.
	 *     @type bool   $force    Force deletion.
	 * }
	 * @return array<string, mixed>|WP_Error Result data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Validate required fields.
		if ( empty( $args['id'] ) ) {
			return new WP_Error(
				'missing_id',
				__( 'Term ID is required.', 'albert-ai-butler' ),
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
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/' . $rest_base . '/' . $term_id );

		// Set force parameter if provided.
		if ( ! empty( $args['force'] ) ) {
			$request->set_param( 'force', true );
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
				$data['message'] ?? __( 'An error occurred while deleting the term.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Return formatted data.
		return [
			'deleted'  => $data['deleted'] ?? false,
			'previous' => [
				'id'   => $data['previous']['id'] ?? 0,
				'name' => $data['previous']['name'] ?? '',
				'slug' => $data['previous']['slug'] ?? '',
			],
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
				__( 'Invalid taxonomy.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		if ( empty( $taxonomy_obj->rest_base ) ) {
			return new WP_Error(
				'taxonomy_not_rest_enabled',
				__( 'This taxonomy is not available via REST API.', 'albert-ai-butler' ),
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
