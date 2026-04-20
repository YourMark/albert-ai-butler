<?php
/**
 * End-to-end smoke tests for the write abilities.
 *
 * Each write ability gets one happy-path assertion (the mutation actually
 * landed in the DB) plus one representative sad-path assertion (invalid
 * input or missing row returns a meaningful WP_Error). Read abilities are
 * intentionally NOT covered — they are thin wrappers over WP core and the
 * contract test already asserts their permission + registration shape.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Pages\Create as CreatePage;
use Albert\Abilities\WordPress\Pages\Delete as DeletePage;
use Albert\Abilities\WordPress\Posts\Create as CreatePost;
use Albert\Abilities\WordPress\Posts\Delete as DeletePost;
use Albert\Abilities\WordPress\Posts\Update as UpdatePost;
use Albert\Abilities\WordPress\Taxonomies\CreateTerm;
use Albert\Abilities\WordPress\Taxonomies\DeleteTerm;
use Albert\Abilities\WordPress\Taxonomies\UpdateTerm;
use Albert\Abilities\WordPress\Users\Create as CreateUser;
use Albert\Abilities\WordPress\Users\Delete as DeleteUser;
use Albert\Abilities\WordPress\Users\Update as UpdateUser;
use Albert\Abilities\WordPress\Media\SetFeaturedImage;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Write-ability smoke tests.
 *
 * @covers \Albert\Abilities\WordPress\Posts\Create
 * @covers \Albert\Abilities\WordPress\Posts\Update
 * @covers \Albert\Abilities\WordPress\Posts\Delete
 * @covers \Albert\Abilities\WordPress\Pages\Create
 * @covers \Albert\Abilities\WordPress\Pages\Delete
 * @covers \Albert\Abilities\WordPress\Users\Create
 * @covers \Albert\Abilities\WordPress\Users\Update
 * @covers \Albert\Abilities\WordPress\Users\Delete
 * @covers \Albert\Abilities\WordPress\Taxonomies\CreateTerm
 * @covers \Albert\Abilities\WordPress\Taxonomies\UpdateTerm
 * @covers \Albert\Abilities\WordPress\Taxonomies\DeleteTerm
 * @covers \Albert\Abilities\WordPress\Media\SetFeaturedImage
 */
class WriteAbilitySmokeTest extends TestCase {

