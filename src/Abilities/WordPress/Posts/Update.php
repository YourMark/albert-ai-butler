<?php
/**
 * Update Post Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Posts
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Posts;

use Albert\Abilities\WordPress\PostFields;
use Albert\Abilities\WordPress\PostTerms;
use Albert\Abstracts\BaseAbility;
use Albert\Blocks\BlockSpecSchema;
use Albert\Blocks\WriteContentResolver;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Update Post Ability class
 *
 * Allows AI assistants to update WordPress posts via the abilities API.
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
		$this->id          = 'albert/update-post';
		$this->label       = __( 'Update Post', 'albert-ai-butler' );
		$this->description = __( 'Update an existing post: only the fields you send change. Read it first with albert/view-post before replacing content or blocks; to change one block use albert/edit-post-block, and for a new post use albert/create-post.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'posts';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(
				'Only the fields you send change. `content` and `blocks` replace the whole post body: before sending either, '
				. 'read the post with `albert/view-post` and `"format": ["blocks"]`, edit that tree, and send it back, '
				. 'writing without reading silently discards everything you did not include. Keep every existing '
				. 'image block\'s `id` and `url` exactly as they were unless you were asked to change the image. To '
				. 'change one block, use `albert/edit-post-block` instead: it is cheaper and leaves every other block '
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

		$properties = [
			'id'         => [
				'type'        => 'integer',
				'description' => 'The post ID to update',
				'minimum'     => 1,
			],
			'title'      => [
				'type'        => 'string',
				'description' => 'The post title',
			],
			'content'    => [
				'type'        => 'string',
				'description' => 'The post content as a string. Accepts WordPress block markup (<!-- wp:... -->), stored as sent, or HTML (converted to blocks automatically). For multi-block, nested layouts prefer the structured "blocks" field instead. Ignored when "blocks" is provided. Replaces the entire post body when set.',
			],
			'blocks'     => [
				'type'        => 'array',
				'description' => 'Structured block specs (preferred over "content"). Replaces the entire post body. Each item is { "name": "core/paragraph", "attributes": { ... }, "innerBlocks": [ ... ] }; innerBlocks is the same shape recursively for layout blocks (e.g. core/columns > core/column). Put text in attributes (content/text/value) or in "plaintext". Example: [ { "name": "core/heading", "attributes": { "level": 2, "content": "Intro" } }, { "name": "core/paragraph", "attributes": { "content": "Hello world." } } ]',
				'items'       => BlockSpecSchema::spec(),
			],
			'status'     => [
				'type'        => 'string',
				'enum'        => $post_statuses,
				'description' => 'Post status',
			],
			'excerpt'    => [
				'type'        => 'string',
				'description' => 'Optional post excerpt',
			],
			// The anyOf title is the only schema text core puts in its error, so it carries the pointer to remove-post-terms.
			'categories' => [
				'type'        => 'array',
				'anyOf'       => [ self::non_empty_list( 'integer', 'categories' ) ],
				'description' => 'Deprecated: use albert/add-post-terms or albert/remove-post-terms. Category IDs that replace the post\'s categories. To take categories off, use albert/remove-post-terms.',
			],
			'tags'       => [
				'type'        => 'array',
				'anyOf'       => [ self::non_empty_list( 'string', 'tags' ) ],
				'description' => 'Deprecated: use albert/add-post-terms or albert/remove-post-terms. Tag names, created when missing, that replace the post\'s tags. To take tags off, use albert/remove-post-terms.',
			],
		];

		return [
			'type'          => 'object',
			'properties'    => array_merge( $properties, PostFields::input_schema( PostFields::POST ) ),
			'required'      => [ 'id' ],
			// Refuses a call that sends nothing but `id`.
			'minProperties' => 2,
		];
	}

	/**
	 * A list of at least one item, whose title names albert/remove-post-terms for an empty one.
	 *
	 * @param string $type What the list holds.
	 * @param string $what "categories" or "tags".
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	private static function non_empty_list( string $type, string $what ): array {
		return [
			'title'    => 'list with at least one item (to take ' . $what . ' off, use albert/remove-post-terms)',
			'type'     => 'array',
			'items'    => [ 'type' => $type ],
			'minItems' => 1,
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
				'slug'         => [
					'type'        => 'string',
					'description' => 'The slug that was saved, which may carry a number WordPress added to keep it unique.',
				],
				'date'         => [
					'type'        => 'string',
					'description' => 'The publish date in the site\'s timezone. With status "future" this is when it will be published.',
				],
				'block_issues' => [
					'type'        => 'array',
					'description' => 'Optional, non-fatal block validation warnings (the post was still saved). Each is an actionable message such as "content[0].attributes.level must be between 1 and 6 (got 9). Defaulting to 2.". Fatal block problems are not returned here: they come back as a WP_Error with code "block_validation_failed" and the post is not updated.',
					'items'       => [ 'type' => 'string' ],
				],
			],
			'required'   => [ 'id', 'title', 'status' ],
		];
	}

	/**
	 * Check if current user has permission to execute this ability.
	 *
	 * Uses the permission callback from the WordPress REST API endpoint.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->check_rest_permission( '/wp/v2/posts/(?P<id>[\\d]+)', 'POST', 'edit_posts' );
	}

	/**
	 * Execute the ability - update a post using WordPress REST API.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type int    $id         Post ID (required).
	 *     @type string $title      Post title.
	 *     @type string $content    Post content.
	 *     @type string $status     Post status.
	 *     @type string $excerpt    Post excerpt.
	 *     @type array  $categories Category IDs.
	 *     @type array  $tags       Tag names.
	 *
	 *     Plus the fields in PostFields::POST.
	 * }
	 * @return array<string, mixed>|WP_Error Post data on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		$post_id = absint( $args['id'] );

		// Checked before anything is written: resolving tags creates terms, so a
		// bad id or a refused post must stop the call first.
		$refused = $this->refuse_unless_editable( $post_id );
		if ( $refused ) {
			return $refused;
		}

		$request_data = PostFields::to_rest( $args, PostFields::POST, 'post', $post_id );
		if ( is_wp_error( $request_data ) ) {
			return $request_data;
		}

		$block_issues = [];

		if ( isset( $args['title'] ) ) {
			$request_data['title'] = sanitize_text_field( $args['title'] );
		}

		// Content is only touched when structured "blocks" or a "content" string
		// is provided; otherwise the existing post body is left untouched.
		$has_blocks_input  = ! empty( $args['blocks'] ) && is_array( $args['blocks'] );
		$has_content_input = $has_blocks_input || isset( $args['content'] );

		if ( $has_content_input ) {
			// Resolve the content to store (classic rejection, block
			// serialization, allowed-block enforcement with the Update exemption,
			// and any block issues).
			$resolved = ( new WriteContentResolver() )->resolve( $args, 'post', $post_id );
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

		// An empty list never clears: removing terms is albert/remove-post-terms.
		if ( ! empty( $args['categories'] ) && is_array( $args['categories'] ) ) {
			$request_data['categories'] = array_map( 'intval', $args['categories'] );
			$unknown                    = PostTerms::check_category_ids( $request_data['categories'] );
			if ( $unknown ) {
				return $unknown;
			}
		}

		if ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) {
			$tag_ids = PostTerms::tag_ids( $args['tags'] );
			if ( is_wp_error( $tag_ids ) ) {
				return $tag_ids;
			}
			$request_data['tags'] = $tag_ids;
		}

		// Create REST request.
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
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
			if ( is_array( $data ) && ( $data['code'] ?? '' ) === 'rest_post_invalid_id' ) {
				return self::not_found( $post_id );
			}

			return self::rest_failure( $data, $response->get_status(), __( 'The post could not be updated.', 'albert-ai-butler' ), 'albert_post_permission_denied' );
		}

		// Return formatted post data.
		$post_id = $data['id'];

		$result = [
			'id'        => $post_id,
			'title'     => $data['title']['rendered'] ?? '',
			'status'    => $data['status'],
			'permalink' => $data['link'] ?? '',
			'edit_url'  => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'slug'      => $data['slug'] ?? '',
			'date'      => $data['date'] ?? '',
		];

		if ( ! empty( $block_issues ) ) {
			$result['block_issues'] = $block_issues;
		}

		return $result;
	}

	/**
	 * Refuse an id that is not an editable post, before any side effect runs.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Error|null Null when the post exists and the user may edit it.
	 * @since 1.6.0
	 */
	private function refuse_unless_editable( int $post_id ): ?WP_Error {
		// get_post( 0 ) returns the global post.
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		// Name the other type only to a user who may read it, so the hint leaks nothing.
		if ( $post && $post->post_type === 'page' && current_user_can( 'read_post', $post_id ) ) {
			return new WP_Error(
				'albert_post_not_found',
				/* translators: %d: page ID */
				sprintf( __( 'ID %d is a page, not a post. Use albert/update-page.', 'albert-ai-butler' ), $post_id ),
				[ 'status' => 404 ]
			);
		}

		if ( ! $post || $post->post_type !== 'post' ) {
			return self::not_found( $post_id );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'albert_post_permission_denied',
				/* translators: %d: post ID */
				sprintf( __( 'This account may not edit post %d.', 'albert-ai-butler' ), $post_id ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return null;
	}

	/**
	 * The error for an id that is not a post.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Error
	 * @since 1.6.0
	 */
	private static function not_found( int $post_id ): WP_Error {
		return new WP_Error(
			'albert_post_not_found',
			/* translators: %d: post ID */
			sprintf( __( 'No post with ID %d. Find it with albert/find-posts.', 'albert-ai-butler' ), $post_id ),
			[ 'status' => 404 ]
		);
	}
}
