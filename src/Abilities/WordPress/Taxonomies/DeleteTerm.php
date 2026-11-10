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
	use ResolvesTaxonomyRoute;

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
		$taxonomy = $args['taxonomy'] ?? 'category';
		$term_id  = absint( $args['id'] );

		// Determine the REST route WordPress serves this taxonomy's terms on.
		$route = $this->get_taxonomy_route( $taxonomy );
		if ( is_wp_error( $route ) ) {
			return $route;
		}

		// Create REST request.
		$request = new WP_REST_Request( 'DELETE', $route . '/' . $term_id );

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
}
