<?php
/**
 * Unit tests for AbilitiesRegistry::resolve_required_capability() — the
 * best-effort capability shown on the Abilities screen.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Ability.php';

use Albert\Core\AbilitiesRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WP_Ability;

/**
 * Tests for resolve_required_capability().
 *
 * @covers \Albert\Core\AbilitiesRegistry::resolve_required_capability
 */
class RequiredCapabilityTest extends TestCase {

	/**
	 * Reset the memoised source cache and hook recorder before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']   = [];
		$GLOBALS['albert_test_options'] = [];

		$reflection = new ReflectionClass( AbilitiesRegistry::class );
		$cache      = $reflection->getProperty( 'sources_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
	}

	/**
	 * Annotations marking an ability as write-capable.
	 *
	 * @return array<string, bool>
	 */
	private function write_annotations(): array {
		return [ 'readonly' => false ];
	}

	/**
	 * A declared meta capability is what the screen shows.
	 *
	 * @return void
	 */
	public function test_explicit_meta_capability_is_used(): void {
		$ability = new WP_Ability(
			'albert/create-post',
			[ 'capability' => 'my_custom_cap' ]
		);

		$this->assertSame(
			'my_custom_cap',
			AbilitiesRegistry::resolve_required_capability( $ability )
		);
	}

	/**
	 * Without a declaration nothing is guessed, whatever the id or annotations say.
	 *
	 * @return void
	 */
	public function test_undeclared_capability_is_empty(): void {
		foreach ( [ 'albert/delete-post', 'woo/find-orders', 'vendor/upload-media' ] as $id ) {
			$ability = new WP_Ability( $id, [ 'annotations' => $this->write_annotations() ] );

			$this->assertSame( '', AbilitiesRegistry::resolve_required_capability( $ability ), $id );
		}
	}

	/**
	 * The resolved value passes through the required_capability filter, so an
	 * add-on can declare a capability for an ability it does not own.
	 *
	 * @return void
	 */
	public function test_filter_can_override_resolved_capability(): void {
		$GLOBALS['albert_test_filter_returns']['albert/abilities/required_capability'] = 'overridden_cap';

		$ability = new WP_Ability(
			'albert/find-posts',
			[ 'annotations' => [ 'readonly' => true ] ]
		);

		$this->assertSame(
			'overridden_cap',
			AbilitiesRegistry::resolve_required_capability( $ability )
		);

		unset( $GLOBALS['albert_test_filter_returns']['albert/abilities/required_capability'] );
	}
}
