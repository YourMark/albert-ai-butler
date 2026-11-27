<?php
/**
 * Reply To Comment Ability
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
 * Replies to a comment as the connected user, through core's comments endpoint.
 *
 * Annotated as a create, which it is; safe mode holds it by name instead
 * ({@see \Albert\SafeMode\RiskPolicy}), because the reply is public the moment it is
 * saved and speaks for the site.
 *
 * @since 1.6.0
 */
class ReplyToComment extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/reply-to-comment';
		$this->label       = __( 'Reply to Comment', 'albert-ai-butler' );
		$this->description = __( 'Publish a reply to a comment as the connected user, after reading it with albert/view-comment.', 'albert-ai-butler' );
		$this->category    = 'comments';
		$this->group       = 'comments';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::create(
				'The reply is public and signed with the connected user\'s name. Read the comment with '
				. 'albert/view-comment first, and show the person your reply before sending it. A comment waiting '
				. 'for approval is approved when you reply, as in wp-admin.'
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
					'description' => 'The ID of the comment to reply to, from albert/find-comments.',
					'minimum'     => 1,
				],
				'content'    => [
					'type'        => 'string',
					'description' => 'The reply text. Plain text; line breaks are kept.',
					'minLength'   => 1,
				],
			],
			'required'   => [ 'comment_id', 'content' ],
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
				'id'        => [
					'type'        => 'integer',
					'description' => 'The new reply\'s comment ID.',
				],
				'parent_id' => [
					'type'        => 'integer',
					'description' => 'The comment replied to.',
				],
				'post'      => [
					'type'        => 'object',
					'description' => 'The post the reply is on, as { id, name }.',
				],
				'status'    => [ 'type' => 'string' ],
				'permalink' => [ 'type' => 'string' ],
				'warning'   => [
					'type'        => 'string',
					'description' => 'Only when the reply was saved but the comment it answers could not be approved.',
				],
			],
			'required'   => [ 'id', 'parent_id', 'status' ],
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
		$parent = CommentPresenter::find( is_int( $args['comment_id'] ?? null ) ? $args['comment_id'] : 0 );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}

		$status = CommentPresenter::status( $parent );
		if ( $status === 'spam' || $status === 'trash' ) {
			return new WP_Error(
				'albert_invalid_comment_id',
				sprintf(
					/* translators: %s: comment status */
					__( 'This comment is marked %s. Approve it with albert/moderate-comment before replying.', 'albert-ai-butler' ),
					$status
				),
				[ 'status' => 400 ]
			);
		}

		if ( $status === 'post-trashed' ) {
			return new WP_Error(
				'albert_invalid_comment_id',
				__( 'The post this comment is on is in the trash. Restore the post before replying.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'post', (int) $parent->comment_post_ID );
		$request->set_param( 'parent', (int) $parent->comment_ID );
		$request->set_param( 'content', (string) $args['content'] );

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		if ( $response->is_error() ) {
			return self::rest_failure( $data, $response->get_status(), __( 'The reply could not be saved.', 'albert-ai-butler' ), CommentPresenter::PERMISSION_DENIED );
		}

		$result = [
			'id'        => (int) $data['id'],
			'parent_id' => (int) $parent->comment_ID,
			'post'      => CommentPresenter::post_reference( (int) $parent->comment_post_ID ),
			'status'    => (string) $data['status'],
			'permalink' => (string) $data['link'],
		];

		// wp-admin approves a held comment when a moderator replies to it; do the
		// same, or the reply would be public under an invisible comment. After the
		// reply, as wp-admin does, so a refused reply leaves the comment untouched.
		// The reply exists by now, so a failed approval is a warning: an error would
		// invite a retry that core refuses as a duplicate.
		if ( $status === 'hold' ) {
			$approved = ModerateComment::set_status(
				(int) $parent->comment_ID,
				'approved',
				__( 'The reply was saved, but the comment it answers could not be approved.', 'albert-ai-butler' )
			);
			if ( is_wp_error( $approved ) ) {
				$result['warning'] = sprintf(
					/* translators: %s: why the approval failed */
					__( 'The reply was saved, but the comment it answers is still waiting for approval: %s Approve it with albert/moderate-comment; do not send the reply again.', 'albert-ai-butler' ),
					$approved->get_error_message()
				);
			}
		}

		return $result;
	}
}
