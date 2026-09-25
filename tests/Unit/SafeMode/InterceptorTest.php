<?php
/**
 * Unit tests for the safe-mode interceptor.
 *
 * Pins what the interceptor lets through and what it holds: the MCP wrapper
 * firing is skipped, only assistant-initiated calls are gated, a destructive
 * call is staged and short-circuited, and an approved re-execution passes.
 *
 * @package Albert\Tests\Unit\SafeMode
 */

namespace Albert\Tests\Unit\SafeMode;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Ability.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Error.php';

use Albert\Execution\InterceptorDecision;
use Albert\OAuth\Server\ConnectionContext;
use Albert\SafeMode\ApprovalTicket;
use Albert\SafeMode\Gate;
use Albert\SafeMode\Interceptor;
use Albert\SafeMode\PendingAction;
use Albert\SafeMode\Repository;
use PHPUnit\Framework\TestCase;
use WP_Ability;
use WP_Error;

/**
 * A repository double that records staging without touching a database.
 */
class RecordingRepository extends Repository {

	/**
	 * Calls captured by {@see self::stage()}.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $staged = [];

	/**
	 * Id handed back; 0 simulates an insert that failed.
	 *
	 * @var int
	 */
	public int $stored_id = 1;

	/**
	 * Record and return a staged action without persisting it.
	 *
	 * @param string      $ability_name The ability.
	 * @param array       $input        Resolved input.
	 * @param int         $user_id      Acting user.
	 * @param string|null $client_id    Client id.
	 * @param string|null $client_name  Client name.
	 * @param int         $ttl_seconds  Time to live.
	 * @param array|null  $target       Target snapshot.
	 *
	 * @return PendingAction
	 */
	public function stage( string $ability_name, array $input, int $user_id, ?string $client_id, ?string $client_name, int $ttl_seconds, ?array $target = null ): PendingAction {
		$this->staged[] = compact( 'ability_name', 'input', 'user_id', 'client_id', 'client_name', 'ttl_seconds', 'target' );

		return new PendingAction(
			$this->stored_id,
			'action-ref-1',
			$ability_name,
			$input,
			PendingAction::STATUS_PENDING,
			$user_id,
			$client_id,
			$client_name,
			'2026-01-01 00:00:00',
			'2026-01-02 00:00:00',
			null,
			null,
			null
		);
	}
}

/**
 * Interceptor tests.
 *
 * @covers \Albert\SafeMode\Interceptor
 */
class InterceptorTest extends TestCase {

	/**
	 * The sentinel a real WordPress passes as the pre-execute default.
	 *
	 * @var object
	 */
	private object $sentinel;

	/**
	 * The recording repository double.
	 *
	 * @var RecordingRepository
	 */
	private RecordingRepository $repository;

	/**
	 * The interceptor under test.
	 *
	 * @var Interceptor
	 */
	private Interceptor $interceptor;

