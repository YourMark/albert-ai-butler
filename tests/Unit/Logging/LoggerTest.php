<?php
/**
 * Unit tests for the Logger class.
 *
 * Verifies status derivation, error_code extraction, the ability_failed
 * notification hook, and the storage gate — all without touching the DB.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Logging;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/StubAbility.php';

use Albert\Logging\ExecutionObserver;
use Albert\Logging\Logger;
use Albert\OAuth\Server\ConnectionContext;
use Albert\Logging\Repository;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Logger unit tests.
 *
 * @covers \Albert\Logging\Logger
 */
class LoggerTest extends TestCase {

	/**
	 * Captured insert calls from the spy repository.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $inserts = [];

	/**
	 * Spy repository that records insert calls without touching the DB.
	 *
	 * @var Repository
	 */
	private Repository $spy_repository;

	/**
	 * Logger under test.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Reset state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']          = [];
		$GLOBALS['albert_test_user_id']        = 42;
		$GLOBALS['albert_test_filter_returns'] = [];

		$this->inserts = [];

		// Spy repository captures insert args and never hits the DB.
		$inserts_ref          = &$this->inserts;
		$this->spy_repository = $this->getMockBuilder( Repository::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'insert' ] )
			->getMock();

		$this->spy_repository
			->expects( $this->any() )
			->method( 'insert' )
			->willReturnCallback(
				function ( string $ability_name, int $user_id, string $status = 'success', ?string $error_code = null, array $context = [] ) use ( &$inserts_ref ): void {
					$inserts_ref[] = [
						'ability_name' => $ability_name,
						'user_id'      => $user_id,
						'status'       => $status,
						'error_code'   => $error_code,
						'context'      => $context,
					];
				}
			);

		$this->logger = new Logger( $this->spy_repository );
	}

	// ─── Success path ────────────────────────────────────────────────

	/**
	 * A successful result (plain array) inserts with status='success'.
	 *
	 * @return void
	 */
	public function test_success_result_inserts_with_status_success(): void {
		$this->logger->log_execution( 'core/posts/list', [], [ 'posts' => [] ], 42 );

		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'success', $this->inserts[0]['status'] );
		$this->assertNull( $this->inserts[0]['error_code'] );
		$this->assertSame( 42, $this->inserts[0]['user_id'] );
	}

	/**
	 * A call the observer holds is left for it to log once the outcome is final.
	 *
	 * @return void
	 */
	public function test_stands_down_for_an_observed_call(): void {
		ConnectionContext::set( 'client-1' );
		$observer = new ExecutionObserver( $this->logger );
		$observer->open( 'core/posts/list' );
		$observer->on_before_execute( 'core/posts/list' );

		try {
			$this->logger->log_execution( 'core/posts/list', [], [ 'posts' => [] ], 42 );
			$this->assertSame( [], $this->inserts );

			$observer->on_after_execute( 'core/posts/list', [], [ 'posts' => [] ] );
			$this->assertCount( 1, $this->inserts );
		} finally {
			ExecutionObserver::reset();
			ConnectionContext::reset();
		}
	}

	// ─── Error path ──────────────────────────────────────────────────

	/**
	 * A WP_Error result inserts with status='error' and the correct error_code.
	 *
	 * @return void
	 */
	public function test_wp_error_result_inserts_with_status_error_and_code(): void {
		$error = new WP_Error( 'db_unreachable', 'The database went away.' );

		$this->logger->log_execution( 'core/posts/create', [], $error, 42 );

		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'error', $this->inserts[0]['status'] );
		$this->assertSame( 'db_unreachable', $this->inserts[0]['error_code'] );
		$this->assertSame( 'The database went away.', $this->inserts[0]['context']['error_message'] );
	}

	/**
	 * A WP_Error result fires the albert/logging/ability_failed action.
	 *
	 * @return void
	 */
	public function test_wp_error_fires_ability_failed_action(): void {
		$error = new WP_Error( 'some_error', 'Something went wrong.' );

		$this->logger->log_execution( 'core/posts/delete', [ 'id' => 5 ], $error, 42 );

		$failed_hooks = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['type'] === 'action' && $h['hook'] === 'albert/logging/ability_failed'
		);

		$this->assertCount( 1, $failed_hooks );
		$hook = array_values( $failed_hooks )[0];
		$this->assertSame( 'core/posts/delete', $hook['args'][0] );
		$this->assertInstanceOf( WP_Error::class, $hook['args'][1] );
	}

	/**
	 * A successful result does NOT fire albert/logging/ability_failed.
	 *
	 * @return void
	 */
	public function test_success_result_does_not_fire_ability_failed_action(): void {
		$this->logger->log_execution( 'core/posts/list', [], [ 'posts' => [] ], 42 );

		$failed_hooks = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/logging/ability_failed'
		);

		$this->assertCount( 0, $failed_hooks );
	}

	// ─── Truthful negative answers ───────────────────────────────────

	/**
	 * A `_not_found` error is stored as success, not error.
	 *
	 * `ViewTerm( 999 )` was asked whether a term exists, looked, and answered
	 * truthfully. Recording that as a failure is misinformation. The error code
	 * still rides along, so the row can say *what* it answered.
	 *
	 * @return void
	 */
	public function test_not_found_error_inserts_with_status_success(): void {
		$error = new WP_Error( 'term_not_found', 'No such term.' );

		$this->logger->log_execution( 'albert/view-term', [ 'term_id' => 999 ], $error, 42 );

		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'success', $this->inserts[0]['status'] );
		$this->assertSame( 'term_not_found', $this->inserts[0]['error_code'] );
		$this->assertSame( 'No such term.', $this->inserts[0]['context']['error_message'] );
	}

	/**
	 * A truthful negative answer does NOT fire albert/logging/ability_failed.
	 *
	 * That hook is Premium's failure signal. A 404 should not wake anyone.
	 *
	 * @return void
	 */
	public function test_not_found_does_not_fire_ability_failed_action(): void {
		$error = new WP_Error( 'term_not_found', 'No such term.' );

		$this->logger->log_execution( 'albert/view-term', [ 'term_id' => 999 ], $error, 42 );

		$this->assertCount( 0, $this->ability_failed_hooks() );
	}

	// ─── Policy blocks ───────────────────────────────────────────────

	/**
	 * A refusal is stored as warning, not error.
	 *
	 * @return void
	 */
	public function test_permission_denied_inserts_with_status_warning(): void {
		$error = new WP_Error( 'ability_permission_denied', 'You may not do that.' );

		$this->logger->log_execution( 'albert/delete-post', [ 'id' => 7 ], $error, 42 );

		$this->assertCount( 1, $this->inserts );
		$this->assertSame( 'warning', $this->inserts[0]['status'] );
		$this->assertSame( 'ability_permission_denied', $this->inserts[0]['error_code'] );
	}

	/**
	 * A warning does NOT fire albert/logging/ability_failed either.
	 *
	 * A permission system doing exactly what it was configured to do is not a
	 * failure signal, and paging somebody over it trains them to ignore the hook.
	 *
	 * @return void
	 */
	public function test_warning_does_not_fire_ability_failed_action(): void {
		$error = new WP_Error( 'ability_disabled', 'That ability is switched off.' );

		$this->logger->log_execution( 'albert/delete-post', [ 'id' => 7 ], $error, 42 );

		$this->assertCount( 0, $this->ability_failed_hooks() );
	}

	/**
	 * The outcome filter can silence ability_failed for a code Albert does not know.
	 *
	 * A third-party ability that names its "nothing matched" code anything else
	 * reaches the same quiet outcome through `albert/logging/outcome`.
	 *
	 * @return void
	 */
	public function test_outcome_filter_can_silence_ability_failed(): void {
		$GLOBALS['albert_test_filter_returns']['albert/logging/outcome'] = 'success';

		$error = new WP_Error( 'acme_nothing_matched', 'No rows.' );
		$this->logger->log_execution( 'acme/search', [], $error, 42 );

		$this->assertCount( 0, $this->ability_failed_hooks() );
		$this->assertSame( 'success', $this->inserts[0]['status'] );
	}

	/**
	 * The outcome filter can silence ability_failed by returning a warning too.
	 *
	 * @return void
	 */
	public function test_outcome_filter_returning_warning_silences_ability_failed(): void {
		$GLOBALS['albert_test_filter_returns']['albert/logging/outcome'] = 'warning';

		$error = new WP_Error( 'acme_not_licensed', 'Your plan does not include this.' );
		$this->logger->log_execution( 'acme/search', [], $error, 42 );

		$this->assertCount( 0, $this->ability_failed_hooks() );
		$this->assertSame( 'warning', $this->inserts[0]['status'] );
	}

	// ─── Storage gate ────────────────────────────────────────────────

	/**
	 * When albert/logging/enabled returns false, no insert is made.
	 *
	 * @return void
	 */
	public function test_storage_gate_suppresses_insert_when_disabled(): void {
		$GLOBALS['albert_test_filter_returns']['albert/logging/enabled'] = false;

		$this->logger->log_execution( 'core/posts/list', [], [ 'ok' => true ], 42 );

		$this->assertCount( 0, $this->inserts );
	}

	/**
	 * When albert/logging/enabled returns false, ability_failed still fires for WP_Error.
	 *
	 * The notification hook is decoupled from the storage gate so Premium always
	 * receives the signal even when Free's writes are suppressed.
	 *
	 * @return void
	 */
	public function test_ability_failed_fires_even_when_storage_gate_is_off(): void {
		$GLOBALS['albert_test_filter_returns']['albert/logging/enabled'] = false;

		$error = new WP_Error( 'some_code', 'msg' );
		$this->logger->log_execution( 'core/posts/delete', [], $error, 42 );

		// Insert must NOT have been called.
		$this->assertCount( 0, $this->inserts );

		// But the notification action MUST have fired.
		$failed_hooks = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/logging/ability_failed'
		);
		$this->assertCount( 1, $failed_hooks );
	}

	// ─── Exception safety ────────────────────────────────────────────

	/**
	 * A throwing repository does not propagate the exception.
	 *
	 * @return void
	 */
	public function test_repository_exception_is_swallowed(): void {
		$throwing_repo = $this->getMockBuilder( Repository::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'insert' ] )
			->getMock();

		$throwing_repo
			->method( 'insert' )
			->willThrowException( new \RuntimeException( 'DB is down' ) );

		$logger = new Logger( $throwing_repo );

		// Must not throw.
		$logger->log_execution( 'core/posts/list', [], [ 'ok' => true ], 1 );

		$this->assertTrue( true, 'Logger swallowed the repository exception.' );
	}

	/**
	 * Every recorded firing of the failure notification hook.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ability_failed_hooks(): array {
		return array_values(
			array_filter(
				$GLOBALS['albert_test_hooks'],
				static fn( array $h ): bool => $h['hook'] === 'albert/logging/ability_failed'
			)
		);
	}
}
