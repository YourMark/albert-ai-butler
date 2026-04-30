<?php
/**
 * Unit tests for AbilitiesRegistry — supplier map, source lookup.
 *
 * The get_default_disabled_abilities() method interacts with the
 * wp_get_abilities() stub, so its fresh-install effect is asserted via
 * BaseAbility::is_enabled() in BaseAbilityTest. The end-to-end heuristic
 * derivation across all real abilities is covered by the integration suite.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Core\AbilitiesRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * AbilitiesRegistry tests.
 *
 * @covers \Albert\Core\AbilitiesRegistry
 */
class AbilitiesRegistryTest extends TestCase {

	/**
	 * Reset the static cache and hook recorder before each test.
	 *
	 * Because get_suppliers() memoises into a private static, we reach in
	 * through reflection to reset it between tests. This is strictly a test
	 * concern — production code never needs to reset this cache.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']   = [];
		$GLOBALS['albert_test_options'] = [];

		$reflection = new ReflectionClass( AbilitiesRegistry::class );
		$cache      = $reflection->getProperty( 'suppliers_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
	}

	// ─── get_suppliers() ────────────────────────────────────────────

	/**
	 * The built-in supplier map covers the prefixes Albert ships with.
	 *
	 * @return void
	 */
	public function test_get_suppliers_contains_built_in_prefixes(): void {
		$suppliers = AbilitiesRegistry::get_suppliers();

		$this->assertArrayHasKey( 'core', $suppliers );
		$this->assertArrayHasKey( 'albert', $suppliers );
		$this->assertArrayHasKey( 'woo', $suppliers );
		$this->assertArrayHasKey( 'acf', $suppliers );
	}

	/**
	 * The output goes through the albert/abilities/suppliers filter.
	 *
	 * The unit-test apply_filters stub is a pass-through, so we verify by
	 * checking the recorded hook call rather than the mutated return.
	 *
	 * @return void
	 */
	public function test_get_suppliers_applies_filter(): void {
		AbilitiesRegistry::get_suppliers();

		$filter_calls = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/abilities/suppliers'
		);

		$this->assertCount( 1, $filter_calls );
	}

	/**
	 * Repeat calls do not re-invoke the filter — the map is cached.
	 *
	 * @return void
	 */
	public function test_get_suppliers_caches_result(): void {
		AbilitiesRegistry::get_suppliers();
		AbilitiesRegistry::get_suppliers();
		AbilitiesRegistry::get_suppliers();

		$filter_calls = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/abilities/suppliers'
		);

		$this->assertCount( 1, $filter_calls );
	}

	// ─── get_ability_source() ───────────────────────────────────────

	/**
	 * A known albert-prefixed id resolves to the albert supplier.
	 *
	 * @return void
	 */
	public function test_get_ability_source_resolves_albert_prefix(): void {
		$source = AbilitiesRegistry::get_ability_source( 'albert/create-post' );

		$this->assertSame( 'albert', $source['slug'] );
		$this->assertSame( 'Albert', $source['label'] );
	}

	/**
	 * The legacy albert/woo- naming is still prefixed with `albert`, not `woo`.
	 *
	 * The split is on the first slash only: `albert/woo-find-products` has
	 * prefix `albert`, not `woo`. This protects the documented legacy IDs.
	 *
	 * @return void
	 */
	public function test_get_ability_source_treats_legacy_woo_prefix_as_albert(): void {
		$source = AbilitiesRegistry::get_ability_source( 'albert/woo-find-products' );

		$this->assertSame( 'albert', $source['slug'] );
	}

	/**
	 * A prefix not in the curated map is returned as-is with a prettified label.
	 *
	 * @return void
	 */
	public function test_get_ability_source_prettifies_unknown_prefix(): void {
		$source = AbilitiesRegistry::get_ability_source( 'mycompany/do-thing' );

		$this->assertSame( 'mycompany', $source['slug'] );
		$this->assertSame( 'Mycompany', $source['label'] );
	}

	/**
	 * Dashes/underscores in an unknown prefix are replaced with spaces
	 * before capitalisation so `my-addon` reads as `My addon`.
	 *
	 * @return void
	 */
	public function test_get_ability_source_prettifies_dashed_prefix(): void {
		$source = AbilitiesRegistry::get_ability_source( 'my-addon/run' );

		$this->assertSame( 'my-addon', $source['slug'] );
		$this->assertSame( 'My addon', $source['label'] );
	}

	/**
	 * A malformed id (no slash) has an empty prefix and returns the Unknown sentinel.
	 *
	 * @return void
	 */
	public function test_get_ability_source_returns_unknown_for_empty_prefix(): void {
		$source = AbilitiesRegistry::get_ability_source( '' );

		$this->assertSame( 'unknown', $source['slug'] );
		$this->assertSame( 'Unknown', $source['label'] );
	}
}
