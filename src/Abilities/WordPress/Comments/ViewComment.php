<?php
/**
 * View Comment Ability
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

defined( 'ABSPATH' ) || exit;

/**
 * Shows one comment with the comment it replies to and its replies.
 *
 * @since 1.6.0
 */
class ViewComment extends BaseAbility {

	/**
	 * Most replies to include.
	 *
	 * @since 1.6.0
	 */
	private const MAX_REPLIES = 50;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/view-comment';
		$this->label       = __( 'View Comment', 'albert-ai-butler' );
		$this->description = __( 'Retrieve one comment by ID, with the comment it replies to and its replies.', 'albert-ai-butler' );
		$this->category    = 'comments';
		$this->group       = 'comments';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(),
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
					'description' => 'The comment ID.',
					'minimum'     => 1,
				],
			] + CommentPresenter::reveal_schema(),
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
				'comment'   => [ 'type' => 'object' ],
				'in_reply'  => [
					'type'        => [ 'object', 'null' ],
					'description' => 'The comment this one replies to, or null for a top-level comment.',
				],
				'replies'   => [
					'type'        => 'array',
					'description' => 'Direct replies, oldest first.',
					'items'       => [ 'type' => 'object' ],
				],
				'edit_link' => [ 'type' => 'string' ],
			],
			'required'   => [ 'comment' ],
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

		$parent = $comment->comment_parent ? get_comment( (int) $comment->comment_parent ) : null;

		$replies = get_comments(
			[
				'parent'  => (int) $comment->comment_ID,
				'status'  => 'all',
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
				'number'  => self::MAX_REPLIES,
			]
		);

		$reply_items = [];
		foreach ( is_array( $replies ) ? $replies : [] as $reply ) {
			if ( $reply instanceof WP_Comment ) {
				$reply_items[] = CommentPresenter::format( $reply );
			}
		}

		return CommentPresenter::redact(
			[
				'comment'   => CommentPresenter::format( $comment ),
				'in_reply'  => $parent instanceof WP_Comment ? CommentPresenter::format( $parent ) : null,
				'replies'   => $reply_items,
				'edit_link' => admin_url( 'comment.php?action=editcomment&c=' . (int) $comment->comment_ID ),
			],
			$args
		);
	}
}
