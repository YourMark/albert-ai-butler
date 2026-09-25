<?php
/**
 * Tests for the BlockSerializer.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

use Albert\Blocks\BlockReader;
use Albert\Blocks\BlockSerializer;
use PHPUnit\Framework\TestCase;

// Load WordPress function stubs (parse_blocks, serialize_blocks, esc_*, etc.)
// and the block type registry stub used by the serializer's BlockSchema.
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';

/**
 * BlockSerializer unit tests.
 *
 * The serializer is the heart of the block pipeline: it converts structured
 * block specs into valid WordPress block markup that survives a
 * parse_blocks( serialize_blocks() ) round-trip without "unexpected or invalid
 * content" warnings, and reports field-level validation issues for self-correction.
 */
class BlockSerializerTest extends TestCase {

	/**
	 * Serializer instance under test.
	 *
	 * @var BlockSerializer
	 */
	private BlockSerializer $serializer;

	/**
	 * Reader used to assert round-trip block names survive.
	 *
	 * @var BlockReader
	 */
	private BlockReader $reader;

	/**
	 * Set up fresh instances for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Register the common core block types (plus a dynamic block) so the
		// serializer's registry checks classify untemplated names correctly.
		// 'core/hero' is intentionally left unregistered for the error path.
		albert_test_register_default_block_types();

		$this->serializer = new BlockSerializer();
		$this->reader     = new BlockReader();
	}

	/**
	 * Reset the controllable registry contents after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['albert_test_block_types'] );
		parent::tearDown();
	}

	/**
	 * Collect issue messages of a given severity.
	 *
	 * @param array<int, array{severity: string, message: string}> $issues   Issues from the serializer.
	 * @param string                                                $severity Severity to keep.
	 * @return array<int, string> Matching messages.
	 */
	private function messages( array $issues, string $severity ): array {
		$out = [];
		foreach ( $issues as $issue ) {
			$this->assertArrayHasKey( 'severity', $issue );
			$this->assertArrayHasKey( 'message', $issue );
			if ( $issue['severity'] === $severity ) {
				$out[] = $issue['message'];
			}
		}

		return $out;
	}

	/**
	 * Assert that serialized markup round-trips: every top-level block name
	 * passed in survives a parse of the serialized output.
	 *
	 * @param array<int, array<string, mixed>> $specs    Block specs.
	 * @param array<int, string>               $expected Expected top-level names after round-trip.
	 * @return void
	 */
	private function assert_round_trip( array $specs, array $expected ): void {
		$markup = $this->serializer->serialize( $specs );
		$tree   = $this->reader->read( $markup );

		$names = array_map( static fn ( $block ) => $block['name'], $tree );
		$this->assertSame( $expected, $names, "Round-trip names mismatch for markup:\n{$markup}" );
	}

	// Per-block round-trips

	public function test_sourced_attributes_are_kept_out_of_the_comment_json(): void {
		albert_test_register_block_type(
			'core/paragraph',
			false,
			[
				'content'   => [
					'type'   => 'rich-text',
					'source' => 'rich-text',
				],
				'dropCap'   => [ 'type' => 'boolean' ],
				'className' => [ 'type' => 'string' ],
			]
		);

		$markup = $this->serializer->serialize(
			[
				[
					'name'       => 'core/paragraph',
					'attributes' => [
						'content' => 'Hello',
						'dropCap' => true,
					],
				],
			]
		);

		$this->assertSame( '<!-- wp:paragraph {"dropCap":true} --><p>Hello</p><!-- /wp:paragraph -->', $markup );
	}

