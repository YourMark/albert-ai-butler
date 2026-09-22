<?php
/**
 * View Pattern Ability
 *
 * Returns one block pattern's block markup so an assistant can reuse it as the
 * basis for new content.
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Blocks
 * @since      1.5.0
 */

namespace Albert\Abilities\WordPress\Blocks;

use Albert\Abstracts\BaseAbility;
use Albert\Blocks\PatternCatalog;
use Albert\Core\Annotations;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * View Pattern ability class.
 *
 * @since 1.5.0
 */
class ViewPattern extends BaseAbility {

	/**
	 * Pattern catalog service.
	 *
	 * @since 1.5.0
	 * @var PatternCatalog
	 */
	private PatternCatalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param PatternCatalog|null $catalog Optional catalog service (injectable for tests).
	 *
	 * @since 1.5.0
	 */
	public function __construct( ?PatternCatalog $catalog = null ) {
		$this->catalog = $catalog ?? new PatternCatalog();

		$this->id          = 'albert/view-pattern';
		$this->label       = __( 'View Pattern', 'albert-ai-butler' );
		$this->description = __( 'Read one block pattern by name, including its block markup. Reuse the markup as the basis for new content. Find pattern names with find-patterns.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'patterns';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [ 'public' => true ],
			'annotations' => Annotations::read(
				'The `content` is valid block markup you can pass straight to create/update as the `content` field, '
				. 'or adapt. A `registered` pattern is read-only; a `user` pattern can also be edited on the site.'
			),
		];

		parent::__construct();
	}

	/**
	 * Get the input schema.
	 *
	 * @return array<string, mixed>
	 * @since 1.5.0
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'name' => [
					'type'        => 'string',
					'description' => 'The pattern name, as returned by find-patterns (e.g. "twentytwentyfour/hero", or a user pattern slug).',
				],
			],
			'required'   => [ 'name' ],
		];
	}

	/**
	 * Get the output schema.
	 *
	 * @return array<string, mixed>
	 * @since 1.5.0
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'name'          => [ 'type' => 'string' ],
				'title'         => [ 'type' => 'string' ],
				'description'   => [ 'type' => 'string' ],
				'categories'    => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'keywords'      => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'viewportWidth' => [ 'type' => [ 'integer', 'null' ] ],
				'content'       => [ 'type' => 'string' ],
				'source'        => [ 'type' => 'string' ],
			],
			'required'   => [ 'name', 'content', 'source' ],
		];
	}

	/**
	 * Anyone who may compose content may read a pattern.
	 *
	 * @return bool|WP_Error
	 * @since 1.5.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'edit_posts' );
	}

	/**
	 * Execute: return one pattern, or a not-found error.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 * @return array<string, mixed>|WP_Error
	 * @since 1.5.0
	 */
	public function execute( array $args ): array|WP_Error {
		$name = isset( $args['name'] ) && is_string( $args['name'] ) ? trim( $args['name'] ) : '';

		if ( $name === '' ) {
			return new WP_Error(
				'pattern_name_required',
				__( 'A pattern name is required.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$pattern = $this->catalog->get( $name );

		if ( $pattern === null ) {
			return new WP_Error(
				'pattern_not_found',
				/* translators: %s: pattern name. */
				sprintf( __( 'No pattern named "%s" is registered on this site. Use find-patterns to list available names.', 'albert-ai-butler' ), $name ),
				[ 'status' => 404 ]
			);
		}

		return $pattern;
	}
}
