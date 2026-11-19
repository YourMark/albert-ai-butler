<?php
/**
 * Unit tests for write-time allowed-block enforcement in the write abilities.
 *
 * Covers Posts and Pages Create/Update: when a submitted block (at any nesting
 * depth) is not in the current user's allowed set the ability must return a
 * WP_Error (code block_validation_failed) and never reach the REST
 * create/update; an allowed write saves normally; on Update a block already
 * present in the existing post is exempt even when now disallowed; and the
 * `albert/blocks/enforce_allowed` filter set to false skips enforcement.
 *
 * The allowed set is driven by the $GLOBALS['albert_test_allowed_block_types']
 * stub the catalog consults.
 *
 * @package Albert\Tests\Unit\Abilities
 */

namespace Albert\Tests\Unit\Abilities;

// WordPress + block stubs (parse_blocks, serialize_blocks, esc_*, apply_filters,
// get_post, get_allowed_block_types) and the registry stub.
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
 * Write-time allowed-block enforcement tests.
 */
class BlockEnforcementTest extends TestCase {

	/**
	 * Reset recorded REST calls, the registry and the allow-list before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_rest_calls']     = [];
		$GLOBALS['albert_test_rest_responses'] = [];
		$GLOBALS['albert_test_hooks']          = [];
		$GLOBALS['albert_test_posts']          = [];

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
			$GLOBALS['albert_test_allowed_block_types'],
			$GLOBALS['albert_test_rest_calls'],
			$GLOBALS['albert_test_rest_responses'],
			$GLOBALS['albert_test_filter_returns'],
			$GLOBALS['albert_test_posts'],
			$GLOBALS['albert_test_hooks']
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
	// Disallowed block — must NOT save.
	// =====================================================================

	public function test_create_post_with_disallowed_block_returns_wp_error_and_does_not_save(): void {
		// Only paragraph is allowed; core/heading is registered but not allowed.
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph' ];

		$ability = new CreatePost();

		$result = $ability->execute(
			[
				'title'  => 'My post',
				'blocks' => [
					[
						'name'       => 'core/heading',
						'attributes' => [
							'level'   => 2,
							'content' => 'Nope',
						],
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'core/heading', $result->get_error_message() );
		$this->assertSame( [], $this->write_routes(), 'No create must run when a block is disallowed.' );
	}

	public function test_create_post_with_disallowed_nested_block_returns_wp_error_and_does_not_save(): void {
		// Columns/column allowed, but the nested paragraph is not.
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/columns', 'core/column' ];

		$ability = new CreatePost();

		$result = $ability->execute(
			[
				'title'  => 'Nested',
				'blocks' => [
					[
						'name'        => 'core/columns',
						'innerBlocks' => [
							[
								'name'        => 'core/column',
								'innerBlocks' => [
									[
										'name'       => 'core/paragraph',
										'attributes' => [ 'content' => 'Deep' ],
									],
								],
							],
						],
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'core/paragraph', $result->get_error_message() );
		$this->assertSame( [], $this->write_routes() );
	}

	public function test_create_page_with_disallowed_block_returns_wp_error_and_does_not_save(): void {
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph' ];

		$ability = new CreatePage();

		$result = $ability->execute(
			[
				'title'  => 'My page',
				'blocks' => [
					[
						'name'       => 'core/heading',
						'attributes' => [ 'content' => 'Nope' ],
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'core/heading', $result->get_error_message() );
		$this->assertSame( [], $this->write_routes() );
	}

	public function test_update_post_with_disallowed_new_block_returns_wp_error_and_does_not_save(): void {
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph' ];

		$ability = new UpdatePost();

		$result = $ability->execute(
			[
				'id'     => 7,
				'blocks' => [
					[
						'name'       => 'core/heading',
						'attributes' => [ 'content' => 'Nope' ],
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertSame( [], $this->write_routes(), 'No update must run when a block is disallowed.' );
	}

	// =====================================================================
	// Allowed write — must save normally.
	// =====================================================================

	public function test_create_post_with_allowed_blocks_saves_normally(): void {
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph', 'core/heading' ];
		$this->queue_write_success(
			[
				'id'     => 321,
				'title'  => [ 'rendered' => 'Allowed' ],
				'status' => 'draft',
				'link'   => 'https://example.com/?p=321',
			]
		);

		$ability = new CreatePost();

		$result = $ability->execute(
			[
				'title'  => 'Allowed',
				'blocks' => [
					[
						'name'       => 'core/heading',
						'attributes' => [
							'level'   => 2,
							'content' => 'Hi',
						],
					],
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'Body.' ],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 321, $result['id'] );
		$this->assertContains( '/wp/v2/posts', $this->write_routes() );
		$this->assertArrayNotHasKey( 'block_issues', $result );
	}

	// =====================================================================
	// Update exemption — block already present in the post is grandfathered.
	// =====================================================================

	public function test_update_post_exempts_block_already_present_in_existing_content(): void {
		// core/heading is no longer allowed, but the existing post already uses it.
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph' ];
		$GLOBALS['albert_test_posts'][7]            = (object) [
			'ID'           => 7,
			'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Existing</h2><!-- /wp:heading -->',
		];
		$this->queue_write_success(
			[
				'id'     => 7,
				'title'  => [ 'rendered' => 'Edited' ],
				'status' => 'publish',
				'link'   => 'https://example.com/?p=7',
			]
		);

		$ability = new UpdatePost();

		// Resubmit the same (now-disallowed) heading plus an allowed paragraph.
		$result = $ability->execute(
			[
				'id'     => 7,
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
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 7, $result['id'] );
		$this->assertContains( '/wp/v2/posts/7', $this->write_routes(), 'Exempt legacy block must not block the update.' );
	}

	public function test_update_post_does_not_exempt_a_new_disallowed_block_alongside_existing(): void {
		// Existing content uses core/heading; the new submission adds a disallowed
		// core/quote that is NOT in the existing content, so it is still rejected.
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph' ];
		$GLOBALS['albert_test_posts'][8]            = (object) [
			'ID'           => 8,
			'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Existing</h2><!-- /wp:heading -->',
		];
		$ability                                    = new UpdatePost();

		$result = $ability->execute(
			[
				'id'     => 8,
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
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'core/quote', $result->get_error_message() );
		$this->assertStringNotContainsString( 'core/heading', $result->get_error_message() );
		$this->assertSame( [], $this->write_routes() );
	}

	public function test_update_page_exempts_block_already_present_in_existing_content(): void {
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph' ];
		$GLOBALS['albert_test_posts'][55]           = (object) [
			'ID'           => 55,
			'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">Existing</h2><!-- /wp:heading -->',
		];
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
						'attributes' => [ 'content' => 'Existing' ],
					],
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'New body.' ],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 55, $result['id'] );
		$this->assertContains( '/wp/v2/pages/55', $this->write_routes() );
	}

	// =====================================================================
	// Filter off — enforcement skipped entirely.
	// =====================================================================

	public function test_filter_false_skips_enforcement(): void {
		$GLOBALS['albert_test_allowed_block_types']                             = [ 'core/paragraph' ];
		$GLOBALS['albert_test_filter_returns']['albert/blocks/enforce_allowed'] = false;
		$this->queue_write_success(
			[
				'id'     => 999,
				'title'  => [ 'rendered' => 'Unenforced' ],
				'status' => 'draft',
				'link'   => 'https://example.com/?p=999',
			]
		);

		$ability = new CreatePost();

		// core/heading is disallowed, but enforcement is off, so it saves.
		$result = $ability->execute(
			[
				'title'  => 'Unenforced',
				'blocks' => [
					[
						'name'       => 'core/heading',
						'attributes' => [ 'content' => 'Allowed through' ],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 999, $result['id'] );
		$this->assertContains( '/wp/v2/posts', $this->write_routes(), 'With the filter off the write must proceed.' );
	}
}
