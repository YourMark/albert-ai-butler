<?php
/**
 * Unit tests for ExecutionObserver.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Logging;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Logging\ExecutionObserver;
use Albert\Logging\Logger;
use Albert\OAuth\Server\ConnectionContext;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * ExecutionObserver unit tests.
 *
 * @covers \Albert\Logging\ExecutionObserver
 */
class ExecutionObserverTest extends TestCase {

	/**
	 * Another plugin's ability.
	 */
	private const ABILITY = 'koko-analytics/get-top-pages';

	/**
	 * Arguments of each Logger::record() call.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $records = [];

	/**
	 * The observer under test.
	 *
	 * @var ExecutionObserver
	 */
	private ExecutionObserver $observer;

	/**
	 * Open a connection and build the observer around a spy Logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['albert_test_hooks']   = [];
		$GLOBALS['albert_test_user_id'] = 42;
		ExecutionObserver::reset();
		ConnectionContext::set( 'client-1' );

		$this->records = [];
		$records       = &$this->records;
		$logger        = $this->getMockBuilder( Logger::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'record' ] )
			->getMock();
		$logger->method( 'record' )->willReturnCallback(
			static function () use ( &$records ): void {
				$records[] = func_get_args();
			}
		);

		$this->observer = new ExecutionObserver( $logger );
	}

	/**
	 * Close the connection and any open call.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ConnectionContext::reset();
		ExecutionObserver::reset();
		parent::tearDown();
	}

	/**
	 * A success is recorded once, with the raw input and the user.
	 *
	 * @return void
	 */
	public function test_records_a_success(): void {
		$this->observer->open( self::ABILITY, [ 'limit' => 5 ] );
		$this->observer->on_after_execute( self::ABILITY, [ 'limit' => 5 ], [ 'pages' => [ '/' ] ] );

		$this->assertSame( [ [ self::ABILITY, [ 'limit' => 5 ], [ 'pages' => [ '/' ] ], 42 ] ], $this->records );
		$this->assertFalse( ExecutionObserver::is_observing( self::ABILITY ) );
	}

	/**
	 * A failed callback, including an exception core caught, is recorded.
	 *
	 * @return void
	 */
	public function test_records_a_failed_callback(): void {
		$error = new WP_Error( 'ability_callback_exception', 'boom' );

		$this->observer->open( self::ABILITY );
		$this->assertSame( $error, $this->observer->on_execute_result( $error, self::ABILITY ) );

		$this->assertSame( $error, $this->records[0][2] );
	}

	/**
	 * A successful callback result is not final until output validation passes.
	 *
	 * @return void
	 */
	public function test_waits_past_a_successful_callback(): void {
		$this->observer->open( self::ABILITY );
		$this->observer->on_execute_result( [ 'ok' => true ], self::ABILITY );

		$this->assertSame( [], $this->records );
		$this->assertTrue( ExecutionObserver::is_observing( self::ABILITY ) );
	}

	/**
	 * Output that fails validation is an error, never a success.
	 *
	 * @return void
	 */
	public function test_records_invalid_output_as_an_error(): void {
		$this->observer->open( self::ABILITY );
		$this->observer->on_execute_result( [ 'ok' => true ], self::ABILITY );
		$this->observer->on_validate_output( false, [ 'ok' => true ], self::ABILITY );

		$this->assertCount( 1, $this->records );
		$this->assertSame( 'ability_invalid_output', $this->records[0][2]->get_error_code() );
	}

	/**
	 * A denial keeps the callback's own error, which core would replace.
	 *
	 * @return void
	 */
	public function test_records_the_real_permission_error(): void {
		$this->observer->open( self::ABILITY );
		$this->observer->on_permission_result( new WP_Error( 'albert_permission_denied', 'No.' ), self::ABILITY );

		$this->assertSame( 'albert_permission_denied', $this->records[0][2]->get_error_code() );
	}

