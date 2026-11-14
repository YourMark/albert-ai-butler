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
use RuntimeException;
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
	 * Wrap a callback the way registration would and return the wrapped one.
	 *
	 * @param callable $callback Original execute_callback.
	 * @param string   $name     Ability name.
	 *
	 * @return callable
	 */
	private function wrap( callable $callback, string $name = 'koko-analytics/get-top-pages' ): callable {
		$args = $this->observer->wrap_execute_callback( [ 'execute_callback' => $callback ], $name );

		return $args['execute_callback'];
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
	 * A success is logged once core accepted the output, not from the callback.
	 *
	 * @return void
	 */
	public function test_logs_a_successful_call_after_core_accepts_it(): void {
		$wrapped = $this->wrap( static fn( $input ) => [ 'pages' => [ '/' ] ] );

		$result = $wrapped( [ 'limit' => 5 ] );

		$this->assertSame( [ 'pages' => [ '/' ] ], $result );
		$this->assertSame( [], $this->after_execute_calls(), 'Output validation has not run yet.' );

		$this->observer->record_success( 'koko-analytics/get-top-pages', [ 'limit' => 5 ], $result );

		$calls = $this->after_execute_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( [ 'koko-analytics/get-top-pages', [ 'limit' => 5 ], [ 'pages' => [ '/' ] ], 42 ], $calls[0]['args'] );
	}

	/**
	 * A WP_Error from the callback is logged as is.
	 *
	 * @return void
	 */
	public function test_logs_a_failed_call(): void {
		$error   = new WP_Error( 'koko_unavailable', 'No data.' );
		$wrapped = $this->wrap( static fn( $input ) => $error );

		$this->assertSame( $error, $wrapped( [] ) );
		$this->assertSame( $error, $this->after_execute_calls()[0]['args'][2] );
	}

	/**
	 * A throwing callback is logged and the exception still reaches core.
	 *
	 * @return void
	 */
	public function test_logs_and_rethrows_an_exception(): void {
		$wrapped = $this->wrap(
			static function (): void {
				throw new RuntimeException( 'boom' );
			}
		);

		try {
			$wrapped();
			$this->fail( 'Expected the exception to be rethrown.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$logged = $this->after_execute_calls()[0]['args'][2];
		$this->assertInstanceOf( WP_Error::class, $logged );
		$this->assertSame( 'ability_callback_exception', $logged->get_error_code() );
	}

	/**
	 * Scalars are wrapped so the hook still receives arrays.
	 *
	 * @return void
	 */
	public function test_wraps_scalar_input_and_result(): void {
		$this->wrap( static fn( $input ) => 7 );

		$this->observer->record_success( 'koko-analytics/get-top-pages', 'abc', 7 );

		$args = $this->after_execute_calls()[0]['args'];
		$this->assertSame( [ 'input' => 'abc' ], $args[1] );
		$this->assertSame( [ 'result' => 7 ], $args[2] );
	}

	/**
	 * Without input schema core passes no argument; the callback gets none either.
	 *
	 * @return void
	 */
	public function test_passes_no_argument_when_core_passes_none(): void {
		$received = null;
		$wrapped  = $this->wrap(
			static function ( ...$args ) use ( &$received ): array {
				$received = $args;
				return [];
			}
		);

		$wrapped();

		$this->assertSame( [], $received );
	}

	/**
	 * Calls outside an Albert connection are not logged.
	 *
	 * @return void
	 */
	public function test_ignores_calls_outside_a_connection(): void {
		ConnectionContext::reset();
		$this->wrap( static fn( $input ) => [] );

		$this->observer->record_success( 'koko-analytics/get-top-pages', [], [] );

		$this->assertSame( [], $this->after_execute_calls() );
	}

	/**
	 * A call another writer already logged is not logged again.
	 *
	 * @return void
	 */
	public function test_skips_a_call_already_logged(): void {
		ExecutionLogMarker::mark( 'koko-analytics/get-top-pages' );
		$this->wrap( static fn( $input ) => [] );

		$this->observer->record_success( 'koko-analytics/get-top-pages', [], [] );

		$this->assertSame( [], $this->after_execute_calls() );
	}

	/**
	 * A success for an ability this observer did not wrap is not its to log.
	 *
	 * @return void
	 */
	public function test_ignores_success_of_an_unwrapped_ability(): void {
		$this->observer->record_success( 'albert/never-wrapped', [], [] );

		$this->assertSame( [], $this->after_execute_calls() );
	}

	/**
	 * Albert's own abilities are left alone: they fire the hooks themselves.
	 *
	 * @return void
	 */
	public function test_leaves_albert_abilities_unwrapped(): void {
		$callback = [ new StubAbility( 'albert/find-posts', [] ), 'guarded_execute' ];

		$args = $this->observer->wrap_execute_callback( [ 'execute_callback' => $callback ], 'albert/find-posts' );

		$this->assertSame( $callback, $args['execute_callback'] );
	}

	/**
	 * The adapter's meta-tools are left alone: they only proxy an ability.
	 *
	 * @return void
	 */
	public function test_leaves_transport_abilities_unwrapped(): void {
		$callback = static fn() => [];

		$args = $this->observer->wrap_execute_callback( [ 'execute_callback' => $callback ], 'mcp-adapter/execute-ability' );

		$this->assertSame( $callback, $args['execute_callback'] );
	}
}
