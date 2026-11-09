<?php
/**
 * Integration tests for the revision abilities.
 *
 * Real posts and revisions, core's restore, and the safe mode gate.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Revisions\FindRevisions;
use Albert\Abilities\WordPress\Revisions\RestoreRevision;
use Albert\Abilities\WordPress\Revisions\RevisionDiff;
use Albert\Abilities\WordPress\Revisions\ViewRevision;
use Albert\SafeMode\Gate;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Revision ability tests.
 */
class RevisionsAbilityTest extends TestCase {

	/**
	 * The post under test.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * The revision holding the first version.
	 *
	 * @var int
	 */
	private int $first_revision;

	/**
	 * The revision holding the second (current) version.
	 *
	 * @var int
	 */
	private int $second_revision;

	/**
	 * Run as an administrator with a post edited once.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );

		$this->post_id        = self::factory()->post->create(
			[
				'post_title'   => 'Opening hours',
				'post_content' => "We open at nine.\n\nWe close at five.\n\nClosed on Sundays.",
			]
		);
		$this->first_revision = $this->save_revision();

		wp_update_post(
			[
				'ID'           => $this->post_id,
				'post_title'   => 'Opening times',
				'post_content' => "We open at ten.\n\nWe close at five.\n\nClosed on Sundays.",
			]
		);
		$this->second_revision = $this->save_revision();
	}

	/**
	 * Store the post as it is now as a revision, as an editor save does.
	 *
	 * @return int The revision ID.
	 */
	private function save_revision(): int {
		$revision_id = wp_save_post_revision( $this->post_id );
		if ( ! $revision_id ) {
			$revisions   = wp_get_post_revisions( $this->post_id );
			$revision_id = (int) array_key_first( $revisions );
		}
		return (int) $revision_id;
	}

