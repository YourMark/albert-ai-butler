<?php
/**
 * Get Block Type Ability
 *
 * Returns the full detail (attributes and supports) for a single registered
 * block type, so an assistant can compose valid block markup for it.
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
 * Get Block Type ability class.
 *
 * Reads a single block type's title, category, dynamic flag, attribute schema
 * and supports map. Returns a clear WP_Error when the requested block name is
 * not registered.
 *
 * @since 1.2.0
 */
class GetBlockType extends BaseAbility {

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

		$this->id          = 'albert/get-block-type';
		$this->label       = __( 'Get Block Type', 'albert-ai-butler' );
		$this->description = __( 'Get the full detail of a single block type, including its attribute schema and supports, so you can compose valid markup for it.', 'albert-ai-butler' );
		$this->category    = 'content';
		$this->group       = 'blocks';

		$this->input_schema  = $this->get_input_schema();
		$this->output_schema = $this->get_output_schema();

		$this->meta = [
			'mcp'         => [
				'public' => true,
			],
			'annotations' => Annotations::read(),
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
				'name' => [
					'type'        => 'string',
					'description' => 'The block name to inspect (e.g. "core/paragraph").',
				],
			],
			'required'   => [ 'name' ],
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
				'name'       => [ 'type' => 'string' ],
				'title'      => [ 'type' => 'string' ],
				'category'   => [ 'type' => 'string' ],
				'isDynamic'  => [ 'type' => 'boolean' ],
				'attributes' => [
					'type'        => 'object',
					'description' => 'The block attribute schema, keyed by attribute name.',
				],
				'supports'   => [
					'type'        => 'object',
					'description' => 'The block supports map.',
				],
			],
			'required'   => [ 'name', 'title', 'category', 'isDynamic', 'attributes', 'supports' ],
		];
	}

	/**
	 * Execute the ability — return a single block type's detail.
	 *
	 * @param array<string, mixed> $args {
	 *     Input parameters.
	 *
	 *     @type string $name Block name to inspect.
	 * }
	 *
	 * @return array<string, mixed>|WP_Error Block detail on success, WP_Error when not registered.
	 * @since 1.2.0
	 */
	public function execute( array $args ): array|WP_Error {
		$name = isset( $args['name'] ) && is_string( $args['name'] ) ? trim( $args['name'] ) : '';

		if ( $name === '' ) {
			return new WP_Error(
				'block_name_required',
				__( 'A block name is required.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$block = $this->catalog->block( $name );

		if ( $block === null ) {
			return new WP_Error(
				'block_not_registered',
				sprintf(
					/* translators: %s: requested block name */
					__( 'The block "%s" is not registered on this site. Use albert/list-block-types to see available blocks.', 'albert-ai-butler' ),
					$name
				),
				[ 'status' => 404 ]
			);
		}

		// The block is registered, but the current user may not be allowed to use
		// it. Mirror albert/list-block-types, which only lists allowed blocks, so
		// the detail and the catalog stay consistent.
		if ( ! $this->catalog->is_allowed( $name ) ) {
			return new WP_Error(
				'block_not_allowed',
				sprintf(
					/* translators: %s: requested block name */
					__( "Block '%s' is not available to you on this site. Call albert/list-block-types to see the blocks you may use.", 'albert-ai-butler' ),
					$name
				),
				[ 'status' => 403 ]
			);
		}

		return $block;
	}
}
