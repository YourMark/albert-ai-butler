<?php
/**
 * Password-protected content rule for the read abilities.
 *
 * @package Albert
 * @subpackage Abilities\WordPress
 * @since      1.5.1
 */

namespace Albert\Abilities\WordPress;

defined( 'ABSPATH' ) || exit;

use Albert\Blocks\EditorMode;

/**
 * Whether a post's content may be shown to the current user.
 *
 * Mirrors core REST: `read_post` ignores the post password, so a reader without
 * the password sees no content unless they can edit the post.
 *
 * @since 1.5.1
 */
final class ProtectedContent {

	/**
	 * Whether the post's content and excerpt must be withheld.
	 *
	 * Typed `object`, not WP_Post: the unit suite passes stdClass posts.
	 *
	 * @param object $post The post (a WP_Post).
	 * @phpstan-param object{ID: int, post_password?: string} $post
	 *
	 * @return bool
	 * @since 1.5.1
	 */
	public static function is_withheld( object $post ): bool {
		return post_password_required( (int) $post->ID ) && ! current_user_can( 'edit_post', (int) $post->ID );
	}

	/**
	 * Whether the post has a password, for the `protected` output field.
	 *
	 * @param object $post The post (a WP_Post).
	 * @phpstan-param object{ID: int, post_password?: string} $post
	 *
	 * @return bool
	 * @since 1.5.1
	 */
	public static function is_protected( object $post ): bool {
		return ( $post->post_password ?? '' ) !== '';
	}

	/**
	 * The editor signal, without revealing anything about withheld content.
	 *
	 * `has_blocks` is read from the content, so it is reported false when the
	 * content itself is withheld.
	 *
	 * @param object $post The post (a WP_Post).
	 * @phpstan-param object{ID: int, post_type: string, post_content: string, post_password?: string} $post
	 *
	 * @return array{editor: string, has_blocks: bool}
	 * @since 1.5.1
	 */
	public static function editor_signal( object $post ): array {
		$signal = EditorMode::signal( $post );

		if ( self::is_withheld( $post ) ) {
			$signal['has_blocks'] = false;
		}

		return $signal;
	}

	/**
	 * A `posts_search` filter that keeps others' protected posts out of a search.
	 *
	 * Core searches protected content for any logged-in user, so whether a
	 * result came back would answer "does this post contain X". The filter
	 * limits matches to unprotected posts and the user's own; null when the
	 * user may edit everyone's posts of this type and so may read them anyway.
	 *
	 * @param string $post_type The post type searched.
	 *
	 * @return \Closure|null Add to `posts_search` (2 args) around the request, then remove.
	 * @since 1.5.1
	 */
	public static function search_guard( string $post_type ): ?\Closure {
		$type_object = get_post_type_object( $post_type );

		if ( ! $type_object || current_user_can( $type_object->cap->edit_others_posts ) ) {
			return null;
		}

		$user_id = get_current_user_id();

		return static function ( $search ) use ( $user_id ) {
			if ( ! is_string( $search ) || $search === '' ) {
				return $search;
			}

			global $wpdb;

			return $search . $wpdb->prepare( " AND ( {$wpdb->posts}.post_password = '' OR {$wpdb->posts}.post_author = %d )", $user_id );
		};
	}

	/**
	 * The output schema for the `protected` field.
	 *
	 * @return array<string, string>
	 * @since 1.5.1
	 */
	public static function schema(): array {
		return [
			'type'        => 'boolean',
			'description' => 'Whether the item is password-protected. Its content and excerpt are empty unless you can edit it.',
		];
	}
}
