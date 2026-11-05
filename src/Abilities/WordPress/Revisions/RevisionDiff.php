<?php
/**
 * Revision Diff
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Revisions
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Revisions;

use Text_Diff;
use Text_Diff_Op_add;
use Text_Diff_Op_change;
use Text_Diff_Op_copy;
use Text_Diff_Op_delete;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Line diffs and lookups shared by the revision abilities.
 *
 * The diff is plain unified text rather than core's `wp_text_diff()` table: an
 * assistant reads `-`/`+` lines far better than HTML, and the output is bounded
 * so one long post cannot fill the conversation.
 *
 * @since 1.6.0
 */
class RevisionDiff {

	/**
	 * Unchanged lines shown around each change.
	 *
	 * @since 1.6.0
	 */
	private const CONTEXT = 2;

	/**
	 * Most diff lines returned per field.
	 *
	 * @since 1.6.0
	 */
	private const MAX_LINES = 300;

	/**
	 * Most diff characters returned per field.
	 *
	 * @since 1.6.0
	 */
	private const MAX_CHARS = 20000;

	/**
	 * Diff two strings line by line.
	 *
	 * @param string $from The older text.
	 * @param string $to   The newer text.
	 * @return array{changed: bool, diff: string, truncated: bool}
	 * @since 1.6.0
	 */
	public static function diff( string $from, string $to ): array {
		$from = self::normalise( $from );
		$to   = self::normalise( $to );

		if ( $from === $to ) {
			return [
				'changed'   => false,
				'diff'      => '',
				'truncated' => false,
			];
		}

		if ( ! class_exists( 'Text_Diff', false ) ) {
			require_once ABSPATH . 'wp-includes/Text/Diff.php';
		}

		$text_diff = new Text_Diff( 'auto', [ self::lines( $from ), self::lines( $to ) ] );

		return self::render( self::flatten( $text_diff->getDiff() ) );
	}

	/**
	 * Diff every field core stores in a revision.
	 *
	 * @param WP_Post $from The older version.
	 * @param WP_Post $to   The newer version.
	 * @return array<int, array{field: string, label: string, changed: bool, diff: string, truncated: bool}>
	 * @since 1.6.0
	 */
	public static function fields( WP_Post $from, WP_Post $to ): array {
		$fields = [];
		foreach ( self::revision_fields( $to ) as $field => $label ) {
			$fields[] = [
				'field' => $field,
				'label' => $label,
			] + self::diff( self::field_value( $from, $field ), self::field_value( $to, $field ) );
		}
		return $fields;
	}

	/**
	 * The names of the fields that differ between two versions.
	 *
	 * @param WP_Post $from The older version.
	 * @param WP_Post $to   The newer version.
	 * @return array<int, string>
	 * @since 1.6.0
	 */
	public static function changed_fields( WP_Post $from, WP_Post $to ): array {
		$changed = [];
		foreach ( array_keys( self::revision_fields( $to ) ) as $field ) {
			if ( self::normalise( self::field_value( $from, $field ) ) !== self::normalise( self::field_value( $to, $field ) ) ) {
				$changed[] = $field;
			}
		}
		return $changed;
	}

