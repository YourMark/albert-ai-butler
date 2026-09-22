<?php
/**
 * Delete Pattern Ability
 *
 * Deletes a user block pattern (a wp_block post). Registered theme/plugin
 * patterns are read-only and cannot be deleted.
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Blocks
 * @since      1.5.0
 */

namespace Albert\Abilities\WordPress\Blocks;

use Albert\Abstracts\BaseAbility;
use Albert\Blocks\PatternCatalog;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Delete Pattern ability class.
 *
 * @since 1.5.0
 */
class DeletePattern extends BaseAbility {

	/**
	 * Pattern catalog, used to resolve the target and enforce user-only deletes.
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

		$this->id          = 'albert/delete-pattern';
		$this->label       = __( 'Delete Pattern', 'albert-ai-butler' );
		$this->description = __( 'Delete a user block pattern by name. Only user patterns can be deleted; theme and plugin (registered) patterns are read-only. Find a pattern\'s name with find-patterns.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'patterns';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [ 'public' => true ],
			'annotations' => Annotations::delete(
				'A registered pattern cannot be deleted and returns a read-only error. Without "force" the pattern '
				. 'is trashed where the site allows it.'
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
				'name'  => [
					'type'        => 'string',
					'description' => 'The user pattern\'s name (slug), as returned by find-patterns.',
				],
				'force' => [
					'type'        => 'boolean',
					'description' => 'Bypass trash and delete permanently. Default false.',
					'default'     => false,
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
				'id'      => [ 'type' => 'integer' ],
				'name'    => [ 'type' => 'string' ],
				'deleted' => [ 'type' => 'boolean' ],
				'status'  => [ 'type' => 'string' ],
			],
			'required'   => [ 'id', 'deleted', 'status' ],
		];
	}

	/**
	 * Deleting a user pattern needs the delete capability for blocks.
	 *
	 * @return bool|WP_Error
	 * @since 1.5.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->check_rest_permission( '/wp/v2/blocks', 'DELETE', 'delete_posts' );
	}

	/**
	 * Execute: delete the resolved user pattern.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 * @return array<string, mixed>|WP_Error
	 * @since 1.5.0
	 */
	public function execute( array $args ): array|WP_Error {
		$name = isset( $args['name'] ) && is_string( $args['name'] ) ? trim( $args['name'] ) : '';

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
				sprintf( __( 'The pattern "%s" is a registered theme or plugin pattern and cannot be deleted.', 'albert-ai-butler' ), $name ),
				[ 'status' => 403 ]
			);
		}

		$id      = (int) $pattern['id'];
		$force   = ! empty( $args['force'] );
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/blocks/' . $id );
		$request->set_param( 'force', $force );

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $response->is_error() ) {
			return new WP_Error(
				$data['code'] ?? 'rest_error',
				$data['message'] ?? __( 'The pattern could not be deleted.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		return [
			'id'      => $id,
			'name'    => $name,
			'deleted' => true,
			'status'  => $force ? 'deleted' : 'trashed',
		];
	}
}
