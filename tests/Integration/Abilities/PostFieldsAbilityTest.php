<?php
/**
 * Integration tests for slug, date, author, parent, order, template, comments,
 * sticky and format on the post and page abilities.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Pages\Create as CreatePage;
use Albert\Abilities\WordPress\Pages\Update as UpdatePage;
use Albert\Abilities\WordPress\Pages\ViewPage;
use Albert\Abilities\WordPress\Posts\Create as CreatePost;
use Albert\Abilities\WordPress\Posts\Update as UpdatePost;
use Albert\Abilities\WordPress\Posts\ViewPost;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Post fields through the real REST endpoints.
 *
 * @since 1.6.0
 */
class PostFieldsAbilityTest extends TestCase {

	/**
	 * The administrator running the calls.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Run as administrator with all abilities enabled.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * Remove the theme supports and templates a test added.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_theme_support( 'post-formats' );
		remove_all_filters( 'theme_page_templates' );
		update_option( 'timezone_string', '' );
		parent::tear_down();
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

	/**
	 * Offer one page template for both posts and pages.
	 *
	 * @return void
	 */
	private function offer_wide_template(): void {
		add_filter(
			'theme_page_templates',
			static fn( array $templates ): array => $templates + [ 'templates/wide.php' => 'Wide' ]
		);
	}

	public function test_a_future_date_with_publish_schedules_the_post(): void {
		$date   = gmdate( 'Y-m-d\TH:i:s', time() + WEEK_IN_SECONDS );
		$result = ( new CreatePost() )->execute(
			[
				'title'  => 'Scheduled',
				'status' => 'publish',
				'date'   => $date,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'future', $result['status'] );
		$this->assertSame( $date, $result['date'] );
		$this->assertSame( 'future', get_post_status( $result['id'] ) );
		$this->assertSame( str_replace( 'T', ' ', $date ), get_post( $result['id'] )->post_date );
	}

	public function test_a_future_date_schedules_an_already_published_post(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$result = ( new UpdatePost() )->execute(
			[
				'id'   => $post_id,
				'date' => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ),
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'future', get_post_status( $post_id ) );
	}

	public function test_a_date_with_an_offset_is_stored_in_utc(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		( new UpdatePost() )->execute(
			[
				'id'   => $post_id,
				'date' => '2020-01-01T12:00:00+02:00',
			]
		);

		$this->assertSame( '2020-01-01 10:00:00', get_post( $post_id )->post_date_gmt );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	public function test_a_date_without_an_offset_is_in_the_site_timezone(): void {
		update_option( 'timezone_string', 'Europe/Amsterdam' );
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		( new UpdatePost() )->execute(
			[
				'id'   => $post_id,
				'date' => '2020-07-01T12:00:00',
			]
		);

		$post = get_post( $post_id );
		$this->assertSame( '2020-07-01 12:00:00', $post->post_date );
		$this->assertSame( '2020-07-01 10:00:00', $post->post_date_gmt );
	}

	public function test_an_impossible_date_is_refused_and_nothing_changes(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Before' ] );

		$result = ( new UpdatePost() )->execute(
			[
				'id'    => $post_id,
				'title' => 'After',
				'date'  => '2026-02-30T09:00:00',
			]
		);

		$this->assertRefused( 'invalid_date', $result );
		$this->assertSame( 'Before', get_post( $post_id )->post_title );
	}

	public function test_slug_is_saved_and_returned(): void {
		$post_id = self::factory()->post->create();

		$result = ( new UpdatePost() )->execute(
			[
				'id'   => $post_id,
				'slug' => 'Summer Sale',
			]
		);

		$this->assertSame( 'summer-sale', $result['slug'] );
		$this->assertSame( 'summer-sale', get_post( $post_id )->post_name );
	}

	public function test_a_taken_slug_comes_back_with_the_number_wordpress_added(): void {
		self::factory()->post->create(
			[
				'post_name'   => 'taken',
				'post_status' => 'publish',
			]
		);
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$result = ( new UpdatePost() )->execute(
			[
				'id'   => $post_id,
				'slug' => 'taken',
			]
		);

		$this->assertSame( 'taken-2', $result['slug'] );
	}

	public function test_author_is_changed_to_an_editor(): void {
		$editor  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$post_id = self::factory()->post->create();

		$result = ( new UpdatePost() )->execute(
			[
				'id'     => $post_id,
				'author' => $editor,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( (string) $editor, get_post( $post_id )->post_author );
	}

	public function test_a_subscriber_cannot_become_the_author(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$post_id    = self::factory()->post->create( [ 'post_author' => $this->admin_id ] );

		$this->assertRefused(
			'author_cannot_edit',
			( new UpdatePost() )->execute(
				[
					'id'     => $post_id,
					'author' => $subscriber,
				]
			)
		);
		$this->assertSame( (string) $this->admin_id, get_post( $post_id )->post_author );
	}

	public function test_an_unknown_author_is_refused(): void {
		$post_id = self::factory()->post->create();

		$this->assertRefused(
			'invalid_author',
			( new UpdatePost() )->execute(
				[
					'id'     => $post_id,
					'author' => 999999,
				]
			)
		);
	}

	public function test_an_author_role_cannot_hand_a_post_to_someone_else(): void {
		$author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$editor  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$post_id = self::factory()->post->create( [ 'post_author' => $author ] );
		wp_set_current_user( $author );

		$this->assertRefused(
			'author_permission_denied',
			( new UpdatePost() )->execute(
				[
					'id'     => $post_id,
					'author' => $editor,
				]
			)
		);
		$this->assertSame( (string) $author, get_post( $post_id )->post_author );
	}

	public function test_page_parent_and_menu_order_are_saved(): void {
		$parent = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$result = ( new CreatePage() )->execute(
			[
				'title'      => 'Child',
				'parent'     => $parent,
				'menu_order' => 3,
			]
		);

		$page = get_post( $result['id'] );
		$this->assertSame( $parent, $page->post_parent );
		$this->assertSame( 3, $page->menu_order );
	}

	public function test_a_page_moves_to_top_level_with_parent_zero(): void {
		$parent  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$page_id = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_parent' => $parent,
			]
		);

		( new UpdatePage() )->execute(
			[
				'id'     => $page_id,
				'parent' => 0,
			]
		);

		$this->assertSame( 0, get_post( $page_id )->post_parent );
	}

	public function test_a_post_cannot_be_a_page_parent(): void {
		$post_id = self::factory()->post->create();

		$this->assertRefused(
			'invalid_parent',
			( new CreatePage() )->execute(
				[
					'title'  => 'Child',
					'parent' => $post_id,
				]
			)
		);
	}

	public function test_a_parent_loop_is_refused(): void {
		$top   = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$child = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_parent' => $top,
			]
		);

		$this->assertRefused(
			'parent_loop',
			( new UpdatePage() )->execute(
				[
					'id'     => $top,
					'parent' => $child,
				]
			)
		);
		$this->assertSame( 0, get_post( $top )->post_parent );
	}

