<?php
/**
 * Find Posts Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Posts
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Posts;

use Albert\Abstracts\BaseAbility;
use Albert\Blocks\ContentFormatter;
use Albert\Blocks\EditorMode;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

/**
 * Find Posts Ability class
 *
 * Allows AI assistants to find and search WordPress posts via the abilities API.
 *
 * @since 1.0.0
 */
class FindPosts extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-posts';
		$this->label       = __( 'Find Posts', 'albert-ai-butler' );
		$this->description = __( 'Find and search WordPress posts with optional filtering and pagination.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'posts';

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
				'page'       => [
					'type'        => 'integer',
					'description' => 'Page number for pagination',
					'default'     => 1,
					'minimum'     => 1,
				],
				'per_page'   => [
					'type'        => 'integer',
					'description' => 'Number of posts per page',
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				],
				'search'     => [
					'type'        => 'string',
					'description' => 'Search posts by title or content',
					'default'     => '',
				],
				'status'     => [
					'type'        => 'string',
					'description' => 'Filter posts by status',
					'enum'        => $post_statuses,
				],
				'categories' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => 'Filter by category IDs',
				],
				'order'      => [
					'type'        => 'string',
					'description' => 'Order direction',
					'enum'        => [ 'asc', 'desc' ],
					'default'     => 'desc',
				],
				'orderby'    => [
					'type'        => 'string',
					'description' => 'Sort by field',
					'enum'        => [ 'date', 'modified', 'title', 'id' ],
					'default'     => 'date',
				],
				'format'     => ContentFormatter::input_schema_property(),
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
				'posts'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'         => [ 'type' => 'integer' ],
							'title'      => [ 'type' => 'string' ],
							'content'    => [
								'type'        => 'string',
								'description' => 'The stored post content: raw block markup (<!-- wp:... --> comment-delimited) for block-editor posts, plain HTML for classic-editor posts (see "has_blocks" / "editor"). Consistent with view-post. Included when "content" is requested (default).',
							],
							'blocks'     => [
								'type'        => 'array',
								'description' => 'The post content parsed into a structured block tree. Each node has { name, attributes, innerBlocks, plaintext, path }. "path" (e.g. [2], [2,0]) is the block\'s address: pass it to the edit/add/remove/move-post-block abilities. Included when "blocks" is requested (default).',
								'items'       => [ 'type' => 'object' ],
							],
							'plaintext'  => [
								'type'        => 'string',
								'description' => 'Human-readable plain text of the whole post content. Included when "plaintext" is requested (default).',
							],
							'html'       => [
								'type'        => 'string',
								'description' => 'Rendered HTML produced by do_blocks(), so it reflects dynamic/server-rendered block output. Only included when "html" is requested.',
							],
							'markdown'   => [
								'type'        => 'string',
								'description' => 'Markdown rendering of the post content. Only included when "markdown" is requested.',
							],
							'editor'     => [
								'type'        => 'string',
								'enum'        => [ 'block', 'classic' ],
								'description' => 'Which editor this post uses. "block" means send structured "blocks" when writing; "classic" means send plain HTML in "content" instead.',
							],
							'has_blocks' => [
								'type'        => 'boolean',
								'description' => 'Whether the stored content actually contains block markup.',
							],
							'truncated'  => [
								'type'        => 'boolean',
								'description' => 'Present and true only when a text representation for this item was byte-capped (a "…[truncated, N more characters]" marker was appended). For the full content of a large post, call view-post, which paginates by block.',
							],
							'excerpt'    => [ 'type' => 'string' ],
							'status'     => [ 'type' => 'string' ],
							'date'       => [ 'type' => 'string' ],
							'modified'   => [ 'type' => 'string' ],
							'author'     => [ 'type' => 'integer' ],
							'permalink'  => [ 'type' => 'string' ],
							'categories' => [
								'type'  => 'array',
								'items' => [ 'type' => 'integer' ],
							],
							'tags'       => [
								'type'  => 'array',
								'items' => [ 'type' => 'integer' ],
							],
						],
					],
				],
				'total'       => [ 'type' => 'integer' ],
				'total_pages' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'posts', 'total' ],
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
		return $this->check_rest_permission( '/wp/v2/posts', 'GET', 'edit_posts' );
	}

	/**
	 * Execute the ability - list posts using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type int    $page       Page number for pagination.
	 *     @type int    $per_page   Number of posts per page.
	 *     @type string $search     Search query.
	 *     @type string $status     Filter by status.
	 *     @type array  $categories Filter by category IDs.
	 *     @type string $order      Order direction.
	 *     @type string $orderby    Sort by field.
	 * }
	 * @return array<string, mixed>|WP_Error Posts list on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		// Create REST request.
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );

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

		// Set categories filter if provided.
		if ( ! empty( $args['categories'] ) && is_array( $args['categories'] ) ) {
			$request->set_param( 'categories', array_map( 'absint', $args['categories'] ) );
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
				$data['message'] ?? __( 'An error occurred while retrieving posts.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		// Format posts data.
		//
		// Note: unlike rendered REST output, `content` here is the post's RAW
		// block markup (post_content), mirrored by a structured `blocks` tree and
		// `plaintext`. This keeps find-posts consistent with view-post so the
		// assistant always sees the editable block format, not rendered HTML.
		// The selectable representations are resolved by ContentFormatter; the
		// default keeps the original content/blocks/plaintext fields, and the
		// heavier html/markdown are only built when explicitly requested.
		$format = isset( $args['format'] ) && is_array( $args['format'] ) ? $args['format'] : [];

		// Prime the post cache once for the whole result set so the per-item
		// get_post() calls below hit the cache instead of the database (avoids N+1).
		$post_ids = array_values( array_map( static fn ( $post_data ): int => (int) $post_data['id'], $data ) );
		if ( $post_ids !== [] ) {
			_prime_post_caches( $post_ids );
		}

		$posts = [];
		foreach ( $data as $post_data ) {
			$post        = get_post( (int) $post_data['id'] );
			$raw_content = $post ? $post->post_content : '';

			$posts[] = array_merge(
				[
					'id'    => $post_data['id'],
					'title' => $post_data['title']['rendered'] ?? '',
				],
				ContentFormatter::build( $raw_content, $format, [ 'paginate' => false ] ),
				$post ? EditorMode::signal( $post ) : EditorMode::signal_for_content( $raw_content ),
				[
					'excerpt'    => $post_data['excerpt']['rendered'] ?? '',
					'status'     => $post_data['status'] ?? '',
					'date'       => $post_data['date'] ?? '',
					'modified'   => $post_data['modified'] ?? '',
					'author'     => $post_data['author'] ?? 0,
					'permalink'  => $post_data['link'] ?? '',
					'categories' => $post_data['categories'] ?? [],
					'tags'       => $post_data['tags'] ?? [],
				]
			);
		}

		// Get pagination headers.
		$headers     = $response->get_headers();
		$total       = isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : count( $posts );
		$total_pages = isset( $headers['X-WP-TotalPages'] ) ? (int) $headers['X-WP-TotalPages'] : 1;

		return [
			'posts'       => $posts,
			'total'       => $total,
			'total_pages' => $total_pages,
		];
	}
}
