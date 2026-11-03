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
use WP_Comment;
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
		$this->description = __( 'Publish a reply to a comment, as the connected user.', 'albert-ai-butler' );
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
				'id'      => [
					'type'        => 'integer',
					'description' => 'The ID of the comment to reply to.',
					'minimum'     => 1,
				],
				'content' => [
					'type'        => 'string',
					'description' => 'The reply text. Plain text; line breaks are kept.',
					'minLength'   => 1,
				],
			],
			'required'   => [ 'id', 'content' ],
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
				'id'        => [ 'type' => 'integer' ],
				'parent'    => [ 'type' => 'integer' ],
				'post_id'   => [ 'type' => 'integer' ],
				'status'    => [ 'type' => 'string' ],
				'permalink' => [ 'type' => 'string' ],
			],
			'required'   => [ 'id', 'parent', 'status' ],
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
		$parent = CommentPresenter::find( absint( $args['id'] ?? 0 ) );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}

		$content = trim( (string) ( $args['content'] ?? '' ) );
		if ( $content === '' ) {
			return new WP_Error( 'comment_content_empty', __( 'The reply is empty.', 'albert-ai-butler' ), [ 'status' => 400 ] );
		}

		$status = CommentPresenter::status( $parent );
		if ( $status === 'spam' || $status === 'trash' ) {
			return new WP_Error(
				'comment_not_repliable',
				sprintf(
					/* translators: %s: comment status */
					__( 'This comment is marked %s. Approve it with albert/moderate-comment before replying.', 'albert-ai-butler' ),
					$status
				),
				[ 'status' => 400 ]
			);
		}

		// wp-admin approves a held comment when a moderator replies to it; do the
		// same, or the reply would be public under an invisible comment.
		if ( $status === 'hold' ) {
			$approved = $this->approve( $parent );
			if ( is_wp_error( $approved ) ) {
				return $approved;
			}
		}

		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'post', (int) $parent->comment_post_ID );
		$request->set_param( 'parent', (int) $parent->comment_ID );
		$request->set_param( 'content', $content );

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $response->is_error() ) {
			return CommentPresenter::rest_failure( $data, $response->get_status(), __( 'The reply could not be saved.', 'albert-ai-butler' ) );
		}

		$reply = get_comment( (int) ( $data['id'] ?? 0 ) );

		return [
			'id'        => (int) ( $data['id'] ?? 0 ),
			'parent'    => (int) $parent->comment_ID,
			'post_id'   => (int) $parent->comment_post_ID,
			'status'    => $reply instanceof WP_Comment ? CommentPresenter::status( $reply ) : (string) ( $data['status'] ?? '' ),
			'permalink' => $reply instanceof WP_Comment ? (string) get_comment_link( $reply ) : '',
		];
	}

	/**
	 * Approve the comment being replied to, through core's endpoint.
	 *
	 * @param WP_Comment $comment The held comment.
	 * @return true|WP_Error
	 * @since 1.6.0
	 */
	private function approve( WP_Comment $comment ): bool|WP_Error {
		$request = new WP_REST_Request( 'POST', '/wp/v2/comments/' . (int) $comment->comment_ID );
		$request->set_param( 'status', 'approved' );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return CommentPresenter::rest_failure(
				rest_get_server()->response_to_data( $response, false ),
				$response->get_status(),
				__( 'The comment could not be approved before replying.', 'albert-ai-butler' )
			);
		}

		return true;
	}
}
