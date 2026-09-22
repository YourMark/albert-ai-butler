<?php
/**
 * Create Pattern Ability
 *
 * Saves assistant-composed blocks as a reusable block pattern (a wp_block post),
 * so a layout can be reused across the site.
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Blocks
 * @since      1.5.0
 */

namespace Albert\Abilities\WordPress\Blocks;

use Albert\Abstracts\BaseAbility;
use Albert\Blocks\BlockSpecSchema;
use Albert\Blocks\WriteContentResolver;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Create Pattern ability class.
 *
 * Composes the blocks through the same serializer the post/page writes use, then
 * stores them as a wp_block post. A synced pattern is reused by reference so
 * edits propagate; an unsynced one is a copy-on-insert template.
 *
 * @since 1.5.0
 */
class CreatePattern extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.5.0
	 */
	public function __construct() {
		$this->id          = 'albert/create-pattern';
		$this->label       = __( 'Create Pattern', 'albert-ai-butler' );
		$this->description = __( 'Save a reusable block pattern from composed blocks, so a layout can be reused across the site. This creates a user pattern; theme and plugin (registered) patterns are read-only and cannot be created or changed here. Send block specs in "blocks". By default the pattern is an unsynced copy-on-insert template; set "synced" true to make one source that updates everywhere it is used.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'patterns';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [ 'public' => true ],
			'annotations' => Annotations::create(
				'Compose the pattern in "blocks" the same way you build a post body. A registered (theme/plugin) '
				. 'pattern cannot be created here; this saves a user pattern. Reuse it later with view-pattern.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema.
	 *
	 * @return array<string, mixed>
	 * @since 1.5.0
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'title'      => [
					'type'        => 'string',
					'description' => 'The pattern title.',
				],
				'blocks'     => [
					'type'        => 'array',
					'description' => 'Structured block specs for the pattern, same shape as create-post\'s "blocks". Preferred over "content".',
					'items'       => BlockSpecSchema::spec(),
				],
				'content'    => [
					'type'        => 'string',
					'description' => 'The pattern content as block markup or HTML. Ignored when "blocks" is provided.',
				],
				'synced'     => [
					'type'        => 'boolean',
					'description' => 'True for a synced pattern (one source, reused by reference, edits propagate). Default false: an unsynced copy-on-insert template.',
					'default'     => false,
				],
				'categories' => [
					'type'        => 'array',
					'description' => 'Optional pattern category names to file the pattern under.',
					'items'       => [ 'type' => 'string' ],
				],
			],
			'required'   => [ 'title' ],
		];
	}

	/**
	 * Get the output schema.
	 *
	 * @return array<string, mixed>
	 * @since 1.5.0
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'           => [ 'type' => 'integer' ],
				'name'         => [ 'type' => 'string' ],
				'title'        => [ 'type' => 'string' ],
				'syncStatus'   => [ 'type' => 'string' ],
				'edit_url'     => [ 'type' => 'string' ],
				'block_issues' => [
					'type'        => 'array',
					'description' => 'Optional, non-fatal block validation warnings (the pattern was still saved).',
					'items'       => [ 'type' => 'string' ],
				],
			],
			'required'   => [ 'id', 'name', 'title', 'syncStatus' ],
		];
	}

	/**
	 * Creating a user pattern needs the same capability as saving a block.
	 *
	 * @return bool|WP_Error
	 * @since 1.5.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->check_rest_permission( '/wp/v2/blocks', 'POST', 'edit_posts' );
	}

	/**
	 * Execute: compose the blocks and store them as a wp_block pattern.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 * @return array<string, mixed>|WP_Error
	 * @since 1.5.0
	 */
	public function execute( array $args ): array|WP_Error {
		$title = isset( $args['title'] ) && is_string( $args['title'] ) ? trim( $args['title'] ) : '';

		if ( $title === '' ) {
			return new WP_Error(
				'pattern_title_required',
				__( 'A pattern title is required.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$resolved = ( new WriteContentResolver() )->resolve( $args, 'post' );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$request = new WP_REST_Request( 'POST', '/wp/v2/blocks' );
		$request->set_param( 'title', sanitize_text_field( $title ) );
		$request->set_param( 'content', $resolved['content'] );
		$request->set_param( 'status', 'publish' );

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $response->is_error() ) {
			return new WP_Error(
				$data['code'] ?? 'rest_error',
				$data['message'] ?? __( 'The pattern could not be saved.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		$id = (int) ( $data['id'] ?? 0 );

		// Unsynced is the copy-on-insert default; the meta marks it. A synced
		// pattern carries no such meta.
		$synced = ! empty( $args['synced'] );
		if ( ! $synced ) {
			update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );
		}

		if ( ! empty( $args['categories'] ) && is_array( $args['categories'] ) ) {
			wp_set_object_terms( $id, array_map( 'sanitize_text_field', $args['categories'] ), 'wp_pattern_category', false );
		}

		$edit_url = get_edit_post_link( $id, 'raw' );

		return [
			'id'           => $id,
			'name'         => (string) get_post_field( 'post_name', $id ),
			'title'        => $title,
			'syncStatus'   => $synced ? 'synced' : 'unsynced',
			'edit_url'     => is_string( $edit_url ) ? $edit_url : '',
			'block_issues' => $resolved['block_issues'],
		];
	}
}
