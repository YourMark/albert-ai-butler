<?php
/**
 * Parameter-level integration tests for Taxonomy and Term abilities.
 *
 * Verifies that every input parameter on FindTaxonomies, FindTerms,
 * ViewTerm, CreateTerm, UpdateTerm, and DeleteTerm actually works.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\Taxonomies\CreateTerm;
use Albert\Abilities\WordPress\Taxonomies\DeleteTerm;
use Albert\Abilities\WordPress\Taxonomies\FindTaxonomies;
use Albert\Abilities\WordPress\Taxonomies\FindTerms;
use Albert\Abilities\WordPress\Taxonomies\UpdateTerm;
use Albert\Abilities\WordPress\Taxonomies\ViewTerm;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Taxonomies ability parameter tests.
 *
 * @since 1.1.0
 */
class TaxonomiesAbilityTest extends TestCase {

	/**
	 * Run as administrator with all abilities enabled.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
	}

	// ─── FindTaxonomies ─────────────────────────────────────────────

	/**
	 * FindTaxonomies returns built-in taxonomies.
	 *
	 * @return void
	 */
	public function test_find_taxonomies_returns_builtins(): void {
		$result = ( new FindTaxonomies() )->execute( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'taxonomies', $result );
		$this->assertNotEmpty( $result['taxonomies'] );

		$slugs = array_column( $result['taxonomies'], 'slug' );
		$this->assertContains( 'category', $slugs );
		$this->assertContains( 'post_tag', $slugs );
	}

	/**
	 * FindTaxonomies type parameter filters by post type.
	 *
	 * @return void
	 */
	public function test_find_taxonomies_type_filter(): void {
		$result = ( new FindTaxonomies() )->execute( [ 'type' => 'post' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'taxonomies', $result );

		// Category and post_tag are associated with posts.
		$slugs = array_column( $result['taxonomies'], 'slug' );
		$this->assertContains( 'category', $slugs );
		$this->assertContains( 'post_tag', $slugs );
	}

	/**
	 * Each taxonomy includes the expected fields.
	 *
	 * @return void
	 */
	public function test_find_taxonomies_includes_expected_fields(): void {
		$result = ( new FindTaxonomies() )->execute( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'taxonomies', $result );
		$this->assertNotEmpty( $result['taxonomies'] );

		$first = $result['taxonomies'][0];

		$this->assertArrayHasKey( 'slug', $first );
		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'description', $first );
		$this->assertArrayHasKey( 'hierarchical', $first );
		$this->assertArrayHasKey( 'rest_base', $first );
	}

	// ─── FindTerms ──────────────────────────────────────────────────

