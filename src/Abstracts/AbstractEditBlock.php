<?php
/**
 * Abstract Edit Block Ability
 *
 * Operation base for the "edit one block" ability (posts + pages). Replaces the
 * block at `path` with the submitted block spec, keeping the target's inner
 * blocks when the spec omits `innerBlocks`; all other blocks are preserved
 * byte-for-byte. `edits` applies several such edits in order and saves once.
 *
 * @package    Albert
 * @subpackage Abstracts
 * @since      1.2.0
 */

namespace Albert\Abstracts;

use Albert\Blocks\BlockSpecSchema;
use Albert\Blocks\BlockTreeEditor;
use Albert\Blocks\MarkupTags;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Edit-one-block operation base. Subclasses bind it to a post type.
 *
 * @since 1.2.0
 */
abstract class AbstractEditBlock extends AbstractBlockEdit {

	/**
	 * How an omitted `innerBlocks` behaves on edit, appended to the block spec description.
	 *
	 * @var string
	 * @since 1.5.0
	 */
	private const KEEP_CHILDREN_NOTE = 'Omit innerBlocks to keep the target block\'s existing inner blocks (e.g. to change only a group\'s attributes); send innerBlocks to replace them, or [] to remove them.';

	/**
	 * Input key for several edits saved as one change.
	 *
	 * @var string
	 * @since 1.6.0
	 */
	private const EDITS = 'edits';

	/**
	 * Build the input schema for the edit operation.
	 *
	 * @return array<string, mixed> Input JSON schema.
	 * @since 1.2.0
	 */
	protected function edit_input_schema(): array {
		$properties          = $this->common_properties();
		$properties['block'] = $this->block_property();

		$properties['block']['description'] = trim( ( $properties['block']['description'] ?? '' ) . ' ' . self::KEEP_CHILDREN_NOTE );
		$properties[ MarkupTags::INPUT ]    = MarkupTags::input_schema();
		$properties[ self::EDITS ]          = $this->edits_property( $properties );

		$properties['path']['description']  .= ' Required unless you send `edits`.';
		$properties['block']['description'] .= ' Required unless you send `edits`.';

		// `path` and `block` are required only without `edits`; operations() enforces it.
		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'id' ],
		];
	}

	/**
	 * The `edits` list: several edits of one post, saved as one revision.
	 *
	 * Each item's `block` is validated by the same spec but carries no
	 * descriptions; the top-level `block` already explains it.
	 *
	 * @param array<string, array<string, mixed>> $properties The single-edit properties.
	 * @return array<string, mixed> The `edits` property schema.
	 * @since 1.6.0
	 */
	private function edits_property( array $properties ): array {
		$block                = BlockSpecSchema::spec( BlockSpecSchema::MAX_DEPTH, false );
		$block['description'] = 'Same as the top-level `block`.';

		return [
			'type'        => 'array',
			'minItems'    => 1,
			'description' => 'Optional. Several edits to this post, saved once as one revision. Use it instead of `path`, `block` and `expect` when you change more than one block. Each item takes those three, applied in order: each path points into the tree as the earlier edits left it. If one edit fails, nothing is saved and the error names it as edits[index]. `remove_tags` covers every edit.',
			'items'       => [
				'type'       => 'object',
				'properties' => [
					'path'   => [
						'type'        => 'array',
						'items'       => $properties['path']['items'],
						'description' => 'Same as the top-level `path`, read against the tree after the earlier edits.',
					],
					'block'  => $block,
					'expect' => [
						'type'        => 'string',
						'description' => 'Same as the top-level `expect`.',
					],
				],
				'required'   => [ 'path', 'block' ],
			],
		];
	}

	/**
	 * One operation for `path`/`block`, or one per item of `edits`.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array<int, array<string, mixed>>|WP_Error Operation inputs, or an error.
	 * @since 1.6.0
	 */
	protected function operations( array $args ): array|WP_Error {
		if ( ! array_key_exists( self::EDITS, $args ) ) {
			if ( ! array_key_exists( 'path', $args ) ) {
				return new WP_Error(
					'path_required',
					__( 'Send `path` and `block` for one edit, or `edits` for several.', 'albert-ai-butler' ),
					[ 'status' => 400 ]
				);
			}

			return [ $args ];
		}

		$mixed = array_intersect( [ 'path', 'block', 'expect' ], array_keys( $args ) );
		if ( $mixed !== [] ) {
			return new WP_Error(
				'edits_with_single_edit',
				__( 'Send either `edits` or `path`, `block` and `expect`, not both. Put every edit in `edits`. Nothing was saved.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$edits = $args[ self::EDITS ];
		if ( ! is_array( $edits ) || $edits === [] ) {
			return new WP_Error(
				'edits_empty',
				__( '`edits` must list at least one edit. Nothing was saved.', 'albert-ai-butler' ),
				[ 'status' => 400 ]
			);
		}

		$operations = [];
		foreach ( array_values( $edits ) as $index => $edit ) {
			if ( ! is_array( $edit ) || ! array_key_exists( 'path', $edit ) ) {
				return new WP_Error(
					'path_required',
					sprintf(
						/* translators: %d: zero-based position in the edits list. */
						__( 'edits[%d] needs a `path` and a `block`. Nothing was saved.', 'albert-ai-butler' ),
						$index
					),
					[
						'status'     => 400,
						'edit_index' => $index,
					]
				);
			}

			$operation = array_intersect_key( $edit, array_flip( [ 'path', 'block', 'expect' ] ) );
			if ( array_key_exists( MarkupTags::INPUT, $args ) ) {
				$operation[ MarkupTags::INPUT ] = $args[ MarkupTags::INPUT ];
			}

			$operations[] = $operation;
		}

		return $operations;
	}

	/**
	 * The target block's markup before the edit and its replacement's after.
	 *
	 * @param array<int, mixed>    $tree        Parsed tree before the edit.
	 * @param array<int, mixed>    $result_tree Tree after the edit.
	 * @param array<string, mixed> $args        Ability input.
	 * @return array{0: string, 1: string}|null [ before, after ] markup, or null.
	 * @since 1.6.0
	 */
	protected function changed_markup( array $tree, array $result_tree, array $args ): ?array {
		$editor = new BlockTreeEditor();
		$path   = $this->read_path( $args );
		$before = $editor->find( $tree, $path );
		$after  = $editor->find( $result_tree, $path );

		if ( $before === null || $after === null ) {
			return null;
		}

		return [ serialize_block( $before ), serialize_block( $after ) ];
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
