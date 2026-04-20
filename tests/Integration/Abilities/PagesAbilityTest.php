<?php
/**
 * Parameter-level integration tests for Page abilities.
 *
 * Verifies that every input parameter on FindPages, ViewPage, CreatePage,
 * UpdatePage, and DeletePage actually works as documented.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Pages\Create as CreatePage;
use Albert\Abilities\WordPress\Pages\Delete as DeletePage;
use Albert\Abilities\WordPress\Pages\FindPages;
use Albert\Abilities\WordPress\Pages\Update as UpdatePage;
use Albert\Abilities\WordPress\Pages\ViewPage;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Pages ability parameter tests.
 *
 * @since 1.1.0
 */
class PagesAbilityTest extends TestCase {

	/**
	 * Run as administrator with all abilities enabled.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	// ─── FindPages ──────────────────────────────────────────────────

	/**
	 * Search parameter filters pages by title.
	 *
	 * @return void
	 */
	public function test_find_pages_search_filters_by_title(): void {
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'About Us Unique',
				'post_status' => 'publish',
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Contact Page',
				'post_status' => 'publish',
			]
		);

		$result = ( new FindPages() )->execute( [ 'search' => 'About Us Unique' ] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['pages'] );
		$this->assertSame( 'About Us Unique', $result['pages'][0]['title'] );
	}

	/**
	 * Status parameter filters pages by status.
	 *
	 * @return void
	 */
	public function test_find_pages_status_filter(): void {
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'draft',
			]
		);

		$result = ( new FindPages() )->execute( [ 'status' => 'draft' ] );

		$this->assertIsArray( $result );
		foreach ( $result['pages'] as $page ) {
			$this->assertSame( 'draft', $page['status'] );
		}
	}

	/**
	 * Parent parameter filters by parent page ID.
	 *
	 * @return void
	 */
	public function test_find_pages_parent_filter(): void {
		$parent = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Parent',
				'post_status' => 'publish',
			]
		);
		$child  = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Child',
				'post_parent' => $parent,
				'post_status' => 'publish',
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Orphan',
				'post_status' => 'publish',
			]
		);

		$result = ( new FindPages() )->execute( [ 'parent' => $parent ] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $child, $result['pages'][0]['id'] );
	}

	/**
	 * Order and orderby parameters control sorting.
	 *
	 * @return void
	 */
	public function test_find_pages_order_and_orderby(): void {
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'AAA Page',
				'post_status' => 'publish',
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'ZZZ Page',
				'post_status' => 'publish',
			]
		);

		$result = ( new FindPages() )->execute(
			[
				'orderby' => 'title',
				'order'   => 'asc',
			]
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 2, count( $result['pages'] ) );
		$this->assertSame( 'AAA Page', $result['pages'][0]['title'] );
	}

	/**
	 * Pagination works correctly.
	 *
	 * @return void
	 */
	public function test_find_pages_pagination(): void {
		self::factory()->post->create_many(
			5,
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);

		$page1 = ( new FindPages() )->execute(
			[
				'per_page' => 2,
				'page'     => 1,
			]
		);
		$page2 = ( new FindPages() )->execute(
			[
				'per_page' => 2,
				'page'     => 2,
			]
		);

		$this->assertCount( 2, $page1['pages'] );
		$this->assertCount( 2, $page2['pages'] );
		$this->assertNotSame( $page1['pages'][0]['id'], $page2['pages'][0]['id'] );
	}

	// ─── ViewPage ───────────────────────────────────────────────────

	/**
	 * ViewPage returns all expected fields.
	 *
	 * @return void
	 */
	public function test_view_page_returns_all_fields(): void {
		$parent  = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);
		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'View Me Page',
				'post_content' => 'Page body',
				'post_excerpt' => 'Page excerpt',
				'post_status'  => 'publish',
				'post_parent'  => $parent,
				'menu_order'   => 5,
			]
		);

		$result = ( new ViewPage() )->execute( [ 'id' => $page_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $page_id, $result['page']['id'] );
		$this->assertSame( 'View Me Page', $result['page']['title'] );
		$this->assertSame( 'Page body', $result['page']['content'] );
		$this->assertSame( $parent, $result['page']['parent_id'] );
		$this->assertSame( 5, $result['page']['menu_order'] );
	}

	/**
	 * ViewPage returns error for non-existent page.
	 *
	 * @return void
	 */
	public function test_view_page_not_found(): void {
		$result = ( new ViewPage() )->execute( [ 'id' => 99999 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'page_not_found', $result->get_error_code() );
	}

	/**
	 * ViewPage rejects a post ID (wrong post type).
	 *
	 * @return void
	 */
	public function test_view_page_rejects_post_id(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$result = ( new ViewPage() )->execute( [ 'id' => $post_id ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'page_not_found', $result->get_error_code() );
	}

	// ─── CreatePage ─────────────────────────────────────────────────

	/**
	 * CreatePage with parent page.
	 *
	 * @return void
	 */
	public function test_create_page_with_parent(): void {
		$parent = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);

		$result = ( new CreatePage() )->execute(
			[
				'title'  => 'Child Page',
				'parent' => $parent,
				'status' => 'draft',
			]
		);

		$this->assertIsArray( $result );
		$page = get_post( $result['id'] );
		$this->assertSame( $parent, $page->post_parent );
	}

	/**
	 * CreatePage with excerpt and content.
	 *
	 * @return void
	 */
	public function test_create_page_with_content_and_excerpt(): void {
		$result = ( new CreatePage() )->execute(
			[
				'title'   => 'Content Page',
				'content' => '<p>Page content here</p>',
				'excerpt' => 'Page summary',
				'status'  => 'publish',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['status'] );

		$page = get_post( $result['id'] );
		$this->assertSame( 'Page summary', $page->post_excerpt );
	}

	/**
	 * CreatePage defaults to draft.
	 *
	 * @return void
	 */
	public function test_create_page_defaults_to_draft(): void {
		$result = ( new CreatePage() )->execute( [ 'title' => 'Draft Page' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
	}

	// ─── UpdatePage ─────────────────────────────────────────────────

	/**
	 * UpdatePage changes the title and status.
	 *
	 * @return void
	 */
	public function test_update_page_title_and_status(): void {
		$page_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Old Title',
				'post_status' => 'draft',
			]
		);

		$result = ( new UpdatePage() )->execute(
			[
				'id'     => $page_id,
				'title'  => 'New Title',
				'status' => 'publish',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'New Title', $result['title'] );
		$this->assertSame( 'publish', $result['status'] );
	}

	/**
	 * UpdatePage changes parent.
	 *
	 * @return void
	 */
	public function test_update_page_parent(): void {
		$new_parent = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$page_id    = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$result = ( new UpdatePage() )->execute(
			[
				'id'     => $page_id,
				'parent' => $new_parent,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $new_parent, get_post( $page_id )->post_parent );
	}

	/**
	 * UpdatePage returns error for non-existent page.
	 *
	 * @return void
	 */
	public function test_update_page_not_found(): void {
		$result = ( new UpdatePage() )->execute(
			[
				'id'    => 99999,
				'title' => 'Ghost',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// ─── DeletePage ─────────────────────────────────────────────────

	/**
	 * DeletePage with force permanently deletes.
	 *
	 * @return void
	 */
	public function test_delete_page_force(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$result = ( new DeletePage() )->execute(
			[
				'id'    => $page_id,
				'force' => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 'deleted', $result['status'] );
		$this->assertNull( get_post( $page_id ) );
	}

	/**
	 * DeletePage without force moves to trash.
	 *
	 * @return void
	 */
	public function test_delete_page_trash(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$result = ( new DeletePage() )->execute( [ 'id' => $page_id ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 'trashed', $result['status'] );
		$this->assertSame( 'trash', get_post_status( $page_id ) );
	}

	/**
	 * DeletePage returns error for non-existent page.
	 *
	 * @return void
	 */
	public function test_delete_page_not_found(): void {
		$result = ( new DeletePage() )->execute( [ 'id' => 99999 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'page_not_found', $result->get_error_code() );
	}
}
