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
 * Moves comments to the trash, or deletes them permanently with `force`, through core's comments endpoint.
 *
 * Annotated destructive because `force` purges the comments. Safe mode lets the trash
 * through and holds the permanent call ({@see \Albert\SafeMode\Gate}), once for the whole list.
 *
 * @since 1.6.0
 */
class DeleteComment extends BaseAbility {

	/**
	 * Most comments one call deletes.
	 *
	 * @since 1.6.0
	 */
	private const MAX_IDS = 50;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/delete-comment';
		$this->label       = __( 'Delete Comment', 'albert-ai-butler' );
		$this->description = __( 'Move up to 50 comments to the trash in one call; send force to delete them permanently. Get the IDs from albert/find-comments.', 'albert-ai-butler' );
		$this->category    = 'comments';
		$this->group       = 'comments';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::delete(
				'Without "force" the comments go to the trash, where they can be restored from wp-admin until the '
				. 'trash is emptied. "force": true deletes them permanently and cannot be undone; ask the person '
				. 'first. Send every comment in one call, so a permanent deletion needs one approval instead of many.'
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
				'comment_ids' => [
					'type'        => 'array',
					'description' => 'IDs of the comments to delete, from albert/find-comments. One ID is fine.',
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'minItems'    => 1,
					'maxItems'    => self::MAX_IDS,
				],
				'force'       => [
					'type'        => 'boolean',
					'description' => 'Delete permanently instead of moving to the trash. Replies stay and move up a level. Default false.',
					'default'     => false,
				],
			],
			'required'   => [ 'comment_ids' ],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_output_schema(): array {
		$done = [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'id'              => [ 'type' => 'integer' ],
					'previous_status' => [
						'type'        => 'string',
						'description' => 'The status before this call: approved, hold, spam, trash, or post-trashed when the comment\'s post is in the trash.',
					],
				],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'trashed' => [ 'description' => 'Comments now in the trash, each { id, previous_status }.' ] + $done,
				'deleted' => [ 'description' => 'Comments permanently deleted, each { id, previous_status }.' ] + $done,
				'failed'  => [
					'type'        => 'array',
					'description' => 'Comments that could not be deleted, each { id, code, message }.',
					'items'       => [ 'type' => 'object' ],
				],
			],
			'required'   => [ 'trashed', 'deleted', 'failed' ],
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
	 * A single comment's failure is returned as the error; with several, the others
	 * still go ahead and each failure is listed.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.6.0
	 */
	public function execute( array $args ): array|WP_Error {
		$force = ! empty( $args['force'] );
		$ids   = array_values( array_unique( array_map( 'intval', (array) ( $args['comment_ids'] ?? [] ) ) ) );

		$done   = [];
		$failed = [];

		foreach ( $ids as $id ) {
			$result = $this->delete( $id, $force );

			if ( is_wp_error( $result ) ) {
				if ( count( $ids ) === 1 ) {
					return $result;
				}

				$failed[] = [
					'id'      => $id,
					'code'    => (string) $result->get_error_code(),
					'message' => $result->get_error_message(),
				];
				continue;
			}

			$done[] = [
				'id'              => $id,
				'previous_status' => $result,
			];
		}

		return [
			'trashed' => $force ? [] : $done,
			'deleted' => $force ? $done : [],
			'failed'  => $failed,
		];
	}

	/**
	 * Trash or delete one comment.
	 *
	 * @param int  $id    Comment ID.
	 * @param bool $force Delete permanently.
	 * @return string|WP_Error The comment's status before the call, or the error.
	 * @since 1.6.0
	 */
	private function delete( int $id, bool $force ): string|WP_Error {
		$comment = CommentPresenter::find( $id );
		if ( is_wp_error( $comment ) ) {
			return $comment;
		}

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

		return $previous_status;
	}
}
