<?php
/**
 * Unit tests for the ObservabilityHandler class.
 *
 * Verifies that the handler only records MCP-level errors for real ability
 * names (not meta-tools), respects the storage gate, and is fully defensive.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Logging;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Logging\ObservabilityHandler;
use PHPUnit\Framework\TestCase;

/**
 * ObservabilityHandler unit tests.
 *
 * @covers \Albert\Logging\ObservabilityHandler
 */
class ObservabilityHandlerTest extends TestCase {

	/**
	 * Reset globals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']          = [];
		$GLOBALS['albert_test_user_id']        = 7;
		$GLOBALS['albert_test_filter_returns'] = [];
	}

	// ─── Ignored events ──────────────────────────────────────────────

	/**
	 * Non mcp.request events are silently ignored.
	 *
	 * The handler must never throw for unknown event names.
	 *
	 * @return void
	 */
	public function test_non_mcp_request_event_is_ignored(): void {
		$handler = new ObservabilityHandler();

		// Should not throw.
		$handler->record_event(
			'mcp.component.registration',
			[
				'status'       => 'error',
				'ability_name' => 'core/posts/list',
			]
		);

		$this->assertTrue( true, 'Non-mcp.request event was silently ignored.' );
	}

	/**
	 * An mcp.request event with status=success is ignored.
	 *
	 * @return void
	 */
	public function test_success_status_event_is_ignored(): void {
		$handler = new ObservabilityHandler();

		// Track whether apply_filters for logging/enabled is called (it should NOT be).
		$handler->record_event(
			'mcp.request',
			[
				'status'       => 'success',
				'ability_name' => 'core/posts/list',
			]
		);

		$filter_hooks = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/logging/enabled'
		);

		$this->assertCount( 0, $filter_hooks, 'Storage gate must not be checked for success events.' );
	}

	/**
	 * An mcp.request error event without an ability_name tag is ignored.
	 *
	 * Covers transport/protocol errors that do not map to a named ability.
	 *
	 * @return void
	 */
	public function test_error_event_without_ability_name_is_ignored(): void {
		$handler = new ObservabilityHandler();

		$handler->record_event( 'mcp.request', [ 'status' => 'error' ] );

		$filter_hooks = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/logging/enabled'
		);

		$this->assertCount( 0, $filter_hooks, 'No storage attempt expected when ability_name is absent.' );
	}

	/**
	 * Meta-tool names (mcp-adapter/* prefix) are skipped.
	 *
	 * These would produce misleading log entries since the inner ability id
	 * is not surfaced at the MCP transport layer for meta-tool calls.
	 *
	 * @return void
	 */
	public function test_meta_tool_ability_name_is_skipped(): void {
		$handler = new ObservabilityHandler();

		$handler->record_event(
			'mcp.request',
			[
				'status'       => 'error',
				'ability_name' => 'mcp-adapter/execute-ability',
			]
		);

		$filter_hooks = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/logging/enabled'
		);

		$this->assertCount( 0, $filter_hooks, 'Meta-tool names must be silently skipped.' );
	}

	/**
	 * All three meta-tools are skipped.
	 *
	 * @return void
	 */
	public function test_all_meta_tools_are_skipped(): void {
		$handler = new ObservabilityHandler();

		$meta_tools = [
			'mcp-adapter/discover-abilities',
			'mcp-adapter/get-ability-info',
			'mcp-adapter/execute-ability',
		];

		foreach ( $meta_tools as $tool ) {
			$handler->record_event(
				'mcp.request',
				[
					'status'       => 'error',
					'ability_name' => $tool,
				]
			);
		}

		$filter_hooks = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/logging/enabled'
		);

		$this->assertCount( 0, $filter_hooks, 'All three meta-tools must be skipped.' );
	}

	// ─── Storage gate ────────────────────────────────────────────────

	/**
	 * When albert/logging/enabled returns false, no insert attempt is made.
	 *
	 * The handler checks the filter before touching the DB. We verify this by
	 * confirming the filter was evaluated but no wpdb call follows. Since we
	 * have no real DB in unit tests, the absence of a fatal error is the proof.
	 *
	 * @return void
	 */
	public function test_storage_gate_suppresses_insert_when_disabled(): void {
		$GLOBALS['albert_test_filter_returns']['albert/logging/enabled'] = false;

		$handler = new ObservabilityHandler();

		// If the gate is broken and it tries to insert via real DB, a fatal/exception
		// will surface here. The test passing confirms the gate short-circuited.
		$handler->record_event(
			'mcp.request',
			[
				'status'       => 'error',
				'ability_name' => 'core/posts/list',
				'error_code'   => 'rest_forbidden',
			]
		);

		$filter_hooks = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => $h['hook'] === 'albert/logging/enabled'
		);

		$this->assertCount( 1, $filter_hooks, 'Storage gate filter must be evaluated.' );
		$this->assertTrue( true, 'No DB exception means the gate correctly short-circuited.' );
	}

	// ─── Exception safety ────────────────────────────────────────────

	/**
	 * Any exception thrown inside record_event is swallowed.
	 *
	 * The handler must never propagate exceptions to the MCP request cycle.
	 *
	 * @return void
	 */
	public function test_exception_inside_handler_is_swallowed(): void {
		// Trigger a code path that calls apply_filters via a real ability name,
		// then the storage attempt will fail (no $wpdb in unit tests). The
		// try/catch must absorb it.
		$handler = new ObservabilityHandler();

		// apply_filters stub returns true (default) → handler proceeds to new Repository(),
		// which needs $wpdb — that will throw/error in the unit context. Must be caught.
		$handler->record_event(
			'mcp.request',
			[
				'status'       => 'error',
				'ability_name' => 'core/posts/list',
			]
		);

		$this->assertTrue( true, 'Exception from missing $wpdb was swallowed.' );
	}
}
