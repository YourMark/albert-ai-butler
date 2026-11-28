<?php
/**
 * Integration tests for Albert's `note` input.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Core;

use Albert\Abstracts\BaseAbility;
use Albert\Core\NoteInput;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\OAuth\Server\ConnectionContext;
use Albert\SafeMode\Repository as SafeModeRepository;
use Albert\Tests\TestCase;
use Albert\Undo\Journal;
use Albert\Undo\Window;

/**
 * Note input tests.
 *
 * @covers \Albert\Core\NoteInput
 */
class NoteInputTest extends TestCase {

	/**
	 * A stand-in ability whose own input is called `note`, like an order note.
	 *
	 * @var string
	 */
	private const OWN_NOTE = 'acme/add-order-note';

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
	 * Forget the connection and the stand-in.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		Window::close();
		ConnectionContext::reset();

		if ( wp_has_ability( self::OWN_NOTE ) ) {
			wp_unregister_ability( self::OWN_NOTE );
		}

		parent::tear_down();
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

	/**
	 * An ability's own `note` is its input: it is kept, never read as the
	 * assistant's reason, and it still tells held calls apart.
	 *
	 * @return void
	 */
	public function test_an_abilitys_own_note_is_never_the_journal_note(): void {
		$this->register_own_note_ability();

		$this->assertFalse( NoteInput::is_albert_note( self::OWN_NOTE ) );
		$this->assertTrue( NoteInput::is_albert_note( 'albert/update-post' ) );
		$this->assertSame( [ 'note' => 'Shipped today' ], NoteInput::without( self::OWN_NOTE, [ 'note' => 'Shipped today' ] ) );

		ConnectionContext::set( 'client-acme' );
		( new Journal() )->open( [ 'note' => 'Shipped today' ], self::OWN_NOTE );
		update_option( 'acme_order_flag', 'noted' );
		$entry_id = Window::close();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assertion read.
		$note = $wpdb->get_var( $wpdb->prepare( 'SELECT note FROM %i WHERE id = %d', Tables::changes(), (int) $entry_id ) );
		$this->assertNull( $note, 'A customer-facing order note is not the assistant\'s reason.' );

		$queue = new SafeModeRepository();
		$one   = $queue->stage( self::OWN_NOTE, [ 'note' => 'A' ], 1, 'client-acme', 'Acme', HOUR_IN_SECONDS );
		$two   = $queue->stage( self::OWN_NOTE, [ 'note' => 'B' ], 1, 'client-acme', 'Acme', HOUR_IN_SECONDS );
		$this->assertNotSame( $one->id, $two->id );
	}

	/**
	 * Register the stand-in, a BaseAbility declaring `note` itself.
	 *
	 * @return void
	 */
	private function register_own_note_ability(): void {
		global $wp_current_filter;

		$ability = new class( self::OWN_NOTE, wp_get_ability( 'albert/update-post' )->get_category() ) extends BaseAbility {

			/**
			 * Build.
			 *
			 * @param string $id       Ability id.
			 * @param string $category A registered category.
			 */
			public function __construct( string $id, string $category ) {
				$this->id           = $id;
				$this->label        = 'Add order note';
				$this->description  = 'Adds a note a customer reads.';
				$this->category     = $category;
				$this->meta         = [ 'annotations' => [ 'readonly' => false ] ];
				$this->input_schema = [
					'type'       => 'object',
					'properties' => [ 'note' => [ 'type' => 'string' ] ],
				];

				parent::__construct();
			}

			/**
			 * Nothing to do.
			 *
			 * @param array<string, mixed> $args Input.
			 *
			 * @return array<string, mixed>
			 */
			public function execute( array $args ): array {
				return $args;
			}

			/**
			 * Always allowed.
			 *
			 * @return bool
			 */
			public function check_permission(): bool {
				return true;
			}
		};

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP core's own abilities tests use this pattern.
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			$ability->register_ability();
		} finally {
			array_pop( $wp_current_filter );
		}
	}
}
