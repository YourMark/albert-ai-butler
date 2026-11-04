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
 * Validated here so a refusal says what to send instead; the REST endpoint
 * still saves them and keeps its own capability checks.
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
	 * The shapes `rest_parse_date()` accepts, so nothing passes here that the endpoint refuses.
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
				'description' => 'URL slug, e.g. "summer-sale". WordPress adds a number when another item already uses it; the result returns the slug that was saved.',
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
				'description' => 'Post format, e.g. "standard", "aside", "gallery". Only "standard" and the formats the theme supports are accepted.',
			],
		];

		return array_intersect_key( $all, array_flip( $fields ) );
	}

	/**
	 * Validate the supplied fields and turn them into REST parameters.
	 *
	 * Only fields present in `$args` are returned, so an update leaves the rest alone.
	 *
	 * @param array<string, mixed> $args      Ability input.
	 * @param array<int, string>   $fields    Fields this ability accepts.
	 * @param string               $post_type Post type being written.
	 * @param int                  $post_id   Post being updated, 0 on create.
	 * @return array<string, mixed>|WP_Error REST parameters, or the first refusal.
	 * @since 1.6.0
	 */
	public static function to_rest( array $args, array $fields, string $post_type, int $post_id = 0 ): array|WP_Error {
		$params = [];

		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $args ) || $args[ $field ] === null ) {
				continue;
			}

			$value = match ( $field ) {
				'slug'           => self::slug( $args[ $field ] ),
				'date'           => self::date( $args[ $field ] ),
				'author'         => self::author( $args[ $field ], $post_type ),
				'parent'         => self::parent( $args[ $field ], $post_type, $post_id ),
				'menu_order'     => self::integer( 'menu_order', $args[ $field ] ),
				'template'       => self::template( $args[ $field ], $post_type, $post_id ),
				'comment_status' => self::comment_status( $args[ $field ] ),
				'sticky'         => self::sticky( $args[ $field ] ),
				'format'         => self::format( $args[ $field ] ),
				default          => null,
			};

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			if ( $value !== null ) {
				$params[ $field ] = $value;
			}
		}

		return $params;
	}

	/**
	 * Validate a slug.
	 *
	 * @param mixed $value Supplied slug.
	 * @return string|WP_Error
	 */
	private static function slug( mixed $value ): string|WP_Error {
		$slug = is_string( $value ) ? sanitize_title( $value ) : '';

		if ( $slug === '' ) {
			return self::refuse( 'invalid_slug', __( 'The slug is empty once cleaned up. Send letters, numbers or hyphens, e.g. "summer-sale".', 'albert-ai-butler' ) );
		}

		return $slug;
	}

	/**
	 * Validate a date: the shape the REST API parses, and a real calendar date.
	 *
	 * Checked here because `strtotime()` rolls 2026-02-30 over to March instead of failing.
	 *
	 * @param mixed $value Supplied date.
	 * @return string|WP_Error
	 */
	private static function date( mixed $value ): string|WP_Error {
		$valid = is_string( $value ) && preg_match( self::DATE_PATTERN, $value, $m ) === 1
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] )
			&& (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60;

		if ( ! $valid ) {
			return self::refuse(
				'invalid_date',
				__( 'The date is not a valid date and time. Send "YYYY-MM-DDTHH:MM:SS", e.g. "2026-12-01T09:00:00", optionally ending in an offset such as "+02:00".', 'albert-ai-butler' )
			);
		}

		return $value;
	}

	/**
	 * Validate an author: a real user who can edit this type, assignable by the caller.
	 *
	 * The REST API checks only that the user exists, so it would accept a subscriber.
	 *
	 * @param mixed  $value     Supplied user ID.
	 * @param string $post_type Post type being written.
	 * @return int|WP_Error
	 */
	private static function author( mixed $value, string $post_type ): int|WP_Error {
		$user_id = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ? (int) $value : 0;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( ! $user ) {
			return self::refuse(
				'invalid_author',
				/* translators: %d: user ID. */
				sprintf( __( 'No user with ID %d. Find the author with albert/find-users.', 'albert-ai-butler' ), $user_id )
			);
		}

		$caps = self::caps( $post_type );

		if ( ! user_can( $user, $caps['edit_posts'] ) ) {
			return self::refuse(
				'author_cannot_edit',
				/* translators: %s: user display name. */
				sprintf( __( '%s cannot edit this kind of content, so they cannot be its author. Pick a user with an author, editor or administrator role.', 'albert-ai-butler' ), $user->display_name )
			);
		}

		if ( $user_id !== get_current_user_id() && ! current_user_can( $caps['edit_others_posts'] ) ) {
			return self::refuse(
				'author_permission_denied',
				__( 'You can only set yourself as the author: assigning someone else needs permission to edit others\' content.', 'albert-ai-butler' ),
				403
			);
		}

		return $user_id;
	}

	/**
	 * Validate a parent: 0, or an existing item of the same type that is not a descendant.
	 *
	 * A loop would not fail: `wp_insert_post()` silently resets the parent to 0.
	 *
	 * @param mixed  $value     Supplied parent ID.
	 * @param string $post_type Post type being written.
	 * @param int    $post_id   Post being updated, 0 on create.
	 * @return int|WP_Error
	 */
	private static function parent( mixed $value, string $post_type, int $post_id ): int|WP_Error {
		if ( ! is_int( $value ) || $value < 0 ) {
			return self::refuse( 'invalid_parent', __( 'parent must be a page ID, or 0 for a top-level page.', 'albert-ai-butler' ) );
		}

		$parent_id = $value;
		if ( $parent_id === 0 ) {
			return 0;
		}

		$parent = get_post( $parent_id );

		if ( ! $parent || $parent->post_type !== $post_type ) {
			return self::refuse(
				'invalid_parent',
				/* translators: 1: post ID, 2: post type. */
				sprintf( __( 'No %2$s with ID %1$d to use as the parent. The parent must be the same kind of content; send 0 for top level.', 'albert-ai-butler' ), $parent_id, $post_type )
			);
		}

		if ( $post_id > 0 && ( $parent_id === $post_id || in_array( $post_id, array_map( 'intval', get_post_ancestors( $parent ) ), true ) ) ) {
			return self::refuse( 'parent_loop', __( 'That parent is this item itself or one of its children, which would make a loop. Pick another parent.', 'albert-ai-butler' ) );
		}

		return $parent_id;
	}

	/**
	 * Validate a template against those the theme offers for this type.
	 *
	 * The item's current template is always accepted, as the REST API does, so
	 * sending back what view-post returned never fails.
	 *
	 * @param mixed  $value     Supplied template.
	 * @param string $post_type Post type being written.
	 * @param int    $post_id   Post being updated, 0 on create.
	 * @return string|WP_Error
	 */
	private static function template( mixed $value, string $post_type, int $post_id ): string|WP_Error {
		$template = is_string( $value ) ? trim( $value ) : null;
		if ( $template === '' ) {
			return '';
		}

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( $post && $template === get_page_template_slug( $post ) ) {
			return $template;
		}

		$available = array_map( 'strval', array_keys( wp_get_theme()->get_page_templates( $post, $post_type ) ) );

		if ( $template !== null && in_array( $template, $available, true ) ) {
			return $template;
		}

		$message = $available === []
			? __( 'The active theme offers no templates for this kind of content. Send "" for the default template.', 'albert-ai-butler' )
			/* translators: %s: comma-separated template names. */
			: sprintf( __( 'The active theme does not offer that template. Choose one of: %s, or "" for the default.', 'albert-ai-butler' ), implode( ', ', $available ) );

		return self::refuse( 'invalid_template', $message );
	}

	/**
	 * Validate a comment status.
	 *
	 * @param mixed $value Supplied status.
	 * @return string|WP_Error
	 */
	private static function comment_status( mixed $value ): string|WP_Error {
		if ( $value === 'open' || $value === 'closed' ) {
			return $value;
		}

		return self::refuse( 'invalid_comment_status', __( 'comment_status must be "open" or "closed".', 'albert-ai-butler' ) );
	}

	/**
	 * Validate the sticky flag.
	 *
	 * @param mixed $value Supplied flag.
	 * @return bool|WP_Error
	 */
	private static function sticky( mixed $value ): bool|WP_Error {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return self::refuse( 'invalid_sticky', __( 'sticky must be true or false.', 'albert-ai-butler' ) );
	}

	/**
	 * Validate a post format against the theme's support, as the editor offers them.
	 *
	 * @param mixed $value Supplied format.
	 * @return string|WP_Error
	 */
	private static function format( mixed $value ): string|WP_Error {
		$format    = is_string( $value ) ? $value : '';
		$supported = get_theme_support( 'post-formats' );
		$offered   = array_merge( [ 'standard' ], is_array( $supported ) && is_array( $supported[0] ?? null ) ? $supported[0] : [] );

		if ( in_array( $format, $offered, true ) ) {
			return $format;
		}

		return self::refuse(
			'invalid_format',
			/* translators: %s: comma-separated format names. */
			sprintf( __( 'The active theme does not support that post format. Choose one of: %s.', 'albert-ai-butler' ), implode( ', ', $offered ) )
		);
	}

	/**
	 * Validate a whole number.
	 *
	 * @param string $field Field name, for the message.
	 * @param mixed  $value Supplied value.
	 * @return int|WP_Error
	 */
	private static function integer( string $field, mixed $value ): int|WP_Error {
		if ( is_int( $value ) ) {
			return $value;
		}

		/* translators: %s: field name. */
		return self::refuse( 'invalid_' . $field, sprintf( __( '%s must be a whole number.', 'albert-ai-butler' ), $field ) );
	}

	/**
	 * The edit capabilities of a post type, falling back to the post ones.
	 *
	 * @param string $post_type Post type.
	 * @return array{edit_posts: string, edit_others_posts: string}
	 */
	private static function caps( string $post_type ): array {
		$object = get_post_type_object( $post_type );

		return [
			'edit_posts'        => $object->cap->edit_posts ?? 'edit_posts',
			'edit_others_posts' => $object->cap->edit_others_posts ?? 'edit_others_posts',
		];
	}

	/**
	 * Build a refusal.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message telling the assistant what to send instead.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function refuse( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
