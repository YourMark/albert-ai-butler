<?php
/**
 * Update Page Ability
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
 * Update Page Ability class
 *
 * Allows AI assistants to update WordPress pages via the abilities API.
 *
 * @since 1.0.0
 */
class Update extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/update-page';
		$this->label       = __( 'Update Page', 'albert' );
		$this->description = __( 'Update an existing WordPress page with new title and content.', 'albert' );
		$this->category    = 'content';
		$this->group       = 'pages';

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
		// Get all available post statuses dynamically.
		$post_statuses = array_keys( get_post_statuses() );

		return [
			'type'       => 'object',
			'properties' => [
				'id'      => [
					'type'        => 'integer',
					'description' => 'The page ID to update',
				],
				'title'   => [
					'type'        => 'string',
					'description' => 'The page title',
				],
				'content' => [
					'type'        => 'string',
					'description' => 'The page content (HTML allowed)',
				],
				'status'  => [
					'type'        => 'string',
					'enum'        => $post_statuses,
					'description' => 'Page status',
				],
				'excerpt' => [
					'type'        => 'string',
					'description' => 'Optional page excerpt',
				],
				'parent'  => [
					'type'        => 'integer',
					'description' => 'Parent page ID for hierarchical pages',
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
	 * Delegates to the REST API endpoint's own permission callback.
	 *
	 * @return true|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): true|WP_Error {
		return $this->check_rest_permission( '/wp/v2/pages/(?P<id>[\\d]+)', 'POST', 'edit_pages' );
	}

	/**
	 * Execute the ability - update a page using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type int    $id      Page ID (required).
	 *     @type string $title   Page title.
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
		if ( empty( $args['id'] ) ) {
			return new WP_Error(
				'missing_id',
				__( 'Page ID is required.', 'albert' ),
				[ 'status' => 400 ]
			);
		}

		$page_id = absint( $args['id'] );

		// Check if page exists using REST API.
		$check_request  = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $page_id );
		$check_response = rest_do_request( $check_request );

		if ( $check_response->is_error() ) {
			return new WP_Error(
				'page_not_found',
				__( 'Page not found.', 'albert' ),
				[ 'status' => 404 ]
			);
		}

		// Prepare REST API request data (only include provided fields).
		$request_data = [];

		if ( isset( $args['title'] ) ) {
			$request_data['title'] = sanitize_text_field( $args['title'] );
		}

		if ( isset( $args['content'] ) ) {
			$request_data['content'] = ( new Block_Converter( $args['content'] ) )->convert();
		}

		if ( isset( $args['status'] ) ) {
			$request_data['status'] = sanitize_key( $args['status'] );
		}

		if ( isset( $args['excerpt'] ) ) {
			$request_data['excerpt'] = sanitize_textarea_field( $args['excerpt'] );
		}

		if ( isset( $args['parent'] ) ) {
			$request_data['parent'] = absint( $args['parent'] );
		}

		// Create REST request.
		$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $page_id );
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
				$data['message'] ?? __( 'An error occurred while updating the page.', 'albert' ),
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
