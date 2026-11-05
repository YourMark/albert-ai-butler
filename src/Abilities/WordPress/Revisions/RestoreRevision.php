<?php
/**
 * Restore Revision Ability
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
 * Restores a post to one of its revisions through core, so the restore is itself a revision.
 *
 * Annotated destructive: it overwrites the live title, content and excerpt, and
 * until Revert ships there is no undo in Albert, so safe mode holds it.
 *
 * @since 1.6.0
 */
class RestoreRevision extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/restore-revision';
		$this->label       = __( 'Restore Revision', 'albert-ai-butler' );
		$this->description = __( 'Restore a post or page to one of its revisions. The title, content and excerpt are replaced by the revision\'s, and WordPress records the restore as a new revision.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'revisions';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::delete(
				'Show the owner what will change first, with view-revision. The current version is replaced, so restore only the revision the owner chose.'
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
				'id' => [
					'type'        => 'integer',
					'description' => 'The revision ID to restore, from find-revisions.',
					'minimum'     => 1,
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
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'         => [ 'type' => 'integer' ],
				'restored_from'   => [ 'type' => 'integer' ],
				'new_revision_id' => [
					'type'        => [ 'integer', 'null' ],
					'description' => 'The revision WordPress recorded for the restore, or null when nothing changed.',
				],
				'changed_fields'  => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'title'           => [ 'type' => 'string' ],
				'edit_link'       => [ 'type' => 'string' ],
			],
			'required'   => [ 'post_id', 'restored_from', 'new_revision_id', 'changed_fields' ],
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

		if ( $post->post_status === 'trash' ) {
			return new WP_Error(
				'post_in_trash',
				__( 'This post is in the trash. Restore it from the trash before restoring a revision.', 'albert-ai-butler' ),
				[ 'status' => 409 ]
			);
		}

		// As core's revision.php: with revisions off, the restore would leave no revision to undo it.
		if ( ! wp_revisions_enabled( $post ) && ! wp_is_post_autosave( $revision ) ) {
			return new WP_Error(
				'revisions_disabled',
				__( 'Revisions are turned off for this post, so restoring one would leave no way back.', 'albert-ai-butler' ),
				[ 'status' => 409 ]
			);
		}

		$changed = RevisionDiff::changed_fields( $revision, $post );
		$before  = $this->latest_revision_id( $post->ID );

		// Through core, so `wp_restore_post_revision` fires and a new revision records the restore.
		$restored = wp_restore_post_revision( $revision->ID );
		if ( ! $restored ) {
			return new WP_Error(
				'revision_restore_failed',
				__( 'WordPress could not restore this revision.', 'albert-ai-butler' ),
				[ 'status' => 500 ]
			);
		}

		$after   = $this->latest_revision_id( $post->ID );
		$current = get_post( $post->ID );

		return [
			'post_id'         => (int) $post->ID,
			'restored_from'   => (int) $revision->ID,
			'new_revision_id' => $after !== $before ? $after : null,
			'changed_fields'  => $changed,
			'title'           => $current instanceof WP_Post ? (string) $current->post_title : '',
			'edit_link'       => (string) get_edit_post_link( $post->ID, 'raw' ),
		];
	}

	/**
	 * The newest revision of a post, autosaves excluded.
	 *
	 * @param int $post_id Post ID.
	 * @return int|null
	 * @since 1.6.0
	 */
	private function latest_revision_id( int $post_id ): ?int {
		foreach ( wp_get_post_revisions( $post_id ) as $revision ) {
			if ( $revision instanceof WP_Post && ! wp_is_post_autosave( $revision ) ) {
				return (int) $revision->ID;
			}
		}
		return null;
	}
}
