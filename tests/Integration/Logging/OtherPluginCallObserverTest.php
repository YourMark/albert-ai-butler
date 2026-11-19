<?php
/**
 * Integration tests for OtherPluginCallObserver against core's execute flow.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Logging;

use Albert\Logging\ExecutionLogMarker;
use Albert\OAuth\Server\ConnectionContext;
use Albert\Tests\TestCase;
use RuntimeException;
use WP_Error;

/**
 * OtherPluginCallObserver integration tests.
 *
 * @covers \Albert\Logging\OtherPluginCallObserver
 */
class OtherPluginCallObserverTest extends TestCase {

	/**
	 * Test-only ability category.
	 *
	 * @var string
	 */
	private const TEST_CATEGORY = 'albert-test';

	/**
	 * Ability ids to unregister afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $registered_ids = [];

	/**
	 * Ability name and result of each albert/abilities/after_execute call.
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $logged = [];

	/**
	 * Register the category, listen for log calls and open a connection.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		$this->registered_ids = [];
		$this->logged         = [];
		ExecutionLogMarker::reset();
		ConnectionContext::set( 'client-1' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		add_action(
			'albert/abilities/after_execute',
			function ( string $name, $input, $result ): void {
				$this->logged[] = [ $name, $result ];
			},
			10,
			3
		);

		if ( ! wp_has_ability_category( self::TEST_CATEGORY ) ) {
			$this->during(
				'wp_abilities_api_categories_init',
				static function (): void {
					wp_register_ability_category(
						self::TEST_CATEGORY,
						[
							'label'       => 'Albert Test',
							'description' => 'Test-only category.',
						]
					);
				}
			);
		}
	}

	/**
	 * Unregister the test abilities and close the connection.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->registered_ids as $id ) {
			wp_unregister_ability( $id );
		}

		ConnectionContext::reset();
		parent::tear_down();
	}

	/**
	 * Run a callback while the given one-shot action reports as in progress.
	 *
	 * @param string   $action   Action name.
	 * @param callable $callback What to run.
	 *
	 * @return void
	 */
	private function during( string $action, callable $callback ): void {
		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP core's own abilities tests use this pattern.
		$wp_current_filter[] = $action;

		try {
			$callback();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Register another plugin's read-only ability and run it.
	 *
	 * @param callable $callback The execute_callback.
	 *
	 * @return mixed What core returned.
	 */
	private function run_ability( callable $callback ) {
		$name = 'other-plugin/report';

		$this->during(
			'wp_abilities_api_init',
			static function () use ( $name, $callback ): void {
				wp_register_ability(
					$name,
					[
						'label'               => 'Report',
						'description'         => 'Another plugin\'s ability.',
						'category'            => self::TEST_CATEGORY,
						'execute_callback'    => $callback,
						'permission_callback' => '__return_true',
						'meta'                => [
							'annotations' => [
								'readonly'    => true,
								'destructive' => false,
							],
						],
					]
				);
			}
		);
		$this->registered_ids[] = $name;

		return wp_get_ability( $name )->execute();
	}

	/**
	 * A success is logged once.
	 *
	 * @return void
	 */
	public function test_logs_a_success(): void {
		$this->run_ability( static fn(): array => [ 'ok' => true ] );

		$this->assertSame( [ [ 'other-plugin/report', [ 'ok' => true ] ] ], $this->logged );
	}

	/**
	 * A WP_Error from the callback is logged once.
	 *
	 * @return void
	 */
	public function test_logs_a_wp_error(): void {
		$this->run_ability( static fn() => new WP_Error( 'report_unavailable', 'No data.' ) );

		$this->assertCount( 1, $this->logged );
		$this->assertSame( 'report_unavailable', $this->logged[0][1]->get_error_code() );
	}

	/**
	 * An exception is logged as the WP_Error core turns it into.
	 *
	 * @return void
	 */
	public function test_logs_an_exception(): void {
		$result = $this->run_ability(
			static function (): void {
				throw new RuntimeException( 'boom' );
			}
		);

		$this->assertWPError( $result );
		$this->assertCount( 1, $this->logged );
		$this->assertSame( 'ability_callback_exception', $this->logged[0][1]->get_error_code() );
	}

	/**
	 * Without an Albert connection nothing is logged.
	 *
	 * @return void
	 */
	public function test_ignores_a_call_without_a_connection(): void {
		ConnectionContext::reset();

		$this->run_ability( static fn(): array => [ 'ok' => true ] );

		$this->assertSame( [], $this->logged );
	}

	/**
	 * Albert's own ability is logged once, by itself, even with Free's writer off
	 * so the marker stays unset.
	 *
	 * @return void
	 */
	public function test_leaves_albert_abilities_to_log_themselves(): void {
		$ability = wp_get_ability( 'albert/find-users' );
		if ( $ability === null ) {
			$this->markTestSkipped( 'albert/find-users is not registered.' );
		}

		add_filter( 'albert/logging/enabled', '__return_false' );
		$ability->execute( [] );

		$this->assertCount( 1, $this->logged );
		$this->assertSame( 'albert/find-users', $this->logged[0][0] );
	}
}
