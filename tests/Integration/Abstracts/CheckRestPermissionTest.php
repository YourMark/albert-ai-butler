<?php
/**
 * Integration tests for BaseAbility::check_rest_permission.
 *
 * The method talks to the real REST server — registered routes, permission
 * callbacks, pattern matching. These can only be exercised meaningfully
 * against the WP test suite's server instance.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abstracts;

use Albert\Abstracts\BaseAbility;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * BaseAbility::check_rest_permission integration tests.
 *
 * @covers \Albert\Abstracts\BaseAbility::check_rest_permission
 */
class CheckRestPermissionTest extends TestCase {

	/**
	 * Set up — seed a few routes the tests drive against.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Make sure REST routes are registered.
		do_action( 'rest_api_init' );
	}

	/**
	 * Exact-match route: delegates to the route's permission callback.
	 *
	 * When the callback returns true, check_rest_permission returns true.
	 *
	 * @return void
	 */
	public function test_exact_match_passes_through_allow(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$ability = $this->make_ability(
			'/wp/v2/posts',
			'GET',
			'read'
		);

		$this->assertTrue( $ability->invoke() );
	}

	/**
	 * Permission callback returning WP_Error is returned unchanged.
	 *
	 * @return void
	 */
	public function test_permission_wp_error_is_returned(): void {
		register_rest_route(
			'albert-test/v1',
			'/errorer',
			[
				'methods'             => 'GET',
				'callback'            => '__return_true',
				'permission_callback' => static fn () => new WP_Error( 'test_error', 'Nope', [ 'status' => 418 ] ),
			]
		);

		$ability = $this->make_ability( '/albert-test/v1/errorer', 'GET', 'manage_options' );

		$result = $ability->invoke();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'test_error', $result->get_error_code() );
	}

	/**
	 * Permission callback returning false falls back to the capability check.
	 *
	 * @return void
	 */
	public function test_permission_false_falls_back_to_capability(): void {
		register_rest_route(
			'albert-test/v1',
			'/denier',
			[
				'methods'             => 'GET',
				'callback'            => '__return_true',
				'permission_callback' => '__return_false',
			]
		);

		// User has `read` but not `manage_options`.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$ability = $this->make_ability( '/albert-test/v1/denier', 'GET', 'manage_options' );

		$result = $ability->invoke();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_permission_denied', $result->get_error_code() );
	}

	/**
	 * Unknown route falls back to the provided capability check.
	 *
	 * @return void
	 */
	public function test_unknown_route_falls_back_to_capability(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$ability = $this->make_ability( '/wp/v2/does-not-exist', 'GET', 'manage_options' );

		// Admin has manage_options, so fallback passes.
		$this->assertTrue( $ability->invoke() );
	}

	/**
	 * Pattern route with a `(?P<id>...)` named group matches and delegates.
	 *
	 * @return void
	 */
	public function test_pattern_route_matches_and_delegates(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$ability = $this->make_ability(
			'/wp/v2/posts/(?P<id>[\d]+)',
			'GET',
			'read'
		);

		$this->assertTrue( $ability->invoke() );
	}

	/**
	 * Build a CheckRestAbility with the desired route/method/cap.
	 *
	 * @param string $route        REST route (exact or pattern).
	 * @param string $method       HTTP method.
	 * @param string $fallback_cap Capability fallback.
	 *
	 * @return CheckRestAbility
	 */
	private function make_ability( string $route, string $method, string $fallback_cap ): CheckRestAbility {
		return new CheckRestAbility( $route, $method, $fallback_cap );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.ClassComment.Missing

/**
 * Test ability that exposes check_rest_permission() as invoke().
 *
 * BaseAbility::check_rest_permission is protected, so we subclass to expose it.
 */
final class CheckRestAbility extends BaseAbility {

	/**
	 * REST route.
	 *
	 * @var string
	 */
	private string $route;

	/**
	 * HTTP method.
	 *
	 * @var string
	 */
	private string $method;

	/**
	 * Fallback capability.
	 *
	 * @var string
	 */
	private string $fallback_cap;

	/**
	 * Constructor.
	 *
	 * @param string $route        REST route.
	 * @param string $method       HTTP method.
	 * @param string $fallback_cap Fallback capability.
	 */
	public function __construct( string $route, string $method, string $fallback_cap ) {
		$this->id           = 'albert/rest-permission-test';
		$this->label        = 'REST Permission Test';
		$this->description  = 'Exposes check_rest_permission() for testing.';
		$this->category     = 'test';
		$this->route        = $route;
		$this->method       = $method;
		$this->fallback_cap = $fallback_cap;

		parent::__construct();
	}

	/**
	 * Not used — invoke() is the test surface.
	 *
	 * @param array<string, mixed> $args Unused.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $args ): array|WP_Error {
		return [];
	}

	/**
	 * Run check_rest_permission against the configured route.
	 *
	 * @return bool|WP_Error
	 */
	public function invoke(): bool|WP_Error {
		return $this->check_rest_permission( $this->route, $this->method, $this->fallback_cap );
	}
}
