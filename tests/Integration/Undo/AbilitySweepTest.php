<?php
/**
 * Every Albert write ability, recorded.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Undo;

use Albert\Abilities\WordPress\Blocks\CreatePattern;
use Albert\Abilities\WordPress\Blocks\DeletePattern;
use Albert\Abilities\WordPress\Blocks\UpdatePattern;
use Albert\Abilities\WordPress\Media\SetFeaturedImage;
use Albert\Abilities\WordPress\Media\UploadMedia;
use Albert\Abilities\WordPress\Pages\Create as CreatePage;
use Albert\Abilities\WordPress\Posts\AddBlock;
use Albert\Abilities\WordPress\Posts\Create as CreatePost;
use Albert\Abilities\WordPress\Posts\Delete as DeletePost;
use Albert\Abilities\WordPress\Posts\EditBlock;
use Albert\Abilities\WordPress\Posts\MoveBlock;
use Albert\Abilities\WordPress\Posts\RemoveBlock;
use Albert\Abilities\WordPress\Posts\Update as UpdatePost;
use Albert\Abilities\WordPress\Taxonomies\CreateTerm;
use Albert\Abilities\WordPress\Taxonomies\DeleteTerm;
use Albert\Abilities\WordPress\Taxonomies\UpdateTerm;
use Albert\Abilities\WordPress\Users\Create as CreateUser;
use Albert\Abilities\WordPress\Users\Delete as DeleteUser;
use Albert\Abilities\WordPress\Users\Update as UpdateUser;
use Albert\Abstracts\BaseAbility;
use Albert\Database\Installer;
use Albert\Database\Tables;
use Albert\Tests\TestCase;
use Albert\Undo\Recorder;
use Albert\Undo\Window;
use Albert\Undo\WindowContext;
use WP_Error;

/**
 * Runs each write ability inside a recording window and checks the verdict,
 * so a covered write misread as direct (or the reverse) shows up here rather
 * than in review. Content is realistic in size: a long post body is the case a
 * quoted-string regex once failed on.
 *
 * @coversNothing
 */
class AbilitySweepTest extends TestCase {

	/**
	 * Fresh tables, an administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Installer::install();

		global $wpdb;
		foreach ( [ Tables::changes(), Tables::change_items() ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test reset.
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Close a window a failed assertion left open.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		Window::close();
		parent::tear_down();
	}

	/**
	 * Post and page writes, with a long body, are revertable.
	 *
	 * @return void
	 */
	public function test_content_writes_are_revertable(): void {
		$long    = str_repeat( 'A long paragraph with "quotes", it\'s escaped, and a /*! banner */. ', 4000 );
		$created = $this->run_ability(
			new CreatePost(),
			[
				'title'   => 'Sweep',
				'content' => "<!-- wp:paragraph --><p>{$long}</p><!-- /wp:paragraph -->",
			]
		);
		$this->assertRevertable( $created );

		$post_id = (int) $created['result']['id'];
		$this->assertRevertable(
			$this->run_ability(
				new UpdatePost(),
				[
					'id'      => $post_id,
					'title'   => 'Sweep, edited',
					'content' => "<!-- wp:paragraph --><p>{$long} more</p><!-- /wp:paragraph -->",
				]
			)
		);

		$block = [
			'name'        => 'core/paragraph',
			'attributes'  => [ 'content' => 'Added' ],
			'innerBlocks' => [],
		];
		$this->assertRevertable(
			$this->run_ability(
				new AddBlock(),
				[
					'id'       => $post_id,
					'path'     => [ 0 ],
					'position' => 'after',
					'block'    => $block,
				]
			)
		);
		$this->assertRevertable(
			$this->run_ability(
				new EditBlock(),
				[
					'id'    => $post_id,
					'path'  => [ 1 ],
					'block' => [
						'name'        => 'core/paragraph',
						'attributes'  => [ 'content' => 'Edited' ],
						'innerBlocks' => [],
					],
				]
			)
		);
		$this->assertRevertable(
			$this->run_ability(
				new MoveBlock(),
				[
					'id'       => $post_id,
					'path'     => [ 1 ],
					'to_index' => 0,
				]
			)
		);
		$this->assertRevertable(
			$this->run_ability(
				new RemoveBlock(),
				[
					'id'   => $post_id,
					'path' => [ 0 ],
				]
			)
		);

		$attachment = self::factory()->attachment->create_object( 'image.jpg', $post_id, [ 'post_mime_type' => 'image/jpeg' ] );
		$this->assertRevertable(
			$this->run_ability(
				new SetFeaturedImage(),
				[
					'post_id'       => $post_id,
					'attachment_id' => $attachment,
				]
			)
		);

		$parent = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$this->assertRevertable(
			$this->run_ability(
				new CreatePage(),
				[
					'title'  => 'Child',
					'parent' => $parent,
				]
			)
		);

		self::factory()->comment->create( [ 'comment_post_ID' => $post_id ] );
		$this->assertRevertable( $this->run_ability( new DeletePost(), [ 'id' => $post_id ] ) );
	}

