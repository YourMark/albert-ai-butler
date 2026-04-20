<?php
/**
 * Parameter-level integration tests for Media abilities.
 *
 * Verifies that every input parameter on FindMedia, ViewMedia, and
 * SetFeaturedImage actually works as documented. UploadMedia is excluded
 * because it sideloads from an external URL, making it slow and flaky
 * in a test environment.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Media\FindMedia;
use Albert\Abilities\WordPress\Media\SetFeaturedImage;
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
