<?php
/**
 * Replacing text in a post through a real REST save.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Blocks;

use Albert\Abilities\WordPress\Pages\ReplaceText as ReplacePageText;
use Albert\Abilities\WordPress\Posts\ReplaceText as ReplacePostText;
use Albert\Blocks\BlockStructure;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Text may change; blocks, their settings and the markup those settings write may not.
 *
 * @since 1.6.0
 */
class ReplaceTextTest extends TestCase {

	/**
	 * A centred heading and two paragraphs, as the editor stores them.
	 *
	 * @var string
	 */
	private const CONTENT = "<!-- wp:heading {\"textAlign\":\"center\"} -->\n<h2 class=\"wp-block-heading has-text-align-center\">Prices 2025</h2>\n<!-- /wp:heading -->\n\n"
		. "<!-- wp:paragraph -->\n<p>Tom &amp; Jerry costs <strong>€10</strong>. <a href=\"https://old.example/\">Call us</a>.</p>\n<!-- /wp:paragraph -->\n\n"
		. "<!-- wp:paragraph -->\n<p>Once more €10.</p>\n<!-- /wp:paragraph -->";

	/**
	 * Post under test.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Create a post owned by a logged-in administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$this->post_id = self::factory()->post->create(
			[
				'post_author'  => $user_id,
				'post_content' => self::CONTENT,
			]
		);
	}

	/**
	 * Run the post ability.
	 *
	 * @param array<string, mixed> $args Input besides the id.
	 * @return array<string, mixed>|WP_Error
	 */
	private function replace( array $args ): array|WP_Error {
		return ( new ReplacePostText() )->execute( array_merge( [ 'post_id' => $this->post_id ], $args ) );
	}

	/**
	 * The stored content.
	 *
	 * @return string
	 */
	private function content(): string {
		return (string) get_post( $this->post_id )->post_content;
	}

