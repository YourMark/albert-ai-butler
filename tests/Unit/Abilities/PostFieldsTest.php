<?php
/**
 * Unit tests for the post fields on the post and page write abilities.
 *
 * @package Albert\Tests\Unit\Abilities
 */

namespace Albert\Tests\Unit\Abilities;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/wordpress-site.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';
require_once __DIR__ . '/block-write-rest-stubs.php';
require_once __DIR__ . '/read-ability-stubs.php';

use Albert\Abilities\WordPress\Pages\Create as CreatePage;
use Albert\Abilities\WordPress\Pages\ViewPage;
use Albert\Abilities\WordPress\PostFields;
use Albert\Abilities\WordPress\Posts\Update as UpdatePost;
use Albert\Abilities\WordPress\Posts\ViewPost;
use Albert\Blocks\EditorMode;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Post fields tests.
 *
 * @covers \Albert\Abilities\WordPress\PostFields
 */
class PostFieldsTest extends TestCase {

	/**
	 * Users, posts and the theme the tests write against.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_user_id']        = 1;
		$GLOBALS['albert_test_caps']           = [ 'edit_posts', 'edit_pages', 'edit_others_posts', 'edit_others_pages' ];
		$GLOBALS['albert_test_users']          = [
			1 => [
				'display_name' => 'Admin',
				'caps'         => [ 'edit_posts', 'edit_pages' ],
			],
			2 => [
				'display_name' => 'Editor',
				'caps'         => [ 'edit_posts', 'edit_pages' ],
			],
			3 => [
				'display_name' => 'Subscriber',
				'caps'         => [ 'read' ],
			],
		];
		$GLOBALS['albert_test_posts']          = [
			10 => (object) [
				'ID'        => 10,
				'post_type' => 'page',
			],
			11 => (object) [
				'ID'        => 11,
				'post_type' => 'page',
			],
			20 => (object) [
				'ID'        => 20,
				'post_type' => 'post',
			],
		];
		$GLOBALS['albert_test_ancestors']      = [ 11 => [ 10 ] ];
		$GLOBALS['albert_test_site']           = [
			'page_templates' => [
				'page' => [ 'templates/wide.php' => 'Wide' ],
				'post' => [],
			],
		];
		$GLOBALS['albert_test_rest_calls']     = [];
		$GLOBALS['albert_test_rest_responses'] = [];

		albert_test_register_default_block_types();
		EditorMode::reset_cache();
	}

	/**
	 * Clear the globals the tests set.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['albert_test_caps'],
			$GLOBALS['albert_test_users'],
			$GLOBALS['albert_test_posts'],
			$GLOBALS['albert_test_ancestors'],
			$GLOBALS['albert_test_site'],
			$GLOBALS['albert_test_post_formats'],
			$GLOBALS['albert_test_sticky'],
			$GLOBALS['albert_test_rest_calls'],
			$GLOBALS['albert_test_rest_responses'],
			$GLOBALS['albert_test_block_types']
		);
		parent::tearDown();
	}

	/**
	 * Validate post fields.
	 *
	 * @param array<string, mixed> $args    Input.
	 * @param int                  $post_id Post being updated.
	 * @return array<string, mixed>|WP_Error
	 */
	private function post( array $args, int $post_id = 0 ): array|WP_Error {
		return PostFields::to_rest( $args, PostFields::POST, 'post', $post_id );
	}

	/**
	 * Validate page fields.
	 *
	 * @param array<string, mixed> $args    Input.
	 * @param int                  $post_id Page being updated.
	 * @return array<string, mixed>|WP_Error
	 */
	private function page( array $args, int $post_id = 0 ): array|WP_Error {
		return PostFields::to_rest( $args, PostFields::PAGE, 'page', $post_id );
	}

