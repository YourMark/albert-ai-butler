<?php
/**
 * Find Pages Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Pages
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Pages;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

/**
 * Find Pages Ability class
 *
 * Allows AI assistants to find and search WordPress pages via the abilities API.
 *
 * @since 1.0.0
 */
class FindPages extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-pages';
		$this->label       = __( 'Find Pages', 'albert-ai-butler' );
		$this->description = __( 'Find and search WordPress pages with optional filtering and pagination.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'pages';

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
		// Get all available post statuses dynamically.
		$post_statuses = array_keys( get_post_statuses() );

		return [
			'type'       => 'object',
			'properties' => [
				'page'     => [
					'type'        => 'integer',
					'description' => 'Page number for pagination',
					'default'     => 1,
					'minimum'     => 1,
				],
				'per_page' => [
					'type'        => 'integer',
					'description' => 'Number of pages per page',
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				],
				'search'   => [
					'type'        => 'string',
					'description' => 'Search pages by title or content',
					'default'     => '',
				],
				'status'   => [
					'type'        => 'string',
					'description' => 'Filter pages by status',
					'enum'        => $post_statuses,
				],
				'parent'   => [
					'type'        => 'integer',
					'description' => 'Filter by parent page ID (0 for top-level pages)',
				],
				'order'    => [
					'type'        => 'string',
					'description' => 'Order direction',
					'enum'        => [ 'asc', 'desc' ],
					'default'     => 'desc',
				],
				'orderby'  => [
					'type'        => 'string',
					'description' => 'Sort by field',
					'enum'        => [ 'date', 'modified', 'title', 'id', 'menu_order' ],
					'default'     => 'date',
				],
			],
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
				'pages'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'         => [ 'type' => 'integer' ],
							'title'      => [ 'type' => 'string' ],
							'content'    => [ 'type' => 'string' ],
							'excerpt'    => [ 'type' => 'string' ],
							'status'     => [ 'type' => 'string' ],
							'date'       => [ 'type' => 'string' ],
							'modified'   => [ 'type' => 'string' ],
							'author'     => [ 'type' => 'integer' ],
							'parent'     => [ 'type' => 'integer' ],
							'menu_order' => [ 'type' => 'integer' ],
							'permalink'  => [ 'type' => 'string' ],
						],
					],
				],
				'total'       => [ 'type' => 'integer' ],
				'total_pages' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'pages', 'total' ],
		];
	}

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * Delegates to the REST API endpoint's own permission callback.
	 *
	 * @return true|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): true|WP_Error {
		return $this->check_rest_permission( '/wp/v2/pages', 'GET', 'edit_pages' );
	}

	/**
	 * Execute the ability - list pages using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type int    $page     Page number for pagination.
	 *     @type int    $per_page Number of pages per result page.
	 *     @type string $search   Search query.
	 *     @type string $status   Filter by status.
	 *     @type int    $parent   Filter by parent page ID.
	 *     @type string $order    Order direction.
	 *     @type string $orderby  Sort by field.
	 * }
	 * @return array<string, mixed>|WP_Error Pages list on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Create REST request.
		$request = new WP_REST_Request( 'GET', '/wp/v2/pages' );

		// Set pagination parameters.
		$request->set_param( 'page', absint( $args['page'] ?? 1 ) );
		$request->set_param( 'per_page', absint( $args['per_page'] ?? 10 ) );

		// Set search parameter if provided.
		if ( ! empty( $args['search'] ) ) {
			$request->set_param( 'search', sanitize_text_field( $args['search'] ) );
		}

		// Set status filter if provided.
		if ( ! empty( $args['status'] ) ) {
			$request->set_param( 'status', sanitize_key( $args['status'] ) );
		}

		// Set parent filter if provided.
		if ( isset( $args['parent'] ) ) {
			$request->set_param( 'parent', absint( $args['parent'] ) );
		}

		// Set order parameters.
		if ( ! empty( $args['order'] ) ) {
			$request->set_param( 'order', sanitize_key( $args['order'] ) );
		}

		if ( ! empty( $args['orderby'] ) ) {
			$request->set_param( 'orderby', sanitize_key( $args['orderby'] ) );
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
				$data['message'] ?? __( 'An error occurred while retrieving pages.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Format pages data.
		$pages = [];
		foreach ( $data as $page_data ) {
			$pages[] = [
				'id'         => $page_data['id'],
				'title'      => $page_data['title']['rendered'] ?? '',
				'content'    => $page_data['content']['rendered'] ?? '',
				'excerpt'    => $page_data['excerpt']['rendered'] ?? '',
				'status'     => $page_data['status'] ?? '',
				'date'       => $page_data['date'] ?? '',
				'modified'   => $page_data['modified'] ?? '',
				'author'     => $page_data['author'] ?? 0,
				'parent'     => $page_data['parent'] ?? 0,
				'menu_order' => $page_data['menu_order'] ?? 0,
				'permalink'  => $page_data['link'] ?? '',
			];
		}

		// Get pagination headers.
		$headers     = $response->get_headers();
		$total       = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $pages );
		$total_pages = isset( $headers['X-WP-TotalPages'] ) ? (int) $headers['X-WP-TotalPages'] : 1;

		return [
			'pages'       => $pages,
			'total'       => $total,
			'total_pages' => $total_pages,
		];
	}
}
