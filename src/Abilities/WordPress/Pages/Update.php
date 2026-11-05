<?php
/**
 * Update Page Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Pages
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Pages;

use Albert\Abstracts\BaseAbility;
use Albert\Blocks\BlockSpecSchema;
use Albert\Blocks\WriteContentResolver;
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
		$this->label       = __( 'Update Page', 'albert-ai-butler' );
		$this->description = __( 'Update an existing WordPress page with new title and content.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'pages';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(
				'This replaces the whole page body. Read the page first with `albert/view-page` and '
				. '`"format": ["blocks"]`, edit that tree, and send it back, writing without reading '
				. 'silently discards everything you did not include. Keep every existing image block\'s `id` '
				. 'and `url` exactly as they were unless you were asked to change the image. To change one '
				. 'block, use `albert/edit-page-block` instead: it is cheaper and leaves every other block '
				. 'byte-for-byte intact.'
			),
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
					'description' => 'The page content as a string. Accepts WordPress block markup (<!-- wp:... -->), HTML, or Markdown (converted to blocks automatically). For multi-block, nested layouts prefer the structured "blocks" field instead. Ignored when "blocks" is provided. Replaces the entire page body when set.',
				],
				'blocks'  => [
					'type'        => 'array',
					'description' => 'Structured block specs (preferred over "content"). Replaces the entire page body. Each item is { "name": "core/paragraph", "attributes": { ... }, "innerBlocks": [ ... ] }; innerBlocks is the same shape recursively for layout blocks (e.g. core/columns > core/column). Put text in attributes (content/text/value) or in "plaintext". Example: [ { "name": "core/heading", "attributes": { "level": 2, "content": "Intro" } }, { "name": "core/paragraph", "attributes": { "content": "Hello world." } } ]',
					'items'       => BlockSpecSchema::spec(),
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
				'id'           => [ 'type' => 'integer' ],
				'title'        => [ 'type' => 'string' ],
				'status'       => [ 'type' => 'string' ],
				'permalink'    => [ 'type' => 'string' ],
				'edit_url'     => [ 'type' => 'string' ],
				'block_issues' => [
					'type'        => 'array',
					'description' => 'Optional, non-fatal block validation warnings (the page was still saved). Each is an actionable message such as "content[0].attributes.url is required for core/image". Fatal block problems are not returned here: they come back as a WP_Error with code "block_validation_failed" and the page is not updated.',
					'items'       => [ 'type' => 'string' ],
				],
			],
			'required'   => [ 'id', 'title', 'status' ],
		];
	}

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * Delegates to the REST API endpoint's own permission callback.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): bool|WP_Error {
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
		$page_id = absint( $args['id'] );

		// Check if page exists using REST API.
		$check_request  = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $page_id );
		$check_response = rest_do_request( $check_request );

		if ( $check_response->is_error() ) {
			return new WP_Error(
				'page_not_found',
				__( 'Page not found.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		// Prepare REST API request data (only include provided fields).
		$request_data = [];
		$block_issues = [];

		if ( isset( $args['title'] ) ) {
			$request_data['title'] = sanitize_text_field( $args['title'] );
		}

		// Content is only touched when structured "blocks" or a "content" string
		// is provided; otherwise the existing page body is left untouched.
		$has_blocks_input  = ! empty( $args['blocks'] ) && is_array( $args['blocks'] );
		$has_content_input = $has_blocks_input || isset( $args['content'] );

		if ( $has_content_input ) {
			// Resolve the content to store (classic rejection, block
			// serialization, allowed-block enforcement with the Update exemption,
			// and any block issues).
			$resolved = ( new WriteContentResolver() )->resolve( $args, 'page', $page_id );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			$request_data['content'] = $resolved['content'];
			$block_issues            = $resolved['block_issues'];
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
				$data['message'] ?? __( 'An error occurred while updating the page.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Return formatted page data.
		$page_id = $data['id'];

		$result = [
			'id'        => $page_id,
			'title'     => $data['title']['rendered'] ?? '',
			'status'    => $data['status'],
			'permalink' => $data['link'] ?? '',
			'edit_url'  => admin_url( 'post.php?post=' . $page_id . '&action=edit' ),
		];

		if ( ! empty( $block_issues ) ) {
			$result['block_issues'] = $block_issues;
		}

		return $result;
	}
}
