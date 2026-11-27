<?php
/**
 * Unit tests for the block-catalog abilities.
 *
 * Covers albert/list-block-types (catalog shape, category filter, plain-tool meta)
 * and albert/get-block-type (single detail, unknown name => WP_Error).
 *
 * @package Albert\Tests\Unit\Abilities
 */

namespace Albert\Tests\Unit\Abilities;

// WordPress + sanitisation stubs and the controllable block registry.
require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__, 2 ) . '/wp-function-stubs.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Block_Type_Registry.php';

use Albert\Abilities\WordPress\Blocks\GetBlockType;
use Albert\Abilities\WordPress\Blocks\ListBlockTypes;
use Albert\Blocks\BlockCatalog;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Block-catalog ability tests.
 */
class BlockCatalogAbilityTest extends TestCase {

	/**
	 * Seed a controllable registry before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_block_types'] = [
			'core/paragraph' => (object) [
				'title'           => 'Paragraph',
				'category'        => 'text',
				'attributes'      => [ 'content' => [ 'type' => 'string' ] ],
				'supports'        => [ 'anchor' => true ],
				'render_callback' => null,
			],
			'core/image'     => (object) [
				'title'           => 'Image',
				'category'        => 'media',
				'attributes'      => [ 'url' => [ 'type' => 'string' ] ],
				'supports'        => [],
				'render_callback' => null,
			],
		];
	}

	/**
	 * Clean up globals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['albert_test_block_types'],
			$GLOBALS['albert_test_allowed_block_types']
		);
		parent::tearDown();
	}

	public function test_list_block_types_returns_catalog_shape(): void {
		$result = ( new ListBlockTypes( new BlockCatalog() ) )->execute( [] );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['blocks'] );
		$this->assertSame(
			[ 'name', 'title', 'category', 'isDynamic' ],
			array_keys( $result['blocks'][0] )
		);
	}

	public function test_list_block_types_filters_by_category(): void {
		$result = ( new ListBlockTypes( new BlockCatalog() ) )->execute( [ 'category' => 'media' ] );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'core/image', $result['blocks'][0]['name'] );
	}

	public function test_list_block_types_is_a_plain_tool(): void {
		$ability = new ListBlockTypes( new BlockCatalog() );
		$meta    = $this->read_meta( $ability );

		// It is a plain tool (default type): no resource meta keys, so it stays
		// discoverable via discover-abilities.
		$this->assertSame( [ 'public' => true ], $meta['mcp'] );
		$this->assertArrayNotHasKey( 'type', $meta['mcp'] );
		$this->assertArrayNotHasKey( 'uri', $meta['mcp'] );
		$this->assertTrue( $meta['annotations']['readonly'] );
	}

	public function test_get_block_type_returns_single_detail(): void {
		$result = ( new GetBlockType( new BlockCatalog() ) )->execute( [ 'name' => 'core/paragraph' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'core/paragraph', $result['name'] );
		$this->assertSame( 'Paragraph', $result['title'] );
		$this->assertSame( 'text', $result['category'] );
		$this->assertFalse( $result['isDynamic'] );
		$this->assertSame( [ 'content' => [ 'type' => 'string' ] ], $result['attributes'] );
		$this->assertSame( [ 'anchor' => true ], $result['supports'] );
	}

	public function test_get_block_type_unknown_name_returns_error(): void {
		$result = ( new GetBlockType( new BlockCatalog() ) )->execute( [ 'name' => 'foo/bar' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_type_not_found', $result->get_error_code() );
	}

	public function test_get_block_type_disallowed_name_returns_error(): void {
		// core/image is registered (seeded in setUp) but excluded from the
		// per-user allowed set, so detail must be withheld to stay consistent
		// with albert/list-block-types.
		$GLOBALS['albert_test_allowed_block_types'] = [ 'core/paragraph' ];

		$result = ( new GetBlockType( new BlockCatalog() ) )->execute( [ 'name' => 'core/image' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_not_allowed', $result->get_error_code() );
	}

	public function test_get_block_type_missing_name_returns_error(): void {
		$result = ( new GetBlockType( new BlockCatalog() ) )->execute( [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_name_required', $result->get_error_code() );
	}

	/**
	 * Read the protected meta property off an ability via reflection.
	 *
	 * @param object $ability The ability instance.
	 * @return array<string, mixed> The meta array.
	 */
	private function read_meta( object $ability ): array {
		$property = new \ReflectionProperty( $ability, 'meta' );

		return (array) $property->getValue( $ability );
	}
}
