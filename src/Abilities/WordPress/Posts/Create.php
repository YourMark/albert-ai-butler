<?php
/**
 * Create Post Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Posts
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Posts;

use Albert\Abstracts\BaseAbility;
use Albert\Blocks\BlockSpecSchema;
use Albert\Blocks\WriteContentResolver;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

/**
 * Create Post Ability class
 *
 * Allows AI assistants to create WordPress posts via the abilities API.
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
		$this->id          = 'albert/create-post';
		$this->label       = __( 'Create Post', 'albert-ai-butler' );
		$this->description = __( 'Create a new WordPress post with specified title, content, and metadata.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::create(
				'Call `albert/list-block-types` first and compose only from what it returns, an unlisted '
				. 'block fails validation and nothing is saved. Send block specs in `blocks`, never '
				. 'hand-written `<!-- wp:… -->` markup, and put child blocks in `innerBlocks` rather than '
				. 'in attributes. For a classic-editor post type, send HTML in `content` instead.'
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
				'title'      => [
					'type'        => 'string',
					'description' => 'The post title',
				],
				'content'    => [
					'type'        => 'string',
					'description' => 'The post content as a string. Accepts WordPress block markup (<!-- wp:... -->), HTML, or Markdown (converted to blocks automatically). For multi-block, nested layouts prefer the structured "blocks" field instead. Ignored when "blocks" is provided.',
					'default'     => '',
				],
				'blocks'     => [
					'type'        => 'array',
					'description' => 'Structured block specs (preferred over "content"). Each item is { "name": "core/paragraph", "attributes": { ... }, "innerBlocks": [ ... ] }; innerBlocks is the same shape recursively for layout blocks (e.g. core/columns > core/column). Put text in attributes (content/text/value) or in "plaintext". Example: [ { "name": "core/heading", "attributes": { "level": 2, "content": "Intro" } }, { "name": "core/paragraph", "attributes": { "content": "Hello world." } } ]',
					'items'       => BlockSpecSchema::spec(),
					'default'     => [],
				],
				'status'     => [
					'type'        => 'string',
					'enum'        => $post_statuses,
					'description' => 'Post status',
					'default'     => 'draft',
				],
				'excerpt'    => [
					'type'        => 'string',
					'description' => 'Optional post excerpt',
					'default'     => '',
				],
				'categories' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => 'Array of category IDs',
					'default'     => [],
				],
				'tags'       => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Array of tag names',
					'default'     => [],
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
				'id'           => [ 'type' => 'integer' ],
				'title'        => [ 'type' => 'string' ],
				'status'       => [ 'type' => 'string' ],
				'permalink'    => [ 'type' => 'string' ],
				'edit_url'     => [ 'type' => 'string' ],
				'block_issues' => [
					'type'        => 'array',
					'description' => 'Optional, non-fatal block validation warnings (the post was still saved). Each is an actionable message such as "content[0].attributes.url is required for core/image". Fatal block problems are not returned here: they come back as a WP_Error with code "block_validation_failed" and the post is not created.',
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
		return $this->check_rest_permission( '/wp/v2/posts', 'POST', 'edit_posts' );
	}

	/**
	 * Execute the ability - create a post using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $title      Post title (required).
	 *     @type string $content    Post content.
	 *     @type string $status     Post status.
	 *     @type string $excerpt    Post excerpt.
	 *     @type array  $categories Category IDs.
	 *     @type array  $tags       Tag names.
	 * }
	 * @return array<string, mixed>|WP_Error Post data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Resolve the content to store from the content/blocks input (classic
		// rejection, block serialization, allowed-block enforcement, issues).
		$resolved = ( new WriteContentResolver() )->resolve( $args, 'post' );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$block_issues = $resolved['block_issues'];

		// Prepare REST API request data.
		$request_data = [
			'title'   => sanitize_text_field( $args['title'] ),
			'content' => $resolved['content'],
			'status'  => sanitize_key( $args['status'] ?? 'draft' ),
			'excerpt' => sanitize_textarea_field( $args['excerpt'] ?? '' ),
		];

		// Add categories if provided.
		if ( ! empty( $args['categories'] ) && is_array( $args['categories'] ) ) {
			$request_data['categories'] = array_map( 'absint', $args['categories'] );
		}

		// Add tags if provided (REST API expects tag IDs, so convert tag names to IDs).
		if ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) {
			$tag_ids = [];
			foreach ( $args['tags'] as $tag_name ) {
				$tag = get_term_by( 'name', $tag_name, 'post_tag' );
				if ( ! $tag ) {
					// Create tag if it doesn't exist.
					$tag = wp_insert_term( $tag_name, 'post_tag' );
					if ( is_wp_error( $tag ) ) {
						continue;
					}
					$tag_ids[] = $tag['term_id'];
				} else {
					$tag_ids[] = $tag->term_id;
				}
			}
			$request_data['tags'] = $tag_ids;
		}

		// Create REST request.
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
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
				$data['message'] ?? __( 'An error occurred while creating the post.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Return formatted post data.
		$result = [
			'id'        => $data['id'],
			'title'     => $data['title']['rendered'] ?? '',
			'status'    => $data['status'],
			'permalink' => $data['link'] ?? get_permalink( $data['id'] ),
			'edit_url'  => admin_url( 'post.php?post=' . $data['id'] . '&action=edit' ),
		];

		if ( ! empty( $block_issues ) ) {
			$result['block_issues'] = $block_issues;
		}

		return $result;
	}
}
