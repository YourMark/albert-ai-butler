<?php
/**
 * Integration tests for the view-site-health ability.
 *
 * Core's Site Health class with a controlled set of tests, and outgoing HTTP blocked so the suite stays offline.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\SiteHealth\ViewSiteHealth;
use Albert\OAuth\DiscoveryHealth;
use Albert\SafeMode\Gate;
use Albert\Tests\TestCase;
use WP_Error;
use WP_Site_Health;

/**
 * Site Health ability tests.
 */
class SiteHealthAbilityTest extends TestCase {

	/**
	 * URLs the tests tried to fetch.
	 *
	 * @var string[]
	 */
	private array $requests = [];

	/**
	 * Run as an administrator with a known set of Site Health tests and no network.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );
		delete_transient( 'health-check-site-status-result' );

		$this->requests = [];
		add_filter( 'site_status_tests', [ $this, 'health_tests' ], 999 );
		add_filter( 'pre_http_request', [ $this, 'block_http' ], 10, 3 );
	}

	/**
	 * Remove the filters.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'site_status_tests', [ $this, 'health_tests' ], 999 );
		remove_filter( 'pre_http_request', [ $this, 'block_http' ], 10 );
		parent::tear_down();
	}

	/**
	 * Fail every outgoing request, as a site without network access would.
	 *
	 * @param mixed  $preempt Whether to preempt the request.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return WP_Error
	 */
	public function block_http( $preempt, $args, $url ): WP_Error {
		$this->requests[] = (string) $url;
		return new WP_Error( 'http_request_failed', 'Blocked in tests.' );
	}

	/**
	 * Direct tests (one real, one callable, one broken, one login-bound) and async tests of every kind.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function health_tests(): array {
		$site_health = WP_Site_Health::get_instance();

		$tests = [
			'direct' => [
				'php_default_timezone' => [
					'label' => 'PHP Default Timezone',
					'test'  => 'php_default_timezone',
				],
				'albert_example'       => [
					'label' => 'Example',
					'test'  => static fn(): array => [
						'label'       => 'Backups are not &amp; never were configured',
						'status'      => 'critical',
						'badge'       => [
							'label' => 'Security',
							'color' => 'red',
						],
						'description' => '<p>No backup plugin is active.</p><p>Install one <strong>today</strong>, see <a href="https://example.com/guide?a=1&amp;b=2" target="_blank">the guide<span class="screen-reader-text"> (opens in a new tab)</span></a>.</p>',
						'actions'     => '<a href="https://example.com/wp-admin/options-general.php">Change these settings</a>',
						'test'        => 'albert_example',
					],
				],
				'broken'               => [
					'label' => 'Not callable',
					'test'  => 'no_such_test_method',
				],
				'rest_availability'    => [
					'label'     => 'REST API availability',
					'test'      => 'rest_availability',
					'skip_cron' => true,
				],
			],
			'async'  => [
				'dotorg_communication' => [
					'label'             => 'Communication with WordPress.org',
					'test'              => rest_url( 'wp-site-health/v1/tests/dotorg-communication' ),
					'has_rest'          => true,
					'async_direct_test' => [ $site_health, 'get_test_dotorg_communication' ],
				],
				'loopback_requests'    => [
					'label'             => 'Loopback request',
					'test'              => rest_url( 'wp-site-health/v1/tests/loopback-requests' ),
					'has_rest'          => true,
					'async_direct_test' => [ $site_health, 'get_test_loopback_requests' ],
				],
				'authorization_header' => [
					'label'     => 'Authorization header',
					'test'      => rest_url( 'wp-site-health/v1/tests/authorization-header' ),
					'has_rest'  => true,
					'skip_cron' => true,
				],
				'rest_only'            => [
					'label'    => 'REST only',
					'test'     => rest_url( 'example/v1/rest-only' ),
					'has_rest' => true,
				],
			],
		];

		return ( new DiscoveryHealth() )->add_test( $tests );
	}

	/**
	 * Run the ability the way an assistant does.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return mixed
	 */
	private function view_site_health( array $input = [] ) {
		$ability = wp_get_ability( 'albert/view-site-health' );
		$this->assertNotNull( $ability );

		return $ability->execute( $input );
	}

	/**
	 * By default direct tests run, critical first, as plain text; background and login-bound ones are not run.
	 *
	 * @return void
	 */
	public function test_runs_direct_tests_and_lists_the_rest_as_not_run(): void {
		$result = $this->view_site_health();

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['results'], 'A test that cannot run is skipped, not invented.' );
		$this->assertSame( 1, $result['counts']['critical'] );

