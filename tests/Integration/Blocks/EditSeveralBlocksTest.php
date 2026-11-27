<?php
/**
 * Several block edits through a real REST save.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Blocks;

use Albert\Abilities\WordPress\Posts\EditBlock as EditPostBlock;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * `edits` applies every edit to the tree and saves once, so one change makes one revision.
 *
 * @since 1.6.0
 */
class EditSeveralBlocksTest extends TestCase {

	/**
	 * Three paragraphs, as the editor stores them.
	 *
	 * @var string
	 */
	private const THREE_PARAGRAPHS = "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n"
		. "<!-- wp:paragraph -->\n<p>Two</p>\n<!-- /wp:paragraph -->\n\n"
		. "<!-- wp:paragraph -->\n<p>Three</p>\n<!-- /wp:paragraph -->";

	/**
	 * Post under test.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Enable abilities and create a post owned by a logged-in administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$this->post_id = self::factory()->post->create(
			[
				'post_author'  => $user_id,
				'post_content' => self::THREE_PARAGRAPHS,
			]
		);
	}

	/**
	 * A paragraph spec.
	 *
	 * @param string $content Paragraph text.
	 * @return array<string, mixed>
	 */
	private function paragraph( string $content ): array {
		return [
			'name'       => 'core/paragraph',
			'attributes' => [ 'content' => $content ],
		];
	}

	/**
	 * How many revisions the post has.
	 *
	 * @return int
	 */
	private function revision_count(): int {
		return count( wp_get_post_revisions( $this->post_id ) );
	}

	/**
	 * Three edits make one revision.
	 *
	 * @return void
	 */
	public function test_three_edits_make_one_revision(): void {
		$before = $this->revision_count();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => $this->post_id,
				'edits' => [
					[
						'path'  => [ 0 ],
						'block' => $this->paragraph( 'First' ),
					],
					[
						'path'  => [ 1 ],
						'block' => $this->paragraph( 'Second' ),
					],
					[
						'path'  => [ 2 ],
						'block' => $this->paragraph( 'Third' ),
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $before + 1, $this->revision_count() );

		$content = get_post( $this->post_id )->post_content;
		foreach ( [ 'First', 'Second', 'Third' ] as $text ) {
			$this->assertStringContainsString( '<p>' . $text . '</p>', $content );
		}
	}

	/**
	 * The single-edit inputs still save one block per call.
	 *
	 * @return void
	 */
	public function test_single_edit_inputs_still_work(): void {
		$before = $this->revision_count();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'     => $this->post_id,
				'path'   => [ 1 ],
				'expect' => 'core/paragraph',
				'block'  => $this->paragraph( 'Second' ),
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $before + 1, $this->revision_count() );
		$this->assertStringContainsString( '<p>Second</p>', get_post( $this->post_id )->post_content );
	}

	/**
	 * A failing edit in the middle saves nothing, not even the edits before it.
	 *
	 * @return void
	 */
	public function test_a_failing_edit_in_the_middle_saves_nothing(): void {
		$before = $this->revision_count();

		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => $this->post_id,
				'edits' => [
					[
						'path'  => [ 0 ],
						'block' => $this->paragraph( 'First' ),
					],
					[
						'path'  => [ 9 ],
						'block' => $this->paragraph( 'Nowhere' ),
					],
					[
						'path'  => [ 2 ],
						'block' => $this->paragraph( 'Third' ),
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_path_not_found', $result->get_error_code() );
		$this->assertSame( 1, $result->get_error_data()['edit_index'] );
		$this->assertSame( self::THREE_PARAGRAPHS, get_post( $this->post_id )->post_content );
		$this->assertSame( $before, $this->revision_count() );
	}

	/**
	 * A path resolves against the tree the earlier edits left.
	 *
	 * @return void
	 */
	public function test_paths_resolve_sequentially(): void {
		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => $this->post_id,
				'edits' => [
					[
						'path'  => [ 2 ],
						'block' => [
							'name'        => 'core/group',
							'innerBlocks' => [ $this->paragraph( 'Inside' ) ],
						],
					],
					[
						'path'   => [ 2, 0 ],
						'expect' => 'core/paragraph',
						'block'  => $this->paragraph( 'Changed inside' ),
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'core/group', $result['blocks'][2]['name'] );

		$content = get_post( $this->post_id )->post_content;
		$this->assertStringContainsString( '<p>Changed inside</p>', $content );
		$this->assertStringNotContainsString( '<p>Inside</p>', $content );
	}

	/**
	 * Both input shapes in one call are refused, and nothing is saved.
	 *
	 * @return void
	 */
	public function test_edits_with_single_edit_inputs_is_an_error(): void {
		$result = ( new EditPostBlock() )->execute(
			[
				'id'    => $this->post_id,
				'path'  => [ 0 ],
				'block' => $this->paragraph( 'Single' ),
				'edits' => [
					[
						'path'  => [ 1 ],
						'block' => $this->paragraph( 'Batched' ),
					],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'edits_with_single_edit', $result->get_error_code() );
		$this->assertSame( self::THREE_PARAGRAPHS, get_post( $this->post_id )->post_content );
	}

	/**
	 * The schema takes `path` and `block`, or `edits`, and refuses a call or item without a block.
	 *
	 * @return void
	 */
	public function test_schema_accepts_edits(): void {
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'albert/edit-post-block' ) ) {
			$this->markTestSkipped( 'albert/edit-post-block is not registered.' );
		}

		$schema = wp_get_ability( 'albert/edit-post-block' )->get_input_schema();
		$valid  = [
			'id'          => 1,
			'remove_tags' => [ 'ta' ],
			'edits'       => [
				[
					'path'   => [ 0 ],
					'expect' => 'core/paragraph',
					'block'  => $this->paragraph( 'A' ),
				],
			],
		];

		$this->assertTrue( rest_validate_value_from_schema( $valid, $schema, 'input' ) );

		$single = [
			'id'    => 1,
			'path'  => [ 0 ],
			'block' => $this->paragraph( 'A' ),
		];
		$this->assertTrue( rest_validate_value_from_schema( $single, $schema, 'input' ) );

		unset( $single['block'] );
		$this->assertInstanceOf( WP_Error::class, rest_validate_value_from_schema( $single, $schema, 'input' ) );
		$this->assertInstanceOf( WP_Error::class, rest_validate_value_from_schema( [ 'id' => 1 ], $schema, 'input' ) );

		unset( $valid['edits'][0]['block'] );
		$this->assertInstanceOf( WP_Error::class, rest_validate_value_from_schema( $valid, $schema, 'input' ) );
	}
}
