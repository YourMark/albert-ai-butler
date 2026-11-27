<?php
/**
 * Integration tests: every entry point in src/ is reachable the way the outside reaches it.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Wiring;

use Albert\Abstracts\BaseAbility;
use Albert\Core\Plugin;
use Albert\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Discovers entry points from src/ rather than listing them, so a new one that is
 * never wired up fails here instead of passing tests that call it directly.
 *
 * @coversNothing
 */
class EntryPointsTest extends TestCase {

	/**
	 * Every concrete ability class is in core's registry.
	 *
	 * @return void
	 */
	public function test_every_ability_class_is_registered(): void {
		$checked = 0;
		$missing = [];

		foreach ( self::classes_in( 'Abilities' ) as $class ) {
			$reflection = new ReflectionClass( $class );

			if ( $reflection->isAbstract() || ! $reflection->isSubclassOf( BaseAbility::class ) ) {
				continue;
			}

			// WooCommerce abilities register only while WooCommerce is active.
			if ( str_starts_with( $class, 'Albert\\Abilities\\WooCommerce\\' ) && ! class_exists( 'WooCommerce' ) ) {
				continue;
			}

			++$checked;
			$id = ( new $class() )->get_id();

			// wp_get_ability() raises _doing_it_wrong() on a miss, which fails the test before the list.
			if ( ! wp_has_ability( $id ) ) {
				$missing[] = $class . ' (' . $id . ')';
			}
		}

		$this->assertGreaterThan( 0, $checked, 'No ability classes found.' );
		$this->assertSame( [], $missing, 'Ability classes not registered.' );
	}

	/**
	 * Every literal route passed to register_rest_route() is on the REST server.
	 *
	 * @return void
	 */
	public function test_every_rest_route_is_registered(): void {
		$routes  = rest_get_server()->get_routes();
		$prefix  = '/' . Plugin::rest_namespace();
		$checked = 0;
		$missing = [];

		foreach ( self::files() as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading our own source.

			// The route argument, when it is a single literal; a concatenated one is skipped.
			preg_match_all( "/register_rest_route\(\s*[^,]+,\s*'((?:[^'\\\\]|\\\\.)*)'\s*,/", $source, $matches );

			foreach ( $matches[1] as $route ) {
				++$checked;
				$route = stripslashes( $route );

				if ( ! isset( $routes[ $prefix . $route ] ) ) {
					$missing[] = $prefix . $route . ' (' . basename( $file ) . ')';
				}
			}
		}

		$this->assertGreaterThan( 0, $checked, 'No routes found; the pattern no longer matches the source.' );
		$this->assertSame( [], $missing, 'REST routes not registered.' );
	}

	/**
	 * The OAuth discovery routes at the issuer's mid-path `.well-known` are registered.
	 *
	 * Their route is concatenated, so the scan above skips them, and they are the
	 * ones managed hosts have broken before.
	 *
	 * @return void
	 */
	public function test_oauth_well_known_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();
		$prefix = '/' . Plugin::rest_namespace() . '/oauth/\\.well-known/';

		foreach ( [ 'openid-configuration', 'oauth-authorization-server' ] as $suffix ) {
			$this->assertArrayHasKey( $prefix . $suffix, $routes );
		}
	}

	/**
	 * Every cron class's event is scheduled and has a handler once the plugin has loaded.
	 *
	 * @return void
	 */
	public function test_every_cron_job_is_scheduled_and_handled(): void {
		$checked = 0;
		$missing = [];

		foreach ( self::classes_in( 'Cron' ) as $class ) {
			if ( ! defined( $class . '::HOOK' ) ) {
				continue;
			}

			++$checked;

			$hook = constant( $class . '::HOOK' );

			if ( ! wp_next_scheduled( $hook ) ) {
				$missing[] = $class . ' (not scheduled)';
			}

			// A scheduled event with no callback runs and does nothing.
			if ( ! has_action( $hook ) ) {
				$missing[] = $class . ' (no handler)';
			}
		}

		$this->assertGreaterThan( 0, $checked, 'No cron classes found.' );
		$this->assertSame( [], $missing, 'Cron events not wired up.' );
	}

	/**
	 * The classes declared under src/{$dir}.
	 *
	 * @param string $dir A directory under src/.
	 *
	 * @return array<int, class-string>
	 */
	private static function classes_in( string $dir ): array {
		$classes = [];

		foreach ( self::files( $dir ) as $file ) {
			$class = 'Albert\\' . str_replace( [ '/', '.php' ], [ '\\', '' ], substr( $file, strlen( self::src() ) + 1 ) );

			if ( class_exists( $class ) ) {
				$classes[] = $class;
			}
		}

		return $classes;
	}

	/**
	 * The PHP files under src/, or under one directory of it.
	 *
	 * @param string $dir A directory under src/, or '' for all of it.
	 *
	 * @return array<int, string>
	 */
	private static function files( string $dir = '' ): array {
		$files = [];

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::src() . '/' . $dir ) ) as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'php' ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * The src/ directory.
	 *
	 * @return string
	 */
	private static function src(): string {
		return dirname( __DIR__, 3 ) . '/src';
	}
}
