<?php
/**
 * Readable references from a post: author, parent and featured image.
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Shared
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Shared;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the IDs a post points at into { id, name } style references, so an
 * assistant can say who wrote a post without a second lookup.
 *
 * @since 1.6.0
 */
class PostReferences {

	/**
	 * The post's author, or null when the user no longer exists.
	 *
	 * @param WP_Post $post The post.
	 * @return array{id: int, name: string}|null
	 * @since 1.6.0
	 */
	public static function author( WP_Post $post ): ?array {
		$user = get_userdata( (int) $post->post_author );
		if ( ! $user ) {
			return null;
		}

		return [
			'id'   => (int) $user->ID,
			'name' => $user->display_name,
		];
	}

	/**
	 * The post's parent, or null for a top-level post.
	 *
	 * @param WP_Post $post The post.
	 * @return array{id: int, name: string}|null
	 * @since 1.6.0
	 */
	public static function parent( WP_Post $post ): ?array {
		$parent = $post->post_parent ? get_post( $post->post_parent ) : null;
		if ( ! $parent instanceof WP_Post ) {
			return null;
		}

		return [
			'id'   => (int) $parent->ID,
			'name' => $parent->post_title,
		];
	}

	/**
	 * The post's featured image, or null when it has none.
	 *
	 * @param WP_Post $post The post.
	 * @return array{id: int, url: string, alt: string}|null
	 * @since 1.6.0
	 */
	public static function featured_image( WP_Post $post ): ?array {
		$id = (int) get_post_thumbnail_id( $post );
		if ( $id === 0 ) {
			return null;
		}

		return [
			'id'  => $id,
			'url' => (string) wp_get_attachment_url( $id ),
			'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		];
	}

	/**
	 * Output schema for an { id, name } reference that may be null.
	 *
	 * @param string $description What the reference points at.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public static function reference_schema( string $description ): array {
		return [
			'type'        => [ 'object', 'null' ],
			'description' => $description,
			'properties'  => [
				'id'   => [ 'type' => 'integer' ],
				'name' => [ 'type' => 'string' ],
			],
		];
	}

	/**
	 * Output schema for featured_image().
	 *
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public static function featured_image_schema(): array {
		return [
			'type'        => [ 'object', 'null' ],
			'description' => 'The featured image with its URL and alt text, or null when there is none.',
			'properties'  => [
				'id'  => [ 'type' => 'integer' ],
				'url' => [ 'type' => 'string' ],
				'alt' => [ 'type' => 'string' ],
			],
		];
	}
}