	/**
	 * Find a revision and the post it belongs to, if the user may edit that post.
	 *
	 * @param int $revision_id Revision ID.
	 * @return array{0: WP_Post, 1: WP_Post}|WP_Error The revision and its post.
	 * @since 1.6.0
	 */
	public static function find_revision( int $revision_id ): array|WP_Error {
		$revision = $revision_id > 0 ? wp_get_post_revision( $revision_id ) : null;
		if ( ! $revision instanceof WP_Post ) {
			return new WP_Error(
				'revision_not_found',
				__( 'No revision with that ID. Use find-revisions to list the revisions of a post.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		$post = self::find_post( (int) $revision->post_parent );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return [ $revision, $post ];
	}

	/**
	 * Find a post whose revisions the current user may see.
	 *
	 * Core's revisions endpoint asks for `edit_post` on the parent, so this does too.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|WP_Error
	 * @since 1.6.0
	 */
	public static function find_post( int $post_id ): WP_Post|WP_Error {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post || $post->post_type === 'revision' ) {
			return new WP_Error(
				'post_not_found',
				__( 'No post with that ID.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'revision_permission_denied',
				__( 'You may not see the revisions of this post, because you cannot edit it.', 'albert-ai-butler' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! post_type_supports( $post->post_type, 'revisions' ) ) {
			return new WP_Error(
				'revisions_not_supported',
				sprintf(
					/* translators: %s: post type name */
					__( 'The "%s" post type does not keep revisions.', 'albert-ai-butler' ),
					$post->post_type
				),
				[ 'status' => 400 ]
			);
		}

		return $post;
	}

	/**
	 * A revision as the abilities list it.
	 *
	 * @param WP_Post $revision The revision.
	 * @return array{id: int, date: string, date_gmt: string, author_id: int, autosave: bool, title: string}
	 * @since 1.6.0
	 */
	public static function summary( WP_Post $revision ): array {
		return [
			'id'        => (int) $revision->ID,
			'date'      => (string) mysql_to_rfc3339( $revision->post_date ),
			'date_gmt'  => (string) mysql_to_rfc3339( $revision->post_date_gmt ),
			'author_id' => (int) $revision->post_author,
			'autosave'  => (bool) wp_is_post_autosave( $revision ),
			'title'     => (string) $revision->post_title,
		];
	}

	/**
	 * The fields core copies into a revision (title, content, excerpt by default).
	 *
	 * @param WP_Post $post The post, so a filter can vary them per type.
	 * @return array<string, string> Field name to label.
	 * @since 1.6.0
	 */
	private static function revision_fields( WP_Post $post ): array {
		$fields = _wp_post_revision_fields( $post );
		$result = [];
		foreach ( $fields as $field => $label ) {
			$result[ (string) $field ] = (string) $label;
		}
		return $result;
	}

	/**
	 * A field's value as text.
	 *
	 * @param WP_Post $post  The post or revision.
	 * @param string  $field Field name.
	 * @return string
	 * @since 1.6.0
	 */
	private static function field_value( WP_Post $post, string $field ): string {
		$value = $post->$field ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Unify line endings and drop trailing spaces, which the editor changes freely.
	 *
	 * @param string $text Text.
	 * @return string
	 * @since 1.6.0
	 */
	private static function normalise( string $text ): string {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		return trim( (string) preg_replace( '/[ \t]+$/m', '', $text ), "\n" );
	}

	/**
	 * Split text into lines; empty text has none.
	 *
	 * @param string $text Normalised text.
	 * @return array<int, string>
	 * @since 1.6.0
	 */
	private static function lines( string $text ): array {
		return $text === '' ? [] : explode( "\n", $text );
	}

	/**
	 * Turn Text_Diff's edits into one entry per line.
	 *
	 * @param array<int, object> $edits Text_Diff operations.
	 * @return array<int, array{0: string, 1: string, 2: int, 3: int}> Prefix, text, old line, new line.
	 * @since 1.6.0
	 */
	private static function flatten( array $edits ): array {
		$rows = [];
		$old  = 1;
		$new  = 1;

		foreach ( $edits as $edit ) {
			$orig  = ( $edit instanceof Text_Diff_Op_copy || $edit instanceof Text_Diff_Op_delete || $edit instanceof Text_Diff_Op_change ) && is_array( $edit->orig ) ? $edit->orig : [];
			$final = ( $edit instanceof Text_Diff_Op_copy || $edit instanceof Text_Diff_Op_add || $edit instanceof Text_Diff_Op_change ) && is_array( $edit->final ) ? $edit->final : [];

			if ( $edit instanceof Text_Diff_Op_copy ) {
				foreach ( $orig as $line ) {
					$rows[] = [ ' ', (string) $line, $old++, $new++ ];
				}
				continue;
			}

			foreach ( $orig as $line ) {
				$rows[] = [ '-', (string) $line, $old++, $new ];
			}
			foreach ( $final as $line ) {
				$rows[] = [ '+', (string) $line, $old, $new++ ];
			}
		}

		return $rows;
	}

	/**
	 * Render changed lines with a little context, as unified diff hunks.
	 *
	 * @param array<int, array{0: string, 1: string, 2: int, 3: int}> $rows Flattened diff.
	 * @return array{changed: bool, diff: string, truncated: bool}
	 * @since 1.6.0
	 */
	private static function render( array $rows ): array {
		$keep  = [];
		$count = count( $rows );
		foreach ( $rows as $i => $row ) {
			if ( $row[0] !== ' ' ) {
				$end = min( $count - 1, $i + self::CONTEXT );
				for ( $j = max( 0, $i - self::CONTEXT ); $j <= $end; $j++ ) {
					$keep[ $j ] = true;
				}
			}
		}

		ksort( $keep );

		$hunks   = [];
		$current = [];
		$last    = -2;
		foreach ( array_keys( $keep ) as $i ) {
			if ( $i !== $last + 1 && $current ) {
				$hunks[] = $current;
				$current = [];
			}
			$current[] = $rows[ $i ];
			$last      = $i;
		}
		if ( $current ) {
			$hunks[] = $current;
		}

		$out       = [];
		$chars     = 0;
		$truncated = false;
		foreach ( $hunks as $hunk ) {
			$old_lines = count( array_filter( $hunk, static fn( array $r ): bool => $r[0] !== '+' ) );
			$new_lines = count( array_filter( $hunk, static fn( array $r ): bool => $r[0] !== '-' ) );
			$lines     = [ sprintf( '@@ -%d,%d +%d,%d @@', $hunk[0][2], $old_lines, $hunk[0][3], $new_lines ) ];
			foreach ( $hunk as $row ) {
				$lines[] = $row[0] . $row[1];
			}

			foreach ( $lines as $line ) {
				if ( count( $out ) >= self::MAX_LINES || $chars + strlen( $line ) > self::MAX_CHARS ) {
					$truncated = true;
					break 2;
				}
				$out[]  = $line;
				$chars += strlen( $line ) + 1;
			}
		}

		return [
			'changed'   => true,
			'diff'      => implode( "\n", $out ),
			'truncated' => $truncated,
		];
	}
}
