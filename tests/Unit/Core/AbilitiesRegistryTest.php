<?php
/**
 * Unit tests for AbilitiesRegistry — source map, per-ability source lookup.
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
	 * Because get_sources() memoises into a private static, we reach in
	 * through reflection to reset it between tests. This is strictly a test
	 * concern — production code never needs to reset this cache.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']            = [];
		$GLOBALS['albert_test_options']          = [];
		$GLOBALS['albert_test_deprecated_calls'] = [];
		$GLOBALS['albert_test_deprecated_hooks'] = [];
		unset( $GLOBALS['albert_test_filter_returns'], $GLOBALS['albert_test_filter_callbacks'] );

		$reflection = new ReflectionClass( AbilitiesRegistry::class );
		$cache      = $reflection->getProperty( 'sources_cache' );
		$cache->setValue( null, null );
	}

	// ─── get_sources() ──────────────────────────────────────────────

	/**
	 * The built-in source map covers the prefixes Albert ships with.
	 *
	 * @return void
	 */
	public function test_get_sources_contains_built_in_prefixes(): void {
		$sources = AbilitiesRegistry::get_sources();

		$this->assertArrayHasKey( 'core', $sources );
		$this->assertArrayHasKey( 'albert', $sources );
		$this->assertArrayHasKey( 'woo', $sources );
		$this->assertArrayHasKey( 'acf', $sources );
	}

	/**
	 * The output goes through the albert/abilities/sources filter.
	 *
	 * The unit-test apply_filters stub is a pass-through, so we verify by
	 * checking the recorded hook call rather than the mutated return.
	 *
	 * @return void
	 */
	public function test_get_sources_applies_filter(): void {
		AbilitiesRegistry::get_sources();

		$filter_calls = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/abilities/sources'
		);

		$this->assertCount( 1, $filter_calls );
	}

	/**
	 * Repeat calls do not re-invoke the filter — the map is cached.
	 *
	 * @return void
	 */
	public function test_get_sources_caches_result(): void {
		AbilitiesRegistry::get_sources();
		AbilitiesRegistry::get_sources();
		AbilitiesRegistry::get_sources();

		$filter_calls = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/abilities/sources'
		);

		$this->assertCount( 1, $filter_calls );
	}

	/**
	 * The deprecated `albert/abilities/suppliers` filter still applies, so an
	 * addon that only ever hooked the old name keeps working unchanged.
	 *
	 * @return void
	 */
	public function test_get_sources_still_applies_the_deprecated_supplier_filter(): void {
		$GLOBALS['albert_test_filter_callbacks']['albert/abilities/suppliers'] =
			static function ( array $sources ): array {
				$sources['mycompany'] = 'My Company';
				return $sources;
			};

		$sources = AbilitiesRegistry::get_sources();

		$this->assertArrayHasKey( 'mycompany', $sources );
		$this->assertSame( 'My Company', $sources['mycompany'] );
	}

	/**
	 * Hooking the deprecated filter triggers a deprecation notice naming the
	 * replacement, the same way core's apply_filters_deprecated() would.
	 *
	 * @return void
	 */
	public function test_hooking_the_deprecated_supplier_filter_triggers_a_notice(): void {
		$GLOBALS['albert_test_filter_callbacks']['albert/abilities/suppliers'] =
			static fn( array $sources ): array => $sources;

		AbilitiesRegistry::get_sources();

		$this->assertCount( 1, $GLOBALS['albert_test_deprecated_hooks'] );
		$this->assertSame( 'albert/abilities/suppliers', $GLOBALS['albert_test_deprecated_hooks'][0]['hook_name'] );
		$this->assertSame( 'albert/abilities/sources', $GLOBALS['albert_test_deprecated_hooks'][0]['replacement'] );
	}

	/**
	 * Nothing hooking either filter produces no deprecation notice — the
	 * common case, on every request that has no addon involved at all.
	 *
	 * @return void
	 */
	public function test_get_sources_triggers_no_notice_when_nothing_is_hooked(): void {
		AbilitiesRegistry::get_sources();

		$this->assertSame( [], $GLOBALS['albert_test_deprecated_hooks'] );
	}

	// ─── get_suppliers() (deprecated) ───────────────────────────────

	/**
	 * The deprecated get_suppliers() still returns the same map get_sources()
	 * does, so existing callers keep working unchanged.
	 *
	 * @return void
	 */
	public function test_get_suppliers_still_returns_the_source_map(): void {
		$this->assertSame( AbilitiesRegistry::get_sources(), AbilitiesRegistry::get_suppliers() );
	}

	/**
	 * Calling the deprecated get_suppliers() triggers _deprecated_function
	 * naming get_sources() as the replacement.
	 *
	 * @return void
	 */
	public function test_get_suppliers_triggers_a_deprecation_notice(): void {
		AbilitiesRegistry::get_suppliers();

		$this->assertCount( 1, $GLOBALS['albert_test_deprecated_calls'] );
		$call = $GLOBALS['albert_test_deprecated_calls'][0];
		$this->assertSame( '1.4.0', $call['version'] );
		$this->assertStringEndsWith( '::get_sources', $call['replacement'] );
		$this->assertStringEndsWith( '::get_suppliers', $call['function_name'] );
	}

	// ─── get_ability_source() ───────────────────────────────────────

	/**
	 * A known albert-prefixed id resolves to the albert source.
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

	// ─── get_protected_abilities() ──────────────────────────────────

	/**
	 * The protected list includes the MCP adapter's own tool abilities, which
	 * must never be unregistered.
	 *
	 * @return void
	 */
	public function test_get_protected_abilities_includes_mcp_adapter_tools(): void {
		$protected = AbilitiesRegistry::get_protected_abilities();

		$this->assertContains( 'mcp-adapter/discover-abilities', $protected );
		$this->assertContains( 'mcp-adapter/get-ability-info', $protected );
		$this->assertContains( 'mcp-adapter/execute-ability', $protected );
	}

	// ─── is_transport_ability() ─────────────────────────────────────

	/**
	 * The raw slash-form IDs of all three transport meta-tools match.
	 *
	 * @return void
	 */
	public function test_is_transport_ability_matches_slash_form(): void {
		$this->assertTrue( AbilitiesRegistry::is_transport_ability( 'mcp-adapter/discover-abilities' ) );
		$this->assertTrue( AbilitiesRegistry::is_transport_ability( 'mcp-adapter/get-ability-info' ) );
		$this->assertTrue( AbilitiesRegistry::is_transport_ability( 'mcp-adapter/execute-ability' ) );
	}

	/**
	 * The MCP-sanitised hyphen-form names (slash → hyphen) also match, so the
	 * discovery gate can compare against the sanitised tool name.
	 *
	 * @return void
	 */
	public function test_is_transport_ability_matches_sanitised_hyphen_form(): void {
		$this->assertTrue( AbilitiesRegistry::is_transport_ability( 'mcp-adapter-discover-abilities' ) );
		$this->assertTrue( AbilitiesRegistry::is_transport_ability( 'mcp-adapter-get-ability-info' ) );
		$this->assertTrue( AbilitiesRegistry::is_transport_ability( 'mcp-adapter-execute-ability' ) );
	}

	/**
	 * Look-alikes are rejected: the check is an exact allowlist, not a broad
	 * `mcp-adapter` prefix, so nothing else can slip through the always-on gate.
	 *
	 * @return void
	 */
	public function test_is_transport_ability_rejects_lookalikes(): void {
		$this->assertFalse( AbilitiesRegistry::is_transport_ability( '' ) );
		$this->assertFalse( AbilitiesRegistry::is_transport_ability( 'mcp-adapter' ) );
		$this->assertFalse( AbilitiesRegistry::is_transport_ability( 'mcp-adapter/execute-ability-now' ) );
		$this->assertFalse( AbilitiesRegistry::is_transport_ability( 'mcp-adapter/some-other-tool' ) );
		$this->assertFalse( AbilitiesRegistry::is_transport_ability( 'albert/execute-ability' ) );
		$this->assertFalse( AbilitiesRegistry::is_transport_ability( 'albert/create-post' ) );
	}

	/**
	 * The MCP exposure precedence: mcp.public ?? public ?? false.
	 *
	 * @dataProvider provideExposureCombinations
	 *
	 * @param array<string, mixed> $meta     The ability meta.
	 * @param bool                 $expected Expected exposure.
	 *
	 * @return void
	 */
	public function test_is_mcp_public_resolves_precedence( array $meta, bool $expected ): void {
		$this->assertSame( $expected, AbilitiesRegistry::is_mcp_public( $meta ) );
	}

	/**
	 * Every flag combination and edge the adapter's exposure logic defines,
	 * including the malformed-mcp fail-closed case and the strict meta.public rule.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function provideExposureCombinations(): array {
		return [
			'neither set'                        => [ [], false ],
			'only mcp.public true'               => [ [ 'mcp' => [ 'public' => true ] ], true ],
			'only public true'                   => [ [ 'public' => true ], true ],
			'both true'                          => [
				[
					'mcp'    => [ 'public' => true ],
					'public' => true,
				],
				true,
			],
			'mcp.public false opts out'          => [
				[
					'mcp'    => [ 'public' => false ],
					'public' => true,
				],
				false,
			],
			'mcp.public true beats public false' => [
				[
					'mcp'    => [ 'public' => true ],
					'public' => false,
				],
				true,
			],
			'malformed mcp fails closed'         => [
				[
					'mcp'    => 'oops',
					'public' => true,
				],
				false,
			],
			'non-bool public is not exposed'     => [ [ 'public' => 1 ], false ],
		];
	}
}
