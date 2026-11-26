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
		$this->description = __( 'Show one revision\'s changes as a line diff, against the current version or the revision before it; the newest revision is the current version, so compare it with "previous" to see the last edit. Use it before albert/restore-revision.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'revisions';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(
				'Get the revision ID from albert/find-revisions. The diff covers the title, content and excerpt and runs from older to newer: "-" lines are only in the older version, "+" lines only in the newer one. With compare_to "current", the older version is this revision.'
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
				'revision_id' => [
					'type'        => 'integer',
					'description' => 'The revision ID, from albert/find-revisions.',
					'minimum'     => 1,
				],
				'compare_to'  => [
					'type'        => 'string',
					'description' => '"current" compares with the content as it is now; "previous" shows what this revision changed compared with the revision before it.',
					'enum'        => [ 'current', 'previous' ],
					'default'     => 'current',
				],
			],
			'required'   => [ 'revision_id' ],
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
				'revision'  => RevisionDiff::summary_schema(),
				'post'      => RevisionDiff::post_reference_schema( 'The post, page, template or other content the revision belongs to.' ),
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
			'required'   => [ 'revision', 'post', 'from', 'to', 'changed', 'fields' ],
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
		$found = RevisionDiff::find_revision( (int) ( $args['revision_id'] ?? 0 ) );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		[ $revision, $post ] = $found;

		$compare_to = ( $args['compare_to'] ?? 'current' ) === 'previous' ? 'previous' : 'current';

		if ( $compare_to === 'previous' ) {
			$previous = RevisionDiff::previous_revision( $revision, wp_get_post_revisions( $post->ID, [ 'fields' => 'ids' ] ) );
			if ( ! $previous instanceof WP_Post ) {
				return new WP_Error(
					'albert_invalid_compare_to',
					__( 'This is the oldest revision, so there is nothing before it. Use compare_to "current" instead.', 'albert-ai-butler' ),
					[ 'status' => 400 ]
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
			'post'      => RevisionDiff::post_reference( $post ),
			'from'      => $from,
			'to'        => $to,
			'changed'   => in_array( true, array_column( $fields, 'changed' ), true ),
			'fields'    => $fields,
			'edit_link' => admin_url( 'revision.php?revision=' . (int) $revision->ID ),
		];
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
