<?php
/**
 * Categories and tags of a post, for the view abilities.
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Shared
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Shared;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a post's categories and tags so an assistant can check a term write took effect.
 *
 * @since 1.6.0
 */
class PostTerms {

	/**
	 * Output keys mapped to the taxonomy each one reads.
	 *
	 * @var array<string, string>
	 * @since 1.6.0
	 */
	private const TAXONOMIES = [
		'categories' => 'category',
		'tags'       => 'post_tag',
	];

	/**
	 * The post's categories and tags as { id, name } lists.
	 *
	 * A key is left out when the post type does not use that taxonomy, so a
	 * missing key means "not applicable" and an empty list means "none assigned".
	 *
	 * @param WP_Post $post The post.
	 * @return array<string, array<int, array{id: int, name: string}>>
	 * @since 1.6.0
	 */
	public static function for_post( object $post ): array {
		$result = [];

		foreach ( self::TAXONOMIES as $key => $taxonomy ) {
			if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy );

			$result[ $key ] = [];
			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$result[ $key ][] = [
					'id'   => (int) $term->term_id,
					'name' => $term->name,
				];
			}
		}

		return $result;
	}

	/**
	 * Output schema properties for the keys returned by for_post().
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.6.0
	 */
	public static function schema(): array {
		$term = [
			'type'       => 'object',
			'properties' => [
				'id'   => [ 'type' => 'integer' ],
				'name' => [ 'type' => 'string' ],
			],
		];

		return [
			'categories' => [
				'type'        => 'array',
				'description' => 'Assigned categories. Absent when this post type has no categories.',
				'items'       => $term,
			],
			'tags'       => [
				'type'        => 'array',
				'description' => 'Assigned tags. Absent when this post type has no tags.',
				'items'       => $term,
			],
		];
	}
}