	public function test_a_template_the_theme_offers_is_saved(): void {
		$this->offer_wide_template();
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$result = ( new UpdatePage() )->execute(
			[
				'id'       => $page_id,
				'template' => 'templates/wide.php',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'templates/wide.php', get_page_template_slug( $page_id ) );
		$this->assertSame( 'templates/wide.php', ( new ViewPage() )->execute( [ 'id' => $page_id ] )['page']['template'] );
	}

	public function test_an_unknown_template_is_refused_with_the_choices(): void {
		$this->offer_wide_template();
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$result = ( new UpdatePage() )->execute(
			[
				'id'       => $page_id,
				'template' => 'templates/missing.php',
			]
		);

		$this->assertRefused( 'invalid_template', $result );
		$this->assertStringContainsString( 'templates/wide.php', $result->get_error_message() );
		$this->assertSame( '', get_page_template_slug( $page_id ) );
	}

	public function test_comments_are_closed(): void {
		$post_id = self::factory()->post->create( [ 'comment_status' => 'open' ] );

		( new UpdatePost() )->execute(
			[
				'id'             => $post_id,
				'comment_status' => 'closed',
			]
		);

		$this->assertSame( 'closed', get_post( $post_id )->comment_status );
	}

	public function test_sticky_is_set_and_cleared(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		( new UpdatePost() )->execute(
			[
				'id'     => $post_id,
				'sticky' => true,
			]
		);
		$this->assertTrue( is_sticky( $post_id ) );
		$this->assertTrue( ( new ViewPost() )->execute( [ 'id' => $post_id ] )['post']['sticky'] );

		( new UpdatePost() )->execute(
			[
				'id'     => $post_id,
				'sticky' => false,
			]
		);
		$this->assertFalse( is_sticky( $post_id ) );
	}

	public function test_a_supported_format_is_saved(): void {
		add_theme_support( 'post-formats', [ 'aside' ] );
		$post_id = self::factory()->post->create();

		( new UpdatePost() )->execute(
			[
				'id'     => $post_id,
				'format' => 'aside',
			]
		);

		$this->assertSame( 'aside', get_post_format( $post_id ) );
		$this->assertSame( 'aside', ( new ViewPost() )->execute( [ 'id' => $post_id ] )['post']['format'] );
	}

	public function test_an_unsupported_format_is_refused(): void {
		$post_id = self::factory()->post->create();

		$this->assertRefused(
			'invalid_format',
			( new UpdatePost() )->execute(
				[
					'id'     => $post_id,
					'format' => 'gallery',
				]
			)
		);
		$this->assertFalse( get_post_format( $post_id ) );
	}

	public function test_view_post_returns_the_fields_before_a_write(): void {
		$post_id = self::factory()->post->create(
			[
				'post_name'      => 'hello-there',
				'comment_status' => 'closed',
			]
		);

		$post = ( new ViewPost() )->execute( [ 'id' => $post_id ] )['post'];

		$this->assertSame( 'hello-there', $post['slug'] );
		$this->assertSame( 'closed', $post['comment_status'] );
		$this->assertSame( '', $post['template'] );
		$this->assertFalse( $post['sticky'] );
		$this->assertSame( 'standard', $post['format'] );
	}

	public function test_the_schema_refuses_a_comment_status_outside_open_and_closed(): void {
		$ability = wp_get_ability( 'albert/update-post' );
		if ( ! $ability ) {
			$this->markTestSkipped( 'albert/update-post is not registered.' );
		}

		$result = $ability->execute(
			[
				'id'             => self::factory()->post->create(),
				'comment_status' => 'registered_only',
			]
		);

		$this->assertRefused( 'ability_invalid_input', $result );
	}
}
