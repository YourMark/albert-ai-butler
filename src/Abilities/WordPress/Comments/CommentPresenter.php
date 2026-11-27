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
use Albert\Privacy\PrivacyMode;
use WP_Comment;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns comments into ability output, with the author's details masked per privacy mode.
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
	 * Statuses, in the REST API's words. `post-trashed` is a comment whose post is in the trash.
	 *
	 * @since 1.6.0
	 */
	public const STATUSES = [ 'approved', 'hold', 'spam', 'trash', 'post-trashed' ];

	/**
	 * The only comment types these abilities handle: comments and pings.
	 *
	 * An allowlist, so reviews, editor notes, order notes and other plugins' types stay out.
	 *
	 * @since 1.6.0
	 */
	public const TYPES = [ '', 'comment', 'pingback', 'trackback' ];

	/**
	 * The same types in WP_Comment_Query's words.
	 *
	 * @since 1.6.0
	 */
	public const QUERY_TYPES = [ 'comment', 'pings' ];

	/**
	 * Error code for a comment the user may not change, ending in `_permission_denied` so it logs as blocked.
	 *
	 * @since 1.6.0
	 */
	public const PERMISSION_DENIED = 'comment_permission_denied';

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
				'description' => "Return commenters' real names, emails, websites and IP addresses, which privacy mode otherwise masks or leaves out. Needs the moderate_comments capability; ignored unless privacy mode is Balanced.",
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
			'id'        => (int) $comment->comment_ID,
			'post'      => self::post_reference( $post_id ),
			'parent_id' => (int) $comment->comment_parent,
			'status'    => self::status( $comment ),
			'type'      => $comment->comment_type !== '' ? $comment->comment_type : 'comment',
			'date'      => $comment->comment_date,
			'content'   => $comment->comment_content,
			'link'      => get_comment_link( $comment ),
			'author'    => [
				'user_id'      => (int) $comment->user_id,
				'display_name' => $comment->comment_author,
				'email'        => $comment->comment_author_email,
				'url'          => $comment->comment_author_url,
				'ip_address'   => $comment->comment_author_IP,
			],
		];
	}

	/**
	 * The post a comment is on, as `{ id, name }`.
	 *
	 * @param int $post_id Post ID.
	 * @return array{id: int, name: string}
	 * @since 1.6.0
	 */
	public static function post_reference( int $post_id ): array {
		return [
			'id'   => $post_id,
			'name' => get_the_title( $post_id ),
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
		if ( self::masks( $args ) ) {
			// The account ID would tie a masked commenter to a known user.
			$payload = self::drop_author_ids( $payload );
		}

		return PiiPolicy::redact(
			$payload,
			$args,
			[ 'reveal_capability' => self::CAPABILITY ]
		);
	}

	/**
	 * Remove `author.user_id` from every comment in a payload.
	 *
	 * @param array<mixed> $data Payload or part of it.
	 * @return array<mixed>
	 * @since 1.6.0
	 */
	private static function drop_author_ids( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			if ( $key === 'author' ) {
				unset( $value['user_id'] );
				$data[ $key ] = $value;
				continue;
			}
			$data[ $key ] = self::drop_author_ids( $value );
		}

		return $data;
	}

	/**
	 * Whether this call returns commenters' details masked.
	 *
	 * @param array<string, mixed> $args The ability's input.
	 * @return bool
	 * @since 1.6.0
	 */
	public static function masks( array $args ): bool {
		$mode = PrivacyMode::resolve();

		return $mode !== PrivacyMode::Off && ! PiiPolicy::allows_reveal( $args, $mode, [ 'reveal_capability' => self::CAPABILITY ] );
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
		return in_array( $comment->comment_type, self::TYPES, true )
			&& current_user_can( 'edit_comment', (int) $comment->comment_ID );
	}

	/**
	 * The comment with this ID, or a not-found error.
	 *
	 * Other comment types and comments the user may not edit are reported as not found,
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
					__( 'Comment with ID %d not found. Find comments with albert/find-comments.', 'albert-ai-butler' ),
					$comment_id
				),
				[ 'status' => 404 ]
			);
		}

		return $comment;
	}
}
