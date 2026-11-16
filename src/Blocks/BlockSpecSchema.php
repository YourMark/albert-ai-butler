<?php
/**
 * Block Spec Schema
 *
 * The shape a caller sends to describe one block, shared by every ability that
 * accepts blocks.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.4.0
 */

namespace Albert\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the block spec that {@see \Albert\Blocks\BlockSerializer} consumes.
 *
 * Declared in one place because it is accepted in two: as the single `block` of
 * an add/edit-block call, and as each item of `blocks` on create/update. Left
 * undescribed on the second, input the schema does not cover was carried and
 * dropped in silence — `{"name": "core/paragraph", "content": "Hi"}` saved an
 * empty paragraph, because text belongs in `attributes`.
 *
 * @since 1.4.0
 */
final class BlockSpecSchema {

	/**
	 * How many levels of `innerBlocks` are described.
	 *
	 * WordPress core's validator has no `$ref`, so a recursive shape can only be
	 * unrolled. Three levels covers the real nesting (group > columns > column >
	 * paragraph); below that `innerBlocks` is left open rather than unrolled
	 * further, since refusing legitimately deeper trees would be worse than not
	 * checking them.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	public const MAX_DEPTH = 3;

	/**
	 * The block spec schema.
	 *
	 * Descriptions are carried only at the top level. Repeating them down an
	 * unrolled tree tripled the schema an MCP client is sent without telling it
	 * anything new; the nested levels exist to be validated, not read.
	 *
	 * `name` is deliberately not required here. The serializer accepts the
	 * `blockName` spelling as an alias, and classic content carries a null name,
	 * so naming is enforced by {@see BlockSerializer::build_block()} — it sees
	 * both spellings and names the block at fault.
	 *
	 * @param int  $depth     How many levels of innerBlocks to describe.
	 * @param bool $described Whether to carry per-property descriptions.
	 *
	 * @return array<string, mixed> The schema.
	 * @since 1.4.0
	 */
	public static function spec( int $depth = self::MAX_DEPTH, bool $described = true ): array {
		$describe = static function ( array $property, string $description ) use ( $described ): array {
			if ( $described ) {
				$property['description'] = $description;
			}

			return $property;
		};

		return [
			'type'       => 'object',
			'properties' => [
				'name'        => $describe(
					[ 'type' => [ 'string', 'null' ] ],
					'Block type, e.g. core/paragraph. Null for classic (pre-block-editor) content, the spelling a view-* call returns for it.'
				),
				'attributes'  => [ 'type' => 'object' ],
				'innerBlocks' => $depth > 1
					? [
						'type'  => 'array',
						'items' => self::spec( $depth - 1, false ),
					]
					: [ 'type' => 'array' ],
				'plaintext'   => $describe( [ 'type' => 'string' ], 'Text content, when the block\'s own text attribute is not set.' ),
				'html'        => $describe( [ 'type' => 'string' ], 'The block\'s saved markup inside its delimiters, as the editor stores it; nested block markup becomes inner blocks. Use it to keep markup exactly, or for an attribute this server cannot write. Not with innerBlocks. For core/html, the raw HTML.' ),
				'blockName'   => $describe( [ 'type' => 'string' ], 'Alias of `name`, the spelling the WordPress block parser uses.' ),
				'attrs'       => $describe( [ 'type' => 'object' ], 'Alias of `attributes`, the spelling the WordPress block parser uses.' ),
				'path'        => $describe(
					[
						'type'  => 'array',
						'items' => [
							'type'    => 'integer',
							'minimum' => 0,
						],
					],
					'Ignored on write. Accepted so a block read from a view-* call can be sent straight back.'
				),
			],
		];
	}
}
