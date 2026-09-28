<?php
/**
 * Serialized block markup checked against real WordPress core.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Blocks;

use Albert\Abilities\WordPress\Posts\Create as CreatePost;
use Albert\Blocks\BlockReader;
use Albert\Blocks\BlockSerializer;
use Albert\Tests\TestCase;
use WP_Block_Type_Registry;

/**
 * The unit suite serializes against a stubbed registry and a stubbed
 * do_blocks(), so it cannot see core's attribute schemas or its render-time
 * validation. These tests run the serializer's output through the real thing.
 *
 * @since 1.5.0
 */
class SerializedMarkupTest extends TestCase {

	/**
	 * Every block the serializer has a template for, with each sourced attribute populated.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function every_templated_block(): array {
		return [
			[
				'name'       => 'core/heading',
				'attributes' => [
					'level'   => 2,
					'content' => 'A heading',
				],
			],
			[
				'name'       => 'core/paragraph',
				'attributes' => [ 'content' => 'A <strong>bold</strong> paragraph' ],
			],
			[
				'name'        => 'core/list',
				'attributes'  => [ 'ordered' => true ],
				'innerBlocks' => [
					[
						'name'       => 'core/list-item',
						'attributes' => [ 'content' => 'A list item' ],
					],
				],
			],
			[
				'name'        => 'core/quote',
				'attributes'  => [ 'citation' => 'A citation' ],
				'innerBlocks' => [
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'A quoted line' ],
					],
				],
			],
			[
				'name'       => 'core/image',
				'attributes' => [
					'url'     => 'https://example.com/image.jpg',
					'alt'     => 'An image',
					'caption' => 'A caption',
				],
			],
			[
				'name'        => 'core/columns',
				'innerBlocks' => [
					[
						'name'        => 'core/column',
						'innerBlocks' => [
							[
								'name'        => 'core/group',
								'innerBlocks' => [
									[
										'name'      => 'core/paragraph',
										'plaintext' => 'Nested text',
									],
								],
							],
						],
					],
				],
			],
			[
				'name'        => 'core/buttons',
				'innerBlocks' => [
					[
						'name'       => 'core/button',
						'attributes' => [
							'text' => 'A button',
							'url'  => 'https://example.com/',
						],
					],
				],
			],
			[ 'name' => 'core/separator' ],
			[
				'name'       => 'core/spacer',
				'attributes' => [ 'height' => '40px' ],
			],
			[
				'name'       => 'core/code',
				'attributes' => [ 'content' => 'echo 1;' ],
			],
			[
				'name'       => 'core/pullquote',
				'attributes' => [
					'value'    => 'A pullquote',
					'citation' => 'A pullquote citation',
				],
			],
		];
	}

	/**
	 * Core validates every comment-JSON attribute on render; none may carry a type it rejects.
	 *
	 * @return void
	 */
	public function test_no_attribute_core_cannot_validate_reaches_the_comment_json(): void {
		$markup   = ( new BlockSerializer() )->serialize( $this->every_templated_block() );
		$registry = WP_Block_Type_Registry::get_instance();
		$allowed  = [ 'array', 'object', 'string', 'number', 'integer', 'boolean', 'null' ];
		$offences = [];

		$walk = static function ( array $blocks ) use ( &$walk, $registry, $allowed, &$offences ): void {
			foreach ( $blocks as $block ) {
				$type = $block['blockName'] === null ? null : $registry->get_registered( $block['blockName'] );

				foreach ( array_keys( $block['attrs'] ) as $attribute ) {
					$types = (array) ( $type->attributes[ $attribute ]['type'] ?? [] );

					if ( isset( $type->attributes[ $attribute ] ) && ( $types === [] || array_diff( $types, $allowed ) !== [] ) ) {
						$offences[] = "{$block['blockName']}.{$attribute}";
					}
				}

				$walk( $block['innerBlocks'] );
			}
		};
		$walk( parse_blocks( $markup ) );

		$this->assertSame( [], $offences, "Attributes core cannot validate in the comment JSON:\n{$markup}" );
	}

	/**
	 * What BlockReader shows for a written block: validatable attributes survive, rich text is in plaintext.
	 *
	 * @return void
	 */
	public function test_block_reader_still_sees_what_was_written(): void {
		$markup = ( new BlockSerializer() )->serialize( $this->every_templated_block() );
		$tree   = ( new BlockReader() )->read( $markup );
		$by     = [];

		$walk = static function ( array $blocks ) use ( &$walk, &$by ): void {
			foreach ( $blocks as $block ) {
				$by[ $block['name'] ][] = $block;
				$walk( $block['innerBlocks'] );
			}
		};
		$walk( $tree );

		$image = $by['core/image'][0];
		$this->assertSame( 'https://example.com/image.jpg', $image['attributes']['url'] ?? null );
		$this->assertSame( 'An image', $image['attributes']['alt'] ?? null );
		$this->assertSame( 'https://example.com/', $by['core/button'][0]['attributes']['url'] ?? null );
		$this->assertSame( 'A button', $by['core/button'][0]['plaintext'] );
		$this->assertSame( 2, $by['core/heading'][0]['attributes']['level'] ?? null );
		$this->assertSame( 'A heading', $by['core/heading'][0]['plaintext'] );
	}

	/**
	 * Rendering a post written through the blocks input raises no notice and keeps its text.
	 *
	 * @return void
	 */
	public function test_rendering_a_post_written_with_blocks_raises_no_notice(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );

		$result = ( new CreatePost() )->execute(
			[
				'title'  => 'Every templated block',
				'blocks' => $this->every_templated_block(),
			]
		);
		$this->assertIsArray( $result );

		$notices = [];
		$collect = static function ( string $function_name, string $message ) use ( &$notices ): void {
			$notices[] = "{$function_name}: {$message}";
		};
		add_action( 'doing_it_wrong_run', $collect, 10, 2 );
		$html = do_blocks( get_post( $result['id'] )->post_content );
		remove_action( 'doing_it_wrong_run', $collect );

		$this->assertSame( [], $notices );

		foreach ( [ 'A heading', '<strong>bold</strong>', 'A list item', 'A citation', 'A caption', 'Nested text', 'A button', 'echo 1;', 'A pullquote' ] as $text ) {
			$this->assertStringContainsString( $text, $html );
		}
	}
}
