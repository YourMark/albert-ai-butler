<?php
/**
 * Unit tests for the WriteContentResolver.
 *
 * The resolver is the shared write-content decision behind Posts and Pages
 * Create/Update: it detects the editor for the target, rejects structured
 * `blocks` on a classic-editor target, serializes `blocks`/`content` to valid
 * block markup on a block-editor target, enforces the allowed-block set (with an
 * Update exemption for blocks already present in the post), and either aborts
 * with a WP_Error or returns the markup plus its non-fatal warnings.
 *
 * @package Albert\Tests\Unit\Blocks
 */

namespace Albert\Tests\Unit\Blocks;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';

use Albert\Blocks\EditorMode;
use Albert\Blocks\WriteContentResolver;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * WriteContentResolver unit tests.
 */
class WriteContentResolverTest extends TestCase {

	/**
	 * Seed the registry, allow-list and editor globals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_filter_returns'] = [];
		$GLOBALS['albert_test_posts']          = [];

		albert_test_register_default_block_types();

		// Default: post type uses the block editor unless a test overrides it.
		$GLOBALS['albert_test_block_editor_post_types'] = [ 'post' => true ];
		unset( $GLOBALS['albert_test_block_editor_posts'], $GLOBALS['albert_test_allowed_block_types'] );
		EditorMode::reset_cache();
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['albert_test_block_types'],
			$GLOBALS['albert_test_allowed_block_types'],
			$GLOBALS['albert_test_filter_returns'],
			$GLOBALS['albert_test_posts'],
			$GLOBALS['albert_test_block_editor_post_types'],
			$GLOBALS['albert_test_block_editor_posts']
		);
		EditorMode::reset_cache();
		parent::tearDown();
	}

	// =====================================================================
	// Classic-editor target.
	// =====================================================================

	public function test_classic_with_blocks_returns_unsupported_error(): void {
		$GLOBALS['albert_test_block_editor_post_types'] = [ 'post' => false ];
		EditorMode::reset_cache();

		$result = ( new WriteContentResolver() )->resolve(
			[
				'blocks' => [
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'Hi.' ],
					],
				],
			],
			'post'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'classic_editor_blocks_unsupported', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assertStringContainsString( '`content`', $result->get_error_message() );
		$this->assertStringContainsString( 'post', $result->get_error_message() );
	}

	public function test_classic_with_content_passes_raw_html_through_without_block_issues(): void {
		$GLOBALS['albert_test_block_editor_post_types'] = [ 'page' => false ];
		EditorMode::reset_cache();

		$html = '<h2>Hello</h2><p>Plain classic HTML, no block comments.</p>';

		$result = ( new WriteContentResolver() )->resolve( [ 'content' => $html ], 'page' );

		$this->assertIsArray( $result );
		$this->assertSame( $html, $result['content'] );
		$this->assertStringNotContainsString( '<!-- wp:', $result['content'] );
		$this->assertSame( [], $result['block_issues'] );
	}

	// =====================================================================
	// Block-editor target.
	// =====================================================================

	public function test_block_with_valid_blocks_returns_serialized_markup(): void {
		$result = ( new WriteContentResolver() )->resolve(
			[
				'blocks' => [
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'Serialized.' ],
					],
				],
			],
			'post'
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( '<!-- wp:paragraph', $result['content'] );
		$this->assertStringContainsString( 'Serialized.', $result['content'] );
		$this->assertSame( [], $result['block_issues'] );
	}

	public function test_block_surfaces_warning_issues_while_still_saving(): void {
		// core/image with no url is a recoverable degradation: a warning rides
		// along in block_issues but the markup is still produced.
		$result = ( new WriteContentResolver() )->resolve(
			[
				'blocks' => [
					[
						'name'       => 'core/image',
						'attributes' => [ 'alt' => 'Missing url' ],
					],
				],
			],
			'post'
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( '<!-- wp:image', $result['content'] );
		$this->assertNotSame( [], $result['block_issues'] );
		$this->assertStringContainsString( 'url is required', implode( ' ', $result['block_issues'] ) );
	}

	public function test_block_with_disallowed_block_returns_validation_error(): void {
		// Only paragraph is allowed; core/heading is registered but not allowed.
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph' ];

		$result = ( new WriteContentResolver() )->resolve(
			[
				'blocks' => [
					[
						'name'       => 'core/heading',
						'attributes' => [
							'level'   => 2,
							'content' => 'Nope',
						],
					],
				],
			],
			'post'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'core/heading', $result->get_error_message() );
	}

	public function test_block_string_content_is_converted_to_block_markup(): void {
		$result = ( new WriteContentResolver() )->resolve(
			[ 'content' => '<!-- wp:paragraph --><p>Existing block markup.</p><!-- /wp:paragraph -->' ],
			'post'
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( '<!-- wp:paragraph', $result['content'] );
		$this->assertSame( [], $result['block_issues'] );
	}

	// =====================================================================
	// Update exemption — a now-disallowed block already in the post is allowed.
	// =====================================================================

	public function test_update_exempts_block_already_present_in_existing_content(): void {
		// core/heading is no longer allowed, but the existing post already uses it.
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph' ];
		$GLOBALS['albert_test_posts'][7]            = (object) [
			'ID'           => 7,
			'post_type'    => 'post',
			'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Existing</h2><!-- /wp:heading -->',
		];

		$result = ( new WriteContentResolver() )->resolve(
			[
				'blocks' => [
					[
						'name'       => 'core/heading',
						'attributes' => [
							'level'   => 2,
							'content' => 'Existing',
						],
					],
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'New body.' ],
					],
				],
			],
			'post',
			7
		);

		$this->assertIsArray( $result, 'The grandfathered block must not abort the resolve.' );
		$this->assertStringContainsString( '<!-- wp:heading', $result['content'] );
		$this->assertStringContainsString( '<!-- wp:paragraph', $result['content'] );
	}

	public function test_update_does_not_exempt_a_new_disallowed_block(): void {
		// Existing content uses core/heading; the new submission adds a disallowed
		// core/quote that is NOT in the existing content, so it is still rejected.
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph' ];
		$GLOBALS['albert_test_posts'][8]            = (object) [
			'ID'           => 8,
			'post_type'    => 'post',
			'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Existing</h2><!-- /wp:heading -->',
		];

		$result = ( new WriteContentResolver() )->resolve(
			[
				'blocks' => [
					[
						'name'       => 'core/heading',
						'attributes' => [ 'content' => 'Existing' ],
					],
					[
						'name'       => 'core/quote',
						'attributes' => [],
					],
				],
			],
			'post',
			8
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'core/quote', $result->get_error_message() );
		$this->assertStringNotContainsString( 'core/heading', $result->get_error_message() );
	}
}
