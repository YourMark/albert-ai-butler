<?php
/**
 * Integration tests for the view-site-health ability.
 *
 * Core's Site Health class with a controlled set of tests, so the suite makes no HTTP requests.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Abilities;

use Albert\Abilities\WordPress\SiteHealth\ViewSiteHealth;
use Albert\SafeMode\Gate;
use Albert\Tests\TestCase;
use WP_Error;

/**
 * Site Health ability tests.
 */
class SiteHealthAbilityTest extends TestCase {

	/**
	 * Run as an administrator with a known set of Site Health tests.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		delete_option( 'albert_disabled_abilities' );
		update_option( 'albert_abilities_saved', true );

		add_filter( 'site_status_tests', [ $this, 'health_tests' ], 999 );
	}

	/**
	 * Remove the test filter.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'site_status_tests', [ $this, 'health_tests' ], 999 );
		parent::tear_down();
	}

	/**
	 * One real core test, one callable, and one async test.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function health_tests(): array {
		return [
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
						'description' => '<p>No backup plugin is active.</p><p>Install one <strong>today</strong>.</p>',
						'actions'     => '<a href="https://example.com">Choose a backup plugin</a>',
						'test'        => 'albert_example',
					],
				],
				'broken'               => [
					'label' => 'Not callable',
					'test'  => 'no_such_test_method',
				],
			],
			'async'  => [
				'dotorg_communication' => [
					'label'    => 'Communication with WordPress.org',
					'test'     => 'dotorg_communication',
					'has_rest' => true,
				],
			],
		];
	}

	/**
	 * Direct tests run, critical first, as plain text; async ones are listed as not run.
	 *
	 * @return void
	 */
	public function test_runs_direct_tests_and_lists_async_ones(): void {
		$result = ( new ViewSiteHealth() )->execute( [] );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['results'], 'A test that cannot run is skipped, not invented.' );
		$this->assertSame( 1, $result['counts']['critical'] );

		$first = $result['results'][0];
		$this->assertSame( 'albert_example', $first['test'] );
		$this->assertSame( 'critical', $first['status'] );
		$this->assertSame( 'Security', $first['badge'] );
		$this->assertSame( 'Backups are not & never were configured', $first['label'] );
		$this->assertSame( "No backup plugin is active.\nInstall one today.", $first['description'] );
		$this->assertSame( 'Choose a backup plugin', $first['actions'] );

		$this->assertSame( 'php_default_timezone', $result['results'][1]['test'] );

		$this->assertSame(
			[
				[
					'test'  => 'dotorg_communication',
					'label' => 'Communication with WordPress.org',
				],
			],
			$result['not_run']
		);
		$this->assertStringContainsString( 'site-health.php', $result['site_health_url'] );
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

		$result = ( new ViewSiteHealth() )->execute( [] );

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
		$result = ( new ViewSiteHealth() )->execute( [ 'include_passed' => false ] );

		$this->assertIsArray( $result );
		$this->assertNotContains( 'good', array_column( $result['results'], 'status' ) );
		$this->assertContains( 'albert_example', array_column( $result['results'], 'test' ) );
	}

	/**
	 * Running the checks writes nothing: the screen's cached counts are left alone.
	 *
	 * @return void
	 */
	public function test_does_not_store_results(): void {
		delete_transient( 'health-check-site-status-result' );

		( new ViewSiteHealth() )->execute( [] );

		$this->assertFalse( get_transient( 'health-check-site-status-result' ) );
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
