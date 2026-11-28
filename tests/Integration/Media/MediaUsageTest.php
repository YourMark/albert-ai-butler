<?php
/**
 * Integration tests for where a media file is used.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Media;

use Albert\Media\MediaUsage;
use Albert\Tests\TestCase;

/**
 * Each placement, with its own alt text, link, caption and surrounding text.
 *
 * @covers \Albert\Media\MediaUsage
 * @covers \Albert\Abilities\WordPress\Media\ViewMedia
 */
class MediaUsageTest extends TestCase {

	/**
	 * The media file.
	 *
	 * @var int
	 */
	private int $image;

	/**
	 * Create the file and log in as an editor.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->image = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
		update_post_meta( $this->image, '_wp_attachment_image_alt', 'Library default' );
	}

	/**
	 * A block placement: path, alt there, link, caption, nearby text and heading.
	 *
	 * @return void
	 */
	public function test_a_block_placement_and_its_context(): void {
		$id   = $this->image;
		$post = self::factory()->post->create(
			[
				'post_title'   => 'Spring range',
				'post_content' => '<!-- wp:heading --><h2 class="wp-block-heading">New drums</h2><!-- /wp:heading -->'
					. '<!-- wp:paragraph --><p>Our birch kit arrived today.</p><!-- /wp:paragraph -->'
					. '<!-- wp:image {"id":' . $id . ',"linkDestination":"custom"} --><figure class="wp-block-image"><a href="https://example.test/shop"><img src="x.jpg" alt="Birch kit in blue" class="wp-image-' . $id . '"/></a><figcaption class="wp-element-caption">The 2026 kit</figcaption></figure><!-- /wp:image -->'
					. '<!-- wp:paragraph --><p>Order before Friday.</p><!-- /wp:paragraph -->',
			]
		);

		$placement = MediaUsage::of( $id )['placements'][0];

		$this->assertSame( $post, $placement['post_id'] );
		$this->assertSame( 'block', $placement['kind'] );
		$this->assertSame( 'core/image', $placement['block'] );
		$this->assertSame( [ 2 ], $placement['path'] );
		$this->assertSame( 'Birch kit in blue', $placement['alt'] );
		$this->assertSame( 'https://example.test/shop', $placement['link'] );
		$this->assertSame( 'The 2026 kit', $placement['caption'] );
		$this->assertSame( 'New drums', $placement['heading'] );
		$this->assertSame( 'Our birch kit arrived today.', $placement['before'] );
		$this->assertSame( 'Order before Friday.', $placement['after'] );
		$this->assertFalse( $placement['decorative'] );
	}

	/**
	 * Inside a gallery, in classic content and as a featured image; a decorative image says so.
	 *
	 * @return void
	 */
	public function test_every_kind_of_placement(): void {
		$id = $this->image;
		self::factory()->post->create( [ 'post_content' => '<!-- wp:gallery --><figure class="wp-block-gallery"><!-- wp:image {"id":' . $id . ',"isDecorative":true} --><figure class="wp-block-image"><img src="x.jpg" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->' ] );
		self::factory()->post->create( [ 'post_content' => '<p>Old post <img class="alignnone wp-image-99999" src="a.jpg" alt="Other"> and <img class="size-full wp-image-' . $id . '" src="x.jpg" alt="Classic alt"></p>' ] );
		$featured = self::factory()->post->create();
		// Direct: set_post_thumbnail() needs a real image file.
		update_post_meta( $featured, '_thumbnail_id', $id );

		$kinds = [];
		foreach ( MediaUsage::of( $id )['placements'] as $placement ) {
			$kinds[ $placement['kind'] ] = $placement;
		}

		$this->assertSame( [ 0, 0 ], $kinds['block']['path'] );
		$this->assertTrue( $kinds['block']['decorative'] );
		$this->assertSame( 'Classic alt', $kinds['classic']['alt'] );
		$this->assertSame( $featured, $kinds['featured_image']['post_id'] );
	}

	/**
	 * A longer id that starts with this one is not a match, and content the user cannot read is left out.
	 *
	 * @return void
	 */
	public function test_no_false_or_hidden_matches(): void {
		$id    = $this->image;
		$other = (int) ( $id . '7' );
		self::factory()->post->create( [ 'post_content' => '<!-- wp:image {"id":' . $other . '} --><figure class="wp-block-image"><img src="y.jpg" alt="" class="wp-image-' . $other . '"/></figure><!-- /wp:image -->' ] );
		self::factory()->post->create(
			[
				'post_status'  => 'private',
				'post_author'  => self::factory()->user->create( [ 'role' => 'administrator' ] ),
				'post_content' => '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="x.jpg" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->',
			]
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$this->assertSame( [], MediaUsage::of( $id )['placements'] );
	}

	/**
	 * View-media reports the placements next to the file.
	 *
	 * @return void
	 */
	public function test_view_media_includes_the_placements(): void {
		$id = $this->image;
		self::factory()->post->create( [ 'post_content' => '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="x.jpg" alt="Here" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->' ] );

		$result = wp_get_ability( 'albert/view-media' )->execute( [ 'id' => $id ] );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'Library default', $result['media']['alt_text'] );
		$this->assertSame( 'Here', $result['used_in'][0]['alt'] );
		$this->assertFalse( $result['used_in_more'] );
	}
}
