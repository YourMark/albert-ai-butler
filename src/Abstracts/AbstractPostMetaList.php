<?php
/**
 * Abstract Post Meta List Ability
 *
 * @package    Albert
 * @subpackage Abstracts
 * @since      1.6.0
 */

namespace Albert\Abstracts;

use Albert\Abilities\WordPress\RegisteredMeta;
use WP_Error;
use WP_Post;
use WP_REST_Posts_Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Changes the values of a multi-value registered custom field on any post type.
 *
 * The new list is built in PHP and saved in one REST write, so a call with several
 * values is one change. `update-post`'s `meta` refuses these fields because core
 * replaces the whole list there, and clears it on an empty value.
 *
 * @since 1.6.0
 */
abstract class AbstractPostMetaList extends BaseAbility {

	/**
	 * Most values one call takes.
	 *
	 * @since 1.6.0
	 */
	public const MAX_VALUES = 50;

	/**
	 * The list after the change.
	 *
	 * @param array<int, mixed> $current Stored values.
	 * @param array<int, mixed> $values  Checked values from the input.
	 * @return array<int, mixed>
	 * @since 1.6.0
	 */
	abstract protected function apply_values( array $current, array $values ): array;

	/**
	 * Meta capability core checks for this change, passed to the key's `auth_callback`.
	 *
	 * @return string
	 * @since 1.6.0
	 */
	abstract protected function meta_capability(): string;

	/**
	 * Input schema shared by add and remove.
	 *
	 * @param string $values_description Description of the `values` property.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	protected function list_input_schema( string $values_description ): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the post, page or other content item. Find it with albert/find-posts or albert/find-pages.',
				],
				'key'     => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'Name of a custom field that holds a list, as it appears in the "meta" object of albert/view-post or albert/view-page.',
				],
				'values'  => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => self::MAX_VALUES,
					'items'       => [ 'type' => [ 'string', 'number', 'boolean', 'object', 'array' ] ],
					'description' => $values_description,
				],
			],
			'required'   => [ 'post_id', 'key', 'values' ],
		];
	}

	/**
	 * Output schema shared by add and remove.
	 *
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	protected function list_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post'   => [
					'type'        => 'object',
					'description' => 'The item that was changed, as { id, name }.',
					'properties'  => [
						'id'   => [ 'type' => 'integer' ],
						'name' => [ 'type' => 'string' ],
					],
				],
				'key'    => [ 'type' => 'string' ],
				'values' => [
					'type'        => 'array',
					'description' => 'Every value the field holds after the change.',
				],
			],
			'required'   => [ 'post', 'key', 'values' ],
		];
	}

	/**
	 * Whether the current user may edit the post named in the input.
	 *
	 * Asks the post type's REST controller, so each type's own capabilities apply and
	 * safe mode refuses at once instead of holding a call core would refuse after
	 * approval. Without input (listing abilities) only `edit_posts` is checked. An ID
	 * that is not a post passes, so execute() can answer `albert_post_not_found`.
	 *
	 * @param mixed $input Ability input; absent when listing abilities.
	 * @return bool|WP_Error True if permitted, WP_Error otherwise.
	 * @since 1.6.0
	 */
	public function check_permission( mixed $input = null ): bool|WP_Error {
		$allowed = $this->require_capability( 'edit_posts' );
		$post    = is_array( $input ) && is_int( $input['post_id'] ?? null ) ? get_post( $input['post_id'] ) : null;

		if ( $allowed !== true || ! $post instanceof WP_Post ) {
			return $allowed;
		}

		$controller = get_post_type_object( $post->post_type )?->get_rest_controller();
		if ( ! $controller instanceof WP_REST_Posts_Controller ) {
			return $allowed;
		}

		$request = new WP_REST_Request( 'POST', rest_get_route_for_post( $post ) );
		$request->set_url_params( [ 'id' => $post->ID ] );

		$check = $controller->update_item_permissions_check( $request );
		if ( $check === true ) {
			return true;
		}

		return new WP_Error(
			'albert_post_permission_denied',
			is_wp_error( $check ) ? $check->get_error_message() : __( 'You are not allowed to edit this item.', 'albert-ai-butler' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Change the list and return its values afterwards.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array<string, mixed>|WP_Error
	 * @since 1.6.0
	 */
	public function execute( array $args ): array|WP_Error {
		$post_id = is_int( $args['post_id'] ?? null ) ? $args['post_id'] : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'albert_post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'No item with ID %d. Find it with albert/find-posts or albert/find-pages.', 'albert-ai-butler' ), $post_id ),
				[ 'status' => 404 ]
			);
		}

		$route = rest_get_route_for_post( $post );
		if ( $route === '' ) {
			return new WP_Error(
				'albert_invalid_post_id',
				/* translators: %d: post ID */
				sprintf( __( 'Item %d is of a type that has no custom fields available to assistants.', 'albert-ai-butler' ), $post_id ),
				[ 'status' => 400 ]
			);
		}

		$name   = (string) ( $args['key'] ?? '' );
		$values = RegisteredMeta::check_list_values( $name, (array) ( $args['values'] ?? [] ), $post->ID, $post->post_type, $this->meta_capability() );
		if ( is_wp_error( $values ) ) {
			return $values;
		}

		$current = array_values( (array) ( RegisteredMeta::read( $post->ID, $post->post_type )[ $name ] ?? [] ) );
		$list    = $this->apply_values( $current, $values );

		if ( $list === $current ) {
			return $this->result( $post, $name, $current );
		}

		$request = new WP_REST_Request( 'POST', $route );
		$request->set_param( 'meta', [ $name => $list ] );

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		if ( $response->is_error() ) {
			return self::rest_failure( $data, $response->get_status(), __( 'The custom field could not be saved.', 'albert-ai-butler' ), 'albert_post_permission_denied' );
		}

		return $this->result( $post, $name, array_values( (array) ( $data['meta'][ $name ] ?? [] ) ) );
	}

	/**
	 * The output for the item and its list.
	 *
	 * @param WP_Post           $post   The item.
	 * @param string            $name   Field name.
	 * @param array<int, mixed> $values The field's values.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	private function result( WP_Post $post, string $name, array $values ): array {
		return [
			'post'   => [
				'id'   => $post->ID,
				'name' => $post->post_title,
			],
			'key'    => $name,
			'values' => $values,
		];
	}
}
