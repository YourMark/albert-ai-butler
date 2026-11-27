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
			'description' => 'Where to insert the new block relative to `path`: "before"/"after" place it as a sibling of the block at `path`; "inside_start"/"inside_end" prepend/append it inside that block\'s inner blocks. Those two are only valid when the block at `path` is a container (e.g. core/group, core/columns, core/column, core/buttons); on a leaf block like a paragraph this is rejected, so use "before"/"after" instead. Use "after" with the last block\'s path to append to the end. Not needed when the post has no blocks yet.',
		];

		$properties['path']['description']  .= ' Leave it out only when the post has no blocks yet: what you add then becomes its content.';
		$properties['block']['description'] .= ' Send `block` or `pattern`, not both.';
		$properties['pattern']               = [
			'type'        => 'string',
			'minLength'   => 1,
			'description' => 'Optional, instead of `block`: the `name` of a pattern from albert/find-patterns. The server inserts the pattern\'s blocks itself, exactly as the pattern stores them, so you do not copy its markup. A synced user pattern is inserted as a reference, so later edits to the pattern show here too.',
		];

		// anyOf, not oneOf: sending both must reach apply(), whose error names the fix.
		return [
			'type'       => 'object',
			'properties' => $properties,
			'required'   => [ 'id' ],
			'anyOf'      => [
				[
					'type'     => 'object',
					'title'    => '`block` (a block spec)',
					'required' => [ 'block' ],
				],
				[
					'type'     => 'object',
					'title'    => '`pattern` (a pattern name)',
					'required' => [ 'pattern' ],
				],
			],
		];
	}

	/**
	 * The shared output schema plus where the insert landed.
	 *
	 * @return array<string, mixed> Output JSON schema.
	 * @since 1.6.0
	 */
	protected function add_output_schema(): array {
		$schema = $this->shared_output_schema();

		$schema['properties']['inserted'] = [
			'type'        => 'object',
			'description' => 'Where the insert landed: the number of top-level blocks added (a pattern can add several) and their paths in the refreshed tree.',
			'properties'  => [
				'count' => [ 'type' => 'integer' ],
				'paths' => [
					'type'  => 'array',
					'items' => [
						'type'  => 'array',
						'items' => [ 'type' => 'integer' ],
					],
				],
			],
		];

		return $schema;
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
		$path     = $this->read_path( $args );

		if ( $path === [] && self::count_blocks( $tree ) > 0 ) {
			return new WP_Error(
				'block_path_required',
				sprintf(
					/* translators: %s: post type. */
					__( 'This %s already has blocks, so send `path` and `position` to say where to insert. Take the path from a fresh read of its blocks.', 'albert-ai-butler' ),
					$this->post_type()
				),
				[ 'status' => 400 ]
			);
		}

		if ( isset( $args['pattern'] ) ) {
			if ( isset( $args['block'] ) ) {
				return new WP_Error( 'invalid_input', __( 'Send either `block` or `pattern` (a pattern name), not both.', 'albert-ai-butler' ), [ 'status' => 400 ] );
			}
			if ( ! is_string( $args['pattern'] ) ) {
				return new WP_Error( 'invalid_input', __( '`pattern` must be a pattern name from albert/find-patterns.', 'albert-ai-butler' ), [ 'status' => 400 ] );
			}

			$blocks = $this->pattern_blocks( $args['pattern'], absint( $args['id'] ?? 0 ) );
		} else {
			$blocks = $this->build_block_or_error( $args );
			$blocks = $blocks instanceof WP_Error ? $blocks : [ $blocks ];
		}

		if ( $blocks instanceof WP_Error ) {
			return $blocks;
		}

		// An empty post has nothing to be relative to.
		if ( $path === [] ) {
			return array_merge( $blocks, $tree );
		}

		return $editor->add_all( $tree, $path, $position, $blocks );
	}

	/**
	 * Report where the insert landed, so the next edit needs no re-read to find it.
	 *
	 * @param array<int, mixed>    $before Tree before the insert.
	 * @param array<int, mixed>    $after  Tree after the insert.
	 * @param array<string, mixed> $args   Ability input.
	 * @return array<string, mixed>
	 * @since 1.6.0
	 */
	protected function result_fields( array $before, array $after, array $args ): array {
		$path        = $this->read_path( $args );
		$inside      = in_array( $args['position'] ?? '', [ 'inside_start', 'inside_end' ], true );
		$parent_path = $inside ? $path : array_slice( $path, 0, -1 );
		$added       = self::count_blocks( $after, $parent_path ) - self::count_blocks( $before, $parent_path );

		if ( $path === [] ) {
			$start = 0;
		} elseif ( $inside ) {
			$start = $args['position'] === 'inside_start' ? 0 : self::count_blocks( $before, $parent_path );
		} else {
			$start = (int) end( $path ) + ( ( $args['position'] ?? '' ) === 'after' ? 1 : 0 );
		}

		$paths = [];
		for ( $i = 0; $i < $added; $i++ ) {
			$paths[] = array_merge( $parent_path, [ $start + $i ] );
		}

		return [
			'inserted' => [
				'count' => $added,
				'paths' => $paths,
			],
		];
	}

	/**
	 * Count the blocks at one level of the tree, the way paths index them.
	 *
	 * @param array<int, mixed> $tree        Parsed block tree.
	 * @param array<int, int>   $parent_path Path of the parent block; [] for the top level.
	 * @return int
	 * @since 1.6.0
	 */
	private static function count_blocks( array $tree, array $parent_path = [] ): int {
		if ( $parent_path !== [] ) {
			$node = ( new BlockTreeEditor() )->find( $tree, $parent_path );
			$tree = is_array( $node['innerBlocks'] ?? null ) ? $node['innerBlocks'] : [];
		}

		return count( array_filter( $tree, static fn ( $block ): bool => is_array( $block ) && ! BlockReader::is_empty_separator( $block ) ) );
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

		// Theme patterns nest others as core/pattern references; the editor expands them on insert, and so does this.
		$blocks = array_values(
			array_filter(
				resolve_pattern_blocks( parse_blocks( (string) ( $pattern['content'] ?? '' ) ) ),
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
		$policy     = new BlockPolicy();
		$checks     = $insert === $blocks ? $insert : array_merge( $insert, $blocks );
		$disallowed = $policy->disallowed_names( $checks, $this->post_type(), $post_id, $policy->existing_block_names( $post_id ) );

		if ( $disallowed !== [] ) {
			return new WP_Error(
				'block_validation_failed',
				sprintf(
					/* translators: 1: pattern name, 2: comma-separated block names. */
					__( "The pattern '%1\$s' can't be used here: it contains blocks you may not use on this site (%2\$s). To reuse its layout, read it with albert/view-pattern, adapt its markup without those blocks, and send that as `block`.", 'albert-ai-butler' ),
					$name,
					implode( ', ', $disallowed )
				),
				[
					'status'     => 400,
					'disallowed' => $disallowed,
				]
			);
		}

		return $insert;
	}
}
