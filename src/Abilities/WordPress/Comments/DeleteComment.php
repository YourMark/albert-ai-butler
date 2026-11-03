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
 * Permanently deletes a comment, through core's comments endpoint.
 *
 * Always `force`: moving to the trash is `moderate-comment`'s job, so this one does
 * the one thing the name says.
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
		$this->description = __( 'Permanently delete a comment. Its replies stay and move up a level.', 'albert-ai-butler' );
		$this->category    = 'comments';
		$this->group       = 'comments';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::delete(
				'This cannot be undone. To remove a comment from the site but keep a way back, use '
				. 'albert/moderate-comment with "trash" instead.'
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
				'id' => [
					'type'        => 'integer',
					'description' => 'The comment ID to delete.',
					'minimum'     => 1,
				],
			],
			'required'   => [ 'id' ],
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
				'id'      => [ 'type' => 'integer' ],
				'deleted' => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'id', 'deleted' ],
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
		$comment = CommentPresenter::find( absint( $args['id'] ?? 0 ) );
		if ( is_wp_error( $comment ) ) {
			return $comment;
		}

		$id      = (int) $comment->comment_ID;
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/comments/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return CommentPresenter::rest_failure(
				rest_get_server()->response_to_data( $response, false ),
				$response->get_status(),
				__( 'The comment could not be deleted.', 'albert-ai-butler' )
			);
		}

		return [
			'id'      => $id,
			'deleted' => true,
		];
	}
}
