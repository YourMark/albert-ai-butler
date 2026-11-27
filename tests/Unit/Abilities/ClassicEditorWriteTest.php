<?php
/**
 * Unit tests for the classic-editor branch in the write abilities.
 *
 * Covers Posts and Pages Create/Update. For a classic-editor target the
 * abilities must (1) reject a non-empty structured `blocks` field with a
 * classic_editor_blocks_unsupported WP_Error and perform no REST write, and
 * (2) pass a raw `content` HTML string straight through to the REST endpoint
 * without any block serialization or allowed-block enforcement. Block-editor
 * targets keep their existing serializer behaviour.
 *
 * @package Albert\Tests\Unit\Abilities
 */

namespace Albert\Tests\Unit\Abilities;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';
require_once __DIR__ . '/block-write-rest-stubs.php';

use Albert\Abilities\WordPress\Pages\Create as CreatePage;
use Albert\Abilities\WordPress\Pages\Update as UpdatePage;
use Albert\Abilities\WordPress\Posts\Create as CreatePost;
use Albert\Abilities\WordPress\Posts\Update as UpdatePost;
use Albert\Blocks\EditorMode;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Classic-editor write-ability tests.
 */
class ClassicEditorWriteTest extends TestCase {

	private const RAW_HTML = '<h2>Hello</h2><p>Plain classic HTML, no block comments.</p>';

	/**
	 * Reset recorders and force the classic editor for all targets.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_rest_calls']     = [];
		$GLOBALS['albert_test_rest_responses'] = [];

		// The update abilities refuse an id that is not an existing post or page.
		$GLOBALS['albert_test_posts'] = [
			7  => (object) [
				'ID'           => 7,
				'post_type'    => 'post',
				'post_content' => '',
			],
			9  => (object) [
				'ID'           => 9,
				'post_type'    => 'page',
				'post_content' => '',
			],
			55 => (object) [
				'ID'           => 55,
				'post_type'    => 'page',
				'post_content' => '',
			],
		];

		// Both post types use the classic editor for these tests.
		$GLOBALS['albert_test_block_editor_post_types'] = [
			'post' => false,
			'page' => false,
		];
		unset( $GLOBALS['albert_test_block_editor_posts'] );
		EditorMode::reset_cache();

		albert_test_register_default_block_types();
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['albert_test_block_types'],
			$GLOBALS['albert_test_rest_calls'],
			$GLOBALS['albert_test_rest_responses'],
			$GLOBALS['albert_test_posts'],
			$GLOBALS['albert_test_block_editor_post_types'],
			$GLOBALS['albert_test_block_editor_posts']
		);
		EditorMode::reset_cache();
		parent::tearDown();
	}

	/**
	 * Record a successful write response for the next mutating REST request.
	 *
	 * @param array<string, mixed> $data Response body the ability should see.
	 * @return void
	 */
	private function queue_write_success( array $data ): void {
		$GLOBALS['albert_test_rest_responses']['write'] = [
			'is_error' => false,
			'data'     => $data,
		];
	}

	/**
	 * Get the recorded mutating (POST) REST routes.
	 *
	 * @return array<int, string>
	 */
	private function write_routes(): array {
		$routes = [];
		foreach ( (array) $GLOBALS['albert_test_rest_calls'] as $call ) {
			if ( $call['method'] === 'POST' ) {
				$routes[] = $call['route'];
			}
		}

		return $routes;
	}

	/**
	 * Get the `content` param of the last recorded mutating (POST) REST request.
	 *
	 * @return string|null
	 */
	private function last_write_content(): ?string {
		$content = null;
		foreach ( (array) $GLOBALS['albert_test_rest_calls'] as $call ) {
			if ( $call['method'] === 'POST' && array_key_exists( 'content', $call['params'] ?? [] ) ) {
				$content = $call['params']['content'];
			}
		}

		return $content;
	}

	// =====================================================================
	// Classic target + structured blocks → rejected, no write.
	// =====================================================================

