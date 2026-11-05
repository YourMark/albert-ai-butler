<?php
/**
 * Comment shape shared by the comment abilities
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Comments
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Comments;

use Albert\Privacy\PiiPolicy;
use WP_Comment;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns comments into ability output, with the author's details masked per privacy mode.
 *
 * The author sits in its own sub-array without an `id`, so the anonymiser reduces the
 * name to initials instead of a "Customer #" reference, empties the URL and drops the IP.
 *
 * @since 1.6.0
 */
class CommentPresenter {

	/**
	 * The capability every comment ability needs: comments carry visitors' personal data.
	 *
	 * @since 1.6.0
	 */
	public const CAPABILITY = 'moderate_comments';

	/**
	 * Statuses, in the REST API's words.
	 *
	 * @since 1.6.0
	 */
	public const STATUSES = [ 'approved', 'hold', 'spam', 'trash' ];

	/**
	 * Comment types left to their own abilities: WooCommerce reviews and editor notes.
	 *
	 * @since 1.6.0
	 */
	public const EXCLUDED_TYPES = [ 'review', 'note' ];

	/**
	 * Input schema property for revealing personal data.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.6.0
	 */
	public static function reveal_schema(): array {
		return [
			'reveal_personal_data' => [
				'type'        => 'boolean',
				'description' => "Return commenters' real names, emails, websites and IP addresses instead of masked ones. Ignored unless privacy mode is Balanced.",
				'default'     => false,
			],
		];
	}

	/**
	 * One comment as ability output, before masking.
	 *
	 * @param WP_Comment $comment The comment.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public static function format( WP_Comment $comment ): array {
		$post_id = (int) $comment->comment_post_ID;

		return [
			'id'         => (int) $comment->comment_ID,
			'post_id'    => $post_id,
			'post_title' => get_the_title( $post_id ),
			'parent'     => (int) $comment->comment_parent,
			'status'     => self::status( $comment ),
			'type'       => $comment->comment_type !== '' ? $comment->comment_type : 'comment',
			'date'       => $comment->comment_date,
			'content'    => $comment->comment_content,
			'link'       => get_comment_link( $comment ),
			'author'     => [
				'user_id'    => (int) $comment->user_id,
				'name'       => $comment->comment_author,
				'email'      => $comment->comment_author_email,
				'url'        => $comment->comment_author_url,
				'ip_address' => $comment->comment_author_IP,
			],
		];
	}

	/**
	 * Mask personal data in a payload per privacy mode.
	 *
	 * @param array<string, mixed> $payload Ability result.
	 * @param array<string, mixed> $args    Ability input, for `reveal_personal_data`.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public static function redact( array $payload, array $args ): array {
		return PiiPolicy::redact(
			$payload,
			$args,
			[
				'mask_context_names' => true,
				'reveal_capability'  => self::CAPABILITY,
			]
		);
	}

	/**
	 * A comment's status in the REST API's words.
	 *
	 * @param WP_Comment $comment The comment.
	 * @return string
	 * @since 1.6.0
	 */
	public static function status( WP_Comment $comment ): string {
		return match ( (string) $comment->comment_approved ) {
			'1'     => 'approved',
			'0'     => 'hold',
			default => (string) $comment->comment_approved,
		};
	}

	/**
	 * Whether the current user may see and handle this comment.
	 *
	 * `moderate_comments` alone is not enough: like wp-admin, each comment needs
	 * `edit_comment`, which maps to editing its post (private and others' posts included).
	 *
	 * @param WP_Comment $comment The comment.
	 * @return bool
	 * @since 1.6.0
	 */
	public static function can_access( WP_Comment $comment ): bool {
		return ! in_array( $comment->comment_type, self::EXCLUDED_TYPES, true )
			&& current_user_can( 'edit_comment', (int) $comment->comment_ID );
	}

	/**
	 * The comment with this ID, or a not-found error.
	 *
	 * Reviews, notes and comments the user may not edit are reported as not found,
	 * so their existence is not confirmed either.
	 *
	 * @param int $comment_id Comment ID.
	 * @return WP_Comment|WP_Error
	 * @since 1.6.0
	 */
	public static function find( int $comment_id ): WP_Comment|WP_Error {
		$comment = $comment_id > 0 ? get_comment( $comment_id ) : null;

		if ( ! $comment instanceof WP_Comment || ! self::can_access( $comment ) ) {
			return new WP_Error(
				'comment_not_found',
				sprintf(
					/* translators: %d: Comment ID */
					__( 'Comment with ID %d not found.', 'albert-ai-butler' ),
					$comment_id
				),
				[ 'status' => 404 ]
			);
		}

		return $comment;
	}

	/**
	 * A REST error as an ability error, with permission failures named as such.
	 *
	 * @param mixed  $data     Response data.
	 * @param int    $status   HTTP status.
	 * @param string $fallback Message when the response has none.
	 * @return WP_Error
	 * @since 1.6.0
	 */
	public static function rest_failure( $data, int $status, string $fallback ): WP_Error {
		$data    = is_array( $data ) ? $data : [];
		$code    = (string) ( $data['code'] ?? 'rest_error' );
		$message = (string) ( $data['message'] ?? $fallback );

		if ( $status === 401 || $status === 403 ) {
			$code = 'comment_permission_denied';
		}

		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
