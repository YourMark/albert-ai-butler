<?php
/**
 * Categories and tags of a post: read for the view abilities, resolved for the write abilities.
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Shared
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Shared;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a post's categories and tags so an assistant can check a term write took
 * effect, and resolves the ones it sends. Core skips an unknown category ID or a
 * tag it cannot create without an error, so both are checked here.
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
	 * Taxonomies albert/add-post-terms and albert/remove-post-terms write.
	 *
	 * @var array<int, string>
	 * @since 1.6.0
	 */
	public const WRITABLE = [ 'category', 'post_tag' ];

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
	public static function for_post( WP_Post $post ): array {
		$result = [];

		foreach ( self::TAXONOMIES as $key => $taxonomy ) {
			if ( is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
				$result[ $key ] = self::terms( $post, $taxonomy );
			}
		}

		return $result;
	}

	/**
	 * The post's terms in one taxonomy as { id, name } pairs.
	 *
	 * @param WP_Post $post     The post.
	 * @param string  $taxonomy Taxonomy name.
	 * @return array<int, array{id: int, name: string}>
	 * @since 1.6.0
	 */
	public static function terms( WP_Post $post, string $taxonomy ): array {
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return [];
		}

		$result = [];
		foreach ( $terms as $term ) {
			$result[] = [
				'id'   => (int) $term->term_id,
				// Core stores "&" as "&amp;"; return the name as it was written.
				'name' => wp_specialchars_decode( $term->name, ENT_QUOTES ),
			];
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
		return [
			'categories' => self::list_schema( 'Assigned categories. Absent when this post type has no categories.' ),
			'tags'       => self::list_schema( 'Assigned tags. Absent when this post type has no tags.' ),
		];
	}

	/**
	 * Output schema for a list of { id, name } terms.
	 *
	 * @param string $description What the list holds.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public static function list_schema( string $description ): array {
		return [
			'type'        => 'array',
			'description' => $description,
			'items'       => [
				'type'       => 'object',
				'properties' => [
					'id'   => [ 'type' => 'integer' ],
					'name' => [ 'type' => 'string' ],
				],
			],
		];
	}

	/**
	 * Refuses category IDs that do not exist, which core would drop without a word.
	 *
	 * @param array<int, int> $ids Category IDs.
	 * @return WP_Error|null Null when every ID is a category.
	 * @since 1.6.0
	 */
	public static function check_category_ids( array $ids ): ?WP_Error {
		$unknown = array_filter( $ids, static fn( int $id ): bool => ! term_exists( $id, 'category' ) );

		if ( $unknown === [] ) {
			return null;
		}

		return new WP_Error(
			'albert_category_not_found',
			sprintf(
				/* translators: %s: comma-separated category IDs. */
				__( 'No category exists with ID %s, so nothing was saved. Look up category IDs with albert/find-terms.', 'albert-ai-butler' ),
				implode( ', ', $unknown )
			),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Tag IDs for the given names, creating the tags that do not exist yet.
	 *
	 * New tags go through the REST endpoint, so the user needs core's right to add them.
	 *
	 * @param array<int, string> $names Tag names.
	 * @param string             $field Input field the names came from, for the error code.
	 * @return array<int, int>|WP_Error WP_Error naming the first tag that could not be created.
	 * @since 1.6.0
	 */
	public static function tag_ids( array $names, string $field = 'tags' ): array|WP_Error {
		$ids = [];

		foreach ( $names as $name ) {
			$tag = get_term_by( 'name', $name, 'post_tag' );
			if ( $tag instanceof WP_Term ) {
				$ids[] = (int) $tag->term_id;
				continue;
			}

			$request = new WP_REST_Request( 'POST', '/wp/v2/tags' );
			$request->set_param( 'name', $name );
			$response = rest_do_request( $request );
			$data     = rest_get_server()->response_to_data( $response, false );

			if ( ! $response->is_error() ) {
				$ids[] = (int) $data['id'];
				continue;
			}

			// A name that differs only in a way get_term_by() misses still counts as found.
			if ( isset( $data['data']['term_id'] ) ) {
				$ids[] = (int) $data['data']['term_id'];
				continue;
			}

			$status = $response->get_status();

			return new WP_Error(
				$status === 401 || $status === 403 ? 'albert_tag_permission_denied' : 'albert_invalid_' . $field,
				sprintf(
					/* translators: 1: tag name, 2: reason from WordPress. */
					__( 'The tag "%1$s" could not be created, so the post was not changed: %2$s', 'albert-ai-butler' ),
					$name,
					$data['message'] ?? ''
				),
				[ 'status' => $status ]
			);
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * IDs of the tags that exist under the given names; unknown names are skipped.
	 *
	 * @param array<int, string> $names Tag names.
	 * @return array<int, int>
	 * @since 1.6.0
	 */
	public static function existing_tag_ids( array $names ): array {
		$ids = [];

		foreach ( $names as $name ) {
			$tag = get_term_by( 'name', $name, 'post_tag' );
			if ( $tag instanceof WP_Term ) {
				$ids[] = (int) $tag->term_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
