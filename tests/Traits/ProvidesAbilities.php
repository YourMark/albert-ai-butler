<?php
/**
 * Shared data provider for ability test classes.
 *
 * Auto-discovers all concrete BaseAbility subclasses under src/Abilities/
 * so new abilities get test coverage without updating any test file.
 *
 * @package Albert
 */

namespace Albert\Tests\Traits;

use Albert\Abstracts\BaseAbility;

/**
 * Trait ProvidesAbilities
 *
 * Provides a PHPUnit @dataProvider that auto-discovers ability classes.
 *
 * @since 1.1.0
 */
trait ProvidesAbilities {

	/**
	 * Discover all concrete BaseAbility classes under src/Abilities/.
	 *
	 * Scans PHP files, derives the FQCN from the file path via PSR-4,
	 * and filters to concrete BaseAbility subclasses. Returns an array
	 * keyed by a short label for clear PHPUnit output.
	 *
	 * Does NOT instantiate the classes — some constructors call WordPress
	 * functions (e.g. get_post_statuses) that aren't available in static
	 * data provider context. Test methods instantiate when needed.
	 *
	 * @return array<string, array{0: class-string<BaseAbility>}>
	 */
	public static function provideAbilities(): array {
		$abilities = [];
		$base_dir  = dirname( __DIR__, 2 ) . '/src/Abilities';

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base_dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}

			// Derive FQCN from path: src/Abilities/WordPress/Posts/Create.php → Albert\Abilities\WordPress\Posts\Create
			$relative = str_replace( $base_dir . '/', '', $file->getPathname() );
			$class    = 'Albert\\Abilities\\' . str_replace( [ '/', '.php' ], [ '\\', '' ], $relative );

			if ( ! class_exists( $class ) ) {
				continue;
			}

			$reflection = new \ReflectionClass( $class );

			if ( $reflection->isAbstract() || ! $reflection->isSubclassOf( BaseAbility::class ) ) {
				continue;
			}

			// Use short class name as dataset key for readable PHPUnit output.
			// E.g. "WooCommerce\FindProducts" or "WordPress\Posts\Create"
			$short_name = str_replace( [ 'Albert\\Abilities\\', '.php' ], '', $class );
			$label      = str_replace( '\\', '/', $short_name );

			$abilities[ $label ] = [ $class ];
		}

		ksort( $abilities );

		return $abilities;
	}

	/**
	 * Check if an ability class is a WooCommerce ability.
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class name.
	 *
	 * @return bool
	 */
	protected static function is_woocommerce_ability( string $ability_class ): bool {
		return str_contains( $ability_class, '\\WooCommerce\\' );
	}

	/**
	 * Skip the current test if the ability requires WooCommerce and it's not active.
	 *
	 * @param class-string<BaseAbility> $ability_class Ability class name.
	 *
	 * @return void
	 */
	protected function skip_if_woocommerce_required( string $ability_class ): void {
		if ( self::is_woocommerce_ability( $ability_class ) && ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}
	}
}
