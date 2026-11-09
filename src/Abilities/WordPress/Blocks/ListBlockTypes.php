<?php
/**
 * List Block Types Ability
 *
 * Exposes the block types the current user is allowed to use as an MCP tool so
 * an assistant can discover which blocks it may compose before writing content.
 * The same allowed-block set is also published as the `albert://blocks/types`
 * MCP resource, built separately in {@see \Albert\MCP\BlockTypesResource}.
 *
 * @package    Albert
 * @subpackage Abilities\WordPress\Blocks
 * @since      1.2.0
 */

namespace Albert\Abilities\WordPress\Blocks;

use Albert\Abstracts\BaseAbility;
use Albert\Blocks\BlockCatalog;
use Albert\Core\Annotations;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * List Block Types ability class.
 *
 * Returns a compact catalog of allowed block types so an assistant can discover
 * which blocks it may compose before writing post or page content. This is a
 * plain MCP tool (the default ability type) and so is listed by
 * `discover-abilities`. The allowed-block set is mirrored on the
 * `albert://blocks/types` resource by {@see \Albert\MCP\BlockTypesResource}.
 *
 * @since 1.2.0
 */
class ListBlockTypes extends BaseAbility {

	/**
	 * Block catalog service.
	 *
	 * @since 1.2.0
	 * @var BlockCatalog
	 */
	private BlockCatalog $catalog;

	/**
	 * Constructor.
	 *
	 * @param BlockCatalog|null $catalog Optional catalog service (injectable for tests).
	 *
	 * @since 1.2.0
	 */
	public function __construct( ?BlockCatalog $catalog = null ) {
		$this->catalog = $catalog ?? new BlockCatalog();

		$this->id          = 'albert/list-block-types';
		$this->label       = __( 'List Block Types', 'albert-ai-butler' );
		$this->description = __( 'List the block types the current user may use, with title, category and whether each block renders dynamically. Fetch this before composing block content so you only use blocks that are available.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'blocks';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [ 'public' => true ],
			'annotations' => Annotations::read(
				'Pass the `post_type` you are about to write to. Sites can allow different blocks per '
				. 'post type, so the site-wide list is a superset and composing from it can still fail '
				. 'validation. What comes back already reflects your permissions, treat it as the complete '
				. 'set of names you may use, and use `core/html` when you genuinely need markup no block '
				. 'covers.'
			),
		];

		$this->capability = 'edit_posts';

		parent::__construct();
	}

	/**
	 * Get the input schema for this ability.
	 *
	 * @return array<string, mixed> Input schema.
	 * @since 1.2.0
	 */
	protected function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_type' => [
					'type'        => 'string',
					'description' => 'Optional post type to resolve the allowed blocks for (e.g. "post", "page"). Some sites allow different blocks per post type.',
				],
				'post_id'   => [
					'type'        => 'integer',
					'description' => 'Optional existing post ID to resolve the allowed blocks for, when blocks are restricted per post.',
					'minimum'     => 1,
				],
				'category'  => [
					'type'        => 'string',
					'description' => 'Optional block category slug to filter the result (e.g. "text", "media", "design").',
				],
			],
		];
	}

	/**
	 * Get the output schema for this ability.
	 *
	 * @return array<string, mixed> Output schema.
	 * @since 1.2.0
	 */
	protected function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'blocks' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'      => [ 'type' => 'string' ],
							'title'     => [ 'type' => 'string' ],
							'category'  => [ 'type' => 'string' ],
							'isDynamic' => [ 'type' => 'boolean' ],
						],
					],
				],
				'total'  => [ 'type' => 'integer' ],
			],
			'required'   => [ 'blocks', 'total' ],
		];
	}

	/**
	 * Execute the ability — return the allowed block catalog for the current user.
	 *
	 * Every parameter is optional; with no arguments the unfiltered, post-less
	 * catalog is returned.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 * @type string $post_type Optional post type for the editor context.
	 * @type int $post_id Optional post ID for the editor context.
	 * @type string $category Optional category slug filter.
	 * }
	 *
	 * @return array<string, mixed>|WP_Error Catalog on success.
	 * @since 1.2.0
	 */
	public function execute( array $args ): array|WP_Error {
		$post_type = isset( $args['post_type'] ) && is_string( $args['post_type'] ) && $args['post_type'] !== ''
			? sanitize_key( $args['post_type'] )
			: null;

		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		$post_id = $post_id > 0 ? $post_id : null;

		$blocks = $this->catalog->catalog( $post_type, $post_id );

		$category = isset( $args['category'] ) && is_string( $args['category'] ) && $args['category'] !== ''
			? sanitize_key( $args['category'] )
			: null;

		if ( $category !== null ) {
			$blocks = array_values(
				array_filter(
					$blocks,
					static fn( array $block ): bool => $block['category'] === $category
				)
			);
		}

		return [
			'blocks' => $blocks,
			'total'  => count( $blocks ),
		];
	}
}
