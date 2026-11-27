<?php
/**
 * Find Revisions Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Revisions
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Revisions;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Lists a post's revisions, newest first, with what each save changed and what differs from the current version.
 *
 * @since 1.6.0
 */
class FindRevisions extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-revisions';
		$this->label       = __( 'Find Revisions', 'albert-ai-butler' );
		$this->description = __( 'List the saved revisions of any content that keeps revisions, newest first (the newest is the current version), with the fields each save changed; use albert/view-revision to see one revision\'s changes line by line.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'revisions';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read( 'Works for posts, pages, templates, navigation menus and other content that keeps revisions. Use albert/view-revision to see the line-by-line changes of one revision, and albert/restore-revision to put one back.' ),
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
				'post_id'  => [
					'type'        => 'integer',
					'description' => 'The ID of the content whose revisions to list: a post, page, template, navigation menu or anything else that keeps revisions. Find it with albert/find-posts or albert/find-pages.',
					'minimum'     => 1,
				],
				'page'     => [
					'type'        => 'integer',
					'description' => 'Page of results to return, starting at 1. Use total_pages to see how many there are.',
					'default'     => 1,
					'minimum'     => 1,
				],
				'per_page' => [
					'type'        => 'integer',
					'description' => 'Revisions per page, newest first.',
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 50,
				],
			],
			'required'   => [ 'post_id' ],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_output_schema(): array {
		$item = RevisionDiff::summary_schema();

		$item['properties']['changed_fields'] = [
			'type'        => [ 'array', 'null' ],
			'description' => 'Fields this save changed compared with the revision before it. Null for the oldest revision, which has nothing before it.',
			'items'       => [ 'type' => 'string' ],
		];

		$item['properties']['differs_from_current'] = [
			'type'        => 'array',
			'description' => 'Fields whose value differs from the current version. Empty means this revision matches it.',
			'items'       => [ 'type' => 'string' ],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'post'              => [
					'type'       => 'object',
					'properties' => [
						'id'       => [ 'type' => 'integer' ],
						'type'     => [ 'type' => 'string' ],
						'title'    => [ 'type' => 'string' ],
						'modified' => [ 'type' => 'string' ],
					],
				],
				'revisions'         => [
					'type'  => 'array',
					'items' => $item,
				],
				'total'             => [ 'type' => 'integer' ],
				'total_pages'       => [ 'type' => 'integer' ],
				'revisions_enabled' => [
					'type'        => 'boolean',
					'description' => 'False when this site no longer saves revisions for this content: earlier ones are listed, but none can be restored.',
				],
			],
			'required'   => [ 'post', 'revisions', 'total', 'revisions_enabled' ],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * The per-post `edit_post` check runs in execute(), where the post is known.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.6.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'edit_posts' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.6.0
	 */
	public function execute( array $args ): array|WP_Error {
		$post = RevisionDiff::find_post( (int) ( $args['post_id'] ?? 0 ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = min( 50, max( 1, absint( $args['per_page'] ?? 10 ) ) );

		$all_ids   = RevisionDiff::revision_ids( (int) $post->ID );
		$total     = count( $all_ids );
		$revisions = wp_get_post_revisions(
			$post->ID,
			[
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'check_enabled'  => false,
			]
		);

		$items = [];
		foreach ( $revisions as $revision ) {
			if ( $revision instanceof WP_Post ) {
				$previous = RevisionDiff::previous_revision( $revision, $all_ids );
				$items[]  = RevisionDiff::summary( $revision ) + [
					'changed_fields'       => $previous instanceof WP_Post ? RevisionDiff::changed_fields( $previous, $revision ) : null,
					'differs_from_current' => RevisionDiff::changed_fields( $revision, $post ),
				];
			}
		}

		return [
			'post'              => [
				'id'       => (int) $post->ID,
				'type'     => (string) $post->post_type,
				'title'    => (string) $post->post_title,
				'modified' => (string) mysql_to_rfc3339( $post->post_modified ),
			],
			'revisions'         => $items,
			'total'             => $total,
			'total_pages'       => (int) ceil( $total / $per_page ),
			'revisions_enabled' => wp_revisions_enabled( $post ),
		];
	}
}
