<?php
/**
 * Delete Comment Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Comments
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Comments;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Moves a comment to the trash, or deletes it permanently with `force`, through core's comments endpoint.
 *
 * Annotated destructive because `force` purges the comment.
 *
 * @since 1.6.0
 */
class DeleteComment extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/delete-comment';
		$this->label       = __( 'Delete Comment', 'albert-ai-butler' );
		$this->description = __( 'Move a comment to the trash; send force to delete it permanently. Get the ID from albert/find-comments.', 'albert-ai-butler' );
		$this->category    = 'comments';
		$this->group       = 'comments';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::delete(
				'A trashed comment can be restored from wp-admin until the trash is emptied. "force" cannot be '
				. 'undone; ask the person before sending it.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'comment_id' => [
					'type'        => 'integer',
					'description' => 'The ID of the comment to delete, from albert/find-comments.',
					'minimum'     => 1,
				],
				'force'      => [
					'type'        => 'boolean',
					'description' => 'Delete permanently instead of moving to the trash. Replies stay and move up a level. Default false.',
					'default'     => false,
				],
			],
			'required'   => [ 'comment_id' ],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'              => [ 'type' => 'integer' ],
				'trashed'         => [
					'type'        => 'boolean',
					'description' => 'True when the comment is now in the trash.',
				],
				'deleted'         => [
					'type'        => 'boolean',
					'description' => 'True when the comment is permanently deleted.',
				],
				'previous_status' => [
					'type'        => 'string',
					'description' => 'The status before this call: approved, hold, spam, trash, or post-trashed when the comment\'s post is in the trash.',
				],
			],
			'required'   => [ 'id', 'trashed', 'deleted', 'previous_status' ],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.6.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( CommentPresenter::CAPABILITY );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.6.0
	 */
	public function execute( array $args ): array|WP_Error {
		$comment = CommentPresenter::find( is_int( $args['comment_id'] ?? null ) ? $args['comment_id'] : 0 );
		if ( is_wp_error( $comment ) ) {
			return $comment;
		}

		$force           = ! empty( $args['force'] );
		$id              = (int) $comment->comment_ID;
		$previous_status = CommentPresenter::status( $comment );

		$request = new WP_REST_Request( 'DELETE', '/wp/v2/comments/' . $id );
		$request->set_param( 'force', $force );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return self::rest_failure(
				rest_get_server()->response_to_data( $response, false ),
				$response->get_status(),
				__( 'The comment could not be deleted.', 'albert-ai-butler' ),
				CommentPresenter::PERMISSION_DENIED
			);
		}

		return [
			'id'              => $id,
			'trashed'         => ! $force,
			'deleted'         => $force,
			'previous_status' => $previous_status,
		];
	}
}
