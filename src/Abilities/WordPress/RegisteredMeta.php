<?php
/**
 * Registered custom fields on posts and pages
 *
 * @package Albert
 * @subpackage Abilities\WordPress
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress;

use WP_Error;
use WP_REST_Post_Meta_Fields;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The custom fields a post type exposes to the REST API, read and checked as core's
 * REST API handles them.
 *
 * Only keys registered with `show_in_rest` are reachable, so a plugin decides what an
 * assistant may touch by registering it. A `_`-protected key stays out even when
 * registered: the leading underscore is how a plugin says the field is its own.
 *
 * Writes are checked here before the REST call because core's posts endpoint saves the
 * post before its meta: a meta refusal there would leave the other fields half written.
 *
 * @since 1.6.0
 */
class RegisteredMeta {

	/**
	 * Input schema property for `meta`.
	 *
	 * Free-form: which keys exist depends on what the site's plugins register.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.6.0
	 */
	public static function input_schema(): array {
		return [
			'meta' => [
				'type'        => 'object',
				'description' => 'Custom fields to set, as { "key": value }. Only fields a plugin registered for the REST API are accepted: the "meta" object returned when viewing this item lists every one, with its current value. Each value must match that field\'s type. Send null to clear a field. Other custom fields are left as they are.',
			],
		];
	}

	/**
	 * The reachable fields and their values, keyed by REST name.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	public static function read( int $post_id, string $post_type ): array {
		$fields = new WP_REST_Post_Meta_Fields( $post_type );
		$values = array_intersect_key( $fields->get_value( $post_id, new WP_REST_Request() ), self::reachable_keys( $post_type ) );

		if ( $values === [] || current_user_can( 'edit_post', $post_id ) ) {
			return $values;
		}

		// Core's REST API only shows an "edit"-context field to users who can edit the post.
		$schemas = $fields->get_field_schema()['properties'] ?? [];

		return array_filter(
			$values,
			static fn( $name ): bool => ! isset( $schemas[ $name ]['context'] ) || in_array( 'view', (array) $schemas[ $name ]['context'], true ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Check a `meta` input before it is sent to core's REST endpoint.
	 *
	 * Refuses an unknown or protected key, a key the user may not edit (the key's
	 * `auth_callback`), and a value its schema rejects.
	 *
	 * @param mixed  $meta      The `meta` input.
	 * @param int    $post_id   Post being updated.
	 * @param string $post_type Post type.
	 * @return array<string, mixed>|WP_Error The meta to send, or the first refusal.
	 * @since 1.6.0
	 */
	public static function check_write( mixed $meta, int $post_id, string $post_type ): array|WP_Error {
		if ( ! is_array( $meta ) || ( $meta !== [] && array_is_list( $meta ) ) ) {
			return new WP_Error(
				'meta_invalid',
				__( '"meta" must be an object of { "key": value } pairs.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$keys    = self::reachable_keys( $post_type );
		$schemas = ( new WP_REST_Post_Meta_Fields( $post_type ) )->get_field_schema()['properties'] ?? [];

		foreach ( $meta as $name => $value ) {
			$name = (string) $name;

			if ( ! isset( $keys[ $name ], $schemas[ $name ] ) ) {
				return new WP_Error(
					'meta_not_registered',
					sprintf(
						/* translators: 1: custom field key, 2: comma-separated list of keys */
						__( '"%1$s" is not a custom field that can be written here. Only fields registered for the REST API can be: %2$s.', 'albert-ai-butler' ),
						$name,
						$keys === [] ? __( 'none on this site', 'albert-ai-butler' ) : implode( ', ', array_keys( $keys ) )
					),
					[ 'status' => 400 ]
				);
			}

			$capability = $value === null ? 'delete_post_meta' : 'edit_post_meta';
			if ( ! current_user_can( $capability, $post_id, $keys[ $name ] ) ) {
				return new WP_Error(
					'meta_permission_denied',
					sprintf(
						/* translators: %s: custom field key */
						__( 'You are not allowed to change the "%s" custom field.', 'albert-ai-butler' ),
						$name
					),
					[ 'status' => 403 ]
				);
			}

			if ( $value === null ) {
				continue;
			}

			$valid = rest_validate_value_from_schema( $value, $schemas[ $name ], 'meta.' . $name );
			if ( is_wp_error( $valid ) ) {
				return new WP_Error( 'meta_invalid', $valid->get_error_message(), [ 'status' => 400 ] );
			}
		}

		return $meta;
	}

	/**
	 * REST names of the registered, unprotected fields, mapped to their meta keys.
	 *
	 * The REST name can differ from the key (`show_in_rest.name`); protection and
	 * capabilities are decided on the key.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, string>
	 * @since 1.6.0
	 */
	private static function reachable_keys( string $post_type ): array {
		$registered = array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $post_type ) );
		$keys       = [];

		foreach ( $registered as $meta_key => $args ) {
			$meta_key = (string) $meta_key;

			if ( empty( $args['show_in_rest'] ) || is_protected_meta( $meta_key, 'post' ) ) {
				continue;
			}

			$name = is_array( $args['show_in_rest'] ) && ! empty( $args['show_in_rest']['name'] )
				? (string) $args['show_in_rest']['name']
				: $meta_key;

			$keys[ $name ] = $meta_key;
		}

		return $keys;
	}
}
