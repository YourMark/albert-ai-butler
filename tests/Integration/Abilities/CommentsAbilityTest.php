<?php
/**
 * Integration tests for the comment abilities.
 *
 * Real comments, core's comments endpoint, privacy mode and the safe mode gate.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Comments\DeleteComment;
use Albert\Abilities\WordPress\Comments\FindComments;
use Albert\Abilities\WordPress\Comments\ModerateComment;
use Albert\Abilities\WordPress\Comments\ReplyToComment;
use Albert\Abilities\WordPress\Comments\ViewComment;
use Albert\SafeMode\Gate;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Comment ability tests.
 */
class CommentsAbilityTest extends TestCase {

	/**
	 * Post the comments are on.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * A held comment from a visitor.
	 *
	 * @var int
	 */
	private int $held_id;

	/**
	 * Run as an administrator with a post, a held comment and an approved one.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
		delete_option( 'albert_privacy_mode' );

		$this->post_id = self::factory()->post->create( [ 'post_title' => 'Commented post' ] );
		$this->held_id = $this->comment(
			[
				'comment_approved'     => '0',
				'comment_author'       => 'Jane Visitor',
				'comment_author_email' => 'jane@example.com',
				'comment_author_url'   => 'https://jane.example.com',
				'comment_author_IP'    => '203.0.113.7',
				'comment_content'      => 'Is this still in stock?',
			]
		);
		$this->comment(
			[
				'comment_approved' => '1',
				'comment_content'  => 'Great post.',
			]
		);
	}

	/**
	 * Create a comment on the test post.
	 *
	 * @param array<string, mixed> $args Comment fields.
	 * @return int
	 */
	private function comment( array $args ): int {
		return (int) self::factory()->comment->create( array_merge( [ 'comment_post_ID' => $this->post_id ], $args ) );
	}

