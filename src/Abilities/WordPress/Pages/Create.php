<?php
/**
 * Create Page Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Pages
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Pages;

use Alley\WP\Block_Converter\Block_Converter;
use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

/**
 * Create Page Ability class
 *
 * Allows AI assistants to create WordPress pages via the abilities API.
 *
 * @since 1.0.0
 */
class Create extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/create-page';
		$this->label       = __( 'Create Page', 'albert' );
		$this->description = __( 'Create a new WordPress page with specified title and content.', 'albert' );
		$this->category    = 'content';
		$this->group       = 'pages';

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
		// Get all available post statuses dynamically.
		$post_statuses = array_keys( get_post_statuses() );

		return [
			'type'       => 'object',
			'properties' => [
				'title'   => [
					'type'        => 'string',
					'description' => 'The page title',
				],
				'content' => [
					'type'        => 'string',
					'description' => 'The page content (HTML allowed)',
					'default'     => '',
				],
				'status'  => [
					'type'        => 'string',
					'enum'        => $post_statuses,
					'description' => 'Page status',
					'default'     => 'draft',
				],
				'excerpt' => [
					'type'        => 'string',
					'description' => 'Optional page excerpt',
					'default'     => '',
				],
				'parent'  => [
					'type'        => 'integer',
					'description' => 'Parent page ID for hierarchical pages',
					'default'     => 0,
				],
			],
			'required'   => [ 'title' ],
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
				'id'        => [ 'type' => 'integer' ],
				'title'     => [ 'type' => 'string' ],
				'status'    => [ 'type' => 'string' ],
				'permalink' => [ 'type' => 'string' ],
				'edit_url'  => [ 'type' => 'string' ],
			],
			'required'   => [ 'id', 'title', 'status' ],
		];
	}

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * Uses the permission callback from the WordPress REST API endpoint.
	 *
	 * @return true|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): true|WP_Error {
		return $this->check_rest_permission( '/wp/v2/pages', 'POST', 'edit_pages' );
	}

	/**
	 * Execute the ability - create a page using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $title   Page title (required).
	 *     @type string $content Page content.
	 *     @type string $status  Page status.
	 *     @type string $excerpt Page excerpt.
	 *     @type int    $parent  Parent page ID.
	 * }
	 * @return array<string, mixed>|WP_Error Page data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Validate input.
		if ( empty( $args['title'] ) ) {
			return new WP_Error(
				'missing_title',
				__( 'Page title is required.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		// Process content with Block Converter.
		$content = ! empty( $args['content'] ) ? ( new Block_Converter( $args['content'] ) )->convert() : '';

		// Prepare REST API request data.
		$request_data = [
			'title'   => sanitize_text_field( $args['title'] ),
			'content' => $content,
			'status'  => sanitize_key( $args['status'] ?? 'draft' ),
			'excerpt' => sanitize_textarea_field( $args['excerpt'] ?? '' ),
		];

		// Add parent if provided.
		if ( ! empty( $args['parent'] ) ) {
			$request_data['parent'] = absint( $args['parent'] );
		}

		// Create REST request.
		$request = new WP_REST_Request( 'POST', '/wp/v2/pages' );
		foreach ( $request_data as $key => $value ) {
			$request->set_param( $key, $value );
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
				$data['message'] ?? __( 'An error occurred while creating the page.', 'albert' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Return formatted page data.
		$page_id = $data['id'];

		return [
			'id'        => $page_id,
			'title'     => $data['title']['rendered'] ?? '',
			'status'    => $data['status'],
			'permalink' => $data['link'] ?? '',
			'edit_url'  => admin_url( 'post.php?post=' . $page_id . '&action=edit' ),
		];
	}
}
