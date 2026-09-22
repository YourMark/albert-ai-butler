<?php
/**
 * Unit tests for the block-pattern abilities.
 *
 * Covers albert/find-patterns (summaries, search and category filters) and
 * albert/view-pattern (full content, unknown name => WP_Error), driving a
 * PatternCatalog seeded with injected fixtures so no live registry is needed.
 *
 * @package Albert\Tests\Unit\Abilities
 */

namespace Albert\Tests\Unit\Abilities;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';

use Albert\Abilities\WordPress\Blocks\FindPatterns;
use Albert\Abilities\WordPress\Blocks\ViewPattern;
use Albert\Blocks\PatternCatalog;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Block-pattern ability tests.
 */
class PatternAbilityTest extends TestCase {

	/**
	 * A catalog seeded with one registered and one user pattern.
	 *
	 * @return PatternCatalog
	 */
	private function catalog(): PatternCatalog {
		$registered = [
			[
				'name'          => 'acme/hero',
				'title'         => 'Hero',
				'description'   => 'A big hero header',
				'categories'    => [ 'header', 'featured' ],
				'keywords'      => [ 'banner' ],
				'viewportWidth' => 1200,
				'content'       => '<!-- wp:cover --><div class="wp-block-cover"></div><!-- /wp:cover -->',
				'source'        => 'registered',
				'id'            => null,
				'syncStatus'    => 'unsynced',
			],
		];

		$user = [
			[
				'name'          => 'my-callout',
				'title'         => 'My Callout',
				'description'   => '',
				'categories'    => [ 'featured' ],
				'keywords'      => [],
				'viewportWidth' => null,
				'content'       => '<!-- wp:paragraph --><p>Note</p><!-- /wp:paragraph -->',
				'source'        => 'user',
				'id'            => 42,
				'syncStatus'    => 'synced',
			],
		];

		return new PatternCatalog( $registered, $user );
	}

	public function test_find_returns_summaries_without_content(): void {
		$result = ( new FindPatterns( $this->catalog() ) )->execute( [] );

		$this->assertSame( 2, $result['total'] );
		$this->assertArrayNotHasKey( 'content', $result['patterns'][0], 'Summaries must omit block markup.' );
		$this->assertSame( 'acme/hero', $result['patterns'][0]['name'] );
		$this->assertSame( 'user', $result['patterns'][1]['source'] );
	}

	public function test_find_search_matches_name_title_and_keywords(): void {
		$result = ( new FindPatterns( $this->catalog() ) )->execute( [ 'search' => 'banner' ] );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'acme/hero', $result['patterns'][0]['name'] );
	}

	public function test_find_category_filter(): void {
		$header = ( new FindPatterns( $this->catalog() ) )->execute( [ 'category' => 'header' ] );
		$this->assertSame( 1, $header['total'] );

		$featured = ( new FindPatterns( $this->catalog() ) )->execute( [ 'category' => 'featured' ] );
		$this->assertSame( 2, $featured['total'] );
	}

	public function test_view_returns_full_pattern_with_content(): void {
		$result = ( new ViewPattern( $this->catalog() ) )->execute( [ 'name' => 'acme/hero' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'acme/hero', $result['name'] );
		$this->assertSame( 'registered', $result['source'] );
		$this->assertStringContainsString( 'wp:cover', $result['content'] );
	}

	public function test_view_exposes_sync_status_and_ref_id_for_user_patterns(): void {
		$result = ( new ViewPattern( $this->catalog() ) )->execute( [ 'name' => 'my-callout' ] );

		$this->assertSame( 'user', $result['source'] );
		$this->assertSame( 'synced', $result['syncStatus'] );
		$this->assertSame( 42, $result['id'] );
	}

	public function test_registered_pattern_is_an_unsynced_copy_with_no_ref(): void {
		$result = ( new ViewPattern( $this->catalog() ) )->execute( [ 'name' => 'acme/hero' ] );

		$this->assertSame( 'unsynced', $result['syncStatus'] );
		$this->assertNull( $result['id'] );
	}

	public function test_view_unknown_name_returns_error(): void {
		$result = ( new ViewPattern( $this->catalog() ) )->execute( [ 'name' => 'no/such' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pattern_not_found', $result->get_error_code() );
	}

	public function test_view_requires_a_name(): void {
		$result = ( new ViewPattern( $this->catalog() ) )->execute( [ 'name' => '   ' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pattern_name_required', $result->get_error_code() );
	}
}
