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
 * Lists a post's revisions, newest first, with what each differs in from the current version.
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
		$this->description = __( 'List the saved revisions of a post or page, newest first, with the fields (title, content, excerpt) each one differs in from the current version.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'revisions';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read( 'Use view-revision to see the line-by-line changes of one revision.' ),
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
					'description' => 'The post or page ID.',
					'minimum'     => 1,
				],
				'page'     => [
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				],
				'per_page' => [
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 50,
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
		return [
			'type'       => 'object',
			'properties' => [
				'post'        => [
					'type'       => 'object',
					'properties' => [
						'id'       => [ 'type' => 'integer' ],
						'type'     => [ 'type' => 'string' ],
						'title'    => [ 'type' => 'string' ],
						'modified' => [ 'type' => 'string' ],
					],
				],
				'revisions'   => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'                   => [ 'type' => 'integer' ],
							'date'                 => [ 'type' => 'string' ],
							'date_gmt'             => [ 'type' => 'string' ],
							'author_id'            => [ 'type' => 'integer' ],
							'autosave'             => [ 'type' => 'boolean' ],
							'title'                => [ 'type' => 'string' ],
							'differs_from_current' => [
								'type'        => 'array',
								'description' => 'Fields whose value differs from the current version. Empty means this revision matches it.',
								'items'       => [ 'type' => 'string' ],
							],
						],
					],
				],
				'total'       => [ 'type' => 'integer' ],
				'total_pages' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'post', 'revisions', 'total' ],
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
		$post = RevisionDiff::find_post( absint( $args['post_id'] ?? 0 ) );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = min( 50, max( 1, absint( $args['per_page'] ?? 10 ) ) );

		$total     = count( wp_get_post_revisions( $post->ID, [ 'fields' => 'ids' ] ) );
		$revisions = wp_get_post_revisions(
			$post->ID,
			[
				'posts_per_page' => $per_page,
				'paged'          => $page,
			]
		);

		$items = [];
		foreach ( $revisions as $revision ) {
			if ( $revision instanceof WP_Post ) {
				$items[] = RevisionDiff::summary( $revision ) + [
					'differs_from_current' => RevisionDiff::changed_fields( $revision, $post ),
				];
			}
		}

		return [
			'post'        => [
				'id'       => (int) $post->ID,
				'type'     => (string) $post->post_type,
				'title'    => (string) $post->post_title,
				'modified' => (string) mysql_to_rfc3339( $post->post_modified ),
			],
			'revisions'   => $items,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		];
	}
}