	/**
	 * Text inside a block is replaced and nothing else changes.
	 *
	 * @return void
	 */
	public function test_text_is_replaced_and_the_rest_is_kept(): void {
		$result = $this->replace(
			[
				'search'  => 'Prices 2025',
				'replace' => 'Prices 2026',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['replacements'] );
		$this->assertSame( str_replace( '2025', '2026', self::CONTENT ), $this->content() );
	}

	/**
	 * A link's address is text, not a setting.
	 *
	 * @return void
	 */
	public function test_a_link_address_may_change(): void {
		$result = $this->replace(
			[
				'search'  => 'https://old.example/',
				'replace' => 'https://new.example/',
			]
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'https://new.example/', $this->content() );
	}

	/**
	 * Text found more often than expected saves nothing and says how often.
	 *
	 * @return void
	 */
	public function test_an_unexpected_count_saves_nothing(): void {
		$result = $this->replace(
			[
				'search'  => '€10',
				'replace' => '€12',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_invalid_expected_count', $result->get_error_code() );
		$this->assertSame( 2, $result->get_error_data()['count'] );
		$this->assertSame( self::CONTENT, $this->content() );

		$result = $this->replace(
			[
				'search'         => '€10',
				'replace'        => '€12',
				'expected_count' => 2,
			]
		);

		$this->assertSame( 2, $result['replacements'] );
	}

	/**
	 * A search written unescaped says how the text is stored.
	 *
	 * @return void
	 */
	public function test_text_stored_with_entities_is_pointed_out(): void {
		$result = $this->replace(
			[
				'search'  => 'Tom & Jerry',
				'replace' => 'Tom and Jerry',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_invalid_search', $result->get_error_code() );
		$this->assertStringContainsString( 'Tom &amp; Jerry', $result->get_error_message() );
	}

	/**
	 * Removing a class a block setting writes would break the block.
	 *
	 * @return void
	 */
	public function test_a_class_written_by_a_setting_cannot_change(): void {
		$result = $this->replace(
			[
				'search'  => ' has-text-align-center',
				'replace' => '',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_invalid_replace', $result->get_error_code() );
		$this->assertSame( self::CONTENT, $this->content() );
	}

	/**
	 * A block's settings and its existence are not text.
	 *
	 * @return void
	 */
	public function test_settings_and_blocks_cannot_change(): void {
		$settings = $this->replace(
			[
				'search'  => '{"textAlign":"center"}',
				'replace' => '{"textAlign":"left"}',
			]
		);
		$removed  = $this->replace(
			[
				'search'  => "<!-- wp:paragraph -->\n<p>Once more €10.</p>\n<!-- /wp:paragraph -->",
				'replace' => '',
			]
		);

		$this->assertSame( 'albert_invalid_replace', $settings->get_error_code() );
		$this->assertStringContainsString( 'settings of core/heading at [0]', $settings->get_error_message() );
		$this->assertSame( 'albert_invalid_replace', $removed->get_error_code() );
		$this->assertSame( self::CONTENT, $this->content() );
	}

	/**
	 * Splitting a paragraph is a new element, not text; bold is text formatting.
	 *
	 * @return void
	 */
	public function test_a_new_element_is_refused_but_inline_formatting_is_not(): void {
		$split = $this->replace(
			[
				'search'  => 'Once more',
				'replace' => 'Once</p><p>more',
			]
		);
		$this->assertSame( 'albert_invalid_replace', $split->get_error_code() );
		$this->assertSame( self::CONTENT, $this->content() );

		$bold = $this->replace(
			[
				'search'  => 'Once more',
				'replace' => '<em>Once</em> more',
			]
		);
		$this->assertIsArray( $bold );
	}

	/**
	 * Classic content cannot gain blocks through a text edit.
	 *
	 * @return void
	 */
	public function test_classic_content_cannot_gain_a_block(): void {
		wp_update_post(
			[
				'ID'           => $this->post_id,
				'post_content' => '<p>Classic text.</p>',
			]
		);

		$result = $this->replace(
			[
				'search'  => 'Classic text.',
				'replace' => "Classic text.</p>\n<!-- wp:paragraph -->\n<p>New</p>\n<!-- /wp:paragraph -->\n<p>",
			]
		);

		$this->assertSame( 'albert_invalid_replace', $result->get_error_code() );
	}

	/**
	 * A user who may not edit the post learns nothing about its text.
	 *
	 * @return void
	 */
	public function test_another_authors_post_reveals_nothing(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		foreach ( [ 'Prices', 'not in the post' ] as $search ) {
			$result = $this->replace(
				[
					'search'  => $search,
					'replace' => 'x',
				]
			);

			$this->assertSame( 'albert_post_permission_denied', $result->get_error_code() );
		}
		$this->assertSame( self::CONTENT, $this->content() );
	}

	/**
	 * Dropping a tag the content had needs confirmation.
	 *
	 * @return void
	 */
	public function test_a_dropped_tag_needs_confirmation(): void {
		$args = [
			'search'  => '<strong>€10</strong>',
			'replace' => '€10',
		];

		$refused = $this->replace( $args );
		$this->assertSame( 'tags_removed', $refused->get_error_code() );

		$args['remove_tags'] = [ 'strong' ];
		$this->assertIsArray( $this->replace( $args ) );
	}

	/**
	 * The title is a field of its own.
	 *
	 * @return void
	 */
	public function test_the_title_can_be_edited(): void {
		wp_update_post(
			[
				'ID'         => $this->post_id,
				'post_title' => 'Old title',
			]
		);

		$result = $this->replace(
			[
				'field'   => 'title',
				'search'  => 'Old',
				'replace' => 'New',
			]
		);

		$this->assertSame( 'title', $result['field'] );
		$this->assertSame( 'New title', get_post( $this->post_id )->post_title );
	}

	/**
	 * The page ability refuses a post.
	 *
	 * @return void
	 */
	public function test_the_page_ability_refuses_a_post(): void {
		$result = ( new ReplacePageText() )->execute(
			[
				'page_id' => $this->post_id,
				'search'  => '2025',
				'replace' => '2026',
			]
		);

		$this->assertSame( 'albert_page_not_found', $result->get_error_code() );
	}

	/**
	 * An ID of 0 is not found, never resolved to the global post.
	 *
	 * @return void
	 */
	public function test_a_zero_id_is_not_found(): void {
		$GLOBALS['post'] = get_post( $this->post_id );

		$result = $this->replace(
			[
				'post_id' => 0,
				'search'  => '2025',
				'replace' => '2026',
			]
		);

		$this->assertSame( 'albert_post_not_found', $result->get_error_code() );
		$this->assertSame( self::CONTENT, $this->content() );
	}

	/**
	 * Repeating a replacement can match again, so neither ability is idempotent.
	 *
	 * @return void
	 */
	public function test_replacing_is_not_idempotent(): void {
		foreach ( [ new ReplacePostText(), new ReplacePageText() ] as $ability ) {
			$meta = ( new \ReflectionProperty( $ability, 'meta' ) )->getValue( $ability );

			$this->assertFalse( $meta['annotations']['idempotent'] );
		}
	}

	/**
	 * Identical markup has no difference; nested changes name their path.
	 *
	 * @return void
	 */
	public function test_structure_difference_names_the_nested_block(): void {
		$group = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --></div><!-- /wp:group -->';

		$this->assertNull( BlockStructure::difference( $group, str_replace( '>A<', '>B<', $group ) ) );
		$this->assertStringContainsString(
			'core/paragraph at [0,0] into core/heading',
			(string) BlockStructure::difference( $group, str_replace( [ 'wp:paragraph', '<p>A</p>' ], [ 'wp:heading', '<h2>A</h2>' ], $group ) )
		);
	}
}