	public function test_create_post_classic_with_blocks_returns_error_and_does_not_save(): void {
		$result = ( new CreatePost() )->execute(
			[
				'title'  => 'My post',
				'blocks' => [
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'Hi.' ],
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'classic_editor_blocks_unsupported', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assertStringContainsString( '`content`', $result->get_error_message() );
		$this->assertSame( [], $this->write_routes(), 'No REST write may run for a rejected classic blocks payload.' );
	}

	public function test_create_page_classic_with_blocks_returns_error_and_does_not_save(): void {
		$result = ( new CreatePage() )->execute(
			[
				'title'  => 'My page',
				'blocks' => [
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'Hi.' ],
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'classic_editor_blocks_unsupported', $result->get_error_code() );
		$this->assertSame( [], $this->write_routes() );
	}

	public function test_update_post_classic_with_blocks_returns_error_and_does_not_save(): void {
		$result = ( new UpdatePost() )->execute(
			[
				'id'     => 7,
				'blocks' => [
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'Hi.' ],
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'classic_editor_blocks_unsupported', $result->get_error_code() );
		$this->assertSame( [], $this->write_routes() );
	}

	public function test_update_page_classic_with_blocks_returns_error_and_does_not_save(): void {
		$result = ( new UpdatePage() )->execute(
			[
				'id'     => 9,
				'blocks' => [
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'Hi.' ],
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'classic_editor_blocks_unsupported', $result->get_error_code() );
		$this->assertSame( [], $this->write_routes() );
	}

	// =====================================================================
	// Classic target + content string → raw HTML passed through.
	// =====================================================================

	public function test_create_post_classic_with_content_saves_raw_html(): void {
		$this->queue_write_success(
			[
				'id'     => 321,
				'title'  => [ 'rendered' => 'My post' ],
				'status' => 'draft',
				'link'   => 'https://example.com/?p=321',
			]
		);

		$result = ( new CreatePost() )->execute(
			[
				'title'   => 'My post',
				'content' => self::RAW_HTML,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 321, $result['id'] );
		$this->assertContains( '/wp/v2/posts', $this->write_routes() );

		// The raw HTML is passed through verbatim — no block markup added.
		$this->assertSame( self::RAW_HTML, $this->last_write_content() );
		$this->assertStringNotContainsString( '<!-- wp:', (string) $this->last_write_content() );
		$this->assertArrayNotHasKey( 'block_issues', $result );
	}

	public function test_create_page_classic_with_content_saves_raw_html(): void {
		$this->queue_write_success(
			[
				'id'     => 654,
				'title'  => [ 'rendered' => 'My page' ],
				'status' => 'draft',
				'link'   => 'https://example.com/?p=654',
			]
		);

		$result = ( new CreatePage() )->execute(
			[
				'title'   => 'My page',
				'content' => self::RAW_HTML,
			]
		);

		$this->assertIsArray( $result );
		$this->assertContains( '/wp/v2/pages', $this->write_routes() );
		$this->assertSame( self::RAW_HTML, $this->last_write_content() );
		$this->assertStringNotContainsString( '<!-- wp:', (string) $this->last_write_content() );
	}

	public function test_update_post_classic_with_content_saves_raw_html(): void {
		$this->queue_write_success(
			[
				'id'     => 7,
				'title'  => [ 'rendered' => 'Edited' ],
				'status' => 'publish',
				'link'   => 'https://example.com/?p=7',
			]
		);

		$result = ( new UpdatePost() )->execute(
			[
				'id'      => 7,
				'content' => self::RAW_HTML,
			]
		);

		$this->assertIsArray( $result );
		$this->assertContains( '/wp/v2/posts/7', $this->write_routes() );
		$this->assertSame( self::RAW_HTML, $this->last_write_content() );
		$this->assertStringNotContainsString( '<!-- wp:', (string) $this->last_write_content() );
	}

	public function test_update_page_classic_with_content_saves_raw_html(): void {
		$this->queue_write_success(
			[
				'id'     => 9,
				'title'  => [ 'rendered' => 'Edited' ],
				'status' => 'publish',
				'link'   => 'https://example.com/?p=9',
			]
		);

		$result = ( new UpdatePage() )->execute(
			[
				'id'      => 9,
				'content' => self::RAW_HTML,
			]
		);

		$this->assertIsArray( $result );
		$this->assertContains( '/wp/v2/pages/9', $this->write_routes() );
		$this->assertSame( self::RAW_HTML, $this->last_write_content() );
		$this->assertStringNotContainsString( '<!-- wp:', (string) $this->last_write_content() );
	}

	// =====================================================================
	// Block target → existing serializer behaviour preserved.
	// =====================================================================

	public function test_create_post_block_target_still_serializes_blocks(): void {
		// Override the classic default: this post type uses the block editor.
		$GLOBALS['albert_test_block_editor_post_types']['post'] = true;
		EditorMode::reset_cache();

		$this->queue_write_success(
			[
				'id'     => 999,
				'title'  => [ 'rendered' => 'Block post' ],
				'status' => 'draft',
				'link'   => 'https://example.com/?p=999',
			]
		);

		$result = ( new CreatePost() )->execute(
			[
				'title'  => 'Block post',
				'blocks' => [
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'Serialized.' ],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 999, $result['id'] );
		$this->assertContains( '/wp/v2/posts', $this->write_routes() );

		// Block markup was produced by the serializer.
		$this->assertStringContainsString( '<!-- wp:paragraph', (string) $this->last_write_content() );
	}
}
