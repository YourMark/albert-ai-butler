<?php
/**
 * Delete Media Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Media
 * @since      1.6.0
 */

namespace Albert\Abilities\WordPress\Media;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Permanently deletes a media file and its generated sizes.
 *
 * Always `force`: core trashes media only when `MEDIA_TRASH` is on, so this keeps
 * one behaviour, permanent, on every site.
 *
 * @since 1.6.0
 */
class DeleteMedia extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/delete-media';
		$this->label       = __( 'Delete Media', 'albert-ai-butler' );
		$this->description = __( 'Permanently delete a media file from the media library, including its file on the server.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'media';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::delete(
				'There is no trash for media: the file is gone for good, and any post that shows it will show a '
				. 'broken image. Confirm the ID with view-media before deleting.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'description' => 'The media ID to delete.',
					'minimum'     => 1,
				],
			],
			'required'   => [ 'id' ],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.6.0
	 */
	private function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'integer' ],
				'title'   => [ 'type' => 'string' ],
				'deleted' => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'id', 'deleted' ],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * Given the input, also asks core's `delete_post` for that file, so safe mode refuses
	 * another user's file at once instead of holding a call core would refuse after
	 * approval. A non-attachment ID passes here and is refused as not found.
	 *
	 * @param mixed $input Ability input; absent when listing abilities.
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.6.0
	 */
	public function check_permission( mixed $input = null ): bool|WP_Error {
		$allowed  = $this->require_capability( 'upload_files' );
		$media_id = is_array( $input ) ? absint( $input['id'] ?? 0 ) : 0;

		if ( $allowed !== true || $media_id === 0 || get_post_type( $media_id ) !== 'attachment' || current_user_can( 'delete_post', $media_id ) ) {
			return $allowed;
		}

		return new WP_Error(
			'media_permission_denied',
			__( 'You are not allowed to delete this media file.', 'albert-ai-butler' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.6.0
	 */
	public function execute( array $args ): array|WP_Error {
		$media_id = absint( $args['id'] ?? 0 );
		$media    = get_post( $media_id );

		if ( ! $media || $media->post_type !== 'attachment' ) {
			return new WP_Error(
				'media_not_found',
				sprintf(
					/* translators: %d: Media ID */
					__( 'Media with ID %d not found.', 'albert-ai-butler' ),
					$media_id
				),
				[ 'status' => 404 ]
			);
		}

		$request = new WP_REST_Request( 'DELETE', '/wp/v2/media/' . $media_id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		if ( $response->is_error() ) {
			return self::rest_failure( $data, $response->get_status(), __( 'The media file could not be deleted.', 'albert-ai-butler' ), 'media_permission_denied' );
		}

		return [
			'id'      => $media_id,
			'title'   => (string) $media->post_title,
			'deleted' => true,
		];
	}
}
