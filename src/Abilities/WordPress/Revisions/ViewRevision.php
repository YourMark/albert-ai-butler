<?php
/**
 * View Revision Ability
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
 * Shows what changed between a revision and the current version, or the revision before it.
 *
 * @since 1.6.0
 */
class ViewRevision extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/view-revision';
		$this->label       = __( 'View Revision', 'albert-ai-butler' );
		$this->description = __( 'Show what changed between one revision and the current version of its post (or the revision before it), as a line diff of the title, content and excerpt.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'revisions';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'The diff runs from older to newer: "-" lines are only in the older version, "+" lines only in the newer one. With compare_to "current", the older version is this revision.'
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
				'id'         => [
					'type'        => 'integer',
					'description' => 'The revision ID, from find-revisions.',
					'minimum'     => 1,
				],
				'compare_to' => [
					'type'        => 'string',
					'description' => '"current" compares with the post as it is now; "previous" shows what this revision changed compared with the revision before it.',
					'enum'        => [ 'current', 'previous' ],
					'default'     => 'current',
				],
			],
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
		$version = [
			'type'       => 'object',
			'properties' => [
				'id'    => [ 'type' => 'integer' ],
				'label' => [ 'type' => 'string' ],
				'date'  => [ 'type' => 'string' ],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'revision'  => [ 'type' => 'object' ],
				'post_id'   => [ 'type' => 'integer' ],
				'from'      => $version,
				'to'        => $version,
				'changed'   => [ 'type' => 'boolean' ],
				'fields'    => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'field'     => [ 'type' => 'string' ],
							'label'     => [ 'type' => 'string' ],
							'changed'   => [ 'type' => 'boolean' ],
							'diff'      => [ 'type' => 'string' ],
							'truncated' => [
								'type'        => 'boolean',
								'description' => 'True when the diff was cut short; compare the rest in wp-admin.',
							],
						],
					],
				],
				'edit_link' => [
					'type'        => 'string',
					'description' => 'The revisions screen in wp-admin for this revision.',
				],
			],
			'required'   => [ 'revision', 'post_id', 'from', 'to', 'changed', 'fields' ],
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
		$found = RevisionDiff::find_revision( absint( $args['id'] ?? 0 ) );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		[ $revision, $post ] = $found;

		$compare_to = ( $args['compare_to'] ?? 'current' ) === 'previous' ? 'previous' : 'current';

		if ( $compare_to === 'previous' ) {
			$previous = $this->previous_revision( $revision, $post );
			if ( ! $previous instanceof WP_Post ) {
				return new WP_Error(
					'revision_not_found',
					__( 'This is the oldest revision of the post, so there is nothing before it to compare with.', 'albert-ai-butler' ),
					[ 'status' => 404 ]
				);
			}
			$older = $previous;
			$newer = $revision;
			$from  = $this->version( $previous, __( 'Previous revision', 'albert-ai-butler' ) );
			$to    = $this->version( $revision, __( 'This revision', 'albert-ai-butler' ) );
		} else {
			$older = $revision;
			$newer = $post;
			$from  = $this->version( $revision, __( 'This revision', 'albert-ai-butler' ) );
			$to    = $this->version( $post, __( 'Current version', 'albert-ai-butler' ) );
		}

		$fields = RevisionDiff::fields( $older, $newer );

		return [
			'revision'  => RevisionDiff::summary( $revision ),
			'post_id'   => (int) $post->ID,
			'from'      => $from,
			'to'        => $to,
			'changed'   => in_array( true, array_column( $fields, 'changed' ), true ),
			'fields'    => $fields,
			'edit_link' => admin_url( 'revision.php?revision=' . (int) $revision->ID ),
		];
	}

	/**
	 * The revision saved just before this one, skipping autosaves.
	 *
	 * @param WP_Post $revision The revision.
	 * @param WP_Post $post     Its post.
	 * @return WP_Post|null
	 * @since 1.6.0
	 */
	private function previous_revision( WP_Post $revision, WP_Post $post ): ?WP_Post {
		$found = false;
		foreach ( wp_get_post_revisions( $post->ID ) as $candidate ) {
			if ( ! $candidate instanceof WP_Post ) {
				continue;
			}
			if ( $found && ! wp_is_post_autosave( $candidate ) ) {
				return $candidate;
			}
			if ( (int) $candidate->ID === (int) $revision->ID ) {
				$found = true;
			}
		}
		return null;
	}

	/**
	 * Describe one side of the comparison.
	 *
	 * @param WP_Post $version A revision or the post itself.
	 * @param string  $label   What it is.
	 * @return array{id: int, label: string, date: string}
	 * @since 1.6.0
	 */
	private function version( WP_Post $version, string $label ): array {
		$date = $version->post_type === 'revision' ? $version->post_date : $version->post_modified;

		return [
			'id'    => (int) $version->ID,
			'label' => $label,
			'date'  => (string) mysql_to_rfc3339( $date ),
		];
	}
}
