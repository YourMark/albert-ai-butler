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
use WP_User;

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
	 * Most lines per side that are diffed at all.
	 *
	 * Text_Diff's cost grows with the input, not the capped output, so a larger
	 * field is reported as changed without a diff.
	 *
	 * @since 1.6.0
	 */
	private const MAX_INPUT_LINES = 5000;

	/**
	 * Most characters shown of one line.
	 *
	 * Minified HTML is one line; without this one changed line on its `-` side fills the
	 * whole budget and its `+` side is never shown.
	 *
	 * @since 1.6.0
	 */
	private const MAX_LINE_CHARS = 4000;

	/**
	 * Unchanged characters shown on each side of a change within a long line.
	 *
	 * @since 1.6.0
	 */
	private const LINE_CONTEXT = 200;

	/**
	 * Marks text left out of a long line.
	 *
	 * @since 1.6.0
	 */
	private const ELLIPSIS = '…';

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

		$from_lines = self::lines( $from );
		$to_lines   = self::lines( $to );
		if ( count( $from_lines ) > self::MAX_INPUT_LINES || count( $to_lines ) > self::MAX_INPUT_LINES ) {
			return [
				'changed'   => true,
				'diff'      => '',
				'truncated' => true,
			];
		}

		$text_diff = new Text_Diff( 'auto', [ $from_lines, $to_lines ] );

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
	 * The revision saved just before this one, skipping autosaves.
	 *
	 * @param WP_Post            $revision     The revision.
	 * @param array<int|WP_Post> $revision_ids The post's revision IDs, newest first, from `wp_get_post_revisions()` with `fields` `ids`.
	 * @return WP_Post|null Null for the oldest revision.
	 * @since 1.6.0
	 */
	public static function previous_revision( WP_Post $revision, array $revision_ids ): ?WP_Post {
		$ids      = array_map( static fn( $id ): int => $id instanceof WP_Post ? (int) $id->ID : (int) $id, array_values( $revision_ids ) );
		$position = array_search( (int) $revision->ID, $ids, true );
		if ( $position === false ) {
			return null;
		}

		foreach ( array_slice( $ids, $position + 1 ) as $candidate_id ) {
			$candidate = get_post( $candidate_id );
			if ( $candidate instanceof WP_Post && ! wp_is_post_autosave( $candidate ) ) {
				return $candidate;
			}
		}
		return null;
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
				'albert_revision_not_found',
				__( 'No revision with that ID. Use albert/find-revisions to list the revisions of a post, page or other content.', 'albert-ai-butler' ),
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
				'albert_post_not_found',
				__( 'No content with that ID. Send the ID of any content that keeps revisions; use albert/find-posts or albert/find-pages to find it.', 'albert-ai-butler' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'albert_post_permission_denied',
				__( 'You cannot edit this content, so you may not see or restore its revisions.', 'albert-ai-butler' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! post_type_supports( $post->post_type, 'revisions' ) ) {
			return new WP_Error(
				'albert_invalid_post_id',
				sprintf(
					/* translators: %s: post type name */
					__( 'The "%s" post type does not keep revisions. Send the ID of content that does, such as a post, page, template or navigation menu.', 'albert-ai-butler' ),
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
	 * @return array{id: int, date: string, date_gmt: string, author: array{id: int, name: string}, autosave: bool, title: string}
	 * @since 1.6.0
	 */
	public static function summary( WP_Post $revision ): array {
		$author = get_userdata( (int) $revision->post_author );

		return [
			'id'       => (int) $revision->ID,
			'date'     => (string) mysql_to_rfc3339( $revision->post_date ),
			'date_gmt' => (string) mysql_to_rfc3339( $revision->post_date_gmt ),
			'author'   => [
				'id'   => (int) $revision->post_author,
				'name' => $author instanceof WP_User ? (string) $author->display_name : '',
			],
			'autosave' => (bool) wp_is_post_autosave( $revision ),
			'title'    => (string) $revision->post_title,
		];
	}

	/**
	 * A post as the revision abilities reference it.
	 *
	 * @param WP_Post $post The post.
	 * @return array{id: int, name: string}
	 * @since 1.6.0
	 */
	public static function post_reference( WP_Post $post ): array {
		return [
			'id'   => (int) $post->ID,
			'name' => (string) $post->post_title,
		];
	}

	/**
	 * The output schema of a post reference.
	 *
	 * @param string $description What the post is to the caller.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public static function post_reference_schema( string $description ): array {
		return [
			'type'        => 'object',
			'description' => $description,
			'properties'  => [
				'id'   => [ 'type' => 'integer' ],
				'name' => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * The output schema of a revision summary.
	 *
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public static function summary_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'       => [ 'type' => 'integer' ],
				'date'     => [ 'type' => 'string' ],
				'date_gmt' => [ 'type' => 'string' ],
				'author'   => [
					'type'        => 'object',
					'description' => 'Who saved the revision.',
					'properties'  => [
						'id'   => [ 'type' => 'integer' ],
						'name' => [ 'type' => 'string' ],
					],
				],
				'autosave' => [ 'type' => 'boolean' ],
				'title'    => [ 'type' => 'string' ],
			],
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
	 * Turn Text_Diff's edits into one entry per line, long lines cut down.
	 *
	 * @param array<int, object> $edits Text_Diff operations.
	 * @return array<int, array{0: string, 1: string, 2: int, 3: int, 4: bool}> Prefix, text, old line, new line, whether the text was cut.
	 * @since 1.6.0
	 */
	private static function flatten( array $edits ): array {
		$rows = [];
		$old  = 1;
		$new  = 1;

		foreach ( $edits as $edit ) {
			$orig  = ( $edit instanceof Text_Diff_Op_copy || $edit instanceof Text_Diff_Op_delete || $edit instanceof Text_Diff_Op_change ) && is_array( $edit->orig ) ? array_map( 'strval', $edit->orig ) : [];
			$final = ( $edit instanceof Text_Diff_Op_copy || $edit instanceof Text_Diff_Op_add || $edit instanceof Text_Diff_Op_change ) && is_array( $edit->final ) ? array_map( 'strval', $edit->final ) : [];

			if ( $edit instanceof Text_Diff_Op_copy ) {
				foreach ( $orig as $line ) {
					$text   = self::clip( $line, 0, self::MAX_LINE_CHARS );
					$rows[] = [ ' ', $text, $old++, $new++, $text !== $line ];
				}
				continue;
			}

			// Pair each removed line with the line that replaced it, so both sides show the change.
			$from = [];
			$to   = [];
			foreach ( $orig as $k => $line ) {
				if ( isset( $final[ $k ] ) ) {
					[ $from[ $k ], $to[ $k ] ] = self::clip_pair( $line, $final[ $k ] );
				} else {
					$from[ $k ] = self::clip( $line, 0, self::MAX_LINE_CHARS );
				}
			}
			foreach ( $final as $k => $line ) {
				$to[ $k ] ??= self::clip( $line, 0, self::MAX_LINE_CHARS );
			}

			foreach ( $orig as $k => $line ) {
				$rows[] = [ '-', $from[ $k ], $old++, $new, $from[ $k ] !== $line ];
			}
			foreach ( $final as $k => $line ) {
				$rows[] = [ '+', $to[ $k ], $old, $new++, $to[ $k ] !== $line ];
			}
		}

		return $rows;
	}

	/**
	 * Cut a long changed line and its replacement to the area around the change, on both sides.
	 *
	 * @param string $from The older line.
	 * @param string $to   The newer line.
	 * @return array{0: string, 1: string}
	 * @since 1.6.0
	 */
	private static function clip_pair( string $from, string $to ): array {
		if ( strlen( $from ) <= self::MAX_LINE_CHARS && strlen( $to ) <= self::MAX_LINE_CHARS ) {
			return [ $from, $to ];
		}

		$shortest = min( strlen( $from ), strlen( $to ) );
		$prefix   = strspn( substr( $from, 0, $shortest ) ^ substr( $to, 0, $shortest ), "\0" );
		$suffix   = min(
			strspn( strrev( substr( $from, -$shortest ) ) ^ strrev( substr( $to, -$shortest ) ), "\0" ),
			$shortest - $prefix
		);
		$start    = max( 0, $prefix - self::LINE_CONTEXT );

		return [
			self::clip( $from, $start, strlen( $from ) - $suffix + self::LINE_CONTEXT - $start ),
			self::clip( $to, $start, strlen( $to ) - $suffix + self::LINE_CONTEXT - $start ),
		];
	}

	/**
	 * Part of a line, at most MAX_LINE_CHARS long, with "…" where text was left out.
	 *
	 * @param string $line   The line.
	 * @param int    $start  Byte offset to start at.
	 * @param int    $length Bytes wanted.
	 * @return string
	 * @since 1.6.0
	 */
	private static function clip( string $line, int $start, int $length ): string {
		$length = min( $length, self::MAX_LINE_CHARS );
		if ( $start === 0 && strlen( $line ) <= $length ) {
			return $line;
		}

		$part = mb_strcut( $line, $start, $length, 'UTF-8' );

		return ( $start > 0 ? self::ELLIPSIS : '' ) . $part . ( $start + strlen( $part ) < strlen( $line ) ? self::ELLIPSIS : '' );
	}

	/**
	 * Render changed lines with a little context, as unified diff hunks.
	 *
	 * @param array<int, array{0: string, 1: string, 2: int, 3: int, 4: bool}> $rows Flattened diff.
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
				$lines[]   = $row[0] . $row[1];
				$truncated = $truncated || $row[4];
			}

			foreach ( $lines as $line ) {
				if ( count( $out ) >= self::MAX_LINES ) {
					$truncated = true;
					break 2;
				}
				if ( $chars + strlen( $line ) > self::MAX_CHARS ) {
					// Keep the start of the line that crosses the budget rather than nothing.
					$room = self::MAX_CHARS - $chars;
					if ( $room > 0 ) {
						$out[] = mb_strcut( $line, 0, $room, 'UTF-8' );
					}
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
