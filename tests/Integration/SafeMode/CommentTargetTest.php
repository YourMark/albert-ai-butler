<?php
/**
 * Integration tests for naming the comments a held call acts on.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\SafeMode;

use Albert\SafeMode\TargetResolver;
use Albert\Tests\TestCase;

/**
 * TargetResolver comment target tests.
 *
 * @covers \Albert\SafeMode\TargetResolver
 */
class CommentTargetTest extends TestCase {

	/**
	 * One comment is named with its post and status, so a held delete shows what it removes.
	 *
	 * @return void
	 */
	public function test_one_comment_names_its_post(): void {
		$post_id    = self::factory()->post->create( [ 'post_title' => 'Pricing' ] );
		$comment_id = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
			]
		);

		$target = ( new TargetResolver() )->describe( 'albert/delete-comment', [ 'comment_ids' => [ $comment_id ] ] );

		$this->assertIsArray( $target );
		$this->assertSame( 'comment', $target['type'] );
		$this->assertSame( sprintf( "Comment %d on 'Pricing'", $comment_id ), $target['label'] );
		$this->assertSame( 'approved', $target['status'] );
		$this->assertSame( $target, ( new TargetResolver() )->describe( 'albert/reply-to-comment', [ 'comment_id' => $comment_id ] ) );
	}

	/**
	 * A batch across posts names each post, three at most.
	 *
	 * @return void
	 */
	public function test_a_batch_names_the_posts_it_spans(): void {
		$ids = [];
		foreach ( [ 'One', 'Two', 'Three', 'Four' ] as $title ) {
			$ids[] = self::factory()->comment->create( [ 'comment_post_ID' => self::factory()->post->create( [ 'post_title' => $title ] ) ] );
		}
		$ids[] = self::factory()->comment->create( [ 'comment_post_ID' => get_comment( $ids[0] )->comment_post_ID ] );

		$target = ( new TargetResolver() )->describe( 'albert/delete-comment', [ 'comment_ids' => $ids ] );

		$this->assertIsArray( $target );
		$this->assertSame( "5 comments on 'One', 'Two', 'Three' and 1 other post", $target['label'] );
		$this->assertSame( '', $target['status'] );
	}

	/**
	 * Editing, moderating or deleting a comment after staging marks the batch as changed.
	 *
	 * @return void
	 */
	public function test_a_changed_comment_counts_as_drift(): void {
		$resolver = new TargetResolver();
		$ids      = self::factory()->comment->create_many( 2, [ 'comment_post_ID' => self::factory()->post->create() ] );
		$input    = [ 'comment_ids' => $ids ];
		$staged   = $resolver->describe( 'albert/delete-comment', $input );

		$this->assertFalse( $resolver->has_drifted( $staged, $resolver->describe( 'albert/delete-comment', $input ) ) );

		wp_update_comment(
			[
				'comment_ID'      => $ids[0],
				'comment_content' => 'Edited since',
			]
		);
		$this->assertTrue( $resolver->has_drifted( $staged, $resolver->describe( 'albert/delete-comment', $input ) ) );

		$staged = $resolver->describe( 'albert/delete-comment', $input );
		wp_set_comment_status( $ids[1], 'spam' );
		$this->assertTrue( $resolver->has_drifted( $staged, $resolver->describe( 'albert/delete-comment', $input ) ) );

		$staged = $resolver->describe( 'albert/delete-comment', $input );
		wp_delete_comment( $ids[1], true );
		$this->assertTrue( $resolver->has_drifted( $staged, $resolver->describe( 'albert/delete-comment', $input ) ) );
	}

	/**
	 * Comments that no longer exist resolve to nothing, so the screen shows them as gone.
	 *
	 * @return void
	 */
	public function test_deleted_comments_resolve_to_null(): void {
		$this->assertNull( ( new TargetResolver() )->describe( 'albert/delete-comment', [ 'comment_ids' => [ 999999 ] ] ) );
	}
}
