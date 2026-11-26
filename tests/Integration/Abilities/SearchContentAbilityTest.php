<?php
/**
 * Integration tests for the search-content ability.
 *
 * Real content through core's search route.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Search\SearchContent;
use Albert\SafeMode\Gate;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Search content ability tests.
 */
class SearchContentAbilityTest extends TestCase {

	/**
	 * A published post mentioning the search word.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * A published page mentioning the search word.
	 *
	 * @var int
	 */
	private int $page_id;

	/**
	 * Run as an editor with a post, a page and a draft that all mention "lighthouse".
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );

		$this->post_id = self::factory()->post->create(
			[
				'post_title'   => 'Visiting the lighthouse',
				'post_content' => 'Open daily.',
			]
		);
		$this->page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'About us',
				'post_content' => 'We look after the lighthouse.',
			]
		);
		self::factory()->post->create(
			[
				'post_title'  => 'Lighthouse draft',
				'post_status' => 'draft',
			]
		);
	}

	/**
	 * Run the ability the way an assistant does.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return mixed The result.
	 */
	private function search( array $input ) {
		$ability = wp_get_ability( 'albert/search-content' );
		$this->assertNotNull( $ability );

		return $ability->execute( $input );
	}

	/**
	 * IDs of the results.
	 *
	 * @param mixed $result Ability result.
	 * @return array<int, int|string>
	 */
	private function ids( $result ): array {
		$this->assertIsArray( $result );

		return array_column( $result['results'], 'id' );
	}

