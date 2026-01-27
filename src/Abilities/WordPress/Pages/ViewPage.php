<?php
/**
 * View Page Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Pages
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Pages;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

/**
 * View Page Ability class
 *
 * Allows AI assistants to view a single WordPress page by ID.
 *
 * @since 1.0.0
 */
class ViewPage extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/view-page';
		$this->label       = __( 'View Page', 'albert' );
		$this->description = __( 'Retrieve a single WordPress page by ID.', 'albert' );
		$this->category    = 'content';
		$this->group       = 'pages';

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
					'description' => 'The page ID to retrieve.',
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
				'page' => [
					'type'        => 'object',
					'description' => 'The requested page object.',
				],
			],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * @return bool True if user can execute, false otherwise.
	 * @since 1.0.0
	 */
	public function check_permission(): bool {
		return current_user_can( 'read' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		$page_id = absint( $args['id'] ?? 0 );

		if ( ! $page_id ) {
			return new WP_Error( 'missing_page_id', __( 'Page ID is required.', 'albert' ) );
		}

		$page = get_post( $page_id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return new WP_Error(
				'page_not_found',
				sprintf(
					/* translators: %d: Page ID */
					__( 'Page with ID %d not found.', 'albert' ),
					$page_id
				)
			);
		}

		// Check if user can read this specific page.
		if ( ! current_user_can( 'read_post', $page_id ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to view this page.', 'albert' ) );
		}

		return [
			'page' => [
				'id'             => $page->ID,
				'title'          => $page->post_title,
				'content'        => $page->post_content,
				'excerpt'        => $page->post_excerpt,
				'status'         => $page->post_status,
				'author_id'      => (int) $page->post_author,
				'date'           => $page->post_date,
				'modified'       => $page->post_modified,
				'slug'           => $page->post_name,
				'permalink'      => get_permalink( $page ),
				'parent_id'      => (int) $page->post_parent,
				'menu_order'     => (int) $page->menu_order,
				'featured_image' => get_post_thumbnail_id( $page ) ? get_post_thumbnail_id( $page ) : null,
			],
		];
	}
}
