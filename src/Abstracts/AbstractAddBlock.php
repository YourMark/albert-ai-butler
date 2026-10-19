<?php
/**
 * Abstract Add Block Ability
 *
 * Operation base for the "add one block" ability (posts + pages). Inserts the
 * submitted block spec relative to `path` (before/after as a sibling, or
 * inside_start/inside_end into the target's inner blocks); all other blocks are
 * preserved byte-for-byte.
 *
 * @package    Albert
 * @subpackage Abstracts
 * @since      1.2.0
 */

namespace Albert\Abstracts;

use Albert\Blocks\BlockTreeEditor;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Add-one-block operation base. Subclasses bind it to a post type.
 *
 * @since 1.2.0
 */
abstract class AbstractAddBlock extends AbstractBlockEdit {

	/**
	 * Build the input schema for the add operation.
	 *
	 * @return array<string, mixed> Input JSON schema.
	 * @since 1.2.0
	 */
	protected function add_input_schema(): array {
		$properties             = $this->common_properties();
		$properties['block']    = $this->block_property();
		$properties['position'] = [
			'type'        => 'string',
			'enum'        => [ 'before', 'after', 'inside_start', 'inside_end' ],
			'description' => 'Where to insert the new block relative to `path`: "before"/"after" place it as a sibling of the block at `path`; "inside_start"/"inside_end" prepend/append it inside that block\'s inner blocks. Those two are only valid when the block at `path` is a container (e.g. core/group, core/columns, core/column, core/buttons); on a leaf block like a paragraph this is rejected, so use "before"/"after" instead. Use "after" with the last block\'s path to append to the end.',
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'id', 'path', 'position', 'block' ],
		];
	}

	/**
	 * Apply the insert to the tree.
	 *
	 * @param BlockTreeEditor      $editor Tree editor.
	 * @param array<int, mixed>    $tree   Parsed block tree.
	 * @param array<string, mixed> $args   Ability input.
	 * @return array<int, mixed>|WP_Error New tree, or an error.
	 * @since 1.2.0
	 */
	protected function apply( BlockTreeEditor $editor, array $tree, array $args ): array|WP_Error {
		$built = $this->build_block_or_error( $args );
		if ( $built instanceof WP_Error ) {
			return $built;
		}

		$position = isset( $args['position'] ) ? (string) $args['position'] : '';

		return $editor->add( $tree, $this->read_path( $args ), $position, $built );
	}
}