	/**
	 * One search finds posts, pages and drafts the editor may edit, each with its kind and status.
	 *
	 * @return void
	 */
	public function test_searches_across_post_types(): void {
		$result = $this->search( [ 'search' => 'lighthouse' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['total'] );

		$by_id = array_column( $result['results'], null, 'id' );
		$this->assertSame( 'post', $by_id[ $this->post_id ]['kind'] );
		$this->assertSame( 'page', $by_id[ $this->page_id ]['kind'] );
		$this->assertSame( 'publish', $by_id[ $this->page_id ]['status'] );
		$this->assertSame( 'Visiting the lighthouse', $by_id[ $this->post_id ]['title'] );
		$this->assertSame( get_permalink( $this->page_id ), $by_id[ $this->page_id ]['url'] );
		$this->assertContains( 'draft', array_column( $result['results'], 'status' ) );
	}

	/**
	 * An author finds their own draft and private post, but not someone else's.
	 *
	 * @return void
	 */
	public function test_author_only_finds_their_own_unpublished_items(): void {
		$author         = self::factory()->user->create( [ 'role' => 'author' ] );
		$own            = [
			self::factory()->post->create(
				[
					'post_title'  => 'Lighthouse notes',
					'post_status' => 'draft',
					'post_author' => $author,
				]
			),
			self::factory()->post->create(
				[
					'post_title'  => 'Lighthouse diary',
					'post_status' => 'private',
					'post_author' => $author,
				]
			),
		];
		$others_private = self::factory()->post->create(
			[
				'post_title'  => 'Lighthouse budget',
				'post_status' => 'private',
			]
		);
		wp_set_current_user( $author );

		$ids = $this->ids( $this->search( [ 'search' => 'lighthouse' ] ) );

		sort( $ids );
		$expected = array_merge( [ $this->post_id, $this->page_id ], $own );
		sort( $expected );
		$this->assertSame( $expected, $ids );
		$this->assertNotContains( $others_private, $ids );
	}

	/**
	 * An editor finds other people's private and scheduled posts.
	 *
	 * @return void
	 */
	public function test_editor_finds_private_and_scheduled_posts(): void {
		$private   = self::factory()->post->create(
			[
				'post_title'  => 'Lighthouse budget',
				'post_status' => 'private',
				'post_author' => self::factory()->user->create( [ 'role' => 'author' ] ),
			]
		);
		$scheduled = self::factory()->post->create(
			[
				'post_title'  => 'Lighthouse open day',
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);

		$result = $this->search( [ 'search' => 'lighthouse' ] );

		$by_id = array_column( $result['results'], null, 'id' );
		$this->assertSame( 'private', $by_id[ $private ]['status'] );
		$this->assertSame( 'future', $by_id[ $scheduled ]['status'] );
	}

	/**
	 * A subscriber finds published content only: no drafts, patterns or template parts.
	 *
	 * @return void
	 */
	public function test_subscriber_finds_published_content_only(): void {
		$this->create_pattern_and_template_part();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$ids = $this->ids( $this->search( [ 'search' => 'lighthouse' ] ) );

		sort( $ids );
		$this->assertSame( [ $this->post_id, $this->page_id ], $ids );
	}

	/**
	 * Synced patterns and template parts are found, each saying what it is.
	 *
	 * @return void
	 */
	public function test_finds_synced_patterns_and_template_parts(): void {
		[ $pattern, $part ] = $this->create_pattern_and_template_part();

		$result = $this->search( [ 'search' => 'lighthouse' ] );

		$this->assertIsArray( $result );
		$by_id = array_column( $result['results'], null, 'id' );
		$this->assertSame( 'pattern', $by_id[ $pattern ]['kind'] );
		$this->assertSame( '', $by_id[ $pattern ]['url'] );
		$this->assertSame( 'template_part', $by_id[ $part ]['kind'] );
		$this->assertSame( 5, $result['total'] );
	}

	/**
	 * Subtypes can ask for synced patterns or template parts alone.
	 *
	 * @return void
	 */
	public function test_subtypes_select_patterns_or_template_parts(): void {
		[ $pattern, $part ] = $this->create_pattern_and_template_part();

		$this->assertSame(
			[ $pattern ],
			$this->ids(
				$this->search(
					[
						'search'   => 'lighthouse',
						'subtypes' => [ 'wp_block' ],
					]
				)
			)
		);
		$this->assertSame(
			[ $part ],
			$this->ids(
				$this->search(
					[
						'search'   => 'lighthouse',
						'subtypes' => [ 'wp_template_part' ],
					]
				)
			)
		);
	}

	/**
	 * A word with a leading - leaves out results that contain it, template parts too.
	 *
	 * @return void
	 */
	public function test_minus_excludes_a_word(): void {
		$this->create_pattern_and_template_part();

		$ids = $this->ids( $this->search( [ 'search' => 'lighthouse -daily -phone' ] ) );

		$this->assertNotContains( $this->post_id, $ids );
		$this->assertContains( $this->page_id, $ids );
		$this->assertCount( 3, $ids );
	}

	/**
	 * Create a synced pattern and a footer template part that mention "lighthouse".
	 *
	 * @return array{0: int, 1: string} Pattern ID and template part ID.
	 */
	private function create_pattern_and_template_part(): array {
		$pattern = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_title'   => 'Contact box',
				'post_content' => '<!-- wp:paragraph --><p>Call the lighthouse.</p><!-- /wp:paragraph -->',
			]
		);
		$part_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template_part',
				'post_name'    => 'albert-footer',
				'post_title'   => 'Footer',
				'post_content' => '<!-- wp:paragraph --><p>Lighthouse phone 555-0100</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $part_id, get_stylesheet(), 'wp_theme' );
		wp_set_post_terms( $part_id, 'footer', 'wp_template_part_area' );

		return [ $pattern, get_stylesheet() . '//albert-footer' ];
	}

	/**
	 * Subtypes limit the search.
	 *
	 * @return void
	 */
	public function test_subtypes_limit_the_search(): void {
		$this->create_pattern_and_template_part();

		$this->assertSame(
			[ $this->page_id ],
			$this->ids(
				$this->search(
					[
						'search'   => 'lighthouse',
						'subtypes' => [ 'page' ],
					]
				)
			)
		);
	}

