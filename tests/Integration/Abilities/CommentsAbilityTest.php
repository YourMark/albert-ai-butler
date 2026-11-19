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
use Albert\Abilities\WordPress\Comments\GetComment;
use Albert\Abilities\WordPress\Comments\ModerateComment;
use Albert\Abilities\WordPress\Comments\ReplyToComment;
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
		$this->assertSame( 'J.V.', $comment['author']['display_name'] );
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
			'comment_id'           => $this->held_id,
			'reveal_personal_data' => true,
		];

		$revealed = ( new GetComment() )->execute( $args );
		$this->assertIsArray( $revealed );
		$this->assertSame( 'jane@example.com', $revealed['comment']['author']['email'] );
		$this->assertSame( '203.0.113.7', $revealed['comment']['author']['ip_address'] );

		update_option( 'albert_privacy_mode', 'strict' );
		$strict = ( new GetComment() )->execute( $args );
		$this->assertIsArray( $strict );
		$this->assertSame( 'j***@e***.com', $strict['comment']['author']['email'] );
	}

	/**
	 * While details are masked, search only matches the comment text, so it cannot confirm an email.
	 *
	 * @return void
	 */
	public function test_masked_search_matches_only_the_comment_text(): void {
		update_option( 'albert_privacy_mode', 'strict' );

		$by_email   = ( new FindComments() )->execute( [ 'search' => 'jane@example.com' ] );
		$by_content = ( new FindComments() )->execute( [ 'search' => 'still in stock' ] );

		$this->assertIsArray( $by_email );
		$this->assertSame( [], $by_email['comments'] );
		$this->assertIsArray( $by_content );
		$this->assertSame( [ $this->held_id ], array_column( $by_content['comments'], 'id' ) );

		update_option( 'albert_privacy_mode', 'off' );
		$unmasked = ( new FindComments() )->execute( [ 'search' => 'jane@example.com' ] );
		$this->assertIsArray( $unmasked );
		$this->assertSame( [ $this->held_id ], array_column( $unmasked['comments'], 'id' ) );
	}

	/**
	 * Off returns the details unmasked.
	 *
	 * @return void
	 */
	public function test_privacy_off_returns_raw_details(): void {
		update_option( 'albert_privacy_mode', 'off' );

		$result = ( new GetComment() )->execute( [ 'comment_id' => $this->held_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'Jane Visitor', $result['comment']['author']['display_name'] );
	}

	/**
	 * Reviews, notes and other plugins' types are never listed, even with type "all", nor found by ID.
	 *
	 * @return void
	 */
	public function test_other_comment_types_stay_out(): void {
		foreach ( [ 'review', 'note', 'order_note', 'some_plugin_type' ] as $type ) {
			$id = $this->comment(
				[
					'comment_type'     => $type,
					'comment_approved' => '1',
				]
			);

			foreach ( [ 'comment', 'all' ] as $filter ) {
				$result = ( new FindComments() )->execute( [ 'type' => $filter ] );
				$this->assertIsArray( $result );

				$ids = array_column( $result['comments'], 'id' );
				$this->assertNotContains( $id, $ids, $type . '/' . $filter );
				$this->assertContains( $this->held_id, $ids, $type . '/' . $filter );
			}

			foreach ( [ new GetComment(), new DeleteComment() ] as $ability ) {
				$result = $ability->execute( [ 'comment_id' => $id ] );
				$this->assertInstanceOf( WP_Error::class, $result, $type );
				$this->assertSame( 'albert_comment_not_found', $result->get_error_code(), $type );
			}

			$moderated = ( new ModerateComment() )->execute(
				[
					'comment_ids' => [ $id ],
					'status'      => 'spam',
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

		$viewed = ( new GetComment() )->execute( [ 'comment_id' => $hidden ] );
		$this->assertInstanceOf( WP_Error::class, $viewed );

		$replied = ( new ReplyToComment() )->execute(
			[
				'comment_id' => $hidden,
				'content'    => 'Thanks!',
			]
		);
		$this->assertInstanceOf( WP_Error::class, $replied );
		$this->assertSame( '0', get_comment( $hidden )->comment_approved );
	}

	/**
	 * A refused reply leaves the held comment held.
	 *
	 * @return void
	 */
	public function test_refused_reply_does_not_approve(): void {
		wp_update_post(
			[
				'ID'             => $this->post_id,
				'comment_status' => 'closed',
			]
		);

		$result = ( new ReplyToComment() )->execute(
			[
				'comment_id' => $this->held_id,
				'content'    => 'Thanks!',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( '0', get_comment( $this->held_id )->comment_approved );
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

		$result = ( new GetComment() )->execute( [ 'comment_id' => $reply ] );
		$this->assertIsArray( $result );
		$this->assertSame( $this->held_id, $result['in_reply']['id'] );

		$parent = ( new GetComment() )->execute( [ 'comment_id' => $this->held_id ] );
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
		$result = ( new GetComment() )->execute( [ 'comment_id' => 999999 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_comment_not_found', $result->get_error_code() );
	}

	/**
	 * Replying approves a held comment, as wp-admin does, and publishes the reply as the user.
	 *
	 * @return void
	 */
	public function test_reply_approves_the_held_comment_and_posts_as_the_user(): void {
		$result = ( new ReplyToComment() )->execute(
			[
				'comment_id' => $this->held_id,
				'content'    => 'Yes, it is.',
			]
		);

		$this->assertIsArray( $result );
		$reply = get_comment( $result['id'] );
		$this->assertSame( $this->held_id, (int) $reply->comment_parent );
		$this->assertSame( get_current_user_id(), (int) $reply->user_id );
		$this->assertSame( 'Yes, it is.', $reply->comment_content );
		$this->assertSame( 'approved', $result['status'] );
		$this->assertSame( get_comment_link( $reply ), $result['permalink'] );
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
				'comment_id' => $this->held_id,
				'content'    => 'Hello',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_invalid_comment_id', $result->get_error_code() );
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
					'comment_ids' => [ $this->held_id ],
					'status'      => 'approved',
				]
			)
		);
		$this->assertSame( 'approved', wp_get_comment_status( $this->held_id ) );

		$again = $moderate->execute(
			[
				'comment_ids' => [ $this->held_id ],
				'status'      => 'approved',
			]
		);
		$this->assertIsArray( $again );
		$this->assertSame( [ $this->held_id ], $again['updated'] );

		$moderate->execute(
			[
				'comment_ids' => [ $this->held_id ],
				'status'      => 'spam',
			]
		);
		$this->assertSame( 'spam', wp_get_comment_status( $this->held_id ) );
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
				'comment_ids' => [ $this->held_id, $other, 999999 ],
				'status'      => 'spam',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ $this->held_id, $other ], $result['updated'] );
		$this->assertSame( 999999, $result['failed'][0]['id'] );
		$this->assertSame( 'albert_comment_not_found', $result['failed'][0]['code'] );
		$this->assertSame( 'spam', wp_get_comment_status( $other ) );
	}

	/**
	 * Moderation offers no trash status and declares itself an idempotent, non-destructive update.
	 *
	 * @return void
	 */
	public function test_moderate_schema_and_annotations(): void {
		$ability = wp_get_ability( 'albert/moderate-comment' );
		$this->assertNotNull( $ability );

		$schema = $ability->get_input_schema();
		$this->assertSame( [ 'approved', 'hold', 'spam' ], $schema['properties']['status']['enum'] );
		$this->assertSame( [ 'comment_ids', 'status' ], $schema['required'] );
		$this->assertSame( 50, $schema['properties']['comment_ids']['maxItems'] );

		$annotations = $ability->get_meta()['annotations'];
		$this->assertFalse( $annotations['destructive'] );
		$this->assertTrue( $annotations['idempotent'] );
	}

	/**
	 * Delete moves the comment to the trash by default.
	 *
	 * @return void
	 */
	public function test_delete_trashes_by_default(): void {
		$result = ( new DeleteComment() )->execute( [ 'comment_id' => $this->held_id ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['trashed'] );
		$this->assertFalse( $result['deleted'] );
		$this->assertSame( 'hold', $result['previous_status'] );
		$this->assertSame( 'trash', wp_get_comment_status( $this->held_id ) );
	}

	/**
	 * With force the comment is deleted for good, from the trash too.
	 *
	 * @return void
	 */
	public function test_delete_with_force_is_permanent(): void {
		wp_trash_comment( $this->held_id );

		$result = ( new DeleteComment() )->execute(
			[
				'comment_id' => $this->held_id,
				'force'      => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertFalse( $result['trashed'] );
		$this->assertSame( 'trash', $result['previous_status'] );
		$this->assertNull( get_comment( $this->held_id ) );
	}

	/**
	 * Get Comment says when it returns only some of the replies, and how many there are.
	 *
	 * @return void
	 */
	public function test_get_comment_marks_truncated_replies(): void {
		for ( $i = 0; $i < 51; $i++ ) {
			$this->comment(
				[
					'comment_parent'   => $this->held_id,
					'comment_approved' => '1',
				]
			);
		}

		$result = ( new GetComment() )->execute( [ 'comment_id' => $this->held_id ] );

		$this->assertIsArray( $result );
		$this->assertCount( 50, $result['replies'] );
		$this->assertSame( 51, $result['replies_total'] );
		$this->assertTrue( $result['replies_truncated'] );
	}

	/**
	 * Comments refer to their post as { id, name }, and the post title is never masked.
	 *
	 * @return void
	 */
	public function test_post_reference_is_id_and_name(): void {
		update_option( 'albert_privacy_mode', 'strict' );

		$result = ( new FindComments() )->execute(
			[
				'post_id'   => $this->post_id,
				'parent_id' => 0,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				'id'   => $this->post_id,
				'name' => 'Commented post',
			],
			$result['comments'][0]['post']
		);
		$this->assertSame( 0, $result['comments'][0]['parent_id'] );
	}
}
