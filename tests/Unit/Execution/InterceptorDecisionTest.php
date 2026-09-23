<?php
/**
 * Unit tests for the interceptor double-fire decision.
 *
 * A single MCP tool call fires `wp_pre_execute_ability` twice: once for the
 * `mcp-adapter/execute-ability` wrapper, once for the real target. These tests
 * pin the rule that only the wrapper is skipped, so an interceptor acts exactly
 * once, on the real ability.
 *
 * @package Albert\Tests\Unit\Execution
 */

namespace Albert\Tests\Unit\Execution;

use Albert\Execution\InterceptorDecision;
use PHPUnit\Framework\TestCase;

/**
 * InterceptorDecision tests.
 *
 * @covers \Albert\Execution\InterceptorDecision
 */
class InterceptorDecisionTest extends TestCase {

	/**
	 * The decision under test.
	 *
	 * @var InterceptorDecision
	 */
	private InterceptorDecision $decision;

	/**
	 * Fresh decision per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->decision = new InterceptorDecision();
	}

	/**
	 * The MCP wrapper firing is recognised as the transport wrapper.
	 *
	 * @return void
	 */
	public function test_execute_wrapper_is_the_transport_wrapper(): void {
		$this->assertTrue( $this->decision->is_transport_wrapper( 'mcp-adapter/execute-ability' ) );
	}

	/**
	 * A real ability firing is not the transport wrapper.
	 *
	 * @return void
	 */
	public function test_real_ability_is_not_the_transport_wrapper(): void {
		$this->assertFalse( $this->decision->is_transport_wrapper( 'albert/delete-post' ) );
	}

	/**
	 * The other MCP meta-tools do not nest a second execute, so they are not
	 * the wrapper and are treated as real invocations.
	 *
	 * @return void
	 */
	public function test_other_meta_tools_are_not_the_transport_wrapper(): void {
		$this->assertFalse( $this->decision->is_transport_wrapper( 'mcp-adapter/discover-abilities' ) );
		$this->assertFalse( $this->decision->is_transport_wrapper( 'mcp-adapter/get-ability-info' ) );
	}

	/**
	 * An interceptor skips the wrapper firing.
	 *
	 * @return void
	 */
	public function test_should_not_intercept_the_wrapper(): void {
		$this->assertFalse( $this->decision->should_intercept( 'mcp-adapter/execute-ability' ) );
	}

	/**
	 * An interceptor acts on the real ability firing.
	 *
	 * @return void
	 */
	public function test_should_intercept_the_real_ability(): void {
		$this->assertTrue( $this->decision->should_intercept( 'albert/delete-post' ) );
		$this->assertTrue( $this->decision->should_intercept( 'woocommerce/delete-order' ) );
	}

	/**
	 * The wrapper constant is pinned to the adapter's execute tool name, the
	 * same string Albert's server lists as a core tool ability.
	 *
	 * @return void
	 */
	public function test_wrapper_constant_is_the_adapter_execute_tool(): void {
		$this->assertSame( 'mcp-adapter/execute-ability', InterceptorDecision::MCP_EXECUTE_WRAPPER );
	}
}
