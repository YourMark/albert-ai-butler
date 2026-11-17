<?php
/**
 * Find Comments Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Comments
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Comments;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Comment;
use WP_Comment_Query;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lists comments, newest first, with commenters' details masked per privacy mode.
 *
 * WooCommerce reviews are left out: they are a comment type, and the WooCommerce
 * add-on's review abilities own them.
 *
 * @since 1.6.0
 */
class FindComments extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-comments';
		$this->label       = __( 'Find Comments', 'albert-ai-butler' );
		$this->description = __( 'Find comments matching filters, for example the ones waiting for approval before you moderate them with albert/moderate-comment.', 'albert-ai-butler' );
		$this->category    = 'comments';
		$this->group       = 'comments';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Comments waiting for approval have status "hold". Product reviews are not included. '
				. 'Commenters\' names, emails and websites may come back masked, depending on the site\'s privacy setting.'
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
				'status'    => [
					'type'        => 'string',
					'enum'        => array_merge( [ 'all' ], CommentPresenter::STATUSES ),
					'description' => 'Which comments to return. "hold" is waiting for approval. "all" is approved and held, without spam and trash. Default "all".',
					'default'     => 'all',
				],
				'post_id'   => [
					'type'        => 'integer',
					'description' => 'Only comments on this post or page.',
					'minimum'     => 1,
				],
				'parent_id' => [
					'type'        => 'integer',
					'description' => 'Only direct replies to this comment ID. 0 for top-level comments only.',
					'minimum'     => 0,
				],
				'search'    => [
					'type'        => 'string',
					'description' => 'Text to look for in the comment. While personal data is masked, only the comment text is searched; otherwise also its author name, email and website.',
				],
				'type'      => [
					'type'        => 'string',
					'enum'        => [ 'comment', 'pings', 'all' ],
					'description' => '"comment" for comments (default), "pings" for pingbacks and trackbacks, "all" for both. Product reviews are never included.',
					'default'     => 'comment',
				],
				'page'      => [
					'type'        => 'integer',
					'description' => 'Page of results. Default 1.',
					'minimum'     => 1,
					'default'     => 1,
				],
				'per_page'  => [
					'type'        => 'integer',
					'description' => 'Comments per page. Default 20.',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				],
			] + CommentPresenter::reveal_schema(),
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
				'comments'    => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'total'       => [
					'type'        => 'integer',
					'description' => 'Every matching comment, including ones on posts you cannot edit, which are left out. A page can therefore hold fewer than per_page.',
				],
				'total_pages' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'comments', 'total' ],
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
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$status   = (string) ( $args['status'] ?? 'all' );

		$query_args = [
			'status'        => match ( $status ) {
				'approved' => 'approve',
				'hold', 'spam', 'trash' => $status,
				default    => 'all',
			},
			'number'        => $per_page,
			'offset'        => ( $page - 1 ) * $per_page,
			'orderby'       => 'comment_date_gmt',
			'order'         => 'DESC',
			'no_found_rows' => false,
		];

		$type               = (string) ( $args['type'] ?? 'comment' );
		$query_args['type'] = $type === 'all' ? CommentPresenter::QUERY_TYPES : $type;

		if ( isset( $args['post_id'] ) && is_int( $args['post_id'] ) ) {
			$query_args['post_id'] = $args['post_id'];
		}

		if ( isset( $args['parent_id'] ) && is_int( $args['parent_id'] ) ) {
			$query_args['parent'] = $args['parent_id'];
		}

		$content_only = null;
		if ( isset( $args['search'] ) && $args['search'] !== '' ) {
			$search = sanitize_text_field( (string) $args['search'] );

			if ( CommentPresenter::masks( $args ) ) {
				// Core's search also matches email, IP and name, so a hit would confirm a masked value.
				$like                       = '%' . $GLOBALS['wpdb']->esc_like( $search ) . '%';
				$content_only               = static function ( array $clauses ) use ( $like ): array {
					$clauses['where'] .= $GLOBALS['wpdb']->prepare( ' AND comment_content LIKE %s', $like );
					return $clauses;
				};
				$query_args['cache_domain'] = 'albert_content_search_' . md5( $search );
				add_filter( 'comments_clauses', $content_only );
			} else {
				$query_args['search'] = $search;
			}
		}

		$query    = new WP_Comment_Query();
		$comments = $query->query( $query_args );

		if ( $content_only !== null ) {
			remove_filter( 'comments_clauses', $content_only );
		}
		$comments = is_array( $comments ) ? $comments : [];

		// Filtered after the query, as core's endpoint does, so `total` may count
		// comments on posts this user cannot edit.
		$items = [];
		foreach ( $comments as $comment ) {
			if ( $comment instanceof WP_Comment && CommentPresenter::can_access( $comment ) ) {
				$items[] = CommentPresenter::format( $comment );
			}
		}

		$total = (int) $query->found_comments;

		return CommentPresenter::redact(
			[
				'comments'    => $items,
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
			],
			$args
		);
	}
}