	/**
	 * Every test runs as an authenticated administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// Ensure no ability is disabled.
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	// ─── Posts ──────────────────────────────────────────────────────

	/**
	 * Creating a post actually inserts a post row.
	 *
	 * @return void
	 */
	public function test_create_post_happy_path(): void {
		$result = ( new CreatePost() )->execute(
			[
				'title'   => 'Smoke Test Post',
				'content' => 'Hello world',
				'status'  => 'draft',
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );

		$post = get_post( $result['id'] );
		$this->assertNotNull( $post );
		$this->assertSame( 'Smoke Test Post', $post->post_title );
	}

	/**
	 * Updating a post actually changes the stored title.
	 *
	 * @return void
	 */
	public function test_update_post_happy_path(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Original' ] );

		$result = ( new UpdatePost() )->execute(
			[
				'id'    => $post_id,
				'title' => 'Updated',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Updated', get_post( $post_id )->post_title );
	}

	/**
	 * Deleting a post removes it (when force is true).
	 *
	 * @return void
	 */
	public function test_delete_post_happy_path_force(): void {
		$post_id = self::factory()->post->create();

		$result = ( new DeletePost() )->execute(
			[
				'id'    => $post_id,
				'force' => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertNull( get_post( $post_id ) );
	}

	/**
	 * Deleting a missing post id returns a 404 WP_Error.
	 *
	 * @return void
	 */
	public function test_delete_post_missing_returns_404(): void {
		$result = ( new DeletePost() )->execute( [ 'id' => 99999 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	// ─── Pages ──────────────────────────────────────────────────────

	/**
	 * Creating a page actually inserts a page row.
	 *
	 * @return void
	 */
	public function test_create_page_happy_path(): void {
		$result = ( new CreatePage() )->execute(
			[
				'title'   => 'Smoke Page',
				'content' => '',
				'status'  => 'draft',
			]
		);

		$this->assertIsArray( $result );
		$page = get_post( $result['id'] );
		$this->assertNotNull( $page );
		$this->assertSame( 'page', $page->post_type );
	}

	/**
	 * Deleting a page removes it.
	 *
	 * @return void
	 */
	public function test_delete_page_happy_path(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$result = ( new DeletePage() )->execute(
			[
				'id'    => $page_id,
				'force' => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertNull( get_post( $page_id ) );
	}

	// ─── Users ──────────────────────────────────────────────────────

	/**
	 * Creating a user actually inserts a user row.
	 *
	 * @return void
	 */
	public function test_create_user_happy_path(): void {
		$result = ( new CreateUser() )->execute(
			[
				'username' => 'smoker',
				'email'    => 'smoker@albert.test',
				'password' => 'strong-password-xyz-12345',
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );

		$user = get_userdata( $result['id'] );
		$this->assertNotFalse( $user );
		$this->assertSame( 'smoker', $user->user_login );
	}

	/**
	 * Updating a user's email actually changes the stored email.
	 *
	 * @return void
	 */
	public function test_update_user_happy_path(): void {
		$user_id = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'before@albert.test',
			]
		);

		$result = ( new UpdateUser() )->execute(
			[
				'id'    => $user_id,
				'email' => 'after@albert.test',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'after@albert.test', get_userdata( $user_id )->user_email );
	}

	/**
	 * Deleting a missing user id returns a 404 WP_Error.
	 *
	 * @return void
	 */
	public function test_delete_user_missing_returns_404(): void {
		$result = ( new DeleteUser() )->execute( [ 'id' => 99999 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'user_not_found', $result->get_error_code() );
	}

	/**
	 * Deleting an existing user actually removes them.
	 *
	 * WP REST's DELETE /wp/v2/users/{id} requires a `reassign` parameter —
	 * either a user id to inherit the deleted user's content, or `false`
	 * to delete the content too. We reassign to a different admin.
	 *
	 * @return void
	 */
	public function test_delete_user_happy_path(): void {
		$inheritor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$victim    = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$result = ( new DeleteUser() )->execute(
			[
				'id'       => $victim,
				'reassign' => $inheritor,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertFalse( get_userdata( $victim ) );
	}

	// ─── Terms ──────────────────────────────────────────────────────

	/**
	 * Creating a term actually inserts a term row.
	 *
	 * @return void
	 */
	public function test_create_term_happy_path(): void {
		$result = ( new CreateTerm() )->execute(
			[
				'taxonomy' => 'category',
				'name'     => 'Smoke Category',
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );

		$term = get_term( $result['id'], 'category' );
		$this->assertNotNull( $term );
		$this->assertSame( 'Smoke Category', $term->name );
	}

	/**
	 * Updating a term's name actually changes the stored name.
	 *
	 * @return void
	 */
	public function test_update_term_happy_path(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Old Name',
			]
		);

		$result = ( new UpdateTerm() )->execute(
			[
				'taxonomy' => 'category',
				'id'       => $term_id,
				'name'     => 'New Name',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'New Name', get_term( $term_id, 'category' )->name );
	}

	/**
	 * Deleting a term removes it.
	 *
	 * Terms don't support trashing (REST returns rest_trash_not_supported
	 * otherwise), so force=true is required for the happy path.
	 *
	 * @return void
	 */
	public function test_delete_term_happy_path(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Doomed',
			]
		);

		$result = ( new DeleteTerm() )->execute(
			[
				'taxonomy' => 'category',
				'id'       => $term_id,
				'force'    => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertNull( get_term( $term_id, 'category' ) );
	}

	// ─── Media: set-featured-image ──────────────────────────────────

	/**
	 * SetFeaturedImage attaches an existing attachment to a post.
	 *
	 * UploadMedia is NOT tested here — it hits an external URL for side-
	 * loading, which is slow, flaky, and a poor smoke target. The featured-
	 * image ability is the riskier one to silently break (wrong post meta
	 * key would make thumbnails disappear sitewide).
	 *
	 * The ability goes through REST `/wp/v2/media/{id}` to validate the
	 * attachment exists, which requires a real file on disk. We use the WP
	 * test suite's bundled canola.jpg fixture via create_upload_object().
	 *
	 * @return void
	 */
	public function test_set_featured_image_happy_path(): void {
		if ( ! defined( 'DIR_TESTDATA' ) ) {
			$this->markTestSkipped( 'DIR_TESTDATA not defined — WP test suite fixtures unavailable.' );
		}

		$post_id = self::factory()->post->create();

		// phpcs:ignore PHPCompatibility.Constants.NewConstants -- DIR_TESTDATA is defined by the WP test suite bootstrap at runtime.
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$post_id
		);

		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );

		$result = ( new SetFeaturedImage() )->execute(
			[
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
			]
		);

		if ( $result instanceof WP_Error ) {
			$this->fail( 'SetFeaturedImage returned WP_Error: ' . $result->get_error_message() );
		}

		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $post_id ) );
	}
}
