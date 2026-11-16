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
	 * A destructive write keeps the capability of what it does, not a delete capability.
	 *
	 * @return void
	 */
	public function test_destructive_write_requires_its_edit_capability(): void {
		$annotations = [
			'readonly'    => false,
			'destructive' => true,
		];

		$user  = new WP_Ability( 'albert/update-user', [ 'annotations' => $annotations ] );
		$block = new WP_Ability( 'albert/remove-page-block', [ 'annotations' => $annotations ] );

		$this->assertSame( 'edit_users', AbilitiesRegistry::resolve_required_capability( $user ) );
		$this->assertSame( 'edit_pages', AbilitiesRegistry::resolve_required_capability( $block ) );
	}

	/**
	 * A delete or trash anywhere in an add-on's name still infers a delete capability.
	 *
	 * @return void
	 */
	public function test_delete_or_trash_in_the_name_requires_delete_posts(): void {
		$annotations = [ 'destructive' => true ];

		$bulk  = new WP_Ability( 'foo/bulk-delete-posts', [ 'annotations' => $annotations ] );
		$trash = new WP_Ability( 'foo/trash-post', [ 'annotations' => $annotations ] );

		$this->assertSame( 'delete_posts', AbilitiesRegistry::resolve_required_capability( $bulk ) );
		$this->assertSame( 'delete_posts', AbilitiesRegistry::resolve_required_capability( $trash ) );
	}

	/**
	 * "delete" only counts as a whole word of the name.
	 *
	 * @return void
	 */
	public function test_delete_inside_a_word_is_not_a_delete(): void {
		$annotations = [ 'destructive' => true ];

		$undelete = new WP_Ability( 'foo/undelete-post', [ 'annotations' => $annotations ] );
		$deleted  = new WP_Ability( 'foo/restore-deleted-posts', [ 'annotations' => $annotations ] );

		$this->assertSame( 'edit_posts', AbilitiesRegistry::resolve_required_capability( $undelete ) );
		$this->assertSame( 'edit_posts', AbilitiesRegistry::resolve_required_capability( $deleted ) );
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