	/**
	 * Terms can be searched instead of content.
	 *
	 * @return void
	 */
	public function test_searches_terms(): void {
		$term = self::factory()->category->create( [ 'name' => 'Lighthouses' ] );

		$result = ( new SearchContent() )->execute(
			[
				'search' => 'lighthouse',
				'type'   => 'term',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ $term ], array_column( $result['results'], 'id' ) );
		$this->assertSame( 'category', $result['results'][0]['subtype'] );
		$this->assertSame( 'term', $result['results'][0]['kind'] );
	}

	/**
	 * Per page bounds the results and the total still counts them all.
	 *
	 * @return void
	 */
	public function test_paging(): void {
		$result = ( new SearchContent() )->execute(
			[
				'search'   => 'lighthouse',
				'per_page' => 1,
			]
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['results'] );
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( 3, $result['total_pages'] );
	}

	/**
	 * A public custom post type shown in the REST API is searched too.
	 *
	 * @return void
	 */
	public function test_searches_custom_post_types_in_rest(): void {
		register_post_type(
			'albert_lighthouse',
			[
				'public'       => true,
				'show_in_rest' => true,
			]
		);
		// Core's search handler lists its post types when the REST server starts.
		$GLOBALS['wp_rest_server'] = null;

		$cpt_id = self::factory()->post->create(
			[
				'post_type'  => 'albert_lighthouse',
				'post_title' => 'Lighthouse keeper',
			]
		);

		$result = ( new SearchContent() )->execute( [ 'search' => 'lighthouse' ] );

		unregister_post_type( 'albert_lighthouse' );
		$GLOBALS['wp_rest_server'] = null;

		$this->assertIsArray( $result );
		$by_id = array_column( $result['results'], null, 'id' );
		$this->assertArrayHasKey( $cpt_id, $by_id );
		$this->assertSame( 'albert_lighthouse', $by_id[ $cpt_id ]['subtype'] );
	}

	/**
	 * A logged-in search matches inside a password-protected post, as core's does.
	 *
	 * @return void
	 */
	public function test_matches_inside_password_protected_posts(): void {
		$protected_id = self::factory()->post->create(
			[
				'post_title'    => 'Members area',
				'post_content'  => 'The lighthouse key code.',
				'post_password' => 'secret',
			]
		);

		$result = ( new SearchContent() )->execute( [ 'search' => 'lighthouse' ] );

		$this->assertIsArray( $result );
		$this->assertContains( $protected_id, array_column( $result['results'], 'id' ) );
	}

	/**
	 * An unknown subtype, or a taxonomy for type "post", is refused with the allowed values.
	 *
	 * @return void
	 */
	public function test_wrong_subtype_is_refused_with_allowed_values(): void {
		foreach ( [ 'no_such_type', 'category' ] as $subtype ) {
			$result = $this->search(
				[
					'search'   => 'lighthouse',
					'subtypes' => [ $subtype ],
				]
			);

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'albert_invalid_subtypes', $result->get_error_code() );
			$this->assertStringContainsString( 'page, wp_block, wp_template_part', $result->get_error_message() );
		}
	}

	/**
	 * A search of only spaces is refused by the input schema.
	 *
	 * @return void
	 */
	public function test_blank_search_is_refused(): void {
		$ability = wp_get_ability( 'albert/search-content' );
		$this->assertNotNull( $ability );

		$result = $ability->execute( [ 'search' => '   ' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Logged-out callers are refused.
	 *
	 * @return void
	 */
	public function test_logged_out_is_refused(): void {
		wp_set_current_user( 0 );

		$this->assertInstanceOf( WP_Error::class, ( new SearchContent() )->check_permission() );
	}

	/**
	 * Search is a read, so safe mode never holds it.
	 *
	 * @return void
	 */
	public function test_safe_mode_passes_search(): void {
		$ability = wp_get_ability( 'albert/search-content' );

		$this->assertNotNull( $ability );
		$this->assertFalse( ( new Gate() )->must_gate( $ability, [ 'search' => 'lighthouse' ] ) );
	}
}
