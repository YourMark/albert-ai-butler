<?php
/**
 * Delete Page Ability
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
 * Delete Page Ability class
 *
 * Allows AI assistants to delete WordPress pages via the abilities API.
 *
 * @since 1.0.0
 */
class Delete extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/delete-page';
		$this->label       = __( 'Delete Page', 'albert-ai-butler' );
		$this->description = __( 'Delete a WordPress page permanently or move it to trash.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'pages';

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
				'id'    => [
					'type'        => 'integer',
					'description' => 'The page ID to delete',
				],
				'force' => [
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
				'id'      => [ 'type' => 'integer' ],
				'deleted' => [ 'type' => 'boolean' ],
				'status'  => [ 'type' => 'string' ],
			],
			'required'   => [ 'id', 'deleted' ],
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
		return $this->check_rest_permission( '/wp/v2/pages/(?P<id>[\\d]+)', 'DELETE', 'delete_pages' );
	}

	/**
	 * Execute the ability - delete a page using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type int  $id    Page ID (required).
	 *     @type bool $force Whether to bypass trash and force deletion.
	 * }
	 * @return array<string, mixed>|WP_Error Deletion result on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Validate input.
		if ( empty( $args['id'] ) ) {
			return new WP_Error(
				'missing_id',
				__( 'Page ID is required.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$page_id = absint( $args['id'] );

		// Check if page exists.
		$page = get_post( $page_id );
		if ( ! $page ) {
			return new WP_Error(
				'page_not_found',
				__( 'Page not found.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		$force = ! empty( $args['force'] );

		// Create REST request.
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/pages/' . $page_id );
		$request->set_param( 'force', $force );

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
				$data['message'] ?? __( 'An error occurred while deleting the page.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Return deletion result.
		return [
			'id'      => $page_id,
			'deleted' => true,
			'status'  => $force ? 'deleted' : 'trashed',
		];
	}
}