	/**
	 * Pattern writes are revertable.
	 *
	 * @return void
	 */
	public function test_pattern_writes_are_revertable(): void {
		$created = $this->run_ability(
			new CreatePattern(),
			[
				'title'   => 'Sweep pattern',
				'content' => '<!-- wp:paragraph --><p>Pattern</p><!-- /wp:paragraph -->',
			]
		);
		$this->assertRevertable( $created );

		$name = (string) $created['result']['name'];
		$this->assertRevertable(
			$this->run_ability(
				new UpdatePattern(),
				[
					'name'  => $name,
					'title' => 'Sweep pattern, edited',
				]
			)
		);
		$this->assertRevertable( $this->run_ability( new DeletePattern(), [ 'name' => $name ] ) );
	}

	/**
	 * An upload is a create: reverting it removes the attachment and its file.
	 *
	 * @return void
	 */
	public function test_an_upload_records_its_attachment(): void {
		if ( ! defined( 'DIR_TESTDATA' ) || ! file_exists( DIR_TESTDATA . '/images/canola.jpg' ) ) {
			$this->markTestSkipped( 'Image fixture not available.' );
		}

		$stub = static function ( $preempt, $args ) {
			if ( empty( $args['filename'] ) ) {
				return $preempt;
			}
			copy( DIR_TESTDATA . '/images/canola.jpg', $args['filename'] );
			return [
				'headers'  => [],
				'body'     => '',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => $args['filename'],
			];
		};

		add_filter( 'pre_http_request', $stub, 10, 2 );
		try {
			$run = $this->run_ability( new UploadMedia(), [ 'url' => 'https://example.org/canola.jpg' ] );
		} finally {
			remove_filter( 'pre_http_request', $stub, 10 );
		}

		$this->assertRevertable( $run );
	}

	/**
	 * Term writes are revertable, including a delete that reparents children.
	 *
	 * @return void
	 */
	public function test_term_writes_are_revertable(): void {
		$created = $this->run_ability(
			new CreateTerm(),
			[
				'name'     => 'Sweep',
				'taxonomy' => 'category',
			]
		);
		$this->assertRevertable( $created );

		$term_id = (int) $created['result']['id'];
		$this->assertRevertable(
			$this->run_ability(
				new UpdateTerm(),
				[
					'id'       => $term_id,
					'taxonomy' => 'category',
					'name'     => 'Sweep, edited',
				]
			)
		);

		self::factory()->category->create( [ 'parent' => $term_id ] );
		$this->assertRevertable(
			$this->run_ability(
				new DeleteTerm(),
				[
					'id'       => $term_id,
					'taxonomy' => 'category',
					'force'    => true,
				]
			)
		);
	}

	/**
	 * User writes: creating and editing are revertable, a deletion is removed.
	 *
	 * @return void
	 */
	public function test_user_writes_say_what_they_are(): void {
		$created = $this->run_ability(
			new CreateUser(),
			[
				'username' => 'sweep',
				'email'    => 'sweep@example.com',
				'roles'    => [ 'editor' ],
			]
		);
		$this->assertRevertable( $created );

		$user_id = (int) $created['result']['id'];
		$this->assertRevertable(
			$this->run_ability(
				new UpdateUser(),
				[
					'id'         => $user_id,
					'first_name' => 'Sweep',
					'email'      => 'sweep-edited@example.com',
				]
			)
		);

		$this->assertSame(
			Recorder::REASON_REMOVED,
			$this->run_ability(
				new DeleteUser(),
				[
					'id'       => $user_id,
					'reassign' => get_current_user_id(),
				]
			)['reason']
		);
	}

	/**
	 * Run one ability inside a recording window.
	 *
	 * @param BaseAbility          $ability The ability.
	 * @param array<string, mixed> $input   Its input.
	 *
	 * @return array{result: array<string, mixed>, reason: string|null, other_tables: string|null, label: string}
	 */
	private function run_ability( BaseAbility $ability, array $input ): array {
		Window::open( new WindowContext( WindowContext::SOURCE_MCP, $ability->get_id(), get_current_user_id(), 'client-sweep' ) );

		try {
			$result = $ability->execute( $input );
		} finally {
			$entry_id = Window::close();
		}

		$label = $ability->get_id();
		$this->assertNotInstanceOf( WP_Error::class, $result, $label . ': ' . ( $result instanceof WP_Error ? $result->get_error_message() : '' ) );
		$this->assertNotNull( $entry_id, "{$label} recorded nothing." );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Assertion read.
		$entry = $wpdb->get_row( $wpdb->prepare( 'SELECT reason, other_tables, effects FROM %i WHERE id = %d', Tables::changes(), $entry_id ), ARRAY_A );

		return [
			'result'       => (array) $result,
			'reason'       => $entry['reason'],
			'other_tables' => $entry['other_tables'],
			'effects'      => $entry['effects'],
			'label'        => $label,
		];
	}

	/**
	 * Assert a run is revertable, naming what was not captured when it is not.
	 *
	 * @param array{reason: string|null, other_tables: string|null, label: string} $run The run.
	 *
	 * @return void
	 */
	private function assertRevertable( array $run ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors PHPUnit's assert naming.
		$this->assertNull( $run['reason'], "{$run["label"]}: {$run["reason"]} " . (string) $run['other_tables'] . (string) ( $run['effects'] ?? '' ) );
	}
}