		$first = $result['results'][0];
		$this->assertSame( 'albert_example', $first['test'] );
		$this->assertSame( 'critical', $first['status'] );
		$this->assertSame( 'Security', $first['badge'] );
		$this->assertSame( 'Backups are not & never were configured', $first['label'] );
		$this->assertSame( "No backup plugin is active.\nInstall one today, see the guide (https://example.com/guide?a=1&b=2).", $first['description'] );
		$this->assertSame( 'Change these settings (https://example.com/wp-admin/options-general.php)', $first['actions'] );

		$this->assertSame( 'php_default_timezone', $result['results'][1]['test'] );

		$this->assertSame(
			[ 'rest_availability', 'dotorg_communication', 'loopback_requests', 'authorization_header', 'rest_only', 'albert_oauth_discovery' ],
			array_column( $result['not_run'], 'test' )
		);
		$this->assertSame( 'Communication with WordPress.org', $result['not_run'][1]['label'] );
		$this->assertSame( [], $this->requests, 'No background check makes a request unless asked.' );
		$this->assertStringContainsString( 'site-health.php', $result['site_health_url'] );
	}

	/**
	 * Background checks with a server-side runner run, as in core's scheduled check, and fail cleanly offline.
	 *
	 * @return void
	 */
	public function test_run_background_checks_runs_async_tests_with_a_runner(): void {
		$result = $this->view_site_health( [ 'run_background_checks' => true ] );

		$this->assertIsArray( $result );

		$by_test = array_column( $result['results'], null, 'test' );
		$this->assertArrayHasKey( 'dotorg_communication', $by_test );
		$this->assertArrayHasKey( 'loopback_requests', $by_test );
		$this->assertArrayHasKey( 'albert_oauth_discovery', $by_test );
		$this->assertSame( 'critical', $by_test['dotorg_communication']['status'] );
		$this->assertSame( 'critical', $by_test['loopback_requests']['status'] );
		$this->assertSame( 'recommended', $by_test['albert_oauth_discovery']['status'] );

		$this->assertSame(
			[ 'rest_availability', 'authorization_header', 'rest_only' ],
			array_column( $result['not_run'], 'test' ),
			'Only skip_cron tests and tests without a server-side runner stay not run.'
		);
		$this->assertNotEmpty( $this->requests );
		$this->assertSame( 3, $result['counts']['critical'] );
	}

	/**
	 * The counts the Site Health screen last stored come back as integers, or null when none are stored.
	 *
	 * @return void
	 */
	public function test_last_screen_counts(): void {
		$result = $this->view_site_health();
		$this->assertIsArray( $result );
		$this->assertNull( $result['last_screen_counts'] );

		// The screen stores its posted counts, which arrive as strings.
		set_transient(
			'health-check-site-status-result',
			wp_json_encode(
				[
					'good'        => '15',
					'recommended' => '3',
					'critical'    => '1',
				]
			)
		);

		$result = $this->view_site_health();
		$this->assertIsArray( $result );
		$this->assertSame(
			[
				'critical'    => 1,
				'recommended' => 3,
				'good'        => 15,
			],
			$result['last_screen_counts']
		);
	}

	/**
	 * Results pass through core's own result filter, as on the screen.
	 *
	 * @return void
	 */
	public function test_results_pass_core_filter(): void {
		$downgrade = static function ( array $result ): array {
			if ( ( $result['test'] ?? '' ) === 'albert_example' ) {
				$result['status'] = 'recommended';
			}
			return $result;
		};
		add_filter( 'site_status_test_result', $downgrade );

		$result = $this->view_site_health();

		remove_filter( 'site_status_test_result', $downgrade );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['counts']['critical'] );
		$this->assertSame( 'recommended', $result['results'][0]['status'] );
	}

	/**
	 * Passed checks can be left out.
	 *
	 * @return void
	 */
	public function test_include_passed_false_returns_only_issues(): void {
		$result = $this->view_site_health( [ 'include_passed' => false ] );

		$this->assertIsArray( $result );
		$this->assertNotContains( 'good', array_column( $result['results'], 'status' ) );
		$this->assertContains( 'albert_example', array_column( $result['results'], 'test' ) );
	}

	/**
	 * Editors cannot see Site Health.
	 *
	 * @return void
	 */
	public function test_editors_are_refused(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = ( new ViewSiteHealth() )->check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_permission_denied', $result->get_error_code() );
	}

	/**
	 * A read, so safe mode never holds it.
	 *
	 * @return void
	 */
	public function test_safe_mode_passes_it(): void {
		$ability = wp_get_ability( 'albert/view-site-health' );

		$this->assertNotNull( $ability );
		$this->assertFalse( ( new Gate() )->must_gate( $ability, [] ) );
	}
}
