<?php
/**
 * Integration tests for naming the target of a held call.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\SafeMode;

use Albert\SafeMode\TargetResolver;
use Albert\Tests\TestCase;

/**
 * TargetResolver integration tests.
 *
 * @covers \Albert\SafeMode\TargetResolver
 */
class TargetResolverTest extends TestCase {

	/**
	 * A page addressed by page_id is named, so a held call can warn when it was edited since.
	 *
	 * @return void
	 */
	public function test_a_page_id_names_the_page(): void {
		$page_id = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'About us',
			]
		);

		$target = ( new TargetResolver() )->describe( 'albert/replace-page-text', [ 'page_id' => $page_id ] );

		$this->assertIsArray( $target );
		$this->assertSame( $page_id, $target['id'] );
		$this->assertSame( 'About us', $target['label'] );
	}

	/**
	 * A held restore names the revision's post, and warns when that post was edited since.
	 *
	 * @return void
	 */
	public function test_a_revision_id_names_its_post(): void {
		$page_id = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Home',
			]
		);
		wp_update_post(
			[
				'ID'           => $page_id,
				'post_content' => 'Welcome.',
			]
		);
		$revision_id = (int) wp_save_post_revision( $page_id );
		if ( ! $revision_id ) {
			$revision_id = (int) array_key_first( wp_get_post_revisions( $page_id ) );
		}
		$resolver = new TargetResolver();

		$staged = $resolver->describe( 'albert/restore-revision', [ 'revision_id' => $revision_id ] );

		$this->assertIsArray( $staged );
		$this->assertSame( 'page', $staged['type'] );
		$this->assertSame( $page_id, $staged['id'] );
		$this->assertSame( 'Home', $staged['label'] );

		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_modified_gmt' => '2030-01-01 00:00:00' ], [ 'ID' => $page_id ] );
		clean_post_cache( $page_id );

		$this->assertTrue( $resolver->has_drifted( $staged, $resolver->describe( 'albert/restore-revision', [ 'revision_id' => $revision_id ] ) ) );
		$this->assertNull( $resolver->describe( 'albert/restore-revision', [ 'revision_id' => $page_id ] ), 'A post ID is not a revision.' );
	}
}
