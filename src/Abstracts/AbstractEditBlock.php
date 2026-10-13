<?php
/**
 * Abstract Edit Block Ability
 *
 * Operation base for the "edit one block" ability (posts + pages). Replaces the
 * block at `path` with the submitted block spec, keeping the target's inner
 * blocks when the spec omits `innerBlocks`; all other blocks are preserved
 * byte-for-byte.
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
 * Edit-one-block operation base. Subclasses bind it to a post type.
 *
 * @since 1.2.0
 */
abstract class AbstractEditBlock extends AbstractBlockEdit {

	/**
	 * Build the input schema for the edit operation.
	 *
	 * @return array<string, mixed> Input JSON schema.
	 * @since 1.2.0
	 */
	protected function edit_input_schema(): array {
		$properties          = $this->common_properties();
		$properties['block'] = $this->block_property();

		$properties['block']['description'] .= ' Omit innerBlocks to keep the target block\'s existing inner blocks (e.g. to change only a group\'s attributes); send innerBlocks to replace them, or [] to remove them.';

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'id', 'path', 'block' ],
		];
	}

	/**
	 * Apply the edit to the tree.
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

		$spec = $args['block'] ?? null;

		// Omitted innerBlocks means keep the target's children; an explicit array
		// (including []) is what the caller asked for and replaces them.
		$keep_children = is_array( $spec ) && ! array_key_exists( 'innerBlocks', $spec );

		return $editor->edit( $tree, $this->read_path( $args ), $built, $this->read_expect( $args ), $keep_children );
	}
}
