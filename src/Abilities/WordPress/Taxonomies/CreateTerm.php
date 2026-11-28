<?php
/**
 * Create Term Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Taxonomies
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Taxonomies;

defined( 'ABSPATH' ) || exit;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

/**
 * Create Term Ability class
 *
 * Allows AI assistants to create taxonomy terms via the abilities API.
 *
 * @since 1.0.0
 */
class CreateTerm extends BaseAbility {
	use ResolvesTaxonomyRoute;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/create-term';
		$this->label       = __( 'Create Term', 'albert-ai-butler' );
		$this->description = __( 'Create a new term in a taxonomy (category, tag, etc).', 'albert-ai-butler' );
		$this->category    = 'taxonomy';
		$this->group       = 'terms';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::create(),
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
				'name'        => [
					'type'        => 'string',
					'description' => 'The term name (required)',
				],
				'slug'        => [
					'type'        => 'string',
					'description' => 'The term slug (optional, auto-generated if not provided)',
					'default'     => '',
				],
				'description' => [
					'type'        => 'string',
					'description' => 'The term description',
					'default'     => '',
				],
				'parent'      => [
					'type'        => 'integer',
					'description' => 'The parent term ID (for hierarchical taxonomies)',
					'default'     => 0,
				],
			],
			'required'   => [ 'name' ],
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
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'manage_categories' );
	}

	/**
	 * Execute the ability - create term using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $taxonomy    Taxonomy slug.
	 *     @type string $name        Term name.
	 *     @type string $slug        Term slug.
	 *     @type string $description Term description.
	 *     @type int    $parent      Parent term ID.
	 * }
	 * @return array<string, mixed>|WP_Error Term data on success, WP_Error on failure.
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
		$request = new WP_REST_Request( 'POST', $route );

		// Set parameters.
		$request->set_param( 'name', sanitize_text_field( $args['name'] ) );

		if ( ! empty( $args['slug'] ) ) {
			$request->set_param( 'slug', sanitize_title( $args['slug'] ) );
		}

		if ( ! empty( $args['description'] ) ) {
			$request->set_param( 'description', sanitize_textarea_field( $args['description'] ) );
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
				$data['message'] ?? __( 'An error occurred while creating the term.', 'albert-ai-butler' ),
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
}
