<?php
/**
 * Post fields beyond title and content
 *
 * @package Albert
 * @subpackage Abilities\WordPress
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress;

use WP_Error;

/**
 * The slug, date, author, parent, order, template, comment, sticky and format
 * fields shared by the post and page write abilities.
 *
 * The REST endpoint validates and saves them. Only what it accepts silently
 * and gets wrong is checked here.
 *
 * @since 1.6.0
 */
class PostFields {

	/**
	 * Fields a post accepts.
	 *
	 * @since 1.6.0
	 */
	public const POST = [ 'slug', 'date', 'author', 'template', 'comment_status', 'sticky', 'format' ];

	/**
	 * Fields a page accepts.
	 *
	 * @since 1.6.0
	 */
	public const PAGE = [ 'slug', 'date', 'author', 'parent', 'menu_order', 'template', 'comment_status' ];

	/**
	 * The shapes `rest_parse_date()` accepts.
	 */
	private const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})[Tt ](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+-]\d{2}(?::\d{2})?)?$/';

	/**
	 * Input schema properties for the given fields.
	 *
	 * @param array<int, string> $fields One of the field lists above.
	 * @return array<string, array<string, mixed>>
	 * @since 1.6.0
	 */
	public static function input_schema( array $fields ): array {
		$all = [
			'slug'           => [
				'type'        => 'string',
				'description' => 'URL slug, e.g. "summer-sale". On a published item this changes its address, so links to the old one may stop working. WordPress adds a number when another item already uses it; the result returns the slug that was saved.',
			],
			'date'           => [
				'type'        => 'string',
				'description' => 'Publish date as "YYYY-MM-DDTHH:MM:SS", in the site\'s timezone unless it ends in an offset ("Z", "+02:00"). To schedule, send a future date with status "publish": WordPress saves it as "future" and publishes it then. A past date backdates it.',
			],
			'author'         => [
				'type'        => 'integer',
				'description' => 'User ID of the new author. Find it with albert/find-users. The user must be able to edit this content type, and changing it to someone else needs permission to edit others\' content.',
			],
			'parent'         => [
				'type'        => 'integer',
				'description' => 'ID of the parent page, or 0 for a top-level page.',
			],
			'menu_order'     => [
				'type'        => 'integer',
				'description' => 'Sort order among sibling pages; lower comes first.',
			],
			'template'       => [
				'type'        => 'string',
				'description' => 'Template file or slug the theme offers for this content type, or "" for the default template. An unknown template is refused with the list of choices.',
			],
			'comment_status' => [
				'type'        => 'string',
				'enum'        => [ 'open', 'closed' ],
				'description' => 'Whether visitors can comment.',
			],
			'sticky'         => [
				'type'        => 'boolean',
				'description' => 'Keep the post at the top of the blog page.',
			],
			'format'         => [
				'type'        => 'string',
				'enum'        => array_values( get_post_format_slugs() ),
				'description' => 'Post format: a classic-theme label such as "aside" or "gallery" that the theme may style differently. Most block themes ignore it.',
			],
		];

		return array_intersect_key( $all, array_flip( $fields ) );
	}

	/**
	 * The supplied fields as REST parameters, or a refusal for what REST would save wrongly.
	 *
	 * Only fields present in `$args` are returned, so an update leaves the rest alone.
	 *
	 * @param array<string, mixed> $args      Ability input.
	 * @param array<int, string>   $fields    Fields this ability accepts.
	 * @param string               $post_type Post type being written.
	 * @param int                  $post_id   Post being updated, 0 on create.
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	public static function to_rest( array $args, array $fields, string $post_type, int $post_id = 0 ): array|WP_Error {
		$params = array_filter(
			array_intersect_key( $args, array_flip( $fields ) ),
			static fn( mixed $value ): bool => $value !== null
		);

		if ( isset( $params['date'] ) && ! self::is_real_date( $params['date'] ) ) {
			return self::refuse(
				'invalid_date',
				__( 'The date is not a valid date and time. Send "YYYY-MM-DDTHH:MM:SS", e.g. "2026-12-01T09:00:00", optionally ending in an offset such as "+02:00".', 'albert-ai-butler' )
			);
		}

		$refusal = isset( $params['author'] ) ? self::author_refusal( (int) $params['author'], $post_type ) : null;
		$refusal = $refusal ?? ( isset( $params['parent'] ) ? self::parent_refusal( (int) $params['parent'], $post_type, $post_id ) : null );

		return $refusal ?? $params;
	}

	/**
	 * Whether a date has the REST shape and exists on the calendar.
	 *
	 * `strtotime()` rolls 2026-02-30 over to March instead of failing.
	 *
	 * @param mixed $value Supplied date.
	 * @return bool
	 */
	private static function is_real_date( mixed $value ): bool {
		return is_string( $value ) && preg_match( self::DATE_PATTERN, $value, $m ) === 1
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] )
			&& (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60;
	}

	/**
	 * Refuse an author who cannot edit this type: REST accepts any existing user, even a subscriber.
	 *
	 * An unknown user, or someone else without `edit_others_posts`, is left for REST to
	 * refuse, so a caller who may not assign others cannot probe user IDs here.
	 *
	 * @param int    $user_id   Supplied user ID.
	 * @param string $post_type Post type being written.
	 * @return WP_Error|null
	 */
	private static function author_refusal( int $user_id, string $post_type ): ?WP_Error {
		$caps = get_post_type_object( $post_type )->cap ?? null;
		$user = get_userdata( $user_id );

		if ( ! $caps || ! $user || ( $user_id !== get_current_user_id() && ! current_user_can( $caps->edit_others_posts ) ) ) {
			return null;
		}

		if ( user_can( $user, $caps->edit_posts ) ) {
			return null;
		}

		return self::refuse(
			'author_cannot_edit',
			/* translators: %s: user display name. */
			sprintf( __( '%s cannot edit this kind of content, so they cannot be its author. Pick a user with an author, editor or administrator role.', 'albert-ai-butler' ), $user->display_name )
		);
	}

	/**
	 * Refuse a parent of another type, or one that makes a loop.
	 *
	 * REST accepts any existing post as parent, and `wp_insert_post()` silently
	 * resets a loop to 0. A parent that does not exist is left for REST to refuse.
	 *
	 * @param int    $parent_id Supplied parent ID.
	 * @param string $post_type Post type being written.
	 * @param int    $post_id   Post being updated, 0 on create.
	 * @return WP_Error|null
	 */
	private static function parent_refusal( int $parent_id, string $post_type, int $post_id ): ?WP_Error {
		$parent = $parent_id > 0 ? get_post( $parent_id ) : null;
		if ( ! $parent ) {
			return null;
		}

		if ( $parent->post_type !== $post_type ) {
			return self::refuse(
				'invalid_parent',
				/* translators: 1: post ID, 2: post type. */
				sprintf( __( 'ID %1$d is not a %2$s. The parent must be the same kind of content; send 0 for top level.', 'albert-ai-butler' ), $parent_id, $post_type )
			);
		}

		if ( $post_id > 0 && ( $parent_id === $post_id || in_array( $post_id, array_map( 'intval', get_post_ancestors( $parent ) ), true ) ) ) {
			return self::refuse( 'parent_loop', __( 'That parent is this item itself or one of its children, which would make a loop. Pick another parent.', 'albert-ai-butler' ) );
		}

		return null;
	}

	/**
	 * Build a refusal.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message telling the assistant what to send instead.
	 * @return WP_Error
	 */
	private static function refuse( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => 400 ] );
	}
}
