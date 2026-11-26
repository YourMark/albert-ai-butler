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

use Albert\Abilities\WordPress\Posts\AddTerms as AddPostTerms;
use Albert\Abilities\WordPress\Posts\Create as CreatePost;
use Albert\Abilities\WordPress\Posts\Delete as DeletePost;
use Albert\Abilities\WordPress\Posts\FindPosts;
use Albert\Abilities\WordPress\Posts\RemoveTerms as RemovePostTerms;
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
	 * ViewPost returns the assigned categories and tags as id and name, and an empty list for none.
	 *
	 * @return void
	 */
	public function test_view_post_returns_categories_and_tags(): void {
		$post_id = self::factory()->post->create();
		$this->assertSame( [], ( new ViewPost() )->execute( [ 'id' => $post_id ] )['post']['tags'] );

		$cat_id  = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'ViewCat',
			]
		);
		wp_set_post_categories( $post_id, [ $cat_id ] );
		wp_set_post_tags( $post_id, [ 'view-tag' ] );
		$tag = get_term_by( 'name', 'view-tag', 'post_tag' );

		$result = ( new ViewPost() )->execute( [ 'id' => $post_id ] );

		$this->assertSame(
			[
				[
					'id'   => $cat_id,
					'name' => 'ViewCat',
				],
			],
			$result['post']['categories']
		);
		$this->assertSame(
			[
				[
					'id'   => $tag->term_id,
					'name' => 'view-tag',
				],
			],
			$result['post']['tags']
		);
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
	 * CreatePost refuses Markdown content and saves nothing.
	 *
	 * @return void
	 */
	public function test_create_post_refuses_markdown_content(): void {
		$before = wp_count_posts()->draft;

		$result = ( new CreatePost() )->execute(
			[
				'title'   => 'Markdown Post',
				'content' => "## Intro\n\nSome **bold** text.\n\n- one\n- two",
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_invalid_content', $result->get_error_code() );
		wp_cache_flush();
		$this->assertSame( $before, wp_count_posts()->draft );
	}

	/**
	 * Markdown between blocks is refused too, since that text is converted like a plain string.
	 *
	 * @return void
	 */
	public function test_create_post_refuses_markdown_between_blocks(): void {
		$result = ( new CreatePost() )->execute(
			[
				'title'   => 'Mixed Post',
				'content' => "<!-- wp:paragraph -->\n<p>Intro.</p>\n<!-- /wp:paragraph -->\n\n## Heading\n\n- one\n- two",
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_invalid_content', $result->get_error_code() );
	}

	/**
	 * UpdatePost refuses Markdown content and leaves the body untouched.
	 *
	 * @return void
	 */
	public function test_update_post_refuses_markdown_content(): void {
		$post_id = self::factory()->post->create( [ 'post_content' => '<p>Original</p>' ] );

		$result = ( new UpdatePost() )->execute(
			[
				'id'      => $post_id,
				'content' => "# Heading\n\nBody.",
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_invalid_content', $result->get_error_code() );
		$this->assertSame( '<p>Original</p>', get_post( $post_id )->post_content );
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

	/**
	 * A REST refusal on create is reported as the post permission code, not a generic error.
	 *
	 * @return void
	 */
	public function test_create_post_rest_refusal_is_permission_denied(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$result = ( new CreatePost() )->execute(
			[
				'title'  => 'Contributor Post',
				'status' => 'publish',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_post_permission_denied', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Any other REST error keeps core's code.
	 *
	 * @return void
	 */
	public function test_create_post_passes_rest_error_code_through(): void {
		$result = ( new CreatePost() )->execute(
			[
				'title'    => 'Bad Template',
				'template' => 'no-such-template.php',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
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
	 * UpdatePost leaves categories and tags alone when they are not sent or sent as an empty list.
	 *
	 * @return void
	 */
	public function test_update_post_without_terms_keeps_them(): void {
		$post_id = self::factory()->post->create();
		$cat_id  = self::factory()->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_post_categories( $post_id, [ $cat_id ] );
		wp_set_post_tags( $post_id, [ 'clear-me' ] );

		( new UpdatePost() )->execute(
			[
				'id'    => $post_id,
				'title' => 'Renamed',
			]
		);
		$result = ( new UpdatePost() )->execute(
			[
				'id'         => $post_id,
				'categories' => [],
				'tags'       => [],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ $cat_id ], wp_get_post_categories( $post_id ) );
		$this->assertSame( 'clear-me', wp_get_post_tags( $post_id )[0]->name );
	}

	/**
	 * UpdatePost refuses a category ID that does not exist and keeps the old ones.
	 *
	 * @return void
	 */
	public function test_update_post_refuses_unknown_category(): void {
		$post_id = self::factory()->post->create();
		$cat_id  = self::factory()->term->create( [ 'taxonomy' => 'category' ] );
		wp_set_post_categories( $post_id, [ $cat_id ] );

		$result = ( new UpdatePost() )->execute(
			[
				'id'         => $post_id,
				'categories' => [ 999999 ],
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'albert_category_not_found', $result->get_error_code() );
		$this->assertSame( [ $cat_id ], wp_get_post_categories( $post_id ) );
	}

	/**
	 * UpdatePost finds a tag by its exact name when another tag contains it.
	 *
	 * @return void
	 */
	public function test_update_post_finds_tag_by_exact_name(): void {
		$post_id = self::factory()->post->create();
		self::factory()->term->create(
			[
				'taxonomy' => 'post_tag',
				'name'     => 'breaking news',
			]
		);
		$news_id = self::factory()->term->create(
			[
				'taxonomy' => 'post_tag',
				'name'     => 'news',
			]
		);

		( new UpdatePost() )->execute(
			[
				'id'   => $post_id,
				'tags' => [ 'news' ],
			]
		);

		$this->assertSame( [ $news_id ], wp_get_post_tags( $post_id, [ 'fields' => 'ids' ] ) );
	}

	/**
	 * A tag name with an ampersand is found on write and read back as written.
	 *
	 * @return void
	 */
	public function test_tag_with_ampersand_round_trips(): void {
		$post_id = self::factory()->post->create();
		$tag_id  = self::factory()->term->create(
			[
				'taxonomy' => 'post_tag',
				'name'     => 'R&D',
			]
		);

		( new UpdatePost() )->execute(
			[
				'id'   => $post_id,
				'tags' => [ 'R&D' ],
			]
		);
		$result = ( new ViewPost() )->execute( [ 'id' => $post_id ] );

		$this->assertSame(
			[
				[
					'id'   => $tag_id,
					'name' => 'R&D',
				],
			],
			$result['post']['tags']
		);
	}

	/**
	 * UpdatePost refuses the whole change when one tag cannot be created.
	 *
	 * @return void
	 */
	public function test_update_post_refuses_tag_it_cannot_create(): void {
		$post_id = self::factory()->post->create();
		wp_set_post_tags( $post_id, [ 'keep-me' ] );

		$result = ( new UpdatePost() )->execute(
			[
				'id'   => $post_id,
				'tags' => [ 'fine', '' ],
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'albert_invalid_tags', $result->get_error_code() );
		$this->assertSame( 'keep-me', wp_get_post_tags( $post_id )[0]->name );
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

	/**
	 * AddPostTerms adds tags, creating a new one, and keeps the tags already there.
	 *
	 * @return void
	 */
	public function test_add_post_terms_keeps_existing_tags(): void {
		$post_id = self::factory()->post->create();
		wp_set_post_tags( $post_id, [ 'old-tag' ] );

		$result = ( new AddPostTerms() )->execute(
			[
				'post_id'  => $post_id,
				'taxonomy' => 'post_tag',
				'terms'    => [ 'new-tag', 'old-tag' ],
			]
		);

		$this->assertIsArray( $result );
		$names = wp_list_pluck( $result['terms'], 'name' );
		sort( $names );
		$this->assertSame( [ 'new-tag', 'old-tag' ], $names );
		$this->assertCount( 2, wp_get_post_tags( $post_id ) );
	}

	/**
	 * AddPostTerms adds a category and keeps the post's other categories.
	 *
	 * @return void
	 */
	public function test_add_post_terms_keeps_existing_categories(): void {
		$post_id = self::factory()->post->create();
		$old_id  = self::factory()->category->create( [ 'name' => 'Old Cat' ] );
		$new_id  = self::factory()->category->create( [ 'name' => 'New Cat' ] );
		wp_set_post_categories( $post_id, [ $old_id ] );

		$result = ( new AddPostTerms() )->execute(
			[
				'post_id'  => $post_id,
				'taxonomy' => 'category',
				'terms'    => [ $new_id ],
			]
		);

		$ids = wp_get_post_categories( $post_id );
		sort( $ids );
		$this->assertSame( [ $old_id, $new_id ], $ids );
		$this->assertContains(
			[
				'id'   => $new_id,
				'name' => 'New Cat',
			],
			$result['terms']
		);
	}

	/**
	 * AddPostTerms refuses a category ID that does not exist and changes nothing.
	 *
	 * @return void
	 */
	public function test_add_post_terms_refuses_unknown_category(): void {
		$post_id = self::factory()->post->create();
		$cat_id  = self::factory()->category->create();
		wp_set_post_categories( $post_id, [ $cat_id ] );

		$result = ( new AddPostTerms() )->execute(
			[
				'post_id'  => $post_id,
				'taxonomy' => 'category',
				'terms'    => [ 999999 ],
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'albert_category_not_found', $result->get_error_code() );
		$this->assertSame( [ $cat_id ], wp_get_post_categories( $post_id ) );
	}

	/**
	 * AddPostTerms refuses category names: categories are sent by ID.
	 *
	 * @return void
	 */
	public function test_add_post_terms_refuses_category_names(): void {
		$post_id = self::factory()->post->create();

		$result = ( new AddPostTerms() )->execute(
			[
				'post_id'  => $post_id,
				'taxonomy' => 'category',
				'terms'    => [ 'News' ],
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'albert_invalid_terms', $result->get_error_code() );
	}

	/**
	 * AddPostTerms answers albert_post_not_found for a page ID.
	 *
	 * @return void
	 */
	public function test_add_post_terms_refuses_page(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$result = ( new AddPostTerms() )->execute(
			[
				'post_id'  => $page_id,
				'taxonomy' => 'post_tag',
				'terms'    => [ 'x' ],
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'albert_post_not_found', $result->get_error_code() );
	}

	/**
	 * AddPostTerms refuses a post the user cannot edit.
	 *
	 * @return void
	 */
	public function test_add_post_terms_refuses_post_user_cannot_edit(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$result = ( new AddPostTerms() )->execute(
			[
				'post_id'  => $post_id,
				'taxonomy' => 'post_tag',
				'terms'    => [ 'x' ],
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'albert_post_permission_denied', $result->get_error_code() );
		$this->assertSame( [], wp_get_post_tags( $post_id ) );
	}

	/**
	 * RemovePostTerms removes one tag and keeps the others; an unknown name is skipped.
	 *
	 * @return void
	 */
	public function test_remove_post_terms_keeps_other_tags(): void {
		$post_id = self::factory()->post->create();
		wp_set_post_tags( $post_id, [ 'keep-tag', 'drop-tag' ] );

		$result = ( new RemovePostTerms() )->execute(
			[
				'post_id'  => $post_id,
				'taxonomy' => 'post_tag',
				'terms'    => [ 'drop-tag', 'never-existed' ],
			]
		);

		$this->assertSame( [ 'keep-tag' ], wp_list_pluck( $result['terms'], 'name' ) );
		$this->assertFalse( $result['default_category_applied'] );
		$this->assertFalse( get_term_by( 'name', 'never-existed', 'post_tag' ) );
	}

	/**
	 * RemovePostTerms puts the default category back when the last one is removed, and says so.
	 *
	 * @return void
	 */
	public function test_remove_post_terms_last_category_applies_default(): void {
		$post_id    = self::factory()->post->create();
		$cat_id     = self::factory()->category->create();
		$default_id = (int) get_option( 'default_category' );
		wp_set_post_categories( $post_id, [ $cat_id ] );

		$result = ( new RemovePostTerms() )->execute(
			[
				'post_id'  => $post_id,
				'taxonomy' => 'category',
				'terms'    => [ $cat_id ],
			]
		);

		$this->assertTrue( $result['default_category_applied'] );
		$this->assertSame( [ $default_id ], wp_list_pluck( $result['terms'], 'id' ) );
		$this->assertSame( [ $default_id ], wp_get_post_categories( $post_id ) );
	}

	/**
	 * RemovePostTerms does not report the default category as put back when the post only had it.
	 *
	 * @return void
	 */
	public function test_remove_post_terms_default_only_category_reports_nothing_applied(): void {
		$post_id    = self::factory()->post->create();
		$default_id = (int) get_option( 'default_category' );
		wp_set_post_categories( $post_id, [ $default_id ] );

		$result = ( new RemovePostTerms() )->execute(
			[
				'post_id'  => $post_id,
				'taxonomy' => 'category',
				'terms'    => [ $default_id ],
			]
		);

		$this->assertFalse( $result['default_category_applied'] );
		$this->assertSame( [ $default_id ], wp_get_post_categories( $post_id ) );
	}

	/**
	 * RemovePostTerms keeps the other categories when one of several is removed.
	 *
	 * @return void
	 */
	public function test_remove_post_terms_keeps_other_categories(): void {
		$post_id = self::factory()->post->create();
		$keep_id = self::factory()->category->create();
		$drop_id = self::factory()->category->create();
		wp_set_post_categories( $post_id, [ $keep_id, $drop_id ] );

		$result = ( new RemovePostTerms() )->execute(
			[
				'post_id'  => $post_id,
				'taxonomy' => 'category',
				'terms'    => [ $drop_id ],
			]
		);

		$this->assertFalse( $result['default_category_applied'] );
		$this->assertSame( [ $keep_id ], wp_get_post_categories( $post_id ) );
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
		$this->assertFalse( $result['deleted'] );
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

	/**
	 * On a trash-disabled site a non-force delete must refuse, not silently
	 * hard-delete: DeletePost inherits WordPress's REST trash guard.
	 *
	 * @return void
	 */
	public function test_delete_post_without_force_refuses_when_trash_disabled(): void {
		add_filter( 'rest_post_trashable', '__return_false' );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$result = ( new DeletePost() )->execute( [ 'id' => $post_id ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_trash_not_supported', $result->get_error_code() );
		$this->assertNotNull( get_post( $post_id ), 'The post must survive — no silent permanent delete.' );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}
}
