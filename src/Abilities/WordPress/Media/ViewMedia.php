<?php
/**
 * View Media Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Media
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Media;

defined( 'ABSPATH' ) || exit;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use Albert\Media\MediaUsage;
use WP_Error;

/**
 * View Media Ability class
 *
 * Allows AI assistants to view a single media file by ID.
 *
 * @since 1.0.0
 */
class ViewMedia extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/view-media';
		$this->label       = __( 'View Media', 'albert-ai-butler' );
		$this->description = __( 'Retrieve a single media file by ID, and where it is used: each post, page or pattern that shows it, with the block path, the alt text there, its link, caption and the text around it. Alt text is set per placement; the media library alt is only the default.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'media';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> JSON Schema array.
	 * @since 1.0.0
	 */
	private function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'description' => 'The media ID to retrieve.',
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
	 * @since 1.0.0
	 */
	private function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'media'        => [
					'type'        => 'object',
					'description' => 'The requested media object.',
				],
				'used_in'      => [
					'type'        => 'array',
					'description' => 'Each place the file appears. kind is block, classic or featured_image. For a block, path addresses it for the block-edit abilities; alt is the alt text in that block (empty when unset or decorative); link is set when the image is a link; before and after are the nearest text around it; heading is the nearest heading above.',
					'items'       => [ 'type' => 'object' ],
				],
				'used_in_more' => [
					'type'        => 'boolean',
					'description' => 'True when there are more placements than listed.',
				],
			],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * @return bool|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'upload_files' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		$media_id = absint( $args['id'] );
		$media    = get_post( $media_id );

		// read_post follows an attached file to its parent's status. Refused
		// reads answer as not found, so they do not reveal the file exists.
		if ( ! $media || $media->post_type !== 'attachment' || ! current_user_can( 'read_post', $media_id ) ) {
			return new WP_Error(
				'media_not_found',
				sprintf(
					/* translators: %d: Media ID */
					__( 'Media with ID %d not found.', 'albert-ai-butler' ),
					$media_id
				)
			);
		}

		$metadata = wp_get_attachment_metadata( $media_id );
		$usage    = MediaUsage::of( $media_id );

		return [
			'used_in'      => $usage['placements'],
			'used_in_more' => $usage['more'],
			'media'        => [
				'id'          => $media->ID,
				'title'       => $media->post_title,
				'description' => $media->post_content,
				'caption'     => $media->post_excerpt,
				'alt_text'    => get_post_meta( $media_id, '_wp_attachment_image_alt', true ),
				'mime_type'   => $media->post_mime_type,
				'url'         => wp_get_attachment_url( $media_id ),
				'date'        => $media->post_date,
				'modified'    => $media->post_modified,
				'author_id'   => (int) $media->post_author,
				'file'        => $metadata['file'] ?? null,
				'width'       => $metadata['width'] ?? null,
				'height'      => $metadata['height'] ?? null,
				'filesize'    => $metadata['filesize'] ?? null,
			],
		];
	}
}
