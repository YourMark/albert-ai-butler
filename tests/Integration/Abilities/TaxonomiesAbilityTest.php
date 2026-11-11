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

	// ─── Route resolution ───────────────────────────────────────────

	/**
	 * Taxonomies registered by a test, unregistered when it ends.
	 *
	 * `$wp_taxonomies` is a global that outlives the test, so a taxonomy left
	 * behind is visible to every test after it. Cleanup lives here rather than
	 * at the end of each test body so that it still happens when an assertion
	 * fails or the ability throws.
	 *
	 * @var array<int, string>
	 */
	private array $registered_taxonomies = [];

	/**
	 * Unregister anything {@see self::register_routed_taxonomy()} created.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->registered_taxonomies as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}

		$this->registered_taxonomies = [];

		parent::tear_down();
	}

	/**
	 * Register a taxonomy and give it its REST route for this request.
	 *
	 * Taxonomy routes are created on `rest_api_init`, which has already run by
	 * the time a test registers one, so it is re-fired here — otherwise the
	 * ability would call a route that does not exist yet and the test would
	 * prove nothing about route resolution. A taxonomy kept out of the REST API
	 * gets no route either way, so it is not re-fired for one.
	 *
	 * @param string               $taxonomy Taxonomy slug to register.
	 * @param array<string, mixed> $args     Extra registration arguments.
	 *
	 * @return void
	 */
	private function register_routed_taxonomy( string $taxonomy, array $args = [] ): void {
		$args = array_merge(
			[
				'show_in_rest' => true,
				'public'       => true,
			],
			$args
		);

		register_taxonomy( $taxonomy, 'post', $args );

		$this->registered_taxonomies[] = $taxonomy;

		if ( ! empty( $args['show_in_rest'] ) ) {
			do_action( 'rest_api_init' );
		}
	}

	/**
	 * A REST-enabled taxonomy with no explicit rest_base is readable.
	 *
	 * `register_taxonomy()` leaves `rest_base` false unless the caller sets it,
	 * and WordPress then serves the taxonomy under its own slug. Reading
	 * `rest_base` as the availability flag rejected every such taxonomy —
	 * WooCommerce's `product_cat` among them — with "not available via REST API".
	 *
	 * @return void
	 */
	public function test_find_terms_taxonomy_without_explicit_rest_base(): void {
		$this->register_routed_taxonomy( 'albert_no_base' );

		self::factory()->term->create(
			[
				'taxonomy' => 'albert_no_base',
				'name'     => 'Baseless Term',
			]
		);

		$result = ( new FindTerms() )->execute( [ 'taxonomy' => 'albert_no_base' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'terms', $result );
		$this->assertContains( 'Baseless Term', array_column( $result['terms'], 'name' ) );
	}

	/**
	 * An explicit rest_base is still used when the taxonomy declares one.
	 *
	 * @return void
	 */
	public function test_find_terms_taxonomy_with_explicit_rest_base(): void {
		$this->register_routed_taxonomy( 'albert_based', [ 'rest_base' => 'albert-based-things' ] );

		self::factory()->term->create(
			[
				'taxonomy' => 'albert_based',
				'name'     => 'Based Term',
			]
		);

		$result = ( new FindTerms() )->execute( [ 'taxonomy' => 'albert_based' ] );

		$this->assertIsArray( $result );
		$this->assertContains( 'Based Term', array_column( $result['terms'], 'name' ) );
	}

	/**
	 * A taxonomy served outside `wp/v2` is reached at its own namespace.
	 *
	 * `rest_namespace` has been part of `register_taxonomy()` since WordPress
	 * 5.9, and `WP_REST_Terms_Controller` registers the route under it. An
	 * ability that builds `/wp/v2/{base}` by hand sends the request to a route
	 * that does not exist and gets `rest_no_route` back — which is why route
	 * resolution is core's `rest_get_route_for_taxonomy_items()` and not a
	 * hand-rolled copy of two thirds of it.
	 *
	 * @return void
	 */
	public function test_find_terms_taxonomy_with_custom_rest_namespace(): void {
		$this->register_routed_taxonomy( 'albert_namespaced', [ 'rest_namespace' => 'albert-test/v1' ] );

		self::factory()->term->create(
			[
				'taxonomy' => 'albert_namespaced',
				'name'     => 'Namespaced Term',
			]
		);

		$result = ( new FindTerms() )->execute( [ 'taxonomy' => 'albert_namespaced' ] );

		$this->assertIsArray( $result );
		$this->assertContains( 'Namespaced Term', array_column( $result['terms'], 'name' ) );
	}

	/**
	 * A site that moves a taxonomy's route is followed there.
	 *
	 * `rest_route_for_taxonomy_items` is the supported way to relocate a route,
	 * and it only applies because resolution goes through core. Asserted on the
	 * resolved route rather than on a term, because a filter that lies about
	 * where the route is does not create one.
	 *
	 * @return void
	 */
	public function test_taxonomy_route_honours_the_core_filter(): void {
		$this->register_routed_taxonomy( 'albert_filtered' );

		$filter = static fn (): string => '/albert-test/v1/moved';
		add_filter( 'rest_route_for_taxonomy_items', $filter );

		$route = rest_get_route_for_taxonomy_items( 'albert_filtered' );

		remove_filter( 'rest_route_for_taxonomy_items', $filter );

		$this->assertSame( '/albert-test/v1/moved', $route );
	}

	/**
	 * A taxonomy kept out of the REST API is refused, and says which one.
	 *
	 * @return void
	 */
	public function test_find_terms_rejects_taxonomy_hidden_from_rest(): void {
		$this->register_routed_taxonomy(
			'albert_private_tax',
			[
				'show_in_rest' => false,
				'public'       => false,
			]
		);

		$result = ( new FindTerms() )->execute( [ 'taxonomy' => 'albert_private_tax' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'taxonomy_not_rest_enabled', $result->get_error_code() );
		$this->assertStringContainsString( 'albert_private_tax', $result->get_error_message() );
	}

	/**
	 * An unregistered taxonomy is refused with a message naming it.
	 *
	 * @return void
	 */
	public function test_find_terms_rejects_unknown_taxonomy(): void {
		$result = ( new FindTerms() )->execute( [ 'taxonomy' => 'no_such_taxonomy' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_taxonomy', $result->get_error_code() );
		$this->assertStringContainsString( 'no_such_taxonomy', $result->get_error_message() );
	}

	/**
	 * `post_tags` is not a taxonomy, and is no longer quietly treated as one.
	 *
	 * The alias made the advertised contract false — the ability says
	 * `post_tag` — and there is no end to the list of plausible second
	 * spellings. The refusal names the slug and points at find-taxonomies.
	 *
	 * @return void
	 */
	public function test_find_terms_rejects_the_post_tags_misspelling(): void {
		$result = ( new FindTerms() )->execute( [ 'taxonomy' => 'post_tags' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_taxonomy', $result->get_error_code() );
		$this->assertStringContainsString( 'albert/find-taxonomies', $result->get_error_message() );
	}

	/**
	 * CreateTerm reaches a taxonomy that declares no rest_base.
	 *
	 * @return void
	 */
	public function test_create_term_taxonomy_without_explicit_rest_base(): void {
		$this->register_routed_taxonomy( 'albert_no_base_write' );

		$result = ( new CreateTerm() )->execute(
			[
				'taxonomy' => 'albert_no_base_write',
				'name'     => 'Written Term',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Written Term', $result['name'] ?? null );
	}

	/**
	 * UpdateTerm reaches a taxonomy that declares no rest_base.
	 *
	 * @return void
	 */
	public function test_update_term_taxonomy_without_explicit_rest_base(): void {
		$this->register_routed_taxonomy( 'albert_no_base_update' );

		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'albert_no_base_update',
				'name'     => 'Before',
			]
		);

		$result = ( new UpdateTerm() )->execute(
			[
				'taxonomy' => 'albert_no_base_update',
				'id'       => $term_id,
				'name'     => 'After',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'After', $result['name'] ?? null );
	}

	/**
	 * DeleteTerm reaches a taxonomy that declares no rest_base.
	 *
	 * The four term abilities share one route resolver; between this, the
	 * create and the update cases, every path through it is exercised.
	 *
	 * @return void
	 */
	public function test_delete_term_taxonomy_without_explicit_rest_base(): void {
		$this->register_routed_taxonomy( 'albert_no_base_delete' );

		$term_id = self::factory()->term->create(
			[
				'taxonomy' => 'albert_no_base_delete',
				'name'     => 'Doomed Term',
			]
		);

		$result = ( new DeleteTerm() )->execute(
			[
				'taxonomy' => 'albert_no_base_delete',
				'id'       => $term_id,
				'force'    => true,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
	}

	/**
	 * WooCommerce's product_cat is readable.
	 *
	 * The reported symptom, kept as its own test because it is the taxonomy a
	 * real site actually has: WooCommerce registers `product_cat` with
	 * `show_in_rest => true` and no `rest_base`, and it was refused as "not
	 * available via REST API".
	 *
	 * @return void
	 */
	public function test_find_terms_woocommerce_product_cat(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not installed on this run.' );
		}

		self::factory()->term->create(
			[
				'taxonomy' => 'product_cat',
				'name'     => 'Shoes',
			]
		);

		$result = ( new FindTerms() )->execute( [ 'taxonomy' => 'product_cat' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'terms', $result );
		$this->assertContains( 'Shoes', array_column( $result['terms'], 'name' ) );
	}

	/**
	 * ViewTerm reaches only taxonomies core exposes over REST.
	 *
	 * @return void
	 */
	public function test_view_term_refuses_a_taxonomy_hidden_from_rest(): void {
		$term = wp_insert_term( 'albert-hidden-term', 'wp_theme' );
		$this->assertIsArray( $term );

		$result = ( new ViewTerm() )->execute(
			[
				'id'       => $term['term_id'],
				'taxonomy' => 'wp_theme',
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'taxonomy_not_rest_enabled', $result->get_error_code() );
	}
}
