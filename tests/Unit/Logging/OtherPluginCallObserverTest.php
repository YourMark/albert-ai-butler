<?php
/**
 * Unit tests for OtherPluginCallObserver.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Logging;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/StubAbility.php';

use Albert\Logging\ExecutionLogMarker;
use Albert\Logging\OtherPluginCallObserver;
use Albert\OAuth\Server\ConnectionContext;
use Albert\Tests\Unit\StubAbility;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * OtherPluginCallObserver unit tests.
 *
 * @covers \Albert\Logging\OtherPluginCallObserver
 */
class OtherPluginCallObserverTest extends TestCase {

	/**
	 * The observer under test.
	 *
	 * @var OtherPluginCallObserver
	 */
	private OtherPluginCallObserver $observer;

	/**
	 * Another plugin's ability.
	 */
	private const ABILITY = 'koko-analytics/get-top-pages';

	/**
	 * Reset hooks, marker and connection before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks']   = [];
		$GLOBALS['albert_test_user_id'] = 42;
		ExecutionLogMarker::reset();
		ConnectionContext::set( 'client-1' );
		( new \ReflectionProperty( OtherPluginCallObserver::class, 'observed' ) )->setValue( null, [] );
		$this->observer = new OtherPluginCallObserver();
	}

	/**
	 * Clear the connection after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ConnectionContext::reset();
		parent::tearDown();
	}

	/**
	 * Register an ability the way core would, so the observer can claim it.
	 *
	 * @param string $name     Ability name.
	 * @param mixed  $callback The execute_callback.
	 *
	 * @return void
	 */
	private function register( string $name = self::ABILITY, $callback = null ): void {
		$args = [ 'execute_callback' => $callback ?? static fn() => [] ];

		$this->assertSame( $args, $this->observer->note_ability( $args, $name ) );
	}

	/**
	 * The after_execute calls recorded so far.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function after_execute_calls(): array {
		return array_values(
			array_filter(
				$GLOBALS['albert_test_hooks'],
				static fn( array $hook ): bool => $hook['type'] === 'action' && $hook['hook'] === 'albert/abilities/after_execute'
			)
		);
	}

	/**
	 * A success is logged with the user who made it.
	 *
	 * @return void
	 */
	public function test_logs_a_successful_call(): void {
		$this->register();

		$this->observer->record_success( self::ABILITY, [ 'limit' => 5 ], [ 'pages' => [ '/' ] ] );

		$calls = $this->after_execute_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( [ self::ABILITY, [ 'limit' => 5 ], [ 'pages' => [ '/' ] ], 42 ], $calls[0]['args'] );
	}

	/**
	 * A WP_Error result is logged and passed through unchanged.
	 *
	 * @return void
	 */
	public function test_logs_a_failed_call(): void {
		$this->register();
		$error = new WP_Error( 'koko_unavailable', 'No data.' );

		$this->assertSame( $error, $this->observer->record_failure( $error, self::ABILITY, [] ) );
		$this->assertSame( $error, $this->after_execute_calls()[0]['args'][2] );
	}

	/**
	 * A thrown exception arrives as core's WP_Error and is logged like any failure.
	 *
	 * @return void
	 */
	public function test_logs_an_exception_core_turned_into_an_error(): void {
		$this->register();
		$error = new WP_Error( 'ability_callback_exception', 'boom' );

		$this->observer->record_failure( $error, self::ABILITY, null );

		$args = $this->after_execute_calls()[0]['args'];
		$this->assertSame( [], $args[1] );
		$this->assertSame( 'ability_callback_exception', $args[2]->get_error_code() );
	}

	/**
	 * A successful result is left for wp_after_execute_ability, after output validation.
	 *
	 * @return void
	 */
	public function test_does_not_log_a_success_from_the_result_filter(): void {
		$this->register();

		$this->assertSame( [ 'ok' => true ], $this->observer->record_failure( [ 'ok' => true ], self::ABILITY, [] ) );
		$this->assertSame( [], $this->after_execute_calls() );
	}

	/**
	 * Calls outside an Albert connection are not logged.
	 *
	 * @return void
	 */
	public function test_ignores_calls_outside_a_connection(): void {
		ConnectionContext::reset();
		$this->register();

		$this->observer->record_success( self::ABILITY, [], [] );
		$this->observer->record_failure( new WP_Error( 'x', 'y' ), self::ABILITY, [] );

		$this->assertSame( [], $this->after_execute_calls() );
	}

	/**
	 * A call another writer already logged is not logged again.
	 *
	 * @return void
	 */
	public function test_skips_a_call_already_logged(): void {
		ExecutionLogMarker::mark( self::ABILITY );
		$this->register();

		$this->observer->record_success( self::ABILITY, [], [] );

		$this->assertSame( [], $this->after_execute_calls() );
	}

	/**
	 * Albert's own abilities fire the hooks themselves, so they are skipped even
	 * when the marker is unset (Free's logging off, or an early return).
	 *
	 * @return void
	 */
	public function test_leaves_albert_abilities_alone(): void {
		$this->register( 'albert/find-posts', [ new StubAbility( 'albert/find-posts', [] ), 'guarded_execute' ] );

		$this->observer->record_success( 'albert/find-posts', [], [] );
		$this->observer->record_failure( new WP_Error( 'x', 'y' ), 'albert/find-posts', [] );

		$this->assertSame( [], $this->after_execute_calls() );
	}

	/**
	 * The adapter's meta-tools only proxy an ability, which is logged itself.
	 *
	 * @return void
	 */
	public function test_leaves_transport_abilities_alone(): void {
		$this->register( 'mcp-adapter/execute-ability' );

		$this->observer->record_success( 'mcp-adapter/execute-ability', [], [] );

		$this->assertSame( [], $this->after_execute_calls() );
	}
}
