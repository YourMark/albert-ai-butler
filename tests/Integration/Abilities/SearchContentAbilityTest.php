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
	 * One search finds posts and pages; drafts are left out, as core's search route does.
	 *
	 * @return void
	 */
	public function test_searches_across_post_types(): void {
		$result = ( new SearchContent() )->execute( [ 'search' => 'lighthouse' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );

		$by_id = array_column( $result['results'], null, 'id' );
		$this->assertSame( 'post', $by_id[ $this->post_id ]['subtype'] );
		$this->assertSame( 'page', $by_id[ $this->page_id ]['subtype'] );
		$this->assertSame( 'Visiting the lighthouse', $by_id[ $this->post_id ]['title'] );
		$this->assertSame( get_permalink( $this->page_id ), $by_id[ $this->page_id ]['url'] );
	}

	/**
	 * Subtypes limit the search.
	 *
	 * @return void
	 */
	public function test_subtypes_limit_the_search(): void {
		$result = ( new SearchContent() )->execute(
			[
				'search'   => 'lighthouse',
				'subtypes' => [ 'page' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ $this->page_id ], array_column( $result['results'], 'id' ) );
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
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 2, $result['total_pages'] );
	}

	/**
	 * An unknown post type and an empty search are refused with a reason.
	 *
	 * @return void
	 */
	public function test_bad_input_is_refused(): void {
		$unknown = ( new SearchContent() )->execute(
			[
				'search'   => 'lighthouse',
				'subtypes' => [ 'no_such_type' ],
			]
		);
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'rest_invalid_param', $unknown->get_error_code() );

		$empty = ( new SearchContent() )->execute( [ 'search' => '   ' ] );
		$this->assertInstanceOf( WP_Error::class, $empty );
		$this->assertSame( 'search_missing', $empty->get_error_code() );
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
