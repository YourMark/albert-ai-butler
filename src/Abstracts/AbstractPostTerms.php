<?php
/**
 * Abstract Post Terms Ability
 *
 * @package    Albert
 * @subpackage Abstracts
 * @since      1.6.0
 */

namespace Albert\Abstracts;

use Albert\Abilities\WordPress\PostTerms;
use WP_Error;
use WP_Post;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Changes some terms of one taxonomy on a post and leaves the rest alone.
 *
 * Reads the current terms, applies the subclass's change and writes the whole
 * list once through the posts REST endpoint, so core's checks and hooks run.
 *
 * @since 1.6.0
 */
abstract class AbstractPostTerms extends BaseAbility {

	/**
	 * Most terms one call takes.
	 *
	 * @var int
	 * @since 1.6.0
	 */
	protected const MAX_TERMS = 50;

	/**
	 * Whether tag names that do not exist yet are created.
	 *
	 * @var bool
	 * @since 1.6.0
	 */
	protected const CREATE_TAGS = true;

	/**
	 * Whether tags may also be sent by ID. A numeric string stays a name, since "2024" is a valid tag.
	 *
	 * @var bool
	 * @since 1.6.0
	 */
	protected const TAG_IDS = false;

	/**
	 * The new term IDs, from the current IDs and the resolved IDs sent.
	 *
	 * @param array<int, int> $current IDs on the post now.
	 * @param array<int, int> $sent    IDs resolved from the input.
	 * @return array<int, int>
	 * @since 1.6.0
	 */
	abstract protected function apply( array $current, array $sent ): array;

