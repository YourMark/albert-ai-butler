<?php
/**
 * Integration tests for ExecutionObserver against core's execute flow.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Logging;

use Albert\Logging\ExecutionLogMarker;
use Albert\Logging\ExecutionObserver;
use Albert\Logging\Installer;
use Albert\Logging\Repository;
use Albert\OAuth\Server\ConnectionContext;
use Albert\Tests\TestCase;
use RuntimeException;
use WP_Error;

/**
 * ExecutionObserver integration tests.
 *
 * @covers \Albert\Logging\ExecutionObserver
 */
class ExecutionObserverTest extends TestCase {

	/**
	 * Test-only ability category.
	 *
	 * @var string
	 */
	private const TEST_CATEGORY = 'albert-test';

	/**
	 * Another plugin's ability.
	 *
	 * @var string
	 */
	private const ABILITY = 'other-plugin/report';

	/**
	 * Ability ids to unregister afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $registered_ids = [];

	/**
	 * The log.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Empty the log, open a connection and register the category.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		Installer::install();
		$this->repository = new Repository();
		$this->repository->truncate();

		$this->registered_ids = [];
		ExecutionLogMarker::reset();
		ExecutionObserver::reset();
		ConnectionContext::set( 'client-1' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

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
		ExecutionObserver::reset();
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
	 * @param callable             $callback   The execute_callback.
	 * @param array<string, mixed> $extra_args Further registration arguments.
	 *
	 * @return mixed What core returned.
	 */
	private function run_ability( callable $callback, array $extra_args = [] ) {
		$this->during(
			'wp_abilities_api_init',
			static function () use ( $callback, $extra_args ): void {
				wp_register_ability(
					self::ABILITY,
					$extra_args + [
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
		$this->registered_ids[] = self::ABILITY;

		return wp_get_ability( self::ABILITY )->execute();
	}

	/**
	 * The log rows for an ability, oldest first.
	 *
	 * @param string $ability_name Ability identifier.
	 *
	 * @return array<int, object>
	 */
	private function rows( string $ability_name = self::ABILITY ): array {
		return array_values(
			array_filter(
				array_reverse( $this->repository->recent( 50 ) ),
				static fn( object $row ): bool => $row->ability_name === $ability_name
			)
		);
	}

	/**
	 * A success writes one success row.
	 *
	 * @return void
	 */
	public function test_logs_a_success(): void {
		$this->run_ability( static fn(): array => [ 'ok' => true ] );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'success', $rows[0]->status );
	}

	/**
	 * A WP_Error from the callback writes one error row with its code.
	 *
	 * @return void
	 */
	public function test_logs_a_wp_error(): void {
		$this->run_ability( static fn() => new WP_Error( 'report_unavailable', 'No data.' ) );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]->status );
		$this->assertSame( 'report_unavailable', $rows[0]->error_code );
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
		$this->assertSame( 'ability_callback_exception', $this->rows()[0]->error_code );
	}

	/**
	 * Output that fails validation is logged as an error, not a success.
	 *
	 * @return void
	 */
	public function test_logs_invalid_output_as_an_error(): void {
		$this->run_ability(
			static fn(): array => [ 'count' => 'many' ],
			[
				'output_schema' => [
					'type'       => 'object',
					'properties' => [ 'count' => [ 'type' => 'integer' ] ],
				],
			]
		);

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ability_invalid_output', $rows[0]->error_code );
	}

	/**
	 * A denied call is logged as a warning with the callback's own reason.
	 *
	 * @return void
	 */
	public function test_logs_a_denial_with_its_reason(): void {
		// Core reports a denial's reason through _doing_it_wrong(), then hides it.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$this->run_ability(
			static fn(): array => [ 'ok' => true ],
			[ 'permission_callback' => static fn() => new WP_Error( 'report_forbidden', 'No.' ) ]
		);

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'report_forbidden', $rows[0]->error_code );
	}

	/**
	 * Without an Albert connection another plugin's call is not logged.
	 *
	 * @return void
	 */
	public function test_ignores_a_call_without_a_connection(): void {
		ConnectionContext::reset();

		$this->run_ability( static fn(): array => [ 'ok' => true ] );

		$this->assertSame( [], $this->rows() );
	}

	/**
	 * Albert's own ability writes one row, from the observer, not two.
	 *
	 * @return void
	 */
	public function test_logs_albert_abilities_once(): void {
		$ability = wp_get_ability( 'albert/find-users' );
		if ( $ability === null ) {
			$this->markTestSkipped( 'albert/find-users is not registered.' );
		}

		$ability->execute( [] );

		$this->assertCount( 1, $this->rows( 'albert/find-users' ) );
	}
}
