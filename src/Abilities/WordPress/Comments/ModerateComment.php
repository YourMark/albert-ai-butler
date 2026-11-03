<?php
/**
 * Moderate Comment Ability
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
 * Approves, holds, spams or trashes one or more comments, through core's comments endpoint.
 *
 * Annotated destructive, so safe mode holds it: until Revert ships nothing can put a
 * moderated comment back the way it was. 1.7.0 relaxes this.
 *
 * @since 1.6.0
 */
class ModerateComment extends BaseAbility {

	/**
	 * Most comments one call moderates.
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
		$this->id          = 'albert/moderate-comment';
		$this->label       = __( 'Moderate Comment', 'albert-ai-butler' );
		$this->description = __( 'Approve, hold, mark as spam or trash one or more comments.', 'albert-ai-butler' );
		$this->category    = 'comments';
		$this->group       = 'comments';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::delete(
				'Send several IDs in "ids" to moderate them in one call, so the person approves one request '
				. 'instead of many. "trash" can be undone from wp-admin for 30 days; to remove a comment for '
				. 'good use albert/delete-comment.'
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
				'id'     => [
					'type'        => 'integer',
					'description' => 'The comment ID. Use "ids" for several.',
					'minimum'     => 1,
				],
				'ids'    => [
					'type'        => 'array',
					'description' => 'Several comment IDs, all set to the same status.',
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'minItems'    => 1,
					'maxItems'    => self::MAX_IDS,
				],
				'status' => [
					'type'        => 'string',
					'enum'        => CommentPresenter::STATUSES,
					'description' => '"approved" publishes it, "hold" takes it back to waiting for approval, "spam" marks it as spam, "trash" moves it to the trash.',
				],
			],
			'required'   => [ 'status' ],
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
				'status'  => [ 'type' => 'string' ],
				'updated' => [
					'type'        => 'array',
					'description' => 'IDs of the comments now in that status.',
					'items'       => [ 'type' => 'integer' ],
				],
				'failed'  => [
					'type'        => 'array',
					'description' => 'Comments that could not be changed, each { id, code, message }.',
					'items'       => [ 'type' => 'object' ],
				],
			],
			'required'   => [ 'status', 'updated', 'failed' ],
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
		$status = (string) ( $args['status'] ?? '' );
		if ( ! in_array( $status, CommentPresenter::STATUSES, true ) ) {
			return new WP_Error( 'comment_status_invalid', __( 'Status must be approved, hold, spam or trash.', 'albert-ai-butler' ), [ 'status' => 400 ] );
		}

		$ids = isset( $args['ids'] ) && is_array( $args['ids'] ) ? $args['ids'] : [];
		if ( isset( $args['id'] ) ) {
			$ids[] = $args['id'];
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( $ids === [] ) {
			return new WP_Error( 'comment_id_missing', __( 'Send the comment to moderate in "id", or several in "ids".', 'albert-ai-butler' ), [ 'status' => 400 ] );
		}

		if ( count( $ids ) > self::MAX_IDS ) {
			return new WP_Error(
				'comment_too_many',
				sprintf(
					/* translators: %d: maximum number of comments */
					__( 'Moderate at most %d comments per call.', 'albert-ai-butler' ),
					self::MAX_IDS
				),
				[ 'status' => 400 ]
			);
		}

		$updated = [];
		$failed  = [];

		foreach ( $ids as $id ) {
			$result = $this->moderate( $id, $status );

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

			$updated[] = $id;
		}

		return [
			'status'  => $status,
			'updated' => $updated,
			'failed'  => $failed,
		];
	}

	/**
	 * Set one comment's status.
	 *
	 * @param int    $id     Comment ID.
	 * @param string $status REST status.
	 * @return bool|WP_Error
	 * @since 1.6.0
	 */
	private function moderate( int $id, string $status ): bool|WP_Error {
		$comment = CommentPresenter::find( $id );
		if ( is_wp_error( $comment ) ) {
			return $comment;
		}

		// Core's endpoint reports an unchanged status as a failed edit.
		if ( CommentPresenter::status( $comment ) === $status ) {
			return true;
		}

		$request = new WP_REST_Request( 'POST', '/wp/v2/comments/' . $id );
		$request->set_param( 'status', $status );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return CommentPresenter::rest_failure(
				rest_get_server()->response_to_data( $response, false ),
				$response->get_status(),
				__( 'The comment could not be moderated.', 'albert-ai-butler' )
			);
		}

		return true;
	}
}
