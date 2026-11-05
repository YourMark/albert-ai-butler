<?php
/**
 * Update Pattern Ability
 *
 * Edits a user block pattern (a wp_block post): its blocks, title, sync status
 * or categories. Registered theme/plugin patterns are read-only.
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Blocks
 * @since      1.5.0
 */

namespace Albert\Abilities\WordPress\Blocks;

use Albert\Abstracts\BaseAbility;
use Albert\Blocks\BlockSpecSchema;
use Albert\Blocks\PatternCatalog;
use Albert\Blocks\WriteContentResolver;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Update Pattern ability class.
 *
 * Only user patterns can be edited; a registered pattern resolves to a
 * read-only error.
 *
 * @since 1.5.0
 */
class UpdatePattern extends BaseAbility {

	/**
	 * Pattern catalog, used to resolve the target and enforce user-only edits.
	 *
	 * @since 1.5.0
	 * @var PatternCatalog
	 */
	private PatternCatalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param PatternCatalog|null $catalog Optional catalog service (injectable for tests).
	 *
	 * @since 1.5.0
	 */
	public function __construct( ?PatternCatalog $catalog = null ) {
		$this->catalog = $catalog ?? new PatternCatalog();

		$this->id          = 'albert/update-pattern';
		$this->label       = __( 'Update Pattern', 'albert-ai-butler' );
		$this->description = __( 'Edit a user block pattern by name: its blocks, title, sync status or categories. Only user patterns can be edited; theme and plugin (registered) patterns are read-only. Find a pattern\'s name with find-patterns.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'patterns';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [ 'public' => true ],
			'annotations' => Annotations::update(
				'Send only the fields you want to change. Compose new `blocks` the same way you build a post body. '
				. 'A registered pattern cannot be edited and returns a read-only error.'
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
				'name'       => [
					'type'        => 'string',
					'description' => 'The user pattern\'s name (slug), as returned by find-patterns.',
				],
				'title'      => [
					'type'        => 'string',
					'description' => 'New title. Omit to leave unchanged.',
				],
				'blocks'     => [
					'type'        => 'array',
					'description' => 'New block specs for the pattern body. Omit to leave the content unchanged.',
					'items'       => BlockSpecSchema::spec(),
				],
				'content'    => [
					'type'        => 'string',
					'description' => 'New content as block markup or HTML. Ignored when "blocks" is provided.',
				],
				'synced'     => [
					'type'        => 'boolean',
					'description' => 'Set the sync status: true for synced (reused by reference), false for an unsynced copy. Omit to leave unchanged.',
				],
				'categories' => [
					'type'        => 'array',
					'description' => 'Replace the pattern\'s categories with these names. Omit to leave unchanged.',
					'items'       => [ 'type' => 'string' ],
				],
			],
			'required'   => [ 'name' ],
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
				'updated'      => [ 'type' => 'boolean' ],
				'block_issues' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
			'required'   => [ 'id', 'name', 'updated' ],
		];
	}

	/**
	 * Editing a user pattern needs the same capability as editing a block.
	 *
	 * @return bool|WP_Error
	 * @since 1.5.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->check_rest_permission( '/wp/v2/blocks', 'POST', 'edit_posts' );
	}

	/**
	 * Execute: edit the resolved user pattern.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 * @return array<string, mixed>|WP_Error
	 * @since 1.5.0
	 */
	public function execute( array $args ): array|WP_Error {
		$name    = isset( $args['name'] ) && is_string( $args['name'] ) ? trim( $args['name'] ) : '';
		$pattern = $this->resolve_user_pattern( $name );
		if ( $pattern instanceof WP_Error ) {
			return $pattern;
		}

		$id           = (int) $pattern['id'];
		$block_issues = [];
		$request      = new WP_REST_Request( 'POST', '/wp/v2/blocks/' . $id );

		if ( isset( $args['title'] ) && is_string( $args['title'] ) ) {
			$request->set_param( 'title', sanitize_text_field( $args['title'] ) );
		}

		$has_new_content = ( ! empty( $args['blocks'] ) && is_array( $args['blocks'] ) ) || isset( $args['content'] );
		if ( $has_new_content ) {
			$resolved = ( new WriteContentResolver() )->resolve_pattern( $args, $id );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$request->set_param( 'content', $resolved['content'] );
			$block_issues = $resolved['block_issues'];
		}

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $response->is_error() ) {
			return new WP_Error(
				$data['code'] ?? 'rest_error',
				$data['message'] ?? __( 'The pattern could not be updated.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		$sync = $this->apply_sync_status( $id, $args, $pattern['syncStatus'] );

		// Presence of the key means "replace", so an empty array clears every
		// category; only an omitted key leaves them unchanged.
		if ( isset( $args['categories'] ) && is_array( $args['categories'] ) ) {
			$terms = wp_set_object_terms( $id, array_map( 'sanitize_text_field', $args['categories'] ), 'wp_pattern_category', false );
			if ( is_wp_error( $terms ) ) {
				$block_issues[] = sprintf(
					/* translators: %s: error message. */
					__( 'The pattern was updated, but its categories could not be set: %s', 'albert-ai-butler' ),
					$terms->get_error_message()
				);
			}
		}

		return [
			'id'           => $id,
			'name'         => (string) get_post_field( 'post_name', $id ),
			'title'        => (string) get_post_field( 'post_title', $id ),
			'syncStatus'   => $sync,
			'updated'      => true,
			'block_issues' => $block_issues,
		];
	}

	/**
	 * Resolve a name to a user pattern, or a WP_Error.
	 *
	 * @param string $name Pattern name.
	 * @return array<string, mixed>|WP_Error User pattern record, or an error.
	 * @since 1.5.0
	 */
	private function resolve_user_pattern( string $name ) {
		if ( $name === '' ) {
			return new WP_Error( 'pattern_name_required', __( 'A pattern name is required.', 'albert-ai-butler' ), [ 'status' => 400 ] );
		}

		$pattern = $this->catalog->get( $name );

		if ( $pattern === null ) {
			return new WP_Error(
				'pattern_not_found',
				/* translators: %s: pattern name. */
				sprintf( __( 'No pattern named "%s" is registered on this site.', 'albert-ai-butler' ), $name ),
				[ 'status' => 404 ]
			);
		}

		if ( ( $pattern['source'] ?? '' ) !== 'user' ) {
			return new WP_Error(
				'pattern_read_only',
				/* translators: %s: pattern name. */
				sprintf( __( 'The pattern "%s" is a registered theme or plugin pattern and cannot be edited.', 'albert-ai-butler' ), $name ),
				[ 'status' => 403 ]
			);
		}

		return $pattern;
	}

	/**
	 * Apply a requested sync-status change and report the resulting status.
	 *
	 * @param int                  $id      Pattern post id.
	 * @param array<string, mixed> $args    Input parameters.
	 * @param string               $current Current sync status.
	 * @return string The status after any change.
	 * @since 1.5.0
	 */
	private function apply_sync_status( int $id, array $args, string $current ): string {
		if ( ! array_key_exists( 'synced', $args ) ) {
			return $current;
		}

		if ( ! empty( $args['synced'] ) ) {
			delete_post_meta( $id, 'wp_pattern_sync_status' );
			return 'synced';
		}

		update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );
		return 'unsynced';
	}
}