	/**
	 * Held comments are found by status, and the commenter's details are masked by default.
	 *
	 * @return void
	 */
	public function test_find_held_comments_masks_personal_data(): void {
		$result = ( new FindComments() )->execute( [ 'status' => 'hold' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );

		$comment = $result['comments'][0];
		$this->assertSame( $this->held_id, $comment['id'] );
		$this->assertSame( 'hold', $comment['status'] );
		$this->assertSame( 'Is this still in stock?', $comment['content'] );
		$this->assertSame( 'J.V.', $comment['author']['name'] );
		$this->assertSame( 'j***@e***.com', $comment['author']['email'] );
		$this->assertSame( '', $comment['author']['url'] );
		$this->assertArrayNotHasKey( 'ip_address', $comment['author'] );
	}

	/**
	 * Balanced mode reveals on request to a user who may moderate; Strict never does.
	 *
	 * @return void
	 */
	public function test_reveal_follows_privacy_mode(): void {
		$args = [
			'id'                   => $this->held_id,
			'reveal_personal_data' => true,
		];

		$revealed = ( new ViewComment() )->execute( $args );
		$this->assertIsArray( $revealed );
		$this->assertSame( 'jane@example.com', $revealed['comment']['author']['email'] );
		$this->assertSame( '203.0.113.7', $revealed['comment']['author']['ip_address'] );

		update_option( 'albert_privacy_mode', 'strict' );
		$strict = ( new ViewComment() )->execute( $args );
		$this->assertIsArray( $strict );
		$this->assertSame( 'j***@e***.com', $strict['comment']['author']['email'] );
	}

	/**
	 * Off returns the details unmasked.
	 *
	 * @return void
	 */
	public function test_privacy_off_returns_raw_details(): void {
		update_option( 'albert_privacy_mode', 'off' );

		$result = ( new ViewComment() )->execute( [ 'id' => $this->held_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'Jane Visitor', $result['comment']['author']['name'] );
	}

	/**
	 * WooCommerce reviews and editor notes are never listed, even with type "all".
	 *
	 * @return void
	 */
	public function test_find_excludes_reviews_and_notes(): void {
		$review = $this->comment(
			[
				'comment_type'     => 'review',
				'comment_approved' => '1',
			]
		);
		$note   = $this->comment(
			[
				'comment_type'     => 'note',
				'comment_approved' => '1',
			]
		);

		foreach ( [ 'comment', 'all' ] as $type ) {
			$result = ( new FindComments() )->execute( [ 'type' => $type ] );
			$this->assertIsArray( $result );

			$ids = array_column( $result['comments'], 'id' );
			$this->assertNotContains( $review, $ids, $type );
			$this->assertNotContains( $note, $ids, $type );
			$this->assertContains( $this->held_id, $ids, $type );
		}
	}

	/**
	 * A review or note asked for by ID is not found, by any ability.
	 *
	 * @return void
	 */
	public function test_reviews_and_notes_are_not_found_by_id(): void {
		foreach ( [ 'review', 'note' ] as $type ) {
			$id = $this->comment( [ 'comment_type' => $type ] );

			foreach ( [ new ViewComment(), new DeleteComment() ] as $ability ) {
				$result = $ability->execute( [ 'id' => $id ] );
				$this->assertInstanceOf( WP_Error::class, $result, $type );
				$this->assertSame( 'comment_not_found', $result->get_error_code(), $type );
			}

			$moderated = ( new ModerateComment() )->execute(
				[
					'id'     => $id,
					'status' => 'spam',
				]
			);
			$this->assertInstanceOf( WP_Error::class, $moderated, $type );
			$this->assertNotNull( get_comment( $id ), $type );
		}
	}

	/**
	 * A moderator who cannot edit a post does not see or touch its comments.
	 *
	 * @return void
	 */
	public function test_comments_on_posts_the_user_cannot_edit_are_hidden(): void {
		$private = self::factory()->post->create( [ 'post_status' => 'private' ] );
		$hidden  = (int) self::factory()->comment->create(
			[
				'comment_post_ID'  => $private,
				'comment_approved' => '0',
			]
		);

		$user = self::factory()->user->create( [ 'role' => 'author' ] );
		get_user_by( 'id', $user )->add_cap( 'moderate_comments' );
		wp_set_current_user( $user );

		$found = ( new FindComments() )->execute( [ 'status' => 'all' ] );
		$this->assertIsArray( $found );
		$this->assertNotContains( $hidden, array_column( $found['comments'], 'id' ) );

		$viewed = ( new ViewComment() )->execute( [ 'id' => $hidden ] );
		$this->assertInstanceOf( WP_Error::class, $viewed );

		$replied = ( new ReplyToComment() )->execute(
			[
				'id'      => $hidden,
				'content' => 'Thanks!',
			]
		);
		$this->assertInstanceOf( WP_Error::class, $replied );
		$this->assertSame( '0', get_comment( $hidden )->comment_approved );
	}

	/**
	 * View shows the parent and replies.
	 *
	 * @return void
	 */
	public function test_view_comment_includes_thread(): void {
		$reply = $this->comment(
			[
				'comment_parent'   => $this->held_id,
				'comment_approved' => '1',
			]
		);

		$result = ( new ViewComment() )->execute( [ 'id' => $reply ] );
		$this->assertIsArray( $result );
		$this->assertSame( $this->held_id, $result['in_reply']['id'] );

		$parent = ( new ViewComment() )->execute( [ 'id' => $this->held_id ] );
		$this->assertIsArray( $parent );
		$this->assertSame( [ $reply ], array_column( $parent['replies'], 'id' ) );
		$this->assertNull( $parent['in_reply'] );
	}

	/**
	 * A missing comment is reported as not found.
	 *
	 * @return void
	 */
	public function test_unknown_comment_is_not_found(): void {
		$result = ( new ViewComment() )->execute( [ 'id' => 999999 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'comment_not_found', $result->get_error_code() );
	}

	/**
	 * Replying approves a held comment, as wp-admin does, and publishes the reply as the user.
	 *
	 * @return void
	 */
	public function test_reply_approves_the_held_comment_and_posts_as_the_user(): void {
		$result = ( new ReplyToComment() )->execute(
			[
				'id'      => $this->held_id,
				'content' => 'Yes, it is.',
			]
		);

		$this->assertIsArray( $result );
		$reply = get_comment( $result['id'] );
		$this->assertSame( $this->held_id, (int) $reply->comment_parent );
		$this->assertSame( get_current_user_id(), (int) $reply->user_id );
		$this->assertSame( 'Yes, it is.', $reply->comment_content );
		$this->assertSame( '1', get_comment( $this->held_id )->comment_approved );
	}

	/**
	 * A reply to spam is refused, so nothing is published under it.
	 *
	 * @return void
	 */
	public function test_reply_to_spam_is_refused(): void {
		wp_spam_comment( $this->held_id );

		$result = ( new ReplyToComment() )->execute(
			[
				'id'      => $this->held_id,
				'content' => 'Hello',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'comment_not_repliable', $result->get_error_code() );
	}

	/**
	 * Moderation sets each status, and an unchanged status is not a failure.
	 *
	 * @return void
	 */
	public function test_moderate_sets_status(): void {
		$moderate = new ModerateComment();

		$this->assertIsArray(
			$moderate->execute(
				[
					'id'     => $this->held_id,
					'status' => 'approved',
				]
			)
		);
		$this->assertSame( 'approved', wp_get_comment_status( $this->held_id ) );

		$again = $moderate->execute(
			[
				'id'     => $this->held_id,
				'status' => 'approved',
			]
		);
		$this->assertIsArray( $again );
		$this->assertSame( [ $this->held_id ], $again['updated'] );

		$moderate->execute(
			[
				'id'     => $this->held_id,
				'status' => 'spam',
			]
		);
		$this->assertSame( 'spam', wp_get_comment_status( $this->held_id ) );

		$moderate->execute(
			[
				'id'     => $this->held_id,
				'status' => 'trash',
			]
		);
		$this->assertSame( 'trash', wp_get_comment_status( $this->held_id ) );
	}

	/**
	 * Bulk moderation goes ahead for the rest and lists what failed.
	 *
	 * @return void
	 */
	public function test_bulk_moderation_lists_failures(): void {
		$other = $this->comment( [ 'comment_approved' => '0' ] );

		$result = ( new ModerateComment() )->execute(
			[
				'ids'    => [ $this->held_id, $other, 999999 ],
				'status' => 'spam',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ $this->held_id, $other ], $result['updated'] );
		$this->assertSame( 999999, $result['failed'][0]['id'] );
		$this->assertSame( 'comment_not_found', $result['failed'][0]['code'] );
		$this->assertSame( 'spam', wp_get_comment_status( $other ) );
	}

	/**
	 * Moderating without an ID is refused.
	 *
	 * @return void
	 */
	public function test_moderate_without_id_is_refused(): void {
		$result = ( new ModerateComment() )->execute( [ 'status' => 'approved' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'comment_id_missing', $result->get_error_code() );
	}

	/**
	 * Delete removes the comment for good.
	 *
	 * @return void
	 */
	public function test_delete_is_permanent(): void {
		$result = ( new DeleteComment() )->execute( [ 'id' => $this->held_id ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertNull( get_comment( $this->held_id ) );
	}

	/**
	 * Every comment ability is refused to a user who may not moderate comments.
	 *
	 * @return void
	 */
	public function test_authors_are_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		foreach ( [ new FindComments(), new ViewComment(), new ReplyToComment(), new ModerateComment(), new DeleteComment() ] as $ability ) {
			$this->assertInstanceOf( WP_Error::class, $ability->check_permission(), $ability->get_id() );
		}
	}

	/**
	 * Reads pass safe mode; reply, moderation and deletion are held.
	 *
	 * @return void
	 */
	public function test_safe_mode_holds_every_comment_write(): void {
		$gate = new Gate();

		$expected = [
			'albert/find-comments'    => false,
			'albert/view-comment'     => false,
			'albert/reply-to-comment' => true,
			'albert/moderate-comment' => true,
			'albert/delete-comment'   => true,
		];

		foreach ( $expected as $id => $held ) {
			$ability = wp_get_ability( $id );
			$this->assertNotNull( $ability, $id . ' is registered' );
			$this->assertSame( $held, $gate->must_gate( $ability, [ 'id' => $this->held_id ] ), $id );
		}
	}
}