	/**
	 * Fresh state and a minimal $wpdb for the client-name lookup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_options'] = [];
		$GLOBALS['albert_test_hooks']   = [];
		$GLOBALS['albert_test_user_id'] = 7;
		ConnectionContext::reset();

		// ConnectionContext::client_name() snapshots from the OAuth table; a tiny
		// double keeps that lazy lookup from needing a real database.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for the isolated unit under test.
		$GLOBALS['wpdb'] = new class() {

			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public string $prefix = 'wp_';

			/**
			 * Return the query unchanged.
			 *
			 * @param string $query   Query.
			 * @param mixed  ...$args Ignored.
			 *
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				return $query;
			}

			/**
			 * Return null for any lookup.
			 *
			 * @param string $query Query.
			 *
			 * @return null
			 */
			public function get_var( $query ) {
				return null;
			}
		};

		$this->sentinel    = new \stdClass();
		$this->repository  = new RecordingRepository();
		$this->interceptor = new Interceptor( new InterceptorDecision(), new Gate(), $this->repository );
	}

	/**
	 * Clear the connection between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		ConnectionContext::reset();
		ApprovalTicket::clear();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * A destructive ability double.
	 *
	 * @param string $name Ability id.
	 *
	 * @return WP_Ability
	 */
	private function destructive( string $name = 'test/delete' ): WP_Ability {
		return new WP_Ability( $name, [ 'annotations' => [ 'destructive' => true ] ] );
	}

	/**
	 * The MCP transport wrapper firing is skipped, never staged.
	 *
	 * @return void
	 */
	public function test_skips_the_transport_wrapper(): void {
		ConnectionContext::set( 'client-1' );

		$result = $this->interceptor->intercept(
			$this->sentinel,
			'mcp-adapter/execute-ability',
			[ 'ability_name' => 'test/delete' ],
			$this->destructive()
		);

		$this->assertSame( $this->sentinel, $result );
		$this->assertSame( [], $this->repository->staged );
	}

	/**
	 * A call with no Albert connection is not gated: not assistant-initiated.
	 *
	 * @return void
	 */
	public function test_skips_a_call_without_a_connection(): void {
		$result = $this->interceptor->intercept( $this->sentinel, 'test/delete', [], $this->destructive() );

		$this->assertSame( $this->sentinel, $result );
		$this->assertSame( [], $this->repository->staged );
	}

	/**
	 * A non-destructive ability passes even over a connection.
	 *
	 * @return void
	 */
	public function test_allows_a_non_destructive_ability(): void {
		ConnectionContext::set( 'client-1' );

		$ability = new WP_Ability( 'test/create', [ 'annotations' => [ 'destructive' => false ] ] );
		$result  = $this->interceptor->intercept( $this->sentinel, 'test/create', [], $ability );

		$this->assertSame( $this->sentinel, $result );
		$this->assertSame( [], $this->repository->staged );
	}

	/**
	 * A destructive assistant call is staged and short-circuited with an error.
	 *
	 * @return void
	 */
	public function test_stages_and_short_circuits_a_destructive_call(): void {
		ConnectionContext::set( 'client-1' );

		$result = $this->interceptor->intercept(
			$this->sentinel,
			'test/delete',
			[ 'id' => 42 ],
			$this->destructive()
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_awaiting_approval', $result->get_error_code() );
		$this->assertCount( 1, $this->repository->staged );
		$this->assertSame( 'test/delete', $this->repository->staged[0]['ability_name'] );
		$this->assertSame( [ 'id' => 42 ], $this->repository->staged[0]['input'] );
		$this->assertSame( 7, $this->repository->staged[0]['user_id'] );
		$this->assertSame( 'client-1', $this->repository->staged[0]['client_id'] );
	}

	/**
	 * A hold that could not be recorded is reported as a failure, not as queued.
	 *
	 * @return void
	 */
	public function test_reports_a_hold_that_could_not_be_recorded(): void {
		ConnectionContext::set( 'client-1' );
		$this->repository->stored_id = 0;

		$result = $this->interceptor->intercept(
			$this->sentinel,
			'test/delete',
			[ 'id' => 42 ],
			$this->destructive()
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_hold_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( 'queued for approval', $result->get_error_message() );
	}

	/**
	 * A gated call the connection isn't permitted to make is denied, not staged.
	 *
	 * @return void
	 */
	public function test_denies_an_unpermitted_call_instead_of_staging(): void {
		ConnectionContext::set( 'client-1' );

		$ability             = $this->destructive();
		$ability->permission = false;

		$result = $this->interceptor->intercept( $this->sentinel, 'test/delete', [ 'id' => 42 ], $ability );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'albert_permission_denied', $result->get_error_code() );
		$this->assertSame( [], $this->repository->staged );
	}

	/**
	 * An armed ticket lets exactly its execution through, once, without staging.
	 *
	 * @return void
	 */
	public function test_lets_an_approved_execution_through(): void {
		ConnectionContext::set( 'client-1' );
		ApprovalTicket::arm( 'test/delete', [ 'id' => 42 ] );

		$result = $this->interceptor->intercept( $this->sentinel, 'test/delete', [ 'id' => 42 ], $this->destructive() );

		$this->assertSame( $this->sentinel, $result );
		$this->assertSame( [], $this->repository->staged );
	}

	/**
	 * The ticket is one-shot: a second identical call is gated again.
	 *
	 * @return void
	 */
	public function test_ticket_is_single_use(): void {
		ConnectionContext::set( 'client-1' );
		ApprovalTicket::arm( 'test/delete', [ 'id' => 42 ] );

		$this->interceptor->intercept( $this->sentinel, 'test/delete', [ 'id' => 42 ], $this->destructive() );
		$second = $this->interceptor->intercept( $this->sentinel, 'test/delete', [ 'id' => 42 ], $this->destructive() );

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertCount( 1, $this->repository->staged );
	}

	/**
	 * A ticket for a different ability does not exempt this one.
	 *
	 * @return void
	 */
	public function test_ticket_is_bound_to_its_ability(): void {
		ConnectionContext::set( 'client-1' );
		ApprovalTicket::arm( 'test/other', [ 'id' => 42 ] );

		$result = $this->interceptor->intercept( $this->sentinel, 'test/delete', [ 'id' => 42 ], $this->destructive() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 1, $this->repository->staged );
	}

	/**
	 * A ticket for different input does not exempt this call.
	 *
	 * @return void
	 */
	public function test_ticket_is_bound_to_its_input(): void {
		ConnectionContext::set( 'client-1' );
		ApprovalTicket::arm( 'test/delete', [ 'id' => 1 ] );

		$result = $this->interceptor->intercept( $this->sentinel, 'test/delete', [ 'id' => 2 ], $this->destructive() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 1, $this->repository->staged );
	}

	/**
	 * Without a WP_Ability instance (below 7.1's shape) the call passes.
	 *
	 * @return void
	 */
	public function test_passes_when_no_ability_instance(): void {
		ConnectionContext::set( 'client-1' );

		$result = $this->interceptor->intercept( $this->sentinel, 'test/delete', [], null );

		$this->assertSame( $this->sentinel, $result );
		$this->assertSame( [], $this->repository->staged );
	}
}
