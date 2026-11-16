<?php
/**
 * Abstract Replace Text Ability
 *
 * @package    Albert
 * @subpackage Abstracts
 * @since      1.6.0
 */

namespace Albert\Abstracts;

use Albert\Blocks\BlockStructure;
use Albert\Blocks\MarkupTags;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces literal text in one field of a post or page, saved through REST.
 *
 * Changing a word in a long page this way costs a few tokens instead of the whole
 * body, and touches nothing else. The edit is refused when the text occurs a
 * different number of times than the caller expects, when it would change the
 * page's blocks rather than their text, or when it drops a tag the content had.
 *
 * @since 1.6.0
 */
abstract class AbstractReplaceText extends BaseAbility {

	/**
	 * Fields that can be edited, keyed by input value, with their post and REST names.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 * @since 1.6.0
	 */
	private const FIELDS = [
		'content' => [ 'post_content', 'content' ],
		'title'   => [ 'post_title', 'title' ],
		'excerpt' => [ 'post_excerpt', 'excerpt' ],
	];

	/**
	 * The post type this ability targets.
	 *
	 * @return string Post type slug.
	 * @since 1.6.0
	 */
	abstract protected function post_type(): string;

	/**
	 * The REST base used to save.
	 *
	 * @return string REST base, e.g. 'posts'.
	 * @since 1.6.0
	 */
	abstract protected function rest_base(): string;

	/**
	 * The capability that gates the ability before a post is known.
	 *
	 * @return string Capability.
	 * @since 1.6.0
	 */
	abstract protected function edit_capability(): string;