	/**
	 * FindTerms returns terms for a taxonomy.
	 *
	 * @return void
	 */
	public function test_find_terms_returns_category_terms(): void {
		self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'FindMe Term',
			]
		);

		$result = ( new FindTerms() )->execute( [ 'taxonomy' => 'category' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'terms', $result );
		$this->assertNotEmpty( $result['terms'] );

		$names = array_column( $result['terms'], 'name' );
		$this->assertContains( 'FindMe Term', $names );
	}

	/**
	 * FindTerms works with post_tag taxonomy.
	 *
	 * @return void
	 */
	public function test_find_terms_post_tags(): void {
		self::factory()->term->create(
			[
				'taxonomy' => 'post_tag',
				'name'     => 'TestTag',
			]
		);

		$result = ( new FindTerms() )->execute( [ 'taxonomy' => 'post_tag' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'terms', $result );
		$names = array_column( $result['terms'], 'name' );
		$this->assertContains( 'TestTag', $names );
	}

	/**
	 * FindTerms search parameter filters terms.
	 *
	 * @return void
	 */
	public function test_find_terms_search(): void {
		self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Unique Searchable Cat',
			]
		);
		self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Other Cat',
			]
		);

		$result = ( new FindTerms() )->execute(
			[
				'taxonomy' => 'category',
				'search'   => 'Unique Searchable',
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'terms', $result );
		$names = array_column( $result['terms'], 'name' );
		$this->assertContains( 'Unique Searchable Cat', $names );
		$this->assertNotContains( 'Other Cat', $names );
	}

	/**
	 * FindTerms parent parameter filters by parent term.
	 *
	 * @return void
	 */
	public function test_find_terms_parent_filter(): void {
		$parent = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Parent Cat',
			]
		);
		$child  = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Child Cat',
				'parent'   => $parent,
			]
		);
		self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Orphan Cat',
			]
		);

		$result = ( new FindTerms() )->execute(
			[
				'taxonomy' => 'category',
				'parent'   => $parent,
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'terms', $result );
		$ids = array_column( $result['terms'], 'id' );
		$this->assertContains( $child, $ids );
		$this->assertNotContains( $parent, $ids );
	}

	/**
	 * FindTerms pagination works.
	 *
	 * @return void
	 */
	public function test_find_terms_pagination(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			self::factory()->term->create(
				[
					'taxonomy' => 'category',
					'name'     => "PagTerm{$i}",
				]
			);
		}

		$page1 = ( new FindTerms() )->execute(
			[
				'taxonomy' => 'category',
				'per_page' => 2,
				'page'     => 1,
			]
		);
		$page2 = ( new FindTerms() )->execute(
			[
				'taxonomy' => 'category',
				'per_page' => 2,
				'page'     => 2,
			]
		);

		$this->assertArrayHasKey( 'terms', $page1 );
		$this->assertArrayHasKey( 'terms', $page2 );
		$this->assertCount( 2, $page1['terms'] );
		$this->assertCount( 2, $page2['terms'] );
		$this->assertNotSame( $page1['terms'][0]['id'], $page2['terms'][0]['id'] );
	}

	// ─── ViewTerm ───────────────────────────────────────────────────

	/**
	 * ViewTerm returns all expected fields.
	 *
	 * @return void
	 */
	public function test_view_term_returns_all_fields(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy'    => 'category',
				'name'        => 'Viewable Cat',
				'description' => 'A test category',
			]
		);

		$result = ( new ViewTerm() )->execute(
			[
				'id'       => $term_id,
				'taxonomy' => 'category',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $term_id, $result['term']['id'] );
		$this->assertSame( 'Viewable Cat', $result['term']['name'] );
		$this->assertSame( 'A test category', $result['term']['description'] );
		$this->assertSame( 'category', $result['term']['taxonomy'] );
		$this->assertArrayHasKey( 'slug', $result['term'] );
		$this->assertArrayHasKey( 'count', $result['term'] );
	}

	/**
	 * ViewTerm returns error for non-existent term.
	 *
	 * @return void
	 */
	public function test_view_term_not_found(): void {
		$result = ( new ViewTerm() )->execute(
			[
				'id'       => 99999,
				'taxonomy' => 'category',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * ViewTerm returns error for invalid taxonomy.
	 *
	 * @return void
	 */
	public function test_view_term_invalid_taxonomy(): void {
		$result = ( new ViewTerm() )->execute(
			[
				'id'       => 1,
				'taxonomy' => 'nonexistent_tax',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_taxonomy', $result->get_error_code() );
	}

	// ─── CreateTerm ─────────────────────────────────────────────────

	/**
	 * CreateTerm with all optional parameters.
	 *
	 * @return void
	 */
	public function test_create_term_with_all_params(): void {
		$parent = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Create Parent',
			]
		);

		$result = ( new CreateTerm() )->execute(
			[
				'taxonomy'    => 'category',
				'name'        => 'Full Term',
				'slug'        => 'full-term-slug',
				'description' => 'A fully configured term',
				'parent'      => $parent,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Full Term', $result['name'] );
		$this->assertSame( 'full-term-slug', $result['slug'] );
		$this->assertSame( 'A fully configured term', $result['description'] );
		$this->assertSame( $parent, $result['parent'] );
	}

	/**
	 * CreateTerm defaults to category taxonomy.
	 *
	 * @return void
	 */
	public function test_create_term_defaults_to_category(): void {
		$result = ( new CreateTerm() )->execute( [ 'name' => 'Default Tax Term' ] );

		$this->assertIsArray( $result );

		$term = get_term( $result['id'], 'category' );
		$this->assertNotNull( $term );
		$this->assertSame( 'Default Tax Term', $term->name );
	}

	/**
	 * CreateTerm works with post_tag taxonomy.
	 *
	 * @return void
	 */
	public function test_create_term_post_tag(): void {
		$result = ( new CreateTerm() )->execute(
			[
				'taxonomy' => 'post_tag',
				'name'     => 'New Tag',
			]
		);

		$this->assertIsArray( $result );

		$term = get_term( $result['id'], 'post_tag' );
		$this->assertNotNull( $term );
		$this->assertSame( 'New Tag', $term->name );
	}

	/**
	 * CreateTerm auto-generates slug from name.
	 *
	 * @return void
	 */
	public function test_create_term_auto_slug(): void {
		$result = ( new CreateTerm() )->execute( [ 'name' => 'Auto Slug Term' ] );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['slug'] );
	}

	// ─── UpdateTerm ─────────────────────────────────────────────────

	/**
	 * UpdateTerm changes the name.
	 *
	 * @return void
	 */
	public function test_update_term_name(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Old Name',
			]
		);

		$result = ( new UpdateTerm() )->execute(
			[
				'taxonomy' => 'category',
				'id'       => $term_id,
				'name'     => 'New Name',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'New Name', $result['name'] );
		$this->assertSame( 'New Name', get_term( $term_id, 'category' )->name );
	}

	/**
	 * UpdateTerm changes the slug and description.
	 *
	 * @return void
	 */
	public function test_update_term_slug_and_description(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Sluggable',
			]
		);

		$result = ( new UpdateTerm() )->execute(
			[
				'taxonomy'    => 'category',
				'id'          => $term_id,
				'slug'        => 'custom-slug',
				'description' => 'Updated description',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'custom-slug', $result['slug'] );
		$this->assertSame( 'Updated description', $result['description'] );
	}

	/**
	 * UpdateTerm changes the parent.
	 *
	 * @return void
	 */
	public function test_update_term_parent(): void {
		$new_parent = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'New Parent',
			]
		);
		$term_id    = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Reparentable',
			]
		);

		$result = ( new UpdateTerm() )->execute(
			[
				'taxonomy' => 'category',
				'id'       => $term_id,
				'parent'   => $new_parent,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $new_parent, $result['parent'] );
	}

	// ─── DeleteTerm ─────────────────────────────────────────────────

	/**
	 * DeleteTerm with force removes the term.
	 *
	 * @return void
	 */
	public function test_delete_term_force(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Doomed Cat',
			]
		);

		$result = ( new DeleteTerm() )->execute(
			[
				'taxonomy' => 'category',
				'id'       => $term_id,
				'force'    => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertArrayHasKey( 'previous', $result );
		$this->assertSame( 'Doomed Cat', $result['previous']['name'] );
		$this->assertNull( get_term( $term_id, 'category' ) );
	}

	/**
	 * DeleteTerm returns the previous term data.
	 *
	 * @return void
	 */
	public function test_delete_term_returns_previous_data(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Previous Data',
				'slug'     => 'previous-data',
			]
		);

		$result = ( new DeleteTerm() )->execute(
			[
				'taxonomy' => 'category',
				'id'       => $term_id,
				'force'    => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $term_id, $result['previous']['id'] );
		$this->assertSame( 'Previous Data', $result['previous']['name'] );
		$this->assertSame( 'previous-data', $result['previous']['slug'] );
	}

	/**
	 * DeleteTerm works with post_tag taxonomy.
	 *
	 * @return void
	 */
	public function test_delete_term_post_tag(): void {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'post_tag',
				'name'     => 'Doomed Tag',
			]
		);

		$result = ( new DeleteTerm() )->execute(
			[
				'taxonomy' => 'post_tag',
				'id'       => $term_id,
				'force'    => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
	}
}