	/**
	 * Input schema shared by add and remove.
	 *
	 * @param string $terms_description Description of the `terms` property.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	protected function terms_input_schema( string $terms_description ): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'  => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the post. Find it with albert/find-posts.',
				],
				'taxonomy' => [
					'type'        => 'string',
					'enum'        => PostTerms::WRITABLE,
					'description' => '"category" for categories, "post_tag" for tags.',
				],
				'terms'    => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => self::MAX_TERMS,
					'items'       => [ 'type' => [ 'integer', 'string' ] ],
					'description' => $terms_description,
				],
			],
			'required'   => [ 'post_id', 'taxonomy', 'terms' ],
		];
	}

	/**
	 * Output schema shared by add and remove.
	 *
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	protected function terms_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'  => [ 'type' => 'integer' ],
				'taxonomy' => [ 'type' => 'string' ],
				'terms'    => PostTerms::list_schema( 'The post\'s terms in this taxonomy after the change.' ),
			],
			'required'   => [ 'post_id', 'taxonomy', 'terms' ],
		];
	}

	/**
	 * Check if the current user may edit posts; the post itself is checked in execute().
	 *
	 * @return bool|WP_Error
	 * @since 1.6.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'edit_posts' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	public function execute( array $args ): array|WP_Error {
		$post_id  = (int) $args['post_id'];
		$taxonomy = (string) $args['taxonomy'];
		$terms    = is_array( $args['terms'] ) ? array_values( $args['terms'] ) : [];

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || $post->post_type !== 'post' ) {
			return new WP_Error(
				'albert_post_not_found',
				sprintf(
					/* translators: %d: Post ID */
					__( 'No post exists with ID %d. Find the post with albert/find-posts.', 'albert-ai-butler' ),
					$post_id
				),
				[ 'status' => 404 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'albert_post_permission_denied',
				__( 'You do not have permission to edit this post. Ask the site owner for access.', 'albert-ai-butler' ),
				[ 'status' => 403 ]
			);
		}

		$sent = $this->resolve( $taxonomy, $terms );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		$current = array_column( PostTerms::terms( $post, $taxonomy ), 'id' );
		$new     = array_values( array_unique( $this->apply( $current, $sent ) ) );

		// Mirrors wp_set_post_categories(): a post always keeps one category.
		$emptied = $taxonomy === 'category' && $new === [] && $post->post_status !== 'auto-draft';
		if ( $emptied ) {
			$new = [ (int) get_option( 'default_category' ) ];
		}

		$unchanged = array_diff( $new, $current ) === [] && array_diff( $current, $new ) === [];
		if ( ! $unchanged ) {
			$written = $this->write( $post_id, $taxonomy, $new );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			clean_post_cache( $post_id );
		}

		// Removing the default category from a post that only has it changes nothing, so nothing was put back.
		return $this->result( $post_id, $taxonomy, $emptied && ! $unchanged, $emptied && $unchanged );
	}

	/**
	 * The ability's result.
	 *
	 * @param int    $post_id         Post ID.
	 * @param string $taxonomy        Taxonomy name.
	 * @param bool   $default_applied Whether the default category was put back.
	 * @param bool   $default_kept    Whether the post kept its only category, the default, because a post always keeps one.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	protected function result( int $post_id, string $taxonomy, bool $default_applied, bool $default_kept ): array {
		$post = get_post( $post_id );

		return [
			'post_id'  => $post_id,
			'taxonomy' => $taxonomy,
			'terms'    => $post instanceof WP_Post ? PostTerms::terms( $post, $taxonomy ) : [],
		];
	}

	/**
	 * Term IDs for the values sent: category IDs as given, tag names resolved.
	 *
	 * @param string            $taxonomy Taxonomy name.
	 * @param array<int, mixed> $terms    Values from the input.
	 * @return array<int, int>|WP_Error
	 * @since 1.6.0
	 */
	private function resolve( string $taxonomy, array $terms ): array|WP_Error {
		if ( $taxonomy === 'category' ) {
			$ids = [];
			foreach ( $terms as $term ) {
				// Assistants often send an ID as text ("19").
				if ( is_string( $term ) && ctype_digit( $term ) ) {
					$term = (int) $term;
				}
				if ( ! is_int( $term ) ) {
					return $this->invalid_terms( __( 'For "category", "terms" takes category IDs as numbers. Look them up with albert/find-terms.', 'albert-ai-butler' ) );
				}
				$ids[] = $term;
			}

			$unknown = PostTerms::check_category_ids( $ids );

			return $unknown ?? array_values( array_unique( $ids ) );
		}

		$ids   = [];
		$names = [];
		foreach ( $terms as $term ) {
			if ( is_int( $term ) && static::TAG_IDS ) {
				$ids[] = $term;
				continue;
			}
			if ( ! is_string( $term ) || trim( $term ) === '' ) {
				return $this->invalid_terms(
					static::TAG_IDS
					? __( 'For "post_tag", "terms" takes tag names as text or tag IDs as numbers.', 'albert-ai-butler' )
					: __( 'For "post_tag", "terms" takes tag names as text, not IDs.', 'albert-ai-butler' )
				);
			}
			$names[] = $term;
		}

		$named = PostTerms::tag_ids( $names, static::CREATE_TAGS, 'terms' );
		if ( is_wp_error( $named ) ) {
			return $named;
		}

		return array_values( array_unique( array_merge( $ids, $named ) ) );
	}

	/**
	 * Write the post's full term list for one taxonomy through the REST endpoint.
	 *
	 * @param int             $post_id  Post ID.
	 * @param string          $taxonomy Taxonomy name.
	 * @param array<int, int> $ids      The complete new list.
	 * @return true|WP_Error
	 * @since 1.6.0
	 */
	private function write( int $post_id, string $taxonomy, array $ids ): bool|WP_Error {
		$object    = get_taxonomy( $taxonomy );
		$rest_base = $object && is_string( $object->rest_base ) && $object->rest_base !== '' ? $object->rest_base : $taxonomy;

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( $rest_base, $ids );

		$response = rest_do_request( $request );
		if ( ! $response->is_error() ) {
			return true;
		}

		return self::rest_failure(
			rest_get_server()->response_to_data( $response, false ),
			$response->get_status(),
			__( 'The post\'s terms could not be saved.', 'albert-ai-butler' ),
			'albert_post_permission_denied'
		);
	}

	/**
	 * An `albert_invalid_terms` error.
	 *
	 * @param string $message What to send instead.
	 * @return WP_Error
	 * @since 1.6.0
	 */
	private function invalid_terms( string $message ): WP_Error {
		return new WP_Error( 'albert_invalid_terms', $message, [ 'status' => 400 ] );
	}
}
