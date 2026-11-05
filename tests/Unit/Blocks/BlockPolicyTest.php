<?php
/**
 * Tests for the BlockPolicy write-time enforcement.
 *
 * Exercises enforce() in isolation: it collects block names (recursively),
 * flags disallowed ones as error issues, honours the exempt list and the
 * `albert/blocks/enforce_allowed` filter, and guards non-array input.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

use Albert\Blocks\BlockPolicy;
use Albert\Blocks\BlockSerializer;
use PHPUnit\Framework\TestCase;

// apply_filters + __; get_allowed_block_types/WP_Block_Editor_Context; registry.
require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';

/**
 * BlockPolicy unit tests.
 */
class BlockPolicyTest extends TestCase {

	/**
	 * Seed a registry and an allowed set where core/list is registered but NOT allowed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_filter_returns'] = [];

		$registered = [ 'core/paragraph', 'core/heading', 'core/columns', 'core/column', 'core/list' ];
		$types      = [];
		foreach ( $registered as $name ) {
			$types[ $name ] = (object) [
				'title'           => $name,
				'category'        => 'text',
				'attributes'      => [],
				'supports'        => [],
				'render_callback' => null,
			];
		}
		$GLOBALS['albert_test_block_types'] = $types;

		// Everything registered except core/list is allowed for this user.
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph', 'core/heading', 'core/columns', 'core/column' ];
	}

	/**
	 * Clean up globals.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['albert_test_filter_returns'],
			$GLOBALS['albert_test_block_types'],
			$GLOBALS['albert_test_allowed_block_types']
		);
		parent::tearDown();
	}

	/**
	 * Allowed blocks produce no issues.
	 *
	 * @return void
	 */
	public function test_allowed_blocks_yield_no_issues(): void {
		$issues = ( new BlockPolicy() )->enforce(
			[
				[ 'name' => 'core/paragraph' ],
				[ 'name' => 'core/heading' ],
			],
			'post'
		);

		$this->assertSame( [], $issues );
	}

	/**
	 * A disallowed block yields one error issue naming the block.
	 *
	 * @return void
	 */
	public function test_disallowed_block_yields_error_issue(): void {
		$issues = ( new BlockPolicy() )->enforce( [ [ 'name' => 'core/list' ] ], 'post' );

		$this->assertCount( 1, $issues );
		$this->assertSame( BlockSerializer::SEVERITY_ERROR, $issues[0]['severity'] );
		$this->assertStringContainsString( 'core/list', $issues[0]['message'] );
	}

	/**
	 * A disallowed block nested inside innerBlocks is detected.
	 *
	 * @return void
	 */
	public function test_nested_disallowed_block_is_detected(): void {
		$issues = ( new BlockPolicy() )->enforce(
			[
				[
					'name'        => 'core/columns',
					'innerBlocks' => [
						[
							'name'        => 'core/column',
							'innerBlocks' => [ [ 'name' => 'core/list' ] ],
						],
					],
				],
			],
			'post'
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'core/list', $issues[0]['message'] );
	}

	/**
	 * A disallowed name in the exempt list is skipped.
	 *
	 * @return void
	 */
	public function test_exempt_names_are_skipped(): void {
		$issues = ( new BlockPolicy() )->enforce( [ [ 'name' => 'core/list' ] ], 'post', null, [ 'core/list' ] );

		$this->assertSame( [], $issues );
	}

	/**
	 * The albert/blocks/enforce_allowed filter set false disables enforcement.
	 *
	 * @return void
	 */
	public function test_filter_false_disables_enforcement(): void {
		$GLOBALS['albert_test_filter_returns']['albert/blocks/enforce_allowed'] = false;

		$issues = ( new BlockPolicy() )->enforce( [ [ 'name' => 'core/list' ] ], 'post' );

		$this->assertSame( [], $issues );
	}

	/**
	 * Non-array input yields no issues.
	 *
	 * @return void
	 */
	public function test_non_array_input_yields_no_issues(): void {
		$this->assertSame( [], ( new BlockPolicy() )->enforce( 'not-an-array', 'post' ) );
	}

	/**
	 * A null post ID yields an empty existing-block-names list.
	 *
	 * @return void
	 */
	public function test_existing_block_names_null_returns_empty(): void {
		$this->assertSame( [], ( new BlockPolicy() )->existing_block_names( null ) );
	}

	/**
	 * Blocks inside a spec's `html` are enforced like innerBlocks.
	 *
	 * @return void
	 */
	public function test_blocks_inside_html_are_enforced(): void {
		$issues = ( new BlockPolicy() )->enforce(
			[
				[
					'name' => 'core/columns',
					'html' => '<div class="wp-block-columns"><!-- wp:list --><ul class="wp-block-list"></ul><!-- /wp:list --></div>',
				],
			],
			'post'
		);

		$this->assertCount( 1, $issues );
		$this->assertStringContainsString( 'core/list', $issues[0]['message'] );
	}
}