	/**
	 * Check if the current user may save through the REST endpoint.
	 *
	 * @return bool|WP_Error
	 * @since 1.6.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->check_rest_permission(
			'/wp/v2/' . $this->rest_base() . '/(?P<id>[\\d]+)',
			'POST',
			$this->edit_capability()
		);
	}

	/**
	 * Replace the text and save.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	public function execute( array $args ): array|WP_Error {
		$post_id = absint( $args['id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post || $post->post_type !== $this->post_type() ) {
			return new WP_Error(
				'post_not_found',
				/* translators: 1: post type, 2: post ID. */
				sprintf( __( 'No %1$s found with ID %2$d.', 'albert-ai-butler' ), $this->post_type(), $post_id ),
				[ 'status' => 404 ]
			);
		}

		// Checked before searching: a found, not-found or count reply would reveal
		// text of a post the user may not edit.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this item.', 'albert-ai-butler' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		$field                   = is_string( $args['field'] ?? null ) && isset( self::FIELDS[ $args['field'] ] ) ? $args['field'] : 'content';
		[ $column, $rest_field ] = self::FIELDS[ $field ];

		$search   = (string) ( $args['search'] ?? '' );
		$replace  = (string) ( $args['replace'] ?? '' );
		$expected = max( 1, (int) ( $args['expected_count'] ?? 1 ) );
		$before   = (string) $post->{$column};

		if ( $search === '' ) {
			return new WP_Error( 'search_empty', __( '`search` must not be empty.', 'albert-ai-butler' ), [ 'status' => 400 ] );
		}

		$found = substr_count( $before, $search );

		if ( $found === 0 ) {
			return $this->not_found( $before, $search, $field );
		}

		if ( $found !== $expected ) {
			return new WP_Error(
				'match_count_mismatch',
				sprintf(
					/* translators: 1: the field, 2: times found, 3: times expected. */
					__( 'The text occurs %2$d times in the %1$s, not %3$d. Send a longer `search` that occurs once, or set `expected_count` to %2$d to replace every one. Nothing was saved.', 'albert-ai-butler' ),
					$field,
					$found,
					$expected
				),
				[
					'status' => 400,
					'count'  => $found,
				]
			);
		}

		$after = str_replace( $search, $replace, $before );

		$refused = $this->refuse( $field, $before, $after, $args );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$saved = $this->persist( $post_id, $rest_field, $after );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$result = [
			'id'           => $post_id,
			'field'        => $field,
			'replacements' => $found,
			'status'       => (string) ( $saved['status'] ?? '' ),
			'permalink'    => (string) ( $saved['link'] ?? '' ),
			'edit_url'     => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		];

		// KSES or a save filter may still change what was sent; never report a
		// save as exact when it was not.
		$stored = get_post( $post_id );
		if ( $stored && (string) $stored->{$column} !== $after ) {
			$result['notice'] = __( 'WordPress changed the text while saving it, for example by removing HTML this account may not post. Read it again before the next edit.', 'albert-ai-butler' );
		}

		return $result;
	}

	/**
	 * Refuse an edit that changes blocks or drops a tag.
	 *
	 * @param string               $field  Field being edited.
	 * @param string               $before Field before.
	 * @param string               $after  Field after.
	 * @param array<string, mixed> $args   Ability input.
	 * @return WP_Error|null
	 * @since 1.6.0
	 */
	private function refuse( string $field, string $before, string $after, array $args ): ?WP_Error {
		if ( $field === 'content' && ( has_blocks( $before ) || has_blocks( $after ) ) ) {
			$difference = BlockStructure::difference( $before, $after );

			if ( $difference !== null ) {
				return new WP_Error(
					'block_structure_changed',
					sprintf(
						/* translators: 1: what changed, e.g. "it changes the settings of core/heading at [2]", 2: the block edit ability id. */
						__( 'This replacement changes blocks, not only text: %1$s. Use %2$s to change a block. Nothing was saved.', 'albert-ai-butler' ),
						$difference,
						'albert/edit-' . $this->post_type() . '-block'
					),
					[ 'status' => 400 ]
				);
			}
		}

		return MarkupTags::refuse_unconfirmed_drop( $before, $after, $args );
	}

	/**
	 * Explain a search that found nothing, with the likeliest reason.
	 *
	 * @param string $haystack The field's stored value.
	 * @param string $search   The text searched for.
	 * @param string $field    The field.
	 * @return WP_Error
	 * @since 1.6.0
	 */
	private function not_found( string $haystack, string $search, string $field ): WP_Error {
		/* translators: %s: the field searched, e.g. content. */
		$message = sprintf( __( 'The text was not found in the %s. It must match the stored text exactly.', 'albert-ai-butler' ), $field );

		foreach ( array_unique( [ esc_html( $search ), htmlspecialchars( $search, ENT_NOQUOTES ), wptexturize( $search ) ] ) as $stored_as ) {
			if ( $stored_as !== $search && str_contains( $haystack, $stored_as ) ) {
				/* translators: %s: the text as stored, e.g. Tom &amp; Jerry. */
				$message .= ' ' . sprintf( __( 'It is stored as `%s`; search for that.', 'albert-ai-butler' ), $stored_as );

				return new WP_Error( 'search_not_found', $message, [ 'status' => 404 ] );
			}
		}

		if ( stripos( $haystack, $search ) !== false ) {
			$message .= ' ' . __( 'It occurs with different capitalisation.', 'albert-ai-butler' );
		}

		return new WP_Error( 'search_not_found', $message, [ 'status' => 404 ] );
	}

	/**
	 * Save one field through the REST endpoint, so capabilities, KSES and
	 * revisions apply as they do in the editor.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $rest_field REST field name.
	 * @param string $value      New value.
	 * @return array<string, mixed>|WP_Error REST response data.
	 * @since 1.6.0
	 */
	private function persist( int $post_id, string $rest_field, string $value ): array|WP_Error {
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . $this->rest_base() . '/' . $post_id );
		$request->set_param( $rest_field, $value );

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $response->is_error() ) {
			return new WP_Error(
				is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'rest_error',
				is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : __( 'An error occurred while saving.', 'albert-ai-butler' ),
				[ 'status' => $response->get_status() ]
			);
		}

		return is_array( $data ) ? $data : [];
	}

	/**
	 * The input schema.
	 *
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	protected function replace_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'              => [
					'type'        => 'integer',
					'minimum'     => 1,
					/* translators: %s: post type. */
					'description' => sprintf( __( 'The %s ID.', 'albert-ai-butler' ), $this->post_type() ),
				],
				'search'          => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'The exact text to find, as stored: in `content` that is the raw block markup, so `&` is often stored as `&amp;`. Include enough surrounding words to make it unique.',
				],
				'replace'         => [
					'type'        => 'string',
					'description' => 'The text to put in its place. Empty removes the text.',
				],
				'field'           => [
					'type'        => 'string',
					'enum'        => array_keys( self::FIELDS ),
					'default'     => 'content',
					'description' => 'Which field to edit. Default content.',
				],
				'expected_count'  => [
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => 'How many times `search` occurs. If it occurs a different number of times, nothing is saved and the error says how many. Default 1.',
				],
				MarkupTags::INPUT => MarkupTags::input_schema(),
			],
			'required'   => [ 'id', 'search', 'replace' ],
		];
	}

	/**
	 * The output schema.
	 *
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	protected function replace_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'           => [ 'type' => 'integer' ],
				'field'        => [ 'type' => 'string' ],
				'replacements' => [
					'type'        => 'integer',
					'description' => 'How many times the text was replaced.',
				],
				'status'       => [ 'type' => 'string' ],
				'permalink'    => [ 'type' => 'string' ],
				'edit_url'     => [ 'type' => 'string' ],
				'notice'       => [
					'type'        => 'string',
					'description' => 'Present when WordPress stored something other than what was sent.',
				],
			],
			'required'   => [ 'id', 'field', 'replacements' ],
		];
	}
}
