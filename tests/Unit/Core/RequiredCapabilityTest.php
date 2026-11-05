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
	 * An explicit meta capability wins over the heuristic.
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
	 * Setting a featured image edits the target post, not the media library,
	 * so it requires edit_posts rather than upload_files.
	 *
	 * @return void
	 */
	public function test_featured_image_requires_edit_posts(): void {
		$ability = new WP_Ability(
			'albert/set-featured-image',
			[ 'annotations' => $this->write_annotations() ]
		);

		$this->assertSame(
			'edit_posts',
			AbilitiesRegistry::resolve_required_capability( $ability )
		);
	}

	/**
	 * Site Health shows what core shows on its screen, to those who may see that screen.
	 *
	 * @return void
	 */
	public function test_site_health_requires_view_site_health_checks(): void {
		$ability = new WP_Ability( 'albert/view-site-health', [ 'annotations' => [ 'readonly' => true ] ] );

		$this->assertSame( 'view_site_health_checks', AbilitiesRegistry::resolve_required_capability( $ability ) );
	}

	/**
	 * Uploading media requires upload_files.
	 *
	 * @return void
	 */
	public function test_media_upload_requires_upload_files(): void {
		$ability = new WP_Ability(
			'albert/upload-media',
			[ 'annotations' => $this->write_annotations() ]
		);

		$this->assertSame(
			'upload_files',
			AbilitiesRegistry::resolve_required_capability( $ability )
		);
	}

	/**
	 * WooCommerce-sourced abilities map to manage_woocommerce.
	 *
	 * @return void
	 */
	public function test_woo_source_requires_manage_woocommerce(): void {
		$ability = new WP_Ability(
			'woo/find-products',
			[ 'annotations' => [ 'readonly' => true ] ]
		);

		$this->assertSame(
			'manage_woocommerce',
			AbilitiesRegistry::resolve_required_capability( $ability )
		);
	}

	/**
	 * A read-only content ability requires only the read capability.
	 *
	 * @return void
	 */
	public function test_read_ability_requires_read(): void {
		$ability = new WP_Ability(
			'albert/find-posts',
			[ 'annotations' => [ 'readonly' => true ] ]
		);

		$this->assertSame(
			'read',
			AbilitiesRegistry::resolve_required_capability( $ability )
		);
	}

	/**
	 * A destructive content ability requires delete_posts.
	 *
	 * @return void
	 */
	public function test_delete_ability_requires_delete_posts(): void {
		$ability = new WP_Ability(
			'albert/delete-post',
			[ 'annotations' => [ 'destructive' => true ] ]
		);

		$this->assertSame(
			'delete_posts',
			AbilitiesRegistry::resolve_required_capability( $ability )
		);
	}

	/**
	 * The resolved value passes through the required_capability filter, so an
	 * add-on can correct the best-effort guess.
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
