<?php
/**
 * Unit tests for the block-validation save-or-fail gate in the write abilities.
 *
 * Covers Posts and Pages Create/Update: when the serializer reports an
 * error-severity issue the ability must return a WP_Error (code
 * block_validation_failed) and never reach the REST create/update; when only
 * warnings are reported the ability must save and surface the warning messages
 * in `block_issues`.
 *
 * @package Albert\Tests\Unit\Abilities
 */

namespace Albert\Tests\Unit\Abilities;

// WordPress + block stubs (parse_blocks, serialize_blocks, esc_*) and the
// registry stub the serializer consults.
require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';
require_once __DIR__ . '/block-write-rest-stubs.php';

use Albert\Abilities\WordPress\Pages\Create as CreatePage;
use Albert\Abilities\WordPress\Pages\Update as UpdatePage;
use Albert\Abilities\WordPress\Posts\Create as CreatePost;
use Albert\Abilities\WordPress\Posts\Update as UpdatePost;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Block write-ability gate tests.
 */
class BlockWriteAbilityTest extends TestCase {

	/**
	 * Reset recorded REST calls and the registry before each test.
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
			$GLOBALS['albert_test_posts']
		);
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

	// =====================================================================
	// Error path — must NOT save.
	// =====================================================================

	public function test_create_post_with_block_error_returns_wp_error_and_does_not_save(): void {
		$ability = new CreatePost();

		$result = $ability->execute(
			[
				'title'  => 'My post',
				'blocks' => [
					// Unregistered block name with no content → error, dropped.
					[ 'name' => 'core/hero' ],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'core/hero', $result->get_error_message() );

		// No create request was performed.
		$this->assertSame( [], $this->write_routes(), 'The REST create must not run when a block error is present.' );
	}

	public function test_create_page_with_block_error_returns_wp_error_and_does_not_save(): void {
		$ability = new CreatePage();

		$result = $ability->execute(
			[
				'title'  => 'My page',
				'blocks' => [ [ 'name' => 'core/hero' ] ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertSame( [], $this->write_routes() );
	}

	public function test_update_post_with_block_error_returns_wp_error_and_does_not_save(): void {
		$ability = new UpdatePost();

		$result = $ability->execute(
			[
				'id'     => 7,
				'blocks' => [ [ 'name' => 'core/hero' ] ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertSame( [], $this->write_routes(), 'The REST update must not run when a block error is present.' );
	}

	public function test_update_page_with_block_error_returns_wp_error_and_does_not_save(): void {
		$ability = new UpdatePage();

		$result = $ability->execute(
			[
				'id'     => 9,
				'blocks' => [ [ 'name' => 'core/hero' ] ],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertSame( [], $this->write_routes() );
	}

	// =====================================================================
	// Warning-only path — must save and surface warnings.
	// =====================================================================

	public function test_create_post_with_warning_only_saves_and_returns_block_issues(): void {
		$this->queue_write_success(
			[
				'id'     => 123,
				'title'  => [ 'rendered' => 'My post' ],
				'status' => 'draft',
				'link'   => 'https://example.com/?p=123',
			]
		);

		$ability = new CreatePost();

		$result = $ability->execute(
			[
				'title'  => 'My post',
				'blocks' => [
					// A clamped heading level is a recoverable warning.
					[
						'name'       => 'core/heading',
						'attributes' => [
							'level'   => 9,
							'content' => 'Too deep',
						],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 123, $result['id'] );

		// The post was saved (a POST create request ran).
		$this->assertContains( '/wp/v2/posts', $this->write_routes() );

		// Warning messages ride along as plain strings.
		$this->assertArrayHasKey( 'block_issues', $result );
		$this->assertNotEmpty( $result['block_issues'] );
		$this->assertContainsOnly( 'string', $result['block_issues'] );
		$this->assertStringContainsString( 'level', implode( ' ', $result['block_issues'] ) );
	}

	public function test_create_post_with_clean_blocks_saves_without_block_issues(): void {
		$this->queue_write_success(
			[
				'id'     => 200,
				'title'  => [ 'rendered' => 'Clean' ],
				'status' => 'draft',
				'link'   => 'https://example.com/?p=200',
			]
		);

		$ability = new CreatePost();

		$result = $ability->execute(
			[
				'title'  => 'Clean',
				'blocks' => [
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'All good.' ],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 200, $result['id'] );
		$this->assertContains( '/wp/v2/posts', $this->write_routes() );
		$this->assertArrayNotHasKey( 'block_issues', $result, 'Clean blocks must not produce block_issues.' );
	}

	public function test_update_page_with_warning_only_saves_and_returns_block_issues(): void {
		$this->queue_write_success(
			[
				'id'     => 55,
				'title'  => [ 'rendered' => 'Edited page' ],
				'status' => 'publish',
				'link'   => 'https://example.com/edited-page',
			]
		);

		$ability = new UpdatePage();

		$result = $ability->execute(
			[
				'id'     => 55,
				'blocks' => [
					[
						'name'       => 'core/heading',
						'attributes' => [
							'level'   => 9,
							'content' => 'Too deep',
						],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 55, $result['id'] );
		$this->assertContains( '/wp/v2/pages/55', $this->write_routes() );
		$this->assertArrayHasKey( 'block_issues', $result );
		$this->assertNotEmpty( $result['block_issues'] );
	}
}
