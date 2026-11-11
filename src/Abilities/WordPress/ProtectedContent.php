<?php
/**
 * Password-protected content rule for the read abilities.
 *
 * @package Albert
 * @subpackage Abilities\WordPress
 * @since      1.5.1
 */

namespace Albert\Abilities\WordPress;

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