	/**
	 * Revisions are listed newest first, with the fields that differ from the current version.
	 *
	 * @return void
	 */
	public function test_find_lists_revisions_with_what_differs(): void {
		$result = ( new FindRevisions() )->execute( [ 'post_id' => $this->post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'Opening times', $result['post']['title'] );

		$by_id = array_column( $result['revisions'], null, 'id' );
		$this->assertSame( $this->second_revision, $result['revisions'][0]['id'] );
		$this->assertSame( [], $by_id[ $this->second_revision ]['differs_from_current'] );
		$this->assertSame( [ 'post_title', 'post_content' ], $by_id[ $this->first_revision ]['differs_from_current'] );
	}

	/**
	 * Paging bounds the list and reports the total.
	 *
	 * @return void
	 */
	public function test_find_pages_the_list(): void {
		$result = ( new FindRevisions() )->execute(
			[
				'post_id'  => $this->post_id,
				'per_page' => 1,
			]
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['revisions'] );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
		$this->assertSame( $result['total'], $result['total_pages'] );
	}

	/**
	 * View diffs a revision against the current version, line by line.
	 *
	 * @return void
	 */
	public function test_view_diffs_against_the_current_version(): void {
		$result = ( new ViewRevision() )->execute( [ 'id' => $this->first_revision ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( $this->post_id, $result['to']['id'] );

		$fields = array_column( $result['fields'], null, 'field' );
		$this->assertSame( "@@ -1,1 +1,1 @@\n-Opening hours\n+Opening times", $fields['post_title']['diff'] );
		$this->assertStringContainsString( '-We open at nine.', $fields['post_content']['diff'] );
		$this->assertStringContainsString( '+We open at ten.', $fields['post_content']['diff'] );
		$this->assertStringNotContainsString( 'Closed on Sundays.', $fields['post_content']['diff'], 'Lines far from a change are left out.' );
		$this->assertFalse( $fields['post_excerpt']['changed'] );
		$this->assertSame( '', $fields['post_excerpt']['diff'] );
	}

	/**
	 * "previous" shows what a revision changed compared with the one before it.
	 *
	 * @return void
	 */
	public function test_view_compares_with_the_previous_revision(): void {
		$result = ( new ViewRevision() )->execute(
			[
				'id'         => $this->second_revision,
				'compare_to' => 'previous',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $this->first_revision, $result['from']['id'] );
		$this->assertSame( $this->second_revision, $result['to']['id'] );
		$this->assertTrue( $result['changed'] );
	}

	/**
	 * The oldest revision has nothing before it.
	 *
	 * @return void
	 */
	public function test_view_previous_of_the_oldest_is_not_found(): void {
		$oldest = (int) array_key_last( wp_get_post_revisions( $this->post_id ) );

		$result = ( new ViewRevision() )->execute(
			[
				'id'         => $oldest,
				'compare_to' => 'previous',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'revision_not_found', $result->get_error_code() );
	}

	/**
	 * A long diff is cut short and says so.
	 *
	 * @return void
	 */
	public function test_long_diffs_are_bounded(): void {
		$from = implode( "\n", range( 1, 1000 ) );
		$to   = implode( "\n", array_map( static fn( int $n ): string => 'changed ' . $n, range( 1, 1000 ) ) );

		$diff = RevisionDiff::diff( $from, $to );

		$this->assertTrue( $diff['changed'] );
		$this->assertTrue( $diff['truncated'] );
		$this->assertLessThanOrEqual( 300, substr_count( $diff['diff'], "\n" ) + 1 );
	}

	/**
	 * Restoring goes through core: the post takes the revision's fields and a new revision records it.
	 *
	 * @return void
	 */
	public function test_restore_goes_through_core_and_records_a_revision(): void {
		$restored_hook = 0;
		add_action(
			'wp_restore_post_revision',
			static function () use ( &$restored_hook ): void {
				++$restored_hook;
			}
		);

		$result = ( new RestoreRevision() )->execute( [ 'id' => $this->first_revision ] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $restored_hook );
		$this->assertSame( [ 'post_title', 'post_content' ], $result['changed_fields'] );

		$post = get_post( $this->post_id );
		$this->assertSame( 'Opening hours', $post->post_title );
		$this->assertStringContainsString( 'We open at nine.', $post->post_content );

		$this->assertIsInt( $result['new_revision_id'] );
		$this->assertNotContains( $result['new_revision_id'], [ $this->first_revision, $this->second_revision ] );
		$this->assertSame( 'Opening hours', get_post( $result['new_revision_id'] )->post_title );
	}

	/**
	 * A trashed post is not restored behind the owner's back.
	 *
	 * @return void
	 */
	public function test_restore_refuses_a_trashed_post(): void {
		wp_trash_post( $this->post_id );

		$result = ( new RestoreRevision() )->execute( [ 'id' => $this->first_revision ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_in_trash', $result->get_error_code() );
	}

	/**
	 * With revisions turned off, a restore is refused: nothing would record it.
	 *
	 * @return void
	 */
	public function test_restore_refuses_when_revisions_are_off(): void {
		add_filter( 'wp_revisions_to_keep', '__return_zero' );

		$result = ( new RestoreRevision() )->execute( [ 'id' => $this->first_revision ] );

		remove_filter( 'wp_revisions_to_keep', '__return_zero' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'revisions_disabled', $result->get_error_code() );
		$this->assertSame( 'Opening times', get_post( $this->post_id )->post_title );
	}

	/**
	 * Unknown revisions and posts are reported as not found; a post ID is not a revision ID.
	 *
	 * @return void
	 */
	public function test_unknown_ids_are_not_found(): void {
		$view = ( new ViewRevision() )->execute( [ 'id' => $this->post_id ] );
		$this->assertInstanceOf( WP_Error::class, $view );
		$this->assertSame( 'revision_not_found', $view->get_error_code() );

		$find = ( new FindRevisions() )->execute( [ 'post_id' => 999999 ] );
		$this->assertInstanceOf( WP_Error::class, $find );
		$this->assertSame( 'post_not_found', $find->get_error_code() );

		$find_revision = ( new FindRevisions() )->execute( [ 'post_id' => $this->first_revision ] );
		$this->assertInstanceOf( WP_Error::class, $find_revision );
		$this->assertSame( 'post_not_found', $find_revision->get_error_code() );
	}

	/**
	 * A post type without revisions says so.
	 *
	 * @return void
	 */
	public function test_post_type_without_revisions_is_refused(): void {
		$attachment = self::factory()->post->create( [ 'post_type' => 'attachment' ] );

		$result = ( new FindRevisions() )->execute( [ 'post_id' => $attachment ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'revisions_not_supported', $result->get_error_code() );
	}

	/**
	 * Someone who cannot edit the post sees none of its revisions and cannot restore one.
	 *
	 * @return void
	 */
	public function test_users_who_cannot_edit_the_post_are_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		$find = ( new FindRevisions() )->execute( [ 'post_id' => $this->post_id ] );
		$this->assertInstanceOf( WP_Error::class, $find );
		$this->assertSame( 'revision_permission_denied', $find->get_error_code() );

		foreach ( [ new ViewRevision(), new RestoreRevision() ] as $ability ) {
			$result = $ability->execute( [ 'id' => $this->first_revision ] );
			$this->assertInstanceOf( WP_Error::class, $result, $ability->get_id() );
			$this->assertSame( 'revision_permission_denied', $result->get_error_code(), $ability->get_id() );
		}

		$this->assertSame( 'Opening times', get_post( $this->post_id )->post_title, 'Nothing was restored.' );
	}

	/**
	 * Subscribers cannot use the revision abilities at all.
	 *
	 * @return void
	 */
	public function test_subscribers_are_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		foreach ( [ new FindRevisions(), new ViewRevision(), new RestoreRevision() ] as $ability ) {
			$this->assertInstanceOf( WP_Error::class, $ability->check_permission(), $ability->get_id() );
		}
	}

	/**
	 * Reads pass safe mode; restoring is held for approval.
	 *
	 * @return void
	 */
	public function test_safe_mode_holds_restore(): void {
		$gate = new Gate();

		$expected = [
			'albert/find-revisions'   => false,
			'albert/view-revision'    => false,
			'albert/restore-revision' => true,
		];

		foreach ( $expected as $id => $held ) {
			$ability = wp_get_ability( $id );
			$this->assertNotNull( $ability, $id . ' is registered' );
			$this->assertSame( $held, $gate->must_gate( $ability, [ 'id' => $this->first_revision ] ), $id );
		}
	}
}
