<?php
/**
 * Parameter-level integration tests for Media abilities.
 *
 * Verifies that every input parameter on FindMedia, ViewMedia,
 * SetFeaturedImage, UpdateMedia and DeleteMedia actually works as documented. UploadMedia is excluded
 * because it sideloads from an external URL, making it slow and flaky
 * in a test environment.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Media\DeleteMedia;
use Albert\Abilities\WordPress\Media\FindMedia;
use Albert\Abilities\WordPress\Media\SetFeaturedImage;
use Albert\Abilities\WordPress\Media\UpdateMedia;
use Albert\Abilities\WordPress\Media\ViewMedia;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Media ability parameter tests.
 *
 * @since 1.1.0
 */
class MediaAbilityTest extends TestCase {

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

	/**
	 * Create a test attachment from the WP test suite fixtures.
	 *
	 * @param int $parent_id Optional parent post ID.
	 *
	 * @return int Attachment ID.
	 */
	private function create_test_attachment( int $parent_id = 0 ): int {
		if ( ! defined( 'DIR_TESTDATA' ) ) {
			$this->markTestSkipped( 'DIR_TESTDATA not defined — WP test suite fixtures unavailable.' );
		}

		// phpcs:ignore PHPCompatibility.Constants.NewConstants
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$parent_id
		);

		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );

		return $attachment_id;
	}

	// ─── FindMedia ──────────────────────────────────────────────────

	/**
	 * FindMedia returns media items.
	 *
	 * @return void
	 */
	public function test_find_media_returns_items(): void {
		$this->create_test_attachment();

		$result = ( new FindMedia() )->execute( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'media', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
	}

	/**
	 * Search parameter filters media by title.
	 *
	 * @return void
	 */
	public function test_find_media_search(): void {
		$attachment_id = $this->create_test_attachment();
		wp_update_post(
			[
				'ID'         => $attachment_id,
				'post_title' => 'Unique Searchable Media Title',
			]
		);

		$result = ( new FindMedia() )->execute( [ 'search' => 'Unique Searchable Media' ] );

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 1, count( $result['media'] ) );

		$titles = array_column( $result['media'], 'title' );
		$this->assertContains( 'Unique Searchable Media Title', $titles );
	}

	/**
	 * Mime_type parameter filters by MIME type.
	 *
	 * @return void
	 */
	public function test_find_media_mime_type_filter(): void {
		$this->create_test_attachment(); // image/jpeg

		$result = ( new FindMedia() )->execute( [ 'mime_type' => 'image/jpeg' ] );

		$this->assertIsArray( $result );
		foreach ( $result['media'] as $item ) {
			$this->assertSame( 'image/jpeg', $item['mime_type'] );
		}
	}

	/**
	 * Pagination works correctly.
	 *
	 * @return void
	 */
	public function test_find_media_pagination(): void {
		$this->create_test_attachment();
		$this->create_test_attachment();
		$this->create_test_attachment();

		$page1 = ( new FindMedia() )->execute(
			[
				'per_page' => 2,
				'page'     => 1,
			]
		);
		$page2 = ( new FindMedia() )->execute(
			[
				'per_page' => 2,
				'page'     => 2,
			]
		);

		$this->assertCount( 2, $page1['media'] );
		$this->assertGreaterThanOrEqual( 1, count( $page2['media'] ) );
		$this->assertNotSame( $page1['media'][0]['id'], $page2['media'][0]['id'] );
	}

	/**
	 * FindMedia returns each item's alt text.
	 *
	 * @return void
	 */
	public function test_find_media_returns_alt_text(): void {
		$attachment_id = $this->create_test_attachment();
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'A canola field' );

		$result = ( new FindMedia() )->execute( [] );
		$item   = array_values( wp_list_filter( $result['media'], [ 'id' => $attachment_id ] ) )[0];

		$this->assertSame( 'A canola field', $item['alt_text'] );
	}

	/**
	 * Missing_alt_text lists images with no alt row or an empty one, and nothing else.
	 *
	 * @return void
	 */
	public function test_find_media_missing_alt_text(): void {
		$with_alt  = $this->create_test_attachment();
		$no_row    = $this->create_test_attachment();
		$empty_alt = $this->create_test_attachment();
		update_post_meta( $with_alt, '_wp_attachment_image_alt', 'A canola field' );
		delete_post_meta( $no_row, '_wp_attachment_image_alt' );
		update_post_meta( $empty_alt, '_wp_attachment_image_alt', '' );

		$document = self::factory()->attachment->create(
			[
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'A PDF',
			]
		);

		$result = ( new FindMedia() )->execute(
			[
				'missing_alt_text' => true,
				'per_page'         => 100,
			]
		);
		$ids    = array_column( $result['media'], 'id' );

		$this->assertContains( $no_row, $ids );
		$this->assertContains( $empty_alt, $ids );
		$this->assertNotContains( $with_alt, $ids );
		$this->assertNotContains( $document, $ids, 'Only images need alt text.' );
	}

	/**
	 * An explicit mime_type replaces the images-only default of missing_alt_text.
	 *
	 * @return void
	 */
	public function test_find_media_missing_alt_text_with_mime_type(): void {
		$image    = $this->create_test_attachment();
		$document = self::factory()->attachment->create(
			[
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'A PDF',
			]
		);

		$result = ( new FindMedia() )->execute(
			[
				'missing_alt_text' => true,
				'mime_type'        => 'application/pdf',
				'per_page'         => 100,
			]
		);
		$ids    = array_column( $result['media'], 'id' );

		$this->assertContains( $document, $ids );
		$this->assertNotContains( $image, $ids );
	}

	// UpdateMedia.

	/**
	 * UpdateMedia saves alt text, title, caption and description, and view-media reads them back.
	 *
	 * @return void
	 */
	public function test_update_media_sets_all_four_fields(): void {
		$attachment_id = $this->create_test_attachment();

		$result = ( new UpdateMedia() )->execute(
			[
				'id'          => $attachment_id,
				'alt_text'    => 'A yellow canola field under a blue sky',
				'title'       => 'Canola field',
				'caption'     => 'Spring in the north.',
				'description' => 'Taken in April.',
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'A yellow canola field under a blue sky', $result['media']['alt_text'] );
		$this->assertSame( 'Canola field', $result['media']['title'] );

		$view = ( new ViewMedia() )->execute( [ 'id' => $attachment_id ] )['media'];
		$this->assertSame( 'A yellow canola field under a blue sky', $view['alt_text'] );
		$this->assertSame( 'Canola field', $view['title'] );
		$this->assertSame( 'Spring in the north.', $view['caption'] );
		$this->assertSame( 'Taken in April.', $view['description'] );
	}

	/**
	 * Omitted fields stay as they were; an empty string clears one.
	 *
	 * @return void
	 */
	public function test_update_media_leaves_omitted_fields_and_clears_empty_ones(): void {
		$attachment_id = $this->create_test_attachment();
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Old alt' );
		wp_update_post(
			[
				'ID'           => $attachment_id,
				'post_title'   => 'Keep me',
				'post_excerpt' => 'Old caption',
			]
		);

		( new UpdateMedia() )->execute(
			[
				'id'      => $attachment_id,
				'caption' => '',
			]
		);

		$this->assertSame( 'Old alt', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Keep me', get_post_field( 'post_title', $attachment_id ) );
		$this->assertSame( '', get_post_field( 'post_excerpt', $attachment_id ) );
	}

	/**
	 * Core sanitises what an author sends: alt text becomes plain text, and the caption loses disallowed HTML.
	 *
	 * @return void
	 */
	public function test_update_media_values_are_sanitised_by_core(): void {
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author );
		$attachment_id = $this->create_test_attachment();
		wp_update_post(
			[
				'ID'          => $attachment_id,
				'post_author' => $author,
			]
		);

		$result = ( new UpdateMedia() )->execute(
			[
				'id'       => $attachment_id,
				'alt_text' => '<b>A canola field</b>',
				'caption'  => 'Spring<script>alert(1)</script> in <a href="https://example.com">the north</a>',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'A canola field', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		$caption = get_post_field( 'post_excerpt', $attachment_id );
		$this->assertStringNotContainsString( '<script', $caption );
		$this->assertStringContainsString( '<a href="https://example.com">the north</a>', $caption );
	}

	/**
	 * UpdateMedia refuses an ID that is a post, not an attachment.
	 *
	 * @return void
	 */
	public function test_update_media_rejects_post_id(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Untouched' ] );

		$result = ( new UpdateMedia() )->execute(
			[
				'id'    => $post_id,
				'title' => 'Changed',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_not_found', $result->get_error_code() );
		$this->assertSame( 'Untouched', get_post_field( 'post_title', $post_id ) );
	}

	/**
	 * Core's per-attachment check refuses an author editing someone else's file.
	 *
	 * @return void
	 */
	public function test_update_media_refuses_another_users_attachment(): void {
		$attachment_id = $this->create_test_attachment();
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Admin alt' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$ability = new UpdateMedia();
		$this->assertTrue( $ability->check_permission(), 'An author may use the ability on their own files.' );

		$result = $ability->execute(
			[
				'id'       => $attachment_id,
				'alt_text' => 'Hijacked',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_permission_denied', $result->get_error_code() );
		$this->assertSame( 'Admin alt', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * A user without upload_files cannot use the ability at all.
	 *
	 * @return void
	 */
	public function test_update_media_needs_upload_files(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$this->assertInstanceOf( WP_Error::class, ( new UpdateMedia() )->check_permission() );
		$this->assertInstanceOf( WP_Error::class, ( new DeleteMedia() )->check_permission() );
	}

	// DeleteMedia.

	/**
	 * DeleteMedia removes the attachment permanently.
	 *
	 * @return void
	 */
	public function test_delete_media_removes_it_permanently(): void {
		$attachment_id = $this->create_test_attachment();

		$result = ( new DeleteMedia() )->execute( [ 'id' => $attachment_id ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertNull( get_post( $attachment_id ) );
	}

	/**
	 * DeleteMedia refuses an ID that is a post, and leaves the post alone.
	 *
	 * @return void
	 */
	public function test_delete_media_rejects_post_id(): void {
		$post_id = self::factory()->post->create();

		$result = ( new DeleteMedia() )->execute( [ 'id' => $post_id ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_not_found', $result->get_error_code() );
		$this->assertNotNull( get_post( $post_id ) );
	}

	/**
	 * Core refuses an author deleting someone else's file.
	 *
	 * @return void
	 */
	public function test_delete_media_refuses_another_users_attachment(): void {
		$attachment_id = $this->create_test_attachment();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$result = ( new DeleteMedia() )->execute( [ 'id' => $attachment_id ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_permission_denied', $result->get_error_code() );
		$this->assertNotNull( get_post( $attachment_id ) );
	}

	// ─── ViewMedia ──────────────────────────────────────────────────

	/**
	 * ViewMedia returns all expected fields.
	 *
	 * @return void
	 */
	public function test_view_media_returns_all_fields(): void {
		$attachment_id = $this->create_test_attachment();
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Test alt text' );

		$result = ( new ViewMedia() )->execute( [ 'id' => $attachment_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $attachment_id, $result['media']['id'] );
		$this->assertArrayHasKey( 'title', $result['media'] );
		$this->assertArrayHasKey( 'mime_type', $result['media'] );
		$this->assertArrayHasKey( 'url', $result['media'] );
		$this->assertSame( 'image/jpeg', $result['media']['mime_type'] );
	}

	/**
	 * ViewMedia returns error for non-existent media.
	 *
	 * @return void
	 */
	public function test_view_media_not_found(): void {
		$result = ( new ViewMedia() )->execute( [ 'id' => 99999 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_not_found', $result->get_error_code() );
	}

	/**
	 * ViewMedia rejects a post ID (wrong post type).
	 *
	 * @return void
	 */
	public function test_view_media_rejects_post_id(): void {
		$post_id = self::factory()->post->create();

		$result = ( new ViewMedia() )->execute( [ 'id' => $post_id ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_not_found', $result->get_error_code() );
	}

	// ─── SetFeaturedImage ───────────────────────────────────────────

	/**
	 * SetFeaturedImage attaches an image to a post.
	 *
	 * @return void
	 */
	public function test_set_featured_image_on_post(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = $this->create_test_attachment( $post_id );

		$result = ( new SetFeaturedImage() )->execute(
			[
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( $attachment_id, $result['attachment_id'] );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $post_id ) );
	}

	/**
	 * SetFeaturedImage works on pages too.
	 *
	 * @return void
	 */
	public function test_set_featured_image_on_page(): void {
		$page_id       = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$attachment_id = $this->create_test_attachment( $page_id );

		$result = ( new SetFeaturedImage() )->execute(
			[
				'post_id'       => $page_id,
				'attachment_id' => $attachment_id,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $page_id ) );
	}

	/**
	 * SetFeaturedImage returns error for non-existent post.
	 *
	 * @return void
	 */
	public function test_set_featured_image_invalid_post(): void {
		$attachment_id = $this->create_test_attachment();

		$result = ( new SetFeaturedImage() )->execute(
			[
				'post_id'       => 99999,
				'attachment_id' => $attachment_id,
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * SetFeaturedImage returns error for non-existent attachment.
	 *
	 * @return void
	 */
	public function test_set_featured_image_invalid_attachment(): void {
		$post_id = self::factory()->post->create();

		$result = ( new SetFeaturedImage() )->execute(
			[
				'post_id'       => $post_id,
				'attachment_id' => 99999,
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