	/**
	 * A bare false denial gets core's code.
	 *
	 * @return void
	 */
	public function test_records_a_bare_denial(): void {
		$this->observer->open( self::ABILITY );
		$this->observer->on_permission_result( false, self::ABILITY );

		$this->assertSame( 'ability_invalid_permissions', $this->records[0][2]->get_error_code() );
	}

	/**
	 * A short-circuit error, such as a call safe mode holds, is recorded.
	 *
	 * @return void
	 */
	public function test_records_a_short_circuit(): void {
		$held = new WP_Error( 'albert_awaiting_approval', 'Held.' );

		$this->observer->open( self::ABILITY );
		$this->assertSame( $held, $this->observer->on_pre_execute( $held, self::ABILITY ) );

		$this->assertSame( $held, $this->records[0][2] );
	}

	/**
	 * Core's sentinel means the call goes on.
	 *
	 * @return void
	 */
	public function test_ignores_the_sentinel(): void {
		$this->observer->open( self::ABILITY );
		$this->observer->on_pre_execute( new \WP_Filter_Sentinel(), self::ABILITY );

		$this->assertSame( [], $this->records );
	}

	/**
	 * Failed normalisation is recorded with the raw input.
	 *
	 * @return void
	 */
	public function test_records_failed_normalisation(): void {
		$this->observer->open( self::ABILITY, 'raw' );
		$this->observer->on_normalize_input( new WP_Error( 'bad_input', 'Bad.' ), self::ABILITY );

		$this->assertSame( 'raw', $this->records[0][1] );
	}

	/**
	 * An input rejection closes the call without a row.
	 *
	 * @return void
	 */
	public function test_closes_an_input_rejection_without_a_row(): void {
		$this->observer->open( self::ABILITY );
		$this->observer->on_validate_input( new WP_Error( 'ability_invalid_input', 'Bad.' ), [], self::ABILITY );

		$this->assertSame( [], $this->records );
		$this->assertFalse( ExecutionObserver::is_observing( self::ABILITY ) );
	}

	/**
	 * Another code from an input validation filter is recorded.
	 *
	 * @return void
	 */
	public function test_records_another_input_error(): void {
		$this->observer->open( self::ABILITY );
		$this->observer->on_validate_input( new WP_Error( 'koko_bad_range', 'Bad.' ), [], self::ABILITY );

		$this->assertCount( 1, $this->records );
	}

	/**
	 * Only the first terminal event counts.
	 *
	 * @return void
	 */
	public function test_records_once(): void {
		$error = new WP_Error( 'koko_unavailable', 'No data.' );

		$this->observer->open( self::ABILITY );
		$this->observer->on_execute_result( $error, self::ABILITY );
		$this->observer->on_after_execute( self::ABILITY, [], [] );

		$this->assertCount( 1, $this->records );
	}

	/**
	 * A filter fired outside an open call, such as a permission check before
	 * execute(), is not this observer's business.
	 *
	 * @return void
	 */
	public function test_ignores_hooks_outside_a_call(): void {
		$this->observer->on_permission_result( false, self::ABILITY );
		$this->observer->on_validate_input( false, [], self::ABILITY );

		$this->assertSame( [], $this->records );
	}

	/**
	 * Calls without an Albert connection are left to the Logger.
	 *
	 * @return void
	 */
	public function test_does_not_open_without_a_connection(): void {
		ConnectionContext::reset();

		$this->observer->open( self::ABILITY );

		$this->assertFalse( ExecutionObserver::is_observing( self::ABILITY ) );
	}

	/**
	 * The adapter's meta-tools only proxy the ability that gets logged.
	 *
	 * @return void
	 */
	public function test_does_not_open_a_transport_ability(): void {
		$this->observer->open( 'mcp-adapter/execute-ability' );

		$this->assertFalse( ExecutionObserver::is_observing( 'mcp-adapter/execute-ability' ) );
	}
}
