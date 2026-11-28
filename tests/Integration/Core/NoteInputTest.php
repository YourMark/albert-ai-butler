<?php
/**
 * Integration tests for Albert's `note` input.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Core;

use Albert\Database\Installer;
use Albert\SafeMode\Repository as SafeModeRepository;
use Albert\Tests\TestCase;

/**
 * Note input tests.
 *
 * @covers \Albert\Core\NoteInput
 */
class NoteInputTest extends TestCase {

	/**
	 * Run as an administrator with every ability on.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		Installer::install();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * A note alone is not a change: update-post refuses `{id, note}`.
	 *
	 * @return void
	 */
	public function test_a_note_alone_does_not_update_a_post(): void {
		$post_id = self::factory()->post->create( [ 'post_excerpt' => 'Kept' ] );

		$result = wp_get_ability( 'albert/update-post' )->execute(
			[
				'id'   => $post_id,
				'note' => 'Tidy up',
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertSame( 'Kept', get_post( $post_id )->post_excerpt );
	}

	/**
	 * A real change with a note passes, even an empty excerpt.
	 *
	 * @return void
	 */
	public function test_a_change_with_a_note_passes(): void {
		$post_id = self::factory()->post->create( [ 'post_excerpt' => 'Old' ] );

		$result = wp_get_ability( 'albert/update-post' )->execute(
			[
				'id'      => $post_id,
				'excerpt' => '',
				'note'    => 'Clear the excerpt',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', get_post( $post_id )->post_excerpt );
	}

	/**
	 * Update-media refuses `{id, note}` too.
	 *
	 * @return void
	 */
	public function test_a_note_alone_does_not_update_media(): void {
		$attachment = self::factory()->attachment->create_object( 'acme.jpg', 0, [ 'post_mime_type' => 'image/jpeg' ] );

		$result = wp_get_ability( 'albert/update-media' )->execute(
			[
				'id'   => $attachment,
				'note' => 'Nothing',
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Two held calls that differ only in the note are one decision; the
	 * stored input keeps the first note.
	 *
	 * @return void
	 */
	public function test_held_calls_differing_only_in_the_note_share_a_row(): void {
		$queue  = new SafeModeRepository();
		$first  = $queue->stage(
			'albert/update-post',
			[
				'id'    => 5,
				'title' => 'New',
				'note'  => 'First reason',
			],
			1,
			'client-acme',
			'Acme',
			HOUR_IN_SECONDS
		);
		$second = $queue->stage(
			'albert/update-post',
			[
				'id'    => 5,
				'title' => 'New',
				'note'  => 'Other reason',
			],
			1,
			'client-acme',
			'Acme',
			HOUR_IN_SECONDS
		);

		$this->assertSame( $first->id, $second->id );
		$this->assertSame( 'First reason', $queue->find( $first->action_id )->input['note'] );
	}
}