	public function test_paragraph_round_trip(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'        => 'core/paragraph',
					'attributes'  => [],
					'innerBlocks' => [],
					'plaintext'   => 'Hello world',
				],
			]
		);

		$this->assertSame( '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->', $markup );
		$this->assert_round_trip(
			[
				[
					'name'      => 'core/paragraph',
					'plaintext' => 'Hello world',
				],
			],
			[ 'core/paragraph' ]
		);
	}

	public function test_heading_round_trip(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'       => 'core/heading',
					'attributes' => [ 'level' => 3 ],
					'plaintext'  => 'Section',
				],
			]
		);

		$this->assertStringContainsString( '<!-- wp:heading {"level":3} -->', $markup );
		$this->assertStringContainsString( '<h3 class="wp-block-heading">Section</h3>', $markup );
	}

	public function test_heading_defaults_to_level_2(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'      => 'core/heading',
					'plaintext' => 'No level',
				],
			]
		);

		$this->assertStringContainsString( '<h2 class="wp-block-heading">No level</h2>', $markup );
	}

	public function test_list_round_trip(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'        => 'core/list',
					'innerBlocks' => [
						[
							'name'      => 'core/list-item',
							'plaintext' => 'First',
						],
						[
							'name'      => 'core/list-item',
							'plaintext' => 'Second',
						],
					],
				],
			]
		);

		// core/list is a dynamic block: stored markup is a bare <ul>, the
		// wp-block-list class is injected by the render_callback at render time.
		$this->assertStringContainsString( '<ul>', $markup );
		$this->assertStringNotContainsString( 'class="wp-block-list"', $markup );
		$this->assertStringContainsString( '<li>First</li>', $markup );
		$this->assertStringContainsString( '<li>Second</li>', $markup );

		$this->assert_round_trip(
			[
				[
					'name'        => 'core/list',
					'innerBlocks' => [
						[
							'name'      => 'core/list-item',
							'plaintext' => 'First',
						],
					],
				],
			],
			[ 'core/list' ]
		);
	}

	public function test_ordered_list_uses_ol(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'        => 'core/list',
					'attributes'  => [ 'ordered' => true ],
					'innerBlocks' => [
						[
							'name'      => 'core/list-item',
							'plaintext' => 'One',
						],
					],
				],
			]
		);

		$this->assertStringContainsString( '<ol>', $markup );
		$this->assertStringNotContainsString( 'class="wp-block-list"', $markup );
	}

	public function test_quote_round_trip(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'        => 'core/quote',
					'innerBlocks' => [
						[
							'name'      => 'core/paragraph',
							'plaintext' => 'To be or not to be',
						],
					],
				],
			]
		);

		$this->assertStringContainsString( '<blockquote class="wp-block-quote">', $markup );
		$this->assert_round_trip(
			[
				[
					'name'        => 'core/quote',
					'innerBlocks' => [
						[
							'name'      => 'core/paragraph',
							'plaintext' => 'X',
						],
					],
				],
			],
			[ 'core/quote' ]
		);
	}

	public function test_image_round_trip(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'       => 'core/image',
					'attributes' => [
						'url' => 'https://example.com/p.jpg',
						'alt' => 'A photo',
					],
				],
			]
		);

		$this->assertStringContainsString( '<figure class="wp-block-image">', $markup );
		$this->assertStringContainsString( 'src="https://example.com/p.jpg"', $markup );
		$this->assertStringContainsString( 'alt="A photo"', $markup );
	}

	public function test_columns_nested_round_trip(): void {
		$specs = [
			[
				'name'        => 'core/columns',
				'innerBlocks' => [
					[
						'name'        => 'core/column',
						'innerBlocks' => [
							[
								'name'      => 'core/paragraph',
								'plaintext' => 'Left',
							],
						],
					],
					[
						'name'        => 'core/column',
						'innerBlocks' => [
							[
								'name'      => 'core/paragraph',
								'plaintext' => 'Right',
							],
						],
					],
				],
			],
		];

		$markup = $this->serializer->serialize( $specs );
		$this->assertStringContainsString( '<div class="wp-block-columns">', $markup );
		$this->assertStringContainsString( '<div class="wp-block-column">', $markup );

		$tree = $this->reader->read( $markup );
		$this->assertSame( 'core/columns', $tree[0]['name'] );
		$this->assertCount( 2, $tree[0]['innerBlocks'] );
		$this->assertSame( 'core/column', $tree[0]['innerBlocks'][0]['name'] );
		$this->assertSame( 'Left', $tree[0]['innerBlocks'][0]['innerBlocks'][0]['plaintext'] );
	}

	public function test_group_round_trip(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'        => 'core/group',
					'innerBlocks' => [
						[
							'name'      => 'core/paragraph',
							'plaintext' => 'Grouped',
						],
					],
				],
			]
		);

		$this->assertStringContainsString( '<div class="wp-block-group">', $markup );
		$this->assert_round_trip(
			[
				[
					'name'        => 'core/group',
					'innerBlocks' => [
						[
							'name'      => 'core/paragraph',
							'plaintext' => 'X',
						],
					],
				],
			],
			[ 'core/group' ]
		);
	}

	public function test_buttons_and_button_round_trip(): void {
		$specs = [
			[
				'name'        => 'core/buttons',
				'innerBlocks' => [
					[
						'name'       => 'core/button',
						'attributes' => [
							'url'  => 'https://example.com',
							'text' => 'Click me',
						],
					],
				],
			],
		];

		$markup = $this->serializer->serialize( $specs );
		$this->assertStringContainsString( '<div class="wp-block-buttons">', $markup );
		$this->assertStringContainsString( '<div class="wp-block-button">', $markup );
		$this->assertStringContainsString( '<a class="wp-block-button__link', $markup );
		$this->assertStringContainsString( 'href="https://example.com"', $markup );
		$this->assertStringContainsString( '>Click me</a>', $markup );

		$tree = $this->reader->read( $markup );
		$this->assertSame( 'core/buttons', $tree[0]['name'] );
		$this->assertSame( 'core/button', $tree[0]['innerBlocks'][0]['name'] );
	}

	public function test_separator_round_trip(): void {
		$markup = $this->serializer->serialize( [ [ 'name' => 'core/separator' ] ] );

		$this->assertStringContainsString( '<hr class="wp-block-separator', $markup );
		$this->assert_round_trip( [ [ 'name' => 'core/separator' ] ], [ 'core/separator' ] );
	}

	public function test_spacer_round_trip(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'       => 'core/spacer',
					'attributes' => [ 'height' => '40px' ],
				],
			]
		);

		$this->assertStringContainsString( 'wp-block-spacer', $markup );
		$this->assertStringContainsString( 'height:40px', $markup );
	}

	public function test_code_round_trip(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'      => 'core/code',
					'plaintext' => '<?php echo "hi"; ?>',
				],
			]
		);

		$this->assertStringContainsString( '<pre class="wp-block-code">', $markup );
		// Code content must be escaped.
		$this->assertStringContainsString( '&lt;?php', $markup );
		$this->assertStringNotContainsString( '<?php echo', $markup );
	}

	public function test_pullquote_round_trip(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'      => 'core/pullquote',
					'plaintext' => 'Big idea',
				],
			]
		);

		$this->assertStringContainsString( '<figure class="wp-block-pullquote">', $markup );
		$this->assert_round_trip(
			[
				[
					'name'      => 'core/pullquote',
					'plaintext' => 'Big idea',
				],
			],
			[ 'core/pullquote' ]
		);
	}

	// Alias normalization

	public function test_blockname_alias_is_accepted(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'blockName' => 'core/paragraph',
					'attrs'     => [],
					'plaintext' => 'Aliased',
				],
			]
		);

		$this->assertSame( '<!-- wp:paragraph --><p>Aliased</p><!-- /wp:paragraph -->', $markup );
	}

	// Unknown block fallback

	public function test_unknown_block_is_flagged_as_error_with_html_fallback(): void {
		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'       => 'core/hero',
					'attributes' => [ 'html' => '<div>raw</div>' ],
				],
			]
		);

		// Fallback markup is still emitted so output is never broken...
		$this->assertStringContainsString( '<!-- wp:html -->', $result['markup'] );
		$this->assertStringContainsString( '<div>raw</div>', $result['markup'] );

		// ...but the unknown name is an error, not a warning.
		$errors = $this->messages( $result['issues'], 'error' );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'content[0]', $errors[0] );
		$this->assertStringContainsString( 'core/hero', $errors[0] );
		$this->assertStringContainsString( 'not a registered block', $errors[0] );
		$this->assertSame( [], $this->messages( $result['issues'], 'warning' ) );
	}

	public function test_unknown_block_without_content_is_dropped_with_error(): void {
		$result = $this->serializer->serialize_with_issues(
			[ [ 'name' => 'core/hero' ] ]
		);

		$this->assertSame( '', $result['markup'] );
		$errors = $this->messages( $result['issues'], 'error' );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'dropped', $errors[0] );
	}

	public function test_explicit_registered_core_html_emits_with_no_issue(): void {
		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'       => 'core/html',
					'attributes' => [ 'html' => '<div class="custom">raw markup</div>' ],
				],
			]
		);

		$this->assertStringContainsString( '<!-- wp:html -->', $result['markup'] );
		$this->assertStringContainsString( '<div class="custom">raw markup</div>', $result['markup'] );
		$this->assertSame( [], $result['issues'], 'A legitimate core/html block must not be flagged.' );
	}

	public function test_registered_dynamic_untemplated_block_serializes_comment_only(): void {
		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'       => 'core/latest-posts',
					'attributes' => [ 'postsToShow' => 3 ],
				],
			]
		);

		// Dynamic blocks store no innerHTML — a self-closing comment is valid.
		$this->assertStringContainsString( '<!-- wp:latest-posts', $result['markup'] );
		$this->assertStringContainsString( '/-->', $result['markup'] );
		$this->assertSame( [], $result['issues'], 'A registered dynamic block must not be flagged.' );

		// And it round-trips back to the same block name.
		$tree = $this->reader->read( $result['markup'] );
		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/latest-posts', $tree[0]['name'] );
	}

	public function test_registered_static_untemplated_block_warns_but_does_not_error(): void {
		// A registered static block with no dedicated template here.
		albert_test_register_block_type( 'core/verse', false );

		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'      => 'core/verse',
					'plaintext' => 'A line of verse',
				],
			]
		);

		$warnings = $this->messages( $result['issues'], 'warning' );
		$this->assertNotEmpty( $warnings );
		$this->assertStringContainsString( 'core/verse', $warnings[0] );
		$this->assertSame( [], $this->messages( $result['issues'], 'error' ) );
		$this->assertStringContainsString( '<!-- wp:verse', $result['markup'] );
	}

	// Content-loss regression: hybrid blocks (render_callback + inner blocks)
	//
	// core/cover (since WP 6.0) and core/media-text render from a callback yet
	// still store their inner blocks. The old serializer used the presence of a
	// render_callback as a proxy for "stores no innerHTML" and emitted these
	// self-closing, silently dropping every child with a success response —
	// the most severe bug in the block pipeline. The serializer now decides
	// self-closing on whether the block actually carries markup content
	// (inner blocks, a markup-sourced attribute, or raw spec content), read
	// live from the registry, so the same logic protects third-party and
	// custom blocks with no per-block knowledge.

	public function test_hybrid_block_with_inner_blocks_keeps_its_children(): void {
		// core/cover: a dynamic block (render_callback) that nonetheless stores
		// inner blocks — the exact shape that used to be dropped.
		albert_test_register_block_type( 'core/cover', true, [ 'url' => [] ] );

		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'        => 'core/cover',
					'attributes'  => [ 'url' => 'https://example.com/x.jpg' ],
					'innerBlocks' => [
						[
							'name'       => 'core/paragraph',
							'attributes' => [ 'content' => 'First line' ],
						],
						[
							'name'       => 'core/paragraph',
							'attributes' => [ 'content' => 'Second line' ],
						],
					],
				],
			]
		);

		// The block must NOT be self-closing: it has a real closing delimiter.
		$this->assertStringContainsString( '<!-- wp:cover', $result['markup'] );
		$this->assertStringContainsString( '<!-- /wp:cover -->', $result['markup'] );
		$this->assertStringNotContainsString( 'wp:cover {"url":"https://example.com/x.jpg"} /', $result['markup'] );

		// Both children survive, in order.
		$this->assertStringContainsString( 'First line', $result['markup'] );
		$this->assertStringContainsString( 'Second line', $result['markup'] );

		// And they round-trip back as two nested paragraph blocks.
		$tree = $this->reader->read( $result['markup'] );
		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/cover', $tree[0]['name'] );
		$this->assertCount( 2, $tree[0]['innerBlocks'], 'Both cover children must survive serialization.' );
		$this->assertSame( 'First line', $tree[0]['innerBlocks'][0]['plaintext'] );
		$this->assertSame( 'Second line', $tree[0]['innerBlocks'][1]['plaintext'] );

		// Fidelity of cover's own wrapper is not guaranteed here — that is a
		// warning, never a silent success and never an error.
		$this->assertNotEmpty( $this->messages( $result['issues'], 'warning' ) );
		$this->assertSame( [], $this->messages( $result['issues'], 'error' ) );
	}

	public function test_media_text_hybrid_block_keeps_its_children(): void {
		albert_test_register_block_type( 'core/media-text', true, [ 'mediaUrl' => [] ] );

		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'        => 'core/media-text',
					'attributes'  => [ 'mediaUrl' => 'https://example.com/a.jpg' ],
					'innerBlocks' => [
						[
							'name'       => 'core/heading',
							'attributes' => [
								'level'   => 3,
								'content' => 'Nested heading',
							],
						],
					],
				],
			]
		);

		$this->assertStringContainsString( '<!-- /wp:media-text -->', $result['markup'] );

		$tree = $this->reader->read( $result['markup'] );
		$this->assertCount( 1, $tree[0]['innerBlocks'] );
		$this->assertSame( 'core/heading', $tree[0]['innerBlocks'][0]['name'] );
		$this->assertSame( 'Nested heading', $tree[0]['innerBlocks'][0]['plaintext'] );
	}

	public function test_childless_hybrid_block_self_closes_losslessly(): void {
		// A cover with no children and only JSON-stored attributes carries
		// nothing in its markup, so self-closing loses nothing and must not warn.
		albert_test_register_block_type( 'core/cover', true, [ 'url' => [] ] );

		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'       => 'core/cover',
					'attributes' => [
						'url'      => 'https://example.com/x.jpg',
						'dimRatio' => 50,
					],
				],
			]
		);

		$this->assertStringContainsString( '/-->', $result['markup'] );
		$this->assertStringNotContainsString( '<!-- /wp:cover -->', $result['markup'] );
		$this->assertSame( [], $result['issues'], 'A childless, all-JSON block self-closes losslessly with no issue.' );
	}

	public function test_third_party_block_with_markup_sourced_attribute_is_preserved(): void {
		// A custom block Albert has no template or special knowledge of, whose
		// content lives ONLY in a rich-text (text-sourced) attribute — no
		// plaintext fallback. This is the documented "put text in attributes
		// (content/text/value)" contract. The content must be materialised into
		// innerHTML, not left inert in the comment JSON where the parser ignores
		// it for a markup-sourced attribute and it would be lost on reload.
		albert_test_register_block_type(
			'acme/callout',
			false,
			[ 'text' => [ 'source' => 'rich-text' ] ]
		);

		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'       => 'acme/callout',
					'attributes' => [ 'text' => 'Heads up' ],
				],
			]
		);

		// Not self-closed, and the attribute value lives in the block's markup
		// (not only in the comment JSON, where a markup-sourced attribute is
		// ignored on reload).
		$this->assertStringContainsString( 'Heads up', $result['markup'] );
		$this->assertStringNotContainsString( '/-->', $result['markup'] );
		$this->assertStringContainsString( '<!-- /wp:acme/callout -->', $result['markup'] );
		$this->assertNotEmpty( $this->messages( $result['issues'], 'warning' ) );
		$this->assertSame( [], $this->messages( $result['issues'], 'error' ) );
	}

	public function test_scalar_text_sourced_attribute_is_not_silently_dropped(): void {
		// core/verse stores `content` in its markup (source: html), no template
		// here, no plaintext given. The value must be kept in the markup, not
		// self-closed away with the value inert in the comment JSON.
		albert_test_register_block_type(
			'core/verse',
			false,
			[ 'content' => [ 'source' => 'html' ] ]
		);

		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'       => 'core/verse',
					'attributes' => [ 'content' => 'Roses are red' ],
				],
			]
		);

		$this->assertStringNotContainsString( 'wp:verse {"content":"Roses are red"} /', $result['markup'] );
		$this->assertStringContainsString( 'Roses are red', $result['markup'] );

		// It round-trips as a verse whose plaintext survives.
		$tree = $this->reader->read( $result['markup'] );
		$this->assertSame( 'core/verse', $tree[0]['name'] );
		$this->assertSame( 'Roses are red', $tree[0]['plaintext'] );
	}

	public function test_empty_markup_sourced_attribute_self_closes_losslessly(): void {
		// An empty content attribute stores nothing, so self-closing loses
		// nothing and must not warn or error.
		albert_test_register_block_type(
			'core/verse',
			false,
			[ 'content' => [ 'source' => 'html' ] ]
		);

		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'       => 'core/verse',
					'attributes' => [ 'content' => '' ],
				],
			]
		);

		$this->assertStringContainsString( '/-->', $result['markup'] );
		$this->assertSame( [], $result['issues'] );
	}

	public function test_unreproducible_structural_content_is_refused_not_dropped(): void {
		// A block whose content lives in a structural source (a `query` — nested
		// rows/cells) that this server cannot reproduce as inner text. It must be
		// REFUSED with an error, never self-closed with the content silently lost.
		albert_test_register_block_type(
			'core/table',
			false,
			[ 'body' => [ 'source' => 'query' ] ]
		);

		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'       => 'core/table',
					'attributes' => [ 'body' => [ [ 'cells' => [ [ 'content' => 'A1' ] ] ] ] ],
				],
			]
		);

		$errors = $this->messages( $result['issues'], 'error' );
		$this->assertNotEmpty( $errors, 'Unreproducible structural content must be refused, not dropped.' );
		$this->assertStringContainsString( 'core/table', $errors[0] );
	}

	public function test_inner_blocks_are_never_dropped_under_a_leaf_template(): void {
		// A leaf-templated block (heading) whose template emits no %children%
		// marker, handed inner blocks anyway — e.g. an assistant nesting content
		// under the wrong block. The make_block() safety net must still emit the
		// child rather than silently discard it.
		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'        => 'core/heading',
					'attributes'  => [ 'content' => 'Title' ],
					'innerBlocks' => [
						[
							'name'       => 'core/paragraph',
							'attributes' => [ 'content' => 'Orphaned child' ],
						],
					],
				],
			]
		);

		$this->assertStringContainsString( 'Orphaned child', $result['markup'] );

		$tree = $this->reader->read( $result['markup'] );
		$this->assertCount( 1, $tree[0]['innerBlocks'], 'The nested child must not be dropped by make_block().' );
		$this->assertSame( 'Orphaned child', $tree[0]['innerBlocks'][0]['plaintext'] );
	}

	// Validation issues

	public function test_invalid_heading_level_is_flagged_and_clamped(): void {
		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'       => 'core/heading',
					'attributes' => [ 'level' => 9 ],
					'plaintext'  => 'Bad',
				],
			]
		);

		// A clamp is a recoverable degradation — it is a warning, not an error.
		$warnings = $this->messages( $result['issues'], 'warning' );
		$joined   = implode( "\n", $warnings );
		$this->assertStringContainsString( 'content[0].attributes.level', $joined );
		$this->assertStringContainsString( '1', $joined );
		$this->assertStringContainsString( '6', $joined );
		$this->assertSame( [], $this->messages( $result['issues'], 'error' ) );
		// Should still produce valid markup (clamped).
		$this->assertStringContainsString( '<!-- wp:heading', $result['markup'] );
	}

	public function test_missing_name_is_flagged_as_error(): void {
		$result = $this->serializer->serialize_with_issues(
			[
				[
					'attributes' => [],
					'plaintext'  => 'No name',
				],
			]
		);

		$errors = $this->messages( $result['issues'], 'error' );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'content[0]', $errors[0] );
	}

	public function test_valid_specs_produce_no_issues(): void {
		$result = $this->serializer->serialize_with_issues(
			[
				[
					'name'       => 'core/heading',
					'attributes' => [ 'level' => 2 ],
					'plaintext'  => 'Title',
				],
				[
					'name'      => 'core/paragraph',
					'plaintext' => 'Body text.',
				],
			]
		);

		$this->assertSame( [], $result['issues'] );
	}

	// String input → BlockConverter fallback

	public function test_string_input_routes_through_block_converter(): void {
		$markup = $this->serializer->serialize( '<p>Hello world</p>' );

		$this->assertSame( '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->', $markup );
	}

	public function test_string_input_with_issues_method(): void {
		$result = $this->serializer->serialize_with_issues( '<h2>Title</h2>' );

		$this->assertStringContainsString( '<!-- wp:heading', $result['markup'] );
		$this->assertSame( [], $result['issues'] );
	}

	public function test_string_input_that_is_already_block_markup_passes_through(): void {
		$existing = '<!-- wp:paragraph --><p>Already a block</p><!-- /wp:paragraph -->';
		$markup   = $this->serializer->serialize( $existing );

		// It should remain a single paragraph block, not be double-wrapped in html.
		$tree = $this->reader->read( $markup );
		$this->assertCount( 1, $tree );
		$this->assertSame( 'core/paragraph', $tree[0]['name'] );
		$this->assertSame( 'Already a block', $tree[0]['plaintext'] );
	}

	// Multiple top-level blocks separated correctly

	public function test_multiple_blocks_round_trip_in_order(): void {
		$this->assert_round_trip(
			[
				[
					'name'       => 'core/heading',
					'attributes' => [ 'level' => 2 ],
					'plaintext'  => 'Title',
				],
				[
					'name'      => 'core/paragraph',
					'plaintext' => 'Body',
				],
				[ 'name' => 'core/separator' ],
			],
			[ 'core/heading', 'core/paragraph', 'core/separator' ]
		);
	}

	public function test_html_content_is_escaped_in_paragraph(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'      => 'core/paragraph',
					'plaintext' => 'a < b & c > d',
				],
			]
		);

		$this->assertStringNotContainsString( '<p>a < b', $markup );
		$this->assertStringContainsString( '&lt;', $markup );
	}

	// Rich-text attributes (FIX 1): inline formatting preserved, scripts stripped

	public function test_rich_text_paragraph_attribute_preserves_inline_formatting(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'A <strong>bold</strong> <a href="https://example.com">link</a>.' ],
				],
			]
		);

		// Inline tags must survive (not be double-encoded by esc_html).
		$this->assertStringContainsString( '<strong>bold</strong>', $markup );
		$this->assertStringContainsString( '<a href="https://example.com">link</a>', $markup );
		$this->assertStringNotContainsString( '&lt;strong&gt;', $markup );
	}

	public function test_rich_text_heading_attribute_preserves_inline_formatting(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'       => 'core/heading',
					'attributes' => [
						'level'   => 2,
						'content' => 'Intro with <em>emphasis</em>',
					],
				],
			]
		);

		$this->assertStringContainsString( '<em>emphasis</em>', $markup );
	}

	public function test_rich_text_attribute_strips_script_and_event_handlers(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'       => 'core/paragraph',
					'attributes' => [ 'content' => 'Safe <strong>text</strong><script>alert(1)</script> <a href="javascript:evil()" onclick="evil()">x</a>' ],
				],
			]
		);

		// Assert against the rendered innerHTML body (the block comment legitimately
		// stores the raw attribute JSON; only the visible HTML must be sanitised).
		$this->assertSame( 1, preg_match( '/-->(.*)<!--/s', $markup, $m ) );
		$body = $m[1];

		// The script payload and its contents are gone from the rendered body.
		$this->assertStringNotContainsString( '<script>', $body );
		$this->assertStringNotContainsString( 'alert(1)', $body );

		// Event handlers and javascript: URLs are dropped, but the safe parts stay.
		$this->assertStringNotContainsString( 'onclick', $body );
		$this->assertStringNotContainsString( 'javascript:', $body );
		$this->assertStringContainsString( '<strong>text</strong>', $body );
	}

	public function test_rich_text_button_text_preserves_inline_formatting(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'        => 'core/buttons',
					'innerBlocks' => [
						[
							'name'       => 'core/button',
							'attributes' => [
								'url'  => 'https://example.com',
								'text' => 'Buy <strong>now</strong>',
							],
						],
					],
				],
			]
		);

		$this->assertStringContainsString( 'Buy <strong>now</strong>', $markup );
	}

	public function test_plaintext_fallback_is_html_escaped_not_kses(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'      => 'core/paragraph',
					'plaintext' => 'a <strong>b</strong>',
				],
			]
		);

		// The plaintext fallback is guaranteed plain, so it is fully escaped —
		// the tags are shown verbatim, not interpreted.
		$this->assertStringContainsString( '&lt;strong&gt;', $markup );
		$this->assertStringNotContainsString( '<strong>b</strong>', $markup );
	}

	// Group tagName allowlist (FIX 3)

	public function test_group_tagname_script_is_coerced_to_div(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'        => 'core/group',
					'attributes'  => [ 'tagName' => 'script' ],
					'innerBlocks' => [
						[
							'name'      => 'core/paragraph',
							'plaintext' => 'X',
						],
					],
				],
			]
		);

		$this->assertStringContainsString( '<div class="wp-block-group">', $markup );
		$this->assertStringNotContainsString( '<script class="wp-block-group">', $markup );
	}

	public function test_group_tagname_allowlisted_value_is_kept(): void {
		$markup = $this->serializer->serialize(
			[
				[
					'name'        => 'core/group',
					'attributes'  => [ 'tagName' => 'section' ],
					'innerBlocks' => [
						[
							'name'      => 'core/paragraph',
							'plaintext' => 'X',
						],
					],
				],
			]
		);

		$this->assertStringContainsString( '<section class="wp-block-group">', $markup );
	}
}
