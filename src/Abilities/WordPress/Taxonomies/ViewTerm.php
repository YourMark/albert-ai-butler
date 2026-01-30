<?php
/**
 * View Term Ability
 *
 * @package Albert
 * @subpackage Abilities\WordPress\Taxonomies
 * @since      1.0.0
 */

namespace Albert\Abilities\WordPress\Taxonomies;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Annotations;
use WP_Error;
use WP_REST_Request;

/**
 * View Term Ability class
 *
 * Allows AI assistants to view a single taxonomy term by ID.
 *
 * @since 1.0.0
 */
class ViewTerm extends BaseAbility {
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->id          = 'albert/view-term';
		$this->label       = __( 'View Term', 'albert-ai-butler' );
		$this->description = __( 'Retrieve a single taxonomy term by ID.', 'albert-ai-butler' );
		$this->category    = 'taxonomy';
		$this->group       = 'terms';

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
				'id'       => [
					'type'        => 'integer',
					'description' => 'The term ID to retrieve.',
					'minimum'     => 1,
				],
				'taxonomy' => [
					'type'        => 'string',
					'description' => 'The taxonomy name (category, post_tag, etc).',
				],
			],
			'required'   => [ 'id', 'taxonomy' ],
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
				'term' => [
					'type'        => 'object',
					'description' => 'The requested term object.',
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
		return $this->require_capability( 'manage_categories' );
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Ability arguments.
	 * @return array<string, mixed>|WP_Error Result array or error.
	 * @since 1.0.0
	 */
	public function execute( array $args ): array|WP_Error {
		$term_id  = absint( $args['id'] ?? 0 );
		$taxonomy = sanitize_key( $args['taxonomy'] ?? '' );

		if ( ! $term_id ) {
			return new WP_Error( 'missing_term_id', __( 'Term ID is required.', 'albert-ai-butler' ) );
		}

		if ( ! $taxonomy ) {
			return new WP_Error( 'missing_taxonomy', __( 'Taxonomy is required.', 'albert-ai-butler' ) );
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', __( 'Invalid taxonomy.', 'albert-ai-butler' ) );
		}

		$term = get_term( $term_id, $taxonomy );

		if ( is_wp_error( $term ) ) {
			return $term;
		}

		if ( ! $term ) {
			return new WP_Error(
				'term_not_found',
				sprintf(
					/* translators: %d: Term ID */
					__( 'Term with ID %d not found.', 'albert-ai-butler' ),
					$term_id
				)
			);
		}

		return [
			'term' => [
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'taxonomy'    => $term->taxonomy,
				'parent_id'   => $term->parent,
				'count'       => $term->count,
			],
		];
	}
}
