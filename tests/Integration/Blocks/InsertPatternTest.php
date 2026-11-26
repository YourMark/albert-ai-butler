<?php
/**
 * Inserting a pattern by name through a real REST save.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Blocks;

use Albert\Abilities\WordPress\Posts\AddBlock as AddPostBlock;
use Albert\Tests\TestCase;

/**
 * A pattern is inserted from the site's own copy, never rebuilt.
 *
 * @since 1.6.0
 */
class InsertPatternTest extends TestCase {

	/**
	 * Pattern registered for the test.
	 *
	 * @var string
	 */
	private const PATTERN = 'albert-test/hero';

	/**
	 * Its markup: a group with a heading and a paragraph.
	 *
	 * @var string
	 */
	private const MARKUP = '<!-- wp:group {"tagName":"section","layout":{"type":"constrained"}} --><section class="wp-block-group"><!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Hero</h2><!-- /wp:heading --></section><!-- /wp:group -->'
		. '<!-- wp:paragraph --><p>Below the hero</p><!-- /wp:paragraph -->';

	/**
	 * Post under test.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Register the pattern and create a one-paragraph post as an administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		register_block_pattern(
			self::PATTERN,
			[
				'title'   => 'Hero',
				'content' => self::MARKUP,
			]
		);

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$this->post_id = self::factory()->post->create(
			[
				'post_author'  => $user_id,
				'post_content' => '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->',
			]
		);
	}

	/**
	 * Unregister the pattern.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unregister_block_pattern( self::PATTERN );
		parent::tear_down();
	}

	/**
	 * Insert a pattern after the first block.
	 *
	 * @param string $pattern Pattern name.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function insert( string $pattern ): array|\WP_Error {
		return ( new AddPostBlock() )->execute(
			[
				'id'       => $this->post_id,
				'path'     => [ 0 ],
				'position' => 'after',
				'pattern'  => $pattern,
			]
		);
	}

	/**
	 * Every block of a registered pattern lands exactly as the pattern stores it.
	 *
	 * @return void
	 */
	public function test_a_registered_pattern_is_inserted_as_stored(): void {
		$result = $this->insert( self::PATTERN );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result['blocks'] );
		$this->assertSame(
			'<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->' . self::MARKUP,
			get_post( $this->post_id )->post_content
		);
	}

	/**
	 * Container positions.
	 *
	 * @return array<string, array{string}>
	 */
	public function inside_positions(): array {
		return [
			'inside_start' => [ 'inside_start' ],
			'inside_end'   => [ 'inside_end' ],
		];
	}

	/**
	 * Every block of a pattern lands at the start or end of a container, in order.
	 *
	 * @dataProvider inside_positions
	 *
	 * @param string $position Container position.
	 * @return void
	 */
	public function test_a_pattern_is_inserted_inside_a_group_in_order( string $position ): void {
		$open  = '<!-- wp:group --><div class="wp-block-group">';
		$child = '<!-- wp:paragraph --><p>Inside</p><!-- /wp:paragraph -->';
		$close = '</div><!-- /wp:group -->';
		wp_update_post(
			[
				'ID'           => $this->post_id,
				'post_content' => $open . $child . $close,
			]
		);

		$result = ( new AddPostBlock() )->execute(
			[
				'id'       => $this->post_id,
				'path'     => [ 0 ],
				'position' => $position,
				'pattern'  => self::PATTERN,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			$open . ( $position === 'inside_start' ? self::MARKUP . $child : $child . self::MARKUP ) . $close,
			get_post( $this->post_id )->post_content
		);
	}

	/**
	 * A synced user pattern is inserted by reference.
	 *
	 * @return void
	 */
	public function test_a_synced_user_pattern_is_inserted_by_reference(): void {
		$pattern_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_name'    => 'shared-note',
				'post_content' => '<!-- wp:paragraph --><p>Shared</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertIsArray( $this->insert( 'shared-note' ) );
		$this->assertStringContainsString( '<!-- wp:block {"ref":' . $pattern_id . '} /-->', get_post( $this->post_id )->post_content );
	}

	/**
	 * An unknown pattern saves nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_pattern_saves_nothing(): void {
		$result = $this->insert( 'albert-test/missing' );

		$this->assertSame( 'pattern_not_found', $result->get_error_code() );
		$this->assertSame( '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->', get_post( $this->post_id )->post_content );
	}

	/**
	 * A pattern holding a block the user may not use is refused.
	 *
	 * @return void
	 */
	public function test_a_pattern_with_a_disallowed_block_is_refused(): void {
		$only_paragraphs = static fn (): array => [ 'core/paragraph' ];
		add_filter( 'allowed_block_types_all', $only_paragraphs );

		$result = $this->insert( self::PATTERN );

		remove_filter( 'allowed_block_types_all', $only_paragraphs );

		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'core/group', $result->get_error_message() );
	}

	/**
	 * A synced pattern is refused when a block it renders is not allowed.
	 *
	 * @return void
	 */
	public function test_a_synced_pattern_with_a_disallowed_block_is_refused(): void {
		self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_name'    => 'shared-hero',
				'post_content' => self::MARKUP,
			]
		);
		$no_groups = static fn (): array => [ 'core/paragraph', 'core/heading', 'core/block' ];
		add_filter( 'allowed_block_types_all', $no_groups );

		$result = $this->insert( 'shared-hero' );

		remove_filter( 'allowed_block_types_all', $no_groups );

		$this->assertSame( 'block_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'core/group', $result->get_error_message() );
	}

	/**
	 * An unsynced user pattern is inserted as a copy of its blocks.
	 *
	 * @return void
	 */
	public function test_an_unsynced_user_pattern_is_inserted_as_a_copy(): void {
		$pattern_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_name'    => 'copy-note',
				'post_content' => '<!-- wp:paragraph --><p>Copied</p><!-- /wp:paragraph -->',
			]
		);
		update_post_meta( $pattern_id, 'wp_pattern_sync_status', 'unsynced' );

		$this->assertIsArray( $this->insert( 'copy-note' ) );
		$this->assertSame(
			'<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Copied</p><!-- /wp:paragraph -->',
			get_post( $this->post_id )->post_content
		);
	}

	/**
	 * A pattern without blocks, a draft pattern, and pattern plus block are refused.
	 *
	 * @return void
	 */
	public function test_unusable_pattern_input_saves_nothing(): void {
		self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_name'    => 'draft-note',
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:paragraph --><p>Draft</p><!-- /wp:paragraph -->',
			]
		);
		register_block_pattern(
			'albert-test/empty',
			[
				'title'   => 'Empty',
				'content' => "\n\n",
			]
		);

		$empty = $this->insert( 'albert-test/empty' );
		$draft = $this->insert( 'draft-note' );
		$both  = ( new AddPostBlock() )->execute(
			[
				'id'       => $this->post_id,
				'path'     => [ 0 ],
				'position' => 'after',
				'pattern'  => self::PATTERN,
				'block'    => [ 'name' => 'core/paragraph' ],
			]
		);

		unregister_block_pattern( 'albert-test/empty' );

		$this->assertSame( 'pattern_empty', $empty->get_error_code() );
		$this->assertSame( 'pattern_not_found', $draft->get_error_code() );
		$this->assertSame( 'invalid_input', $both->get_error_code() );
		$this->assertSame( '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->', get_post( $this->post_id )->post_content );
	}
}
