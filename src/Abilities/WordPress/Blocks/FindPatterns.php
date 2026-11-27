<?php
/**
 * Find Patterns Ability
 *
 * Lists the site's block patterns so an assistant can reuse one as the basis for
 * new content instead of composing a layout from scratch.
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
 * Find Patterns ability class.
 *
 * Returns pattern summaries (without the block markup); use view-pattern to read
 * one pattern's content.
 *
 * @since 1.5.0
 */
class FindPatterns extends BaseAbility {

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

		$this->id          = 'albert/find-patterns';
		$this->label       = __( 'Find Patterns', 'albert-ai-butler' );
		$this->description = __( 'List the block patterns registered on this site (theme, plugin and user-created), optionally filtered by a search term or category. To place one, send its name as `pattern` to add-post-block or add-page-block; read its markup with view-pattern only to adapt it.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'patterns';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [ 'public' => true ],
			'annotations' => Annotations::read(
				'A pattern is real, valid block markup already on this site. Prefer building from one over composing a '
				. 'complex layout from scratch. To place one unchanged, send its `name` as `pattern` to albert/add-post-block '
				. 'or albert/add-page-block, also on an empty post or page: the server inserts it exactly as stored. '
				. 'Summaries omit the markup; call view-pattern only when you will adapt it.'
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
				'search'   => [
					'type'        => 'string',
					'description' => 'Optional term matched against a pattern\'s name, title, description and keywords.',
				],
				'category' => [
					'type'        => 'string',
					'description' => 'Optional pattern category slug the pattern must carry (e.g. "featured", "header").',
				],
			],
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
				'patterns' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'        => [ 'type' => 'string' ],
							'title'       => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
							'categories'  => [
								'type'  => 'array',
								'items' => [ 'type' => 'string' ],
							],
							'source'      => [ 'type' => 'string' ],
							'id'          => [ 'type' => [ 'integer', 'null' ] ],
							'syncStatus'  => [ 'type' => 'string' ],
						],
					],
				],
				'total'    => [ 'type' => 'integer' ],
			],
			'required'   => [ 'patterns', 'total' ],
		];
	}

	/**
	 * Anyone who may compose content may discover patterns.
	 *
	 * @return bool|WP_Error
	 * @since 1.5.0
	 */
	public function check_permission(): bool|WP_Error {
		return $this->require_capability( 'edit_posts' );
	}

	/**
	 * Execute: return matching pattern summaries.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 * @return array<string, mixed>|WP_Error
	 * @since 1.5.0
	 */
	public function execute( array $args ): array|WP_Error {
		$search = isset( $args['search'] ) && is_string( $args['search'] ) && $args['search'] !== ''
			? $args['search']
			: null;

		$category = isset( $args['category'] ) && is_string( $args['category'] ) && $args['category'] !== ''
			? sanitize_key( $args['category'] )
			: null;

		$patterns = $this->catalog->all( $search, $category );

		return [
			'patterns' => $patterns,
			'total'    => count( $patterns ),
		];
	}
}
