<?php
/**
 * Unit tests for the MCP ResourceLoader.
 *
 * Verifies the loader returns the built-in block-types resource, lets the
 * `albert/mcp/resources` filter contribute resources, and drops anything that
 * is not an McpResource instance.
 *
 * @package Albert\Tests\Unit\MCP
 */

namespace Albert\Tests\Unit\MCP;

// WordPress + sanitisation stubs and the controllable block registry.
require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';

use Albert\MCP\BlockTypesResource;
use Albert\MCP\ResourceLoader;
use WP\MCP\Domain\Resources\McpResource;
use PHPUnit\Framework\TestCase;

/**
 * ResourceLoader tests.
 */
class ResourceLoaderTest extends TestCase {

	/**
	 * Reset filter overrides before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_filter_returns'] = [];
	}

	/**
	 * Clean up filter overrides after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['albert_test_filter_returns'] );
		parent::tearDown();
	}

	/**
	 * Collect the URIs from a list of resources.
	 *
	 * @param array<int, McpResource> $resources Resources.
	 * @return array<int, string> URIs.
	 */
	private function uris( array $resources ): array {
		return array_map(
			static function ( McpResource $entry ): string {
				return $entry->get_uri();
			},
			$resources
		);
	}

	/**
	 * Build a throwaway McpResource for filter tests.
	 *
	 * @param string $uri Resource URI.
	 * @return McpResource
	 */
	private function make_resource( string $uri ): McpResource {
		$resource = McpResource::fromArray(
			[
				'uri'        => $uri,
				'handler'    => static function (): array {
					return [];
				},
				'permission' => static function (): bool {
					return true;
				},
			]
		);

		$this->assertInstanceOf( McpResource::class, $resource );

		return $resource;
	}

	/**
	 * The built-in block-types resource is included by default.
	 *
	 * @return void
	 */
	public function test_includes_block_types_resource(): void {
		$resources = ( new ResourceLoader() )->resources();

		$this->assertContains( 'albert://blocks/types', $this->uris( $resources ) );
	}

	/**
	 * The albert/mcp/resources filter additively contributes resources.
	 *
	 * Add-ons are expected to append to the passed list (i.e.
	 * `$resources[] = $addon; return $resources;`), keeping the built-ins. The
	 * stub apply_filters returns a fixed override and cannot see the passed
	 * value, so the override array is built as [ built-in, addon ] to genuinely
	 * assert the built-in is preserved alongside the addon.
	 *
	 * @return void
	 */
	public function test_filter_additively_contributes_resources(): void {
		$built_in = ( new BlockTypesResource() )->build();
		$this->assertInstanceOf( McpResource::class, $built_in );

		$GLOBALS['albert_test_filter_returns']['albert/mcp/resources'] = [
			$built_in,
			$this->make_resource( 'albert://addon/thing' ),
		];

		$uris = $this->uris( ( new ResourceLoader() )->resources() );

		$this->assertContains( 'albert://blocks/types', $uris );
		$this->assertContains( 'albert://addon/thing', $uris );
	}

	/**
	 * Non-McpResource entries from the filter are dropped.
	 *
	 * @return void
	 */
	public function test_drops_non_resource_filter_entries(): void {
		$GLOBALS['albert_test_filter_returns']['albert/mcp/resources'] = [
			new \stdClass(),
			$this->make_resource( 'albert://addon/valid' ),
		];

		$uris = $this->uris( ( new ResourceLoader() )->resources() );

		$this->assertSame( [ 'albert://addon/valid' ], $uris );
	}

	/**
	 * A non-array filter return falls back to the built-in resources.
	 *
	 * @return void
	 */
	public function test_non_array_filter_falls_back(): void {
		$GLOBALS['albert_test_filter_returns']['albert/mcp/resources'] = false;

		$this->assertContains( 'albert://blocks/types', $this->uris( ( new ResourceLoader() )->resources() ) );
	}
}
