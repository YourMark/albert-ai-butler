<?php
/**
 * Abstract Add Block Ability
 *
 * Operation base for the "add one block" ability (posts + pages). Inserts the
 * submitted block spec, or a pattern's blocks, relative to `path` (before/after
 * as a sibling, or inside_start/inside_end into the target's inner blocks); all
 * other blocks are preserved byte-for-byte.
 *
 * @package    Albert
 * @subpackage Abstracts
 * @since      1.2.0
 */

namespace Albert\Abstracts;

use Albert\Blocks\BlockIssues;
use Albert\Blocks\BlockPolicy;
use Albert\Blocks\BlockReader;
use Albert\Blocks\BlockTreeEditor;
use Albert\Blocks\PatternCatalog;
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

		$properties['block']['description'] .= ' Send `block` or `pattern`, not both.';
		$properties['pattern']               = [
			'type'        => 'string',
			'minLength'   => 1,
			'description' => 'Optional, instead of `block`: the `name` of a pattern from albert/find-patterns. The server inserts the pattern\'s blocks itself, exactly as the pattern stores them, so you do not copy its markup. A synced user pattern is inserted as a reference, so later edits to the pattern show here too.',
		];

		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'id', 'path', 'position' ],
			'oneOf'      => [
				[
					'type'     => 'object',
					'required' => [ 'block' ],
				],
				[
					'type'     => 'object',
					'required' => [ 'pattern' ],
				],
			],
		];
	}

	/**
	 * Specs for the shared policy and serializer checks; a pattern is checked in {@see pattern_blocks()}.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array<int, array<string, mixed>>|null
	 * @since 1.6.0
	 */
	protected function policy_specs( array $args ): ?array {
		return isset( $args['pattern'] ) ? null : parent::policy_specs( $args );
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
		$position = isset( $args['position'] ) ? (string) $args['position'] : '';

		if ( isset( $args['pattern'] ) ) {
			if ( isset( $args['block'] ) || ! is_string( $args['pattern'] ) ) {
				return new WP_Error( 'invalid_input', __( 'Send either `block` or `pattern` (a pattern name), not both.', 'albert-ai-butler' ), [ 'status' => 400 ] );
			}

			$blocks = $this->pattern_blocks( $args['pattern'], absint( $args['id'] ?? 0 ) );

			return $blocks instanceof WP_Error ? $blocks : $editor->add_all( $tree, $this->read_path( $args ), $position, $blocks );
		}

		$built = $this->build_block_or_error( $args );
		if ( $built instanceof WP_Error ) {
			return $built;
		}

		return $editor->add( $tree, $this->read_path( $args ), $position, $built );
	}

	/**
	 * The blocks a pattern inserts: its own stored blocks, or a reference to a
	 * synced user pattern. Never rebuilt, so they stay as valid as the pattern.
	 *
	 * @param string $name    Pattern name from albert/find-patterns.
	 * @param int    $post_id Target post, for the allowed-block check.
	 * @return array<int, array<string, mixed>>|WP_Error Parsed blocks, or an error.
	 * @since 1.6.0
	 */
	private function pattern_blocks( string $name, int $post_id ): array|WP_Error {
		$pattern = ( new PatternCatalog() )->get( $name );

		if ( $pattern === null ) {
			return new WP_Error(
				'pattern_not_found',
				/* translators: %s: pattern name. */
				sprintf( __( "No pattern named '%s'. Call albert/find-patterns for the names on this site.", 'albert-ai-butler' ), $name ),
				[ 'status' => 404 ]
			);
		}

		$blocks = array_values(
			array_filter(
				parse_blocks( (string) ( $pattern['content'] ?? '' ) ),
				static fn ( array $block ): bool => ! BlockReader::is_empty_separator( $block )
			)
		);

		if ( $blocks === [] ) {
			return new WP_Error(
				'pattern_empty',
				/* translators: %s: pattern name. */
				sprintf( __( "The pattern '%s' has no blocks to insert.", 'albert-ai-butler' ), $name ),
				[ 'status' => 400 ]
			);
		}

		$insert = $blocks;
		if ( ( $pattern['syncStatus'] ?? '' ) === 'synced' && ! empty( $pattern['id'] ) ) {
			$insert = [
				[
					'blockName'    => 'core/block',
					'attrs'        => [ 'ref' => (int) $pattern['id'] ],
					'innerBlocks'  => [],
					'innerHTML'    => '',
					'innerContent' => [],
				],
			];
		}

		// A reference renders the pattern's blocks, so they are checked as well as the reference.
		$policy = new BlockPolicy();
		$checks = $insert === $blocks ? $insert : array_merge( $insert, $blocks );
		$error  = BlockIssues::to_wp_error( $policy->enforce( $checks, $this->post_type(), $post_id, $policy->existing_block_names( $post_id ) ) );

		return $error ?? $insert;
	}
}
