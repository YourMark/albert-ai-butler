<?php
/**
 * Update Media Ability
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
 * Sets a media file's alt text, title, caption and description.
 *
 * Writes through core's media endpoint, so the per-attachment edit check and
 * core's own sanitising apply as in wp-admin.
 *
 * @since 1.6.0
 */
class UpdateMedia extends BaseAbility {

	/**
	 * The writable fields, named as `/wp/v2/media` names them.
	 *
	 * @since 1.6.0
	 * @var string[]
	 */
	private const FIELDS = [ 'alt_text', 'title', 'caption', 'description' ];

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {
		$this->id          = 'albert/update-media';
		$this->label       = __( 'Update Media', 'albert-ai-butler' );
		$this->description = __( 'Set the alt text, title, caption or description of a media file.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'media';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::update(
				'Send only the fields you want to change; an empty string clears one. Alt text describes what the '
				. 'image shows for someone who cannot see it, in one short sentence, without "image of". Read the '
				. 'current values with view-media first, and find images without alt text with find-media\'s '
				. '`missing_alt_text`. An image block already in a post keeps its own `alt`; this changes the '
				. 'library\'s, which new blocks start from.'
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
				'id'          => [
					'type'        => 'integer',
					'description' => 'The media ID to update.',
					'minimum'     => 1,
				],
				'alt_text'    => [
					'type'        => 'string',
					'description' => 'Alternative text for screen readers and search engines. Omit to leave unchanged.',
				],
				'title'       => [
					'type'        => 'string',
					'description' => 'Media title. Omit to leave unchanged.',
				],
				'caption'     => [
					'type'        => 'string',
					'description' => 'Caption shown below the image where the theme displays it. Omit to leave unchanged.',
				],
				'description' => [
					'type'        => 'string',
					'description' => 'Longer description, shown on the attachment page. Omit to leave unchanged.',
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
				'media'   => [
					'type'        => 'object',
					'description' => 'The media file as saved.',
					'properties'  => [
						'id'          => [ 'type' => 'integer' ],
						'alt_text'    => [ 'type' => 'string' ],
						'title'       => [ 'type' => 'string' ],
						'caption'     => [ 'type' => 'string' ],
						'description' => [ 'type' => 'string' ],
						'mime_type'   => [ 'type' => 'string' ],
						'url'         => [ 'type' => 'string' ],
					],
				],
				'updated' => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'media', 'updated' ],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * Given the input, also asks core's `edit_post` for that file, so safe mode refuses
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

		if ( $allowed !== true || $media_id === 0 || get_post_type( $media_id ) !== 'attachment' || current_user_can( 'edit_post', $media_id ) ) {
			return $allowed;
		}

		return new WP_Error(
			'media_permission_denied',
			__( 'You are not allowed to edit this media file.', 'albert-ai-butler' ),
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

		$request = new WP_REST_Request( 'POST', '/wp/v2/media/' . $media_id );
		$request->set_param( 'context', 'edit' );
		$changed = false;

		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$request->set_param( $field, $args[ $field ] );
				$changed = true;
			}
		}

		if ( ! $changed ) {
			return new WP_Error(
				'media_no_changes',
				__( 'Nothing to update: send at least one of alt_text, title, caption or description.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		if ( $response->is_error() ) {
			return self::rest_failure( $data, $response->get_status(), __( 'The media file could not be updated.', 'albert-ai-butler' ), 'media_permission_denied' );
		}

		return [
			'media'   => [
				'id'          => (int) ( $data['id'] ?? $media_id ),
				'alt_text'    => (string) ( $data['alt_text'] ?? '' ),
				'title'       => (string) ( $data['title']['raw'] ?? '' ),
				'caption'     => (string) ( $data['caption']['raw'] ?? '' ),
				'description' => (string) ( $data['description']['raw'] ?? '' ),
				'mime_type'   => (string) ( $data['mime_type'] ?? '' ),
				'url'         => (string) ( $data['source_url'] ?? '' ),
			],
			'updated' => true,
		];
	}
}
