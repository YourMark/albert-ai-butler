<?php
/**
 * Parameter-level integration tests for Post abilities.
 *
 * Verifies that every input parameter on FindPosts, ViewPost, CreatePost,
 * UpdatePost, and DeletePost actually works as documented.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Posts\Create as CreatePost;
use Albert\Abilities\WordPress\Posts\Delete as DeletePost;
use Albert\Abilities\WordPress\Posts\FindPosts;
use Albert\Abilities\WordPress\Posts\Update as UpdatePost;
use Albert\Abilities\WordPress\Posts\ViewPost;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Posts ability parameter tests.
 *
 * @since 1.1.0
 */
class PostsAbilityTest extends TestCase {

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

	// ─── FindPosts ──────────────────────────────────────────────────

	/**
	 * Search parameter filters posts by title.
	 *
	 * @return void
	 */
	public function test_find_posts_search_filters_by_title(): void {
		self::factory()->post->create(
			[
				'post_title'  => 'Alpha Unique Post',
				'post_status' => 'publish',
			]
		);
		self::factory()->post->create(
			[
				'post_title'  => 'Beta Other Post',
				'post_status' => 'publish',
			]
		);

		$result = ( new FindPosts() )->execute( [ 'search' => 'Alpha Unique' ] );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['posts'] );
		$this->assertSame( 'Alpha Unique Post', $result['posts'][0]['title'] );
	}

	/**
	 * Status parameter filters posts by status.
	 *
	 * @return void
	 */
	public function test_find_posts_status_filters_correctly(): void {
		self::factory()->post->create( [ 'post_status' => 'publish' ] );
		self::factory()->post->create( [ 'post_status' => 'draft' ] );
		self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$result = ( new FindPosts() )->execute( [ 'status' => 'draft' ] );

		$this->assertIsArray( $result );
		foreach ( $result['posts'] as $post ) {
			$this->assertSame( 'draft', $post['status'] );
		}
		$this->assertSame( 2, $result['total'] );
	}

	/**
	 * Categories parameter filters posts by category.
	 *
	 * @return void
	 */
	public function test_find_posts_categories_filter(): void {
		$cat_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'TestCat',
			]
		);
		$post_a = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$post_b = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_post_categories( $post_a, [ $cat_id ] );

		$result = ( new FindPosts() )->execute( [ 'categories' => [ $cat_id ] ] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $post_a, $result['posts'][0]['id'] );
	}

	/**
	 * Order and orderby parameters control sorting.
	 *
	 * @return void
	 */
	public function test_find_posts_order_and_orderby(): void {
		self::factory()->post->create(
			[
				'post_title'  => 'AAA Post',
				'post_status' => 'publish',
			]
		);
		self::factory()->post->create(
			[
				'post_title'  => 'ZZZ Post',
				'post_status' => 'publish',
			]
		);

		$result = ( new FindPosts() )->execute(
			[
				'orderby' => 'title',
				'order'   => 'asc',
			]
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 2, count( $result['posts'] ) );
		$this->assertSame( 'AAA Post', $result['posts'][0]['title'] );
	}

	/**
	 * Pagination parameters work correctly.
	 *
	 * @return void
	 */
	public function test_find_posts_pagination(): void {
		self::factory()->post->create_many( 5, [ 'post_status' => 'publish' ] );

		$page1 = ( new FindPosts() )->execute(
			[
				'per_page' => 2,
				'page'     => 1,
			]
		);
		$page2 = ( new FindPosts() )->execute(
			[
				'per_page' => 2,
				'page'     => 2,
			]
		);

		$this->assertCount( 2, $page1['posts'] );
		$this->assertCount( 2, $page2['posts'] );
		$this->assertNotSame( $page1['posts'][0]['id'], $page2['posts'][0]['id'] );
		$this->assertSame( 5, $page1['total'] );
		$this->assertSame( 3, $page1['total_pages'] );
	}

	// ─── ViewPost ───────────────────────────────────────────────────

	/**
	 * ViewPost returns all expected fields.
	 *
	 * @return void
	 */
	public function test_view_post_returns_all_fields(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'View Me',
				'post_content' => 'Post body',
				'post_excerpt' => 'Short excerpt',
				'post_status'  => 'publish',
			]
		);

		$result = ( new ViewPost() )->execute( [ 'id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post']['id'] );
		$this->assertSame( 'View Me', $result['post']['title'] );
		$this->assertSame( 'Post body', $result['post']['content'] );
		$this->assertSame( 'Short excerpt', $result['post']['excerpt'] );
		$this->assertSame( 'publish', $result['post']['status'] );
		$this->assertArrayHasKey( 'permalink', $result['post'] );
		$this->assertArrayHasKey( 'slug', $result['post'] );
	}

	/**
	 * ViewPost returns error for non-existent post.
	 *
	 * @return void
	 */
	public function test_view_post_not_found(): void {
		$result = ( new ViewPost() )->execute( [ 'id' => 99999 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * ViewPost rejects a page ID (wrong post type).
	 *
	 * @return void
	 */
	public function test_view_post_rejects_page_id(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$result = ( new ViewPost() )->execute( [ 'id' => $page_id ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	// ─── CreatePost ─────────────────────────────────────────────────

	/**
	 * CreatePost with all optional parameters.
	 *
	 * @return void
	 */
	public function test_create_post_with_all_params(): void {
		$cat_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'CreateCat',
			]
		);

		$result = ( new CreatePost() )->execute(
			[
				'title'      => 'Full Post',
				'content'    => '<p>Rich content</p>',
				'status'     => 'publish',
				'excerpt'    => 'Custom excerpt',
				'categories' => [ $cat_id ],
				'tags'       => [ 'newtag', 'anothertag' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Full Post', $result['title'] );
		$this->assertSame( 'publish', $result['status'] );

		// Verify categories applied.
		$post_cats = wp_get_post_categories( $result['id'] );
		$this->assertContains( $cat_id, $post_cats );

		// Verify tags created and applied.
		$post_tags = wp_get_post_tags( $result['id'] );
		$tag_names = array_map( fn( $t ) => $t->name, $post_tags );
		$this->assertContains( 'newtag', $tag_names );
		$this->assertContains( 'anothertag', $tag_names );

		// Verify excerpt.
		$post = get_post( $result['id'] );
		$this->assertSame( 'Custom excerpt', $post->post_excerpt );
	}

	/**
	 * CreatePost defaults to draft status.
	 *
	 * @return void
	 */
	public function test_create_post_defaults_to_draft(): void {
		$result = ( new CreatePost() )->execute( [ 'title' => 'Draft Test' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
	}

	/**
	 * CreatePost returns edit_url and permalink.
	 *
	 * @return void
	 */
	public function test_create_post_returns_urls(): void {
		$result = ( new CreatePost() )->execute(
			[
				'title'  => 'URL Test',
				'status' => 'publish',
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'post.php?post=', $result['edit_url'] );
		$this->assertNotEmpty( $result['permalink'] );
	}

	// ─── UpdatePost ─────────────────────────────────────────────────

	/**
	 * UpdatePost changes the title.
	 *
	 * @return void
	 */
	public function test_update_post_title(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Before' ] );

		$result = ( new UpdatePost() )->execute(
			[
				'id'    => $post_id,
				'title' => 'After',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'After', $result['title'] );
		$this->assertSame( 'After', get_post( $post_id )->post_title );
	}

	/**
	 * UpdatePost changes the status.
	 *
	 * @return void
	 */
	public function test_update_post_status(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );

		$result = ( new UpdatePost() )->execute(
			[
				'id'     => $post_id,
				'status' => 'publish',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['status'] );
	}

	/**
	 * UpdatePost adds categories and tags.
	 *
	 * @return void
	 */
	public function test_update_post_categories_and_tags(): void {
		$post_id = self::factory()->post->create();
		$cat_id  = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'UpdateCat',
			]
		);

		$result = ( new UpdatePost() )->execute(
			[
				'id'         => $post_id,
				'categories' => [ $cat_id ],
				'tags'       => [ 'update-tag' ],
			]
		);

		$this->assertIsArray( $result );

		$post_cats = wp_get_post_categories( $post_id );
		$this->assertContains( $cat_id, $post_cats );

		$post_tags = wp_get_post_tags( $post_id );
		$this->assertSame( 'update-tag', $post_tags[0]->name );
	}

	/**
	 * UpdatePost returns error for non-existent post.
	 *
	 * @return void
	 */
	public function test_update_post_not_found(): void {
		$result = ( new UpdatePost() )->execute(
			[
				'id'    => 99999,
				'title' => 'Ghost',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// ─── DeletePost ─────────────────────────────────────────────────

	/**
	 * DeletePost with force=true permanently deletes.
	 *
	 * @return void
	 */
	public function test_delete_post_force(): void {
		$post_id = self::factory()->post->create();

		$result = ( new DeletePost() )->execute(
			[
				'id'    => $post_id,
				'force' => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 'deleted', $result['status'] );
		$this->assertNull( get_post( $post_id ) );
	}

	/**
	 * DeletePost without force moves to trash.
	 *
	 * @return void
	 */
	public function test_delete_post_trash(): void {
		$post_id = self::factory()->post->create();

		$result = ( new DeletePost() )->execute( [ 'id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 'trashed', $result['status'] );
		$this->assertSame( 'trash', get_post_status( $post_id ) );
	}

	/**
	 * DeletePost returns error for non-existent post.
	 *
	 * @return void
	 */
	public function test_delete_post_not_found(): void {
		$result = ( new DeletePost() )->execute( [ 'id' => 99999 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}
}
