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
 * Approves, holds or marks as spam one or more comments, through core's comments endpoint.
 *
 * Not destructive, since another call can set the status back; safe mode holds it by name
 * instead ({@see \Albert\SafeMode\RiskPolicy}), because approving publishes a visitor's
 * text on the site.
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
	 * Statuses this ability sets; trash belongs to albert/delete-comment.
	 *
	 * @since 1.6.0
	 */
	private const STATUSES = [ 'approved', 'hold', 'spam' ];

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/moderate-comment';
		$this->label       = __( 'Moderate Comment', 'albert-ai-butler' );
		$this->description = __( 'Set the moderation status of up to 50 comments in one call; find them first with albert/find-comments.', 'albert-ai-butler' );
		$this->category    = 'comments';
		$this->group       = 'comments';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => [ 'idempotent' => true ] + Annotations::update(
				'Send every comment that gets the same status in one call, so the person approves one request '
				. 'instead of many. To move a comment to the trash use albert/delete-comment.'
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
					'description' => 'IDs of the comments to moderate, all set to the same status. One ID is fine.',
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'minItems'    => 1,
					'maxItems'    => self::MAX_IDS,
				],
				'status'      => [
					'type'        => 'string',
					'enum'        => self::STATUSES,
					'description' => '"approved" publishes the comments, "hold" takes them back to waiting for approval, "spam" marks them as spam.',
				],
			],
			'required'   => [ 'comment_ids', 'status' ],
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
		$status = (string) $args['status'];
		$ids    = array_values( array_unique( array_map( 'intval', (array) $args['comment_ids'] ) ) );

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

		return self::set_status( $id, $status, __( 'The comment could not be moderated.', 'albert-ai-butler' ) );
	}

	/**
	 * Set a comment's status through core's endpoint, so its checks and hooks run.
	 *
	 * Shared with {@see ReplyToComment}, which approves the comment it answers.
	 *
	 * @param int    $id       Comment ID.
	 * @param string $status   REST status.
	 * @param string $fallback Error message when core gives none.
	 * @return bool|WP_Error
	 * @since 1.6.0
	 */
	public static function set_status( int $id, string $status, string $fallback ): bool|WP_Error {
		$request = new WP_REST_Request( 'POST', '/wp/v2/comments/' . $id );
		$request->set_param( 'status', $status );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return self::rest_failure(
				rest_get_server()->response_to_data( $response, false ),
				$response->get_status(),
				$fallback,
				CommentPresenter::PERMISSION_DENIED
			);
		}

		return true;
	}
}
