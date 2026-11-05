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
 * core's own sanitising (KSES on caption and description) apply as in wp-admin.
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
					'description' => 'The media file as saved: id, alt_text, title, caption, description, mime_type, url.',
				],
				'updated' => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'media', 'updated' ],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * The per-attachment edit check runs in core's endpoint at execute time.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.6.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'upload_files' );
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
			if ( ! array_key_exists( $field, $args ) || ! is_string( $args[ $field ] ) ) {
				continue;
			}

			// Title and alt text are plain text. Caption and description may hold
			// links, so core's save filters decide what HTML survives.
			$value = in_array( $field, [ 'title', 'alt_text' ], true )
				? sanitize_text_field( $args[ $field ] )
				: $args[ $field ];

			$request->set_param( $field, $value );
			$changed = true;
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

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $response->is_error() ) {
			return self::rest_failure( $data, $response->get_status(), __( 'The media file could not be updated.', 'albert-ai-butler' ) );
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

	/**
	 * Turn a failed media REST response into an error the log classifies.
	 *
	 * Core refuses with `rest_cannot_edit` or `rest_cannot_delete`, which would
	 * log as a failure; a refusal is a `*_permission_denied` warning.
	 *
	 * @param mixed  $data     Response data.
	 * @param int    $status   HTTP status.
	 * @param string $fallback Message when core gave none.
	 * @return WP_Error
	 * @since 1.6.0
	 */
	public static function rest_failure( $data, int $status, string $fallback ): WP_Error {
		$data    = is_array( $data ) ? $data : [];
		$code    = (string) ( $data['code'] ?? 'rest_error' );
		$message = (string) ( $data['message'] ?? $fallback );

		if ( $status === 401 || $status === 403 ) {
			$code = 'media_permission_denied';
		}

		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