	/**
	 * Assert a refusal with the given code.
	 *
	 * @param string $code   Expected code.
	 * @param mixed  $result Result.
	 * @return void
	 */
	private function assertRefused( string $code, mixed $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function test_only_supplied_fields_are_sent(): void {
		$this->assertSame( [], $this->post( [ 'title' => 'x' ] ) );
		$this->assertSame( [ 'comment_status' => 'closed' ], $this->post( [ 'comment_status' => 'closed' ] ) );
	}

	public function test_fields_the_type_does_not_have_are_ignored(): void {
		$this->assertSame(
			[],
			$this->page(
				[
					'sticky' => true,
					'format' => 'aside',
				]
			)
		);
		$this->assertSame(
			[],
			$this->post(
				[
					'parent'     => 10,
					'menu_order' => 3,
				]
			)
		);
	}

	public function test_slug_is_cleaned(): void {
		$this->assertSame( [ 'slug' => 'summer-sale' ], $this->post( [ 'slug' => 'Summer Sale' ] ) );
	}

	public function test_slug_that_cleans_to_nothing_is_refused(): void {
		$this->assertRefused( 'invalid_slug', $this->post( [ 'slug' => '!!!' ] ) );
		$this->assertRefused( 'invalid_slug', $this->post( [ 'slug' => 42 ] ) );
	}

	public function test_dates_in_local_time_and_with_an_offset_are_accepted(): void {
		$this->assertSame( [ 'date' => '2026-12-01T09:00:00' ], $this->post( [ 'date' => '2026-12-01T09:00:00' ] ) );
		$this->assertSame( [ 'date' => '2026-12-01 09:00:00' ], $this->post( [ 'date' => '2026-12-01 09:00:00' ] ) );
		$this->assertSame( [ 'date' => '2026-12-01T09:00:00+02:00' ], $this->post( [ 'date' => '2026-12-01T09:00:00+02:00' ] ) );
		$this->assertSame( [ 'date' => '2026-12-01T07:00:00Z' ], $this->post( [ 'date' => '2026-12-01T07:00:00Z' ] ) );
		$this->assertSame( [ 'date' => '2026-12-01T09:00:00+02' ], $this->post( [ 'date' => '2026-12-01T09:00:00+02' ] ) );
	}

	/**
	 * Dates that are refused.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalid_dates(): array {
		return [
			'words'           => [ 'next tuesday' ],
			'date only'       => [ '2026-12-01' ],
			'no such day'     => [ '2026-02-30T09:00:00' ],
			'no such hour'    => [ '2026-12-01T24:00:00' ],
			'no such minute'  => [ '2026-12-01T09:60:00' ],
			'day first'       => [ '01-12-2026 09:00:00' ],
			'offset no colon' => [ '2026-12-01T09:00:00+0200' ],
			'not a string'    => [ 1764579600 ],
		];
	}

	/**
	 * A date that is not a real date and time is refused.
	 *
	 * @dataProvider invalid_dates
	 *
	 * @param mixed $date Date.
	 * @return void
	 */
	public function test_invalid_dates_are_refused( mixed $date ): void {
		$this->assertRefused( 'invalid_date', $this->post( [ 'date' => $date ] ) );
	}

	public function test_author_who_can_edit_is_accepted(): void {
		$this->assertSame( [ 'author' => 2 ], $this->post( [ 'author' => 2 ] ) );
	}

	public function test_unknown_author_is_refused(): void {
		$this->assertRefused( 'invalid_author', $this->post( [ 'author' => 99 ] ) );
	}

	public function test_author_who_cannot_edit_the_type_is_refused(): void {
		$result = $this->post( [ 'author' => 3 ] );

		$this->assertRefused( 'author_cannot_edit', $result );
		$this->assertStringContainsString( 'Subscriber', $result->get_error_message() );
	}

	public function test_assigning_someone_else_needs_edit_others(): void {
		$GLOBALS['albert_test_caps'] = [ 'edit_posts' ];

		$this->assertRefused( 'author_permission_denied', $this->post( [ 'author' => 2 ] ) );
		$this->assertRefused( 'author_permission_denied', $this->post( [ 'author' => 3 ] ) );
		$this->assertSame( [ 'author' => 1 ], $this->post( [ 'author' => 1 ] ) );
	}

	public function test_page_author_is_checked_against_page_capabilities(): void {
		$GLOBALS['albert_test_users'][2]['caps'] = [ 'edit_posts' ];

		$this->assertRefused( 'author_cannot_edit', $this->page( [ 'author' => 2 ] ) );
	}

	public function test_parent_page_is_accepted_and_zero_means_top_level(): void {
		$this->assertSame( [ 'parent' => 10 ], $this->page( [ 'parent' => 10 ] ) );
		$this->assertSame( [ 'parent' => 0 ], $this->page( [ 'parent' => 0 ] ) );
		$this->assertSame( [ 'parent' => 10 ], $this->page( [ 'parent' => '10' ] ) );
	}

	public function test_missing_or_wrong_type_parent_is_refused(): void {
		$this->assertRefused( 'invalid_parent', $this->page( [ 'parent' => 999 ] ) );
		$this->assertRefused( 'invalid_parent', $this->page( [ 'parent' => 20 ] ) );
		$this->assertRefused( 'invalid_parent', $this->page( [ 'parent' => -1 ] ) );
	}

	public function test_parent_that_makes_a_loop_is_refused(): void {
		$this->assertRefused( 'parent_loop', $this->page( [ 'parent' => 10 ], 10 ) );
		$this->assertRefused( 'parent_loop', $this->page( [ 'parent' => 11 ], 10 ) );
	}

	public function test_menu_order_must_be_a_whole_number(): void {
		$this->assertSame( [ 'menu_order' => 3 ], $this->page( [ 'menu_order' => 3 ] ) );
		$this->assertSame( [ 'menu_order' => 3 ], $this->page( [ 'menu_order' => '3' ] ) );
		$this->assertRefused( 'invalid_menu_order', $this->page( [ 'menu_order' => 'third' ] ) );
	}

	public function test_template_the_theme_offers_is_accepted(): void {
		$this->assertSame( [ 'template' => 'templates/wide.php' ], $this->page( [ 'template' => 'templates/wide.php' ] ) );
		$this->assertSame( [ 'template' => '' ], $this->page( [ 'template' => '' ] ) );
	}

	public function test_unknown_template_is_refused_with_the_choices(): void {
		$result = $this->page( [ 'template' => 'templates/missing.php' ] );

		$this->assertRefused( 'invalid_template', $result );
		$this->assertStringContainsString( 'templates/wide.php', $result->get_error_message() );
	}

	public function test_a_template_that_is_not_a_string_is_refused(): void {
		$result = $this->page( [ 'template' => 5 ] );

		$this->assertRefused( 'invalid_template', $result );
		$this->assertStringContainsString( 'template name', $result->get_error_message() );
	}

	public function test_template_is_refused_when_the_theme_offers_none(): void {
		$result = $this->post( [ 'template' => 'templates/wide.php' ] );

		$this->assertRefused( 'invalid_template', $result );
		$this->assertStringContainsString( 'offers no templates', $result->get_error_message() );
	}

	public function test_the_current_template_is_accepted_after_the_theme_drops_it(): void {
		$GLOBALS['albert_test_posts'][20]->page_template = 'templates/old.php';

		$this->assertSame( [ 'template' => 'templates/old.php' ], $this->post( [ 'template' => 'templates/old.php' ], 20 ) );
		$this->assertRefused( 'invalid_template', $this->post( [ 'template' => 'templates/old.php' ] ) );
	}

	public function test_comment_status_is_open_or_closed(): void {
		$this->assertSame( [ 'comment_status' => 'open' ], $this->page( [ 'comment_status' => 'open' ] ) );
		$this->assertRefused( 'invalid_comment_status', $this->page( [ 'comment_status' => 'registered_only' ] ) );
	}

	public function test_sticky_must_be_a_boolean(): void {
		$this->assertSame( [ 'sticky' => false ], $this->post( [ 'sticky' => false ] ) );
		$this->assertRefused( 'invalid_sticky', $this->post( [ 'sticky' => 'yes' ] ) );
	}

	public function test_format_must_be_standard_or_supported_by_the_theme(): void {
		$this->assertSame( [ 'format' => 'standard' ], $this->post( [ 'format' => 'standard' ] ) );
		$this->assertRefused( 'invalid_format', $this->post( [ 'format' => 'aside' ] ) );

		$GLOBALS['albert_test_post_formats'] = [ 'aside', 'gallery' ];

		$this->assertSame( [ 'format' => 'aside' ], $this->post( [ 'format' => 'aside' ] ) );
	}

	public function test_update_post_sends_the_fields_to_rest(): void {
		$GLOBALS['albert_test_rest_responses']['write'] = [
			'is_error' => false,
			'data'     => [
				'id'     => 20,
				'status' => 'future',
				'slug'   => 'summer-sale',
				'date'   => '2026-12-01T09:00:00',
			],
		];

		$result = ( new UpdatePost() )->execute(
			[
				'id'     => 20,
				'slug'   => 'summer-sale',
				'date'   => '2026-12-01T09:00:00',
				'author' => 2,
				'sticky' => true,
			]
		);

		$write = end( $GLOBALS['albert_test_rest_calls'] );
		$this->assertSame( 'POST', $write['method'] );
		$this->assertSame( 'summer-sale', $write['params']['slug'] );
		$this->assertSame( '2026-12-01T09:00:00', $write['params']['date'] );
		$this->assertSame( 2, $write['params']['author'] );
		$this->assertTrue( $write['params']['sticky'] );
		$this->assertSame( 'future', $result['status'] );
		$this->assertSame( 'summer-sale', $result['slug'] );
		$this->assertSame( '2026-12-01T09:00:00', $result['date'] );
	}

	public function test_a_refused_field_saves_nothing(): void {
		$result = ( new UpdatePost() )->execute(
			[
				'id'    => 20,
				'title' => 'Still not saved',
				'date'  => '2026-02-30T09:00:00',
			]
		);

		$this->assertRefused( 'invalid_date', $result );
		foreach ( $GLOBALS['albert_test_rest_calls'] as $call ) {
			$this->assertNotSame( 'POST', $call['method'] );
		}
	}

	public function test_create_page_sends_parent_order_and_template(): void {
		$GLOBALS['albert_test_rest_responses']['write'] = [
			'is_error' => false,
			'data'     => [
				'id'     => 30,
				'status' => 'draft',
			],
		];

		( new CreatePage() )->execute(
			[
				'title'      => 'Child',
				'parent'     => 10,
				'menu_order' => 2,
				'template'   => 'templates/wide.php',
			]
		);

		$write = end( $GLOBALS['albert_test_rest_calls'] );
		$this->assertSame( 10, $write['params']['parent'] );
		$this->assertSame( 2, $write['params']['menu_order'] );
		$this->assertSame( 'templates/wide.php', $write['params']['template'] );
	}

	public function test_view_post_returns_the_fields(): void {
		$GLOBALS['albert_test_posts'][20] = (object) [
			'ID'             => 20,
			'post_type'      => 'post',
			'post_title'     => 'Hello',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_status'    => 'publish',
			'post_author'    => 2,
			'post_date'      => '2026-01-01 00:00:00',
			'post_modified'  => '2026-01-01 00:00:00',
			'post_name'      => 'hello',
			'comment_status' => 'closed',
			'page_template'  => 'templates/wide.php',
			'post_format'    => 'aside',
		];
		$GLOBALS['albert_test_sticky']    = [ 20 ];

		unset( $GLOBALS['albert_test_caps'] );

		$post = ( new ViewPost() )->execute( [ 'id' => 20 ] )['post'];

		$this->assertSame( 'hello', $post['slug'] );
		$this->assertSame( 2, $post['author_id'] );
		$this->assertSame( 'templates/wide.php', $post['template'] );
		$this->assertSame( 'closed', $post['comment_status'] );
		$this->assertTrue( $post['sticky'] );
		$this->assertSame( 'aside', $post['format'] );
	}

	public function test_view_page_returns_the_fields(): void {
		$GLOBALS['albert_test_posts'][11] = (object) [
			'ID'             => 11,
			'post_type'      => 'page',
			'post_title'     => 'Child',
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_status'    => 'draft',
			'post_author'    => 1,
			'post_date'      => '2026-01-01 00:00:00',
			'post_modified'  => '2026-01-01 00:00:00',
			'post_name'      => 'child',
			'post_parent'    => 10,
			'menu_order'     => 4,
			'comment_status' => 'open',
			'page_template'  => '',
		];

		unset( $GLOBALS['albert_test_caps'] );

		$page = ( new ViewPage() )->execute( [ 'id' => 11 ] )['page'];

		$this->assertSame( 10, $page['parent_id'] );
		$this->assertSame( 4, $page['menu_order'] );
		$this->assertSame( '', $page['template'] );
		$this->assertSame( 'open', $page['comment_status'] );
		$this->assertArrayNotHasKey( 'sticky', $page );
	}
}
