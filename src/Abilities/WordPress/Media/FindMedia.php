<?php
/**
 * Find Media Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Media
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Media;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;
use WP_Query;

/**
 * Find Media Ability class
 *
 * Allows AI assistants to find and search media files via the abilities API.
 *
 * @since 1.0.0
 */
class FindMedia extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/find-media';
		$this->label       = __( 'Find Media', 'albert-ai-butler' );
		$this->description = __( 'Find and search media files with optional filtering and pagination.', 'albert-ai-butler' );
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
				'search'    => [
					'type'        => 'string',
					'description' => 'Search query for media titles.',
				],
				'mime_type' => [
					'type'        => 'string',
					'description' => 'Filter by MIME type (e.g., image/jpeg).',
				],
				'per_page'  => [
					'type'        => 'integer',
					'description' => 'Number of media items per page.',
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				],
				'page'      => [
					'type'        => 'integer',
					'description' => 'Page number for pagination.',
					'default'     => 1,
					'minimum'     => 1,
				],
			],
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
				'media'       => [
					'type'        => 'array',
					'description' => 'Array of media items.',
				],
				'total'       => [
					'type'        => 'integer',
					'description' => 'Total number of media items found.',
				],
				'total_pages' => [
					'type'        => 'integer',
					'description' => 'Total number of pages.',
				],
			],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * @return true|WP_Error True if permitted, WP_Error with details otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): true|WP_Error {
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
		$search    = sanitize_text_field( $args['search'] ?? '' );
		$mime_type = sanitize_mime_type( $args['mime_type'] ?? '' );
		$per_page  = absint( $args['per_page'] ?? 10 );
		$page      = absint( $args['page'] ?? 1 );

		$per_page = min( max( $per_page, 1 ), 100 );

		$query_args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( ! empty( $search ) ) {
			$query_args['s'] = $search;
		}

		if ( ! empty( $mime_type ) ) {
			$query_args['post_mime_type'] = $mime_type;
		}

		$query = new WP_Query( $query_args );

		$media = [];
		foreach ( $query->posts as $attachment ) {
			if ( ! $attachment instanceof \WP_Post ) {
				continue;
			}

			$media[] = [
				'id'        => $attachment->ID,
				'title'     => $attachment->post_title,
				'mime_type' => $attachment->post_mime_type,
				'url'       => wp_get_attachment_url( $attachment->ID ),
				'date'      => $attachment->post_date,
			];
		}

		return [
			'media'       => $media,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		];
	}
}
