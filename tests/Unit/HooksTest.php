<?php
/**
 * Tests for Albert extensibility hooks.
 *
 * Verifies that all action and filter hooks fire with the correct
 * hook names, parameter counts, and parameter values.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit;

require_once __DIR__ . '/stubs/wordpress.php';

use Albert\Abstracts\BaseAbility;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Concrete ability stub for testing hooks.
 *
 * Returns a configurable result from execute().
 */
class StubAbility extends BaseAbility {

	/**
	 * Value to return from execute().
	 *
	 * @var array<string, mixed>|WP_Error
	 */
	private array|WP_Error $return_value;

	/**
	 * Constructor.
	 *
	 * @param string               $id           Ability identifier.
	 * @param array<string, mixed>|WP_Error $return_value Value returned by execute().
	 */
	public function __construct( string $id = 'test/my-ability', array|WP_Error $return_value = [] ) {
		$this->id           = $id;
		$this->label        = 'Test Ability';
		$this->description  = 'A stub ability for testing hooks.';
		$this->category     = 'test';
		$this->return_value = ! empty( $return_value ) || $return_value instanceof WP_Error
			? $return_value
			: [ 'ok' => true ];

		parent::__construct();
	}

	/**
	 * Check permission (always allowed in tests).
	 *
	 * @return true|WP_Error
	 */
	public function check_permission(): true|WP_Error {
		return true;
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( array $args ): array|WP_Error {
		return $this->return_value;
	}
}

/**
 * Tests for Albert extensibility hooks.
 *
 * @covers \Albert\Abstracts\BaseAbility::guarded_execute
 */
class HooksTest extends TestCase {

	/**
	 * Reset hook tracker and options before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']   = [];
		$GLOBALS['albert_test_user_id'] = 42;
		$GLOBALS['albert_test_options'] = [
			'albert_abilities_saved'    => true,
			'albert_disabled_abilities' => [],
		];
	}

	/**
	 * Get all recorded hook calls matching a hook name.
	 *
	 * @param string $name Hook name to filter by.
	 *
	 * @return array<int, array<string, mixed>> Matching hook entries.
	 */
	private function get_hooks( string $name ): array {
		return array_values(
			array_filter(
				$GLOBALS['albert_test_hooks'],
				static fn( array $h ): bool => $h['hook'] === $name
			)
		);
	}

	// ─── albert/abilities/before_execute (generic) ───────────────────

	/**
	 * Test that the generic before_execute hook fires once per execution.
	 *
	 * @return void
	 */
	public function test_before_execute_fires_on_execute(): void {
		$ability = new StubAbility();
		$ability->guarded_execute( [] );

		$this->assertCount( 1, $this->get_hooks( 'albert/abilities/before_execute' ) );
	}

	/**
	 * Test that before_execute receives the ability ID as first parameter.
	 *
	 * @return void
	 */
	public function test_before_execute_receives_ability_id(): void {
		$ability = new StubAbility( 'core/posts/create' );
		$ability->guarded_execute( [] );

		$hooks = $this->get_hooks( 'albert/abilities/before_execute' );
		$this->assertSame( 'core/posts/create', $hooks[0]['args'][0] );
	}

	/**
	 * Test that before_execute receives the input arguments.
	 *
	 * @return void
	 */
	public function test_before_execute_receives_input_args(): void {
		$input = [
			'title'  => 'Hello',
			'status' => 'draft',
		];

		$ability = new StubAbility();
		$ability->guarded_execute( $input );

		$hooks = $this->get_hooks( 'albert/abilities/before_execute' );
		$this->assertSame( $input, $hooks[0]['args'][1] );
	}

	/**
	 * Test that before_execute receives the current user ID.
	 *
	 * @return void
	 */
	public function test_before_execute_receives_user_id(): void {
		$GLOBALS['albert_test_user_id'] = 99;

		$ability = new StubAbility();
		$ability->guarded_execute( [] );

		$hooks = $this->get_hooks( 'albert/abilities/before_execute' );
		$this->assertSame( 99, $hooks[0]['args'][2] );
	}

	// ─── albert/abilities/before_execute/{ability_id} (dynamic) ──────

	/**
	 * Test that the dynamic before_execute hook fires with ability ID in the hook name.
	 *
	 * @return void
	 */
	public function test_before_execute_dynamic_fires_with_ability_id_in_hook_name(): void {
		$ability = new StubAbility( 'core/posts/create' );
		$ability->guarded_execute( [] );

		$this->assertCount(
			1,
			$this->get_hooks( 'albert/abilities/before_execute/core/posts/create' )
		);
	}

	/**
	 * Test that the dynamic before_execute hook does not pass ability ID as a parameter.
	 *
	 * @return void
	 */
	public function test_before_execute_dynamic_omits_ability_id_from_params(): void {
		$ability = new StubAbility( 'core/posts/create' );
		$ability->guarded_execute( [ 'x' => 1 ] );

		$hooks = $this->get_hooks( 'albert/abilities/before_execute/core/posts/create' );

		$this->assertIsArray( $hooks[0]['args'][0] );
		$this->assertCount( 2, $hooks[0]['args'] );
	}

	/**
	 * Test that the dynamic before_execute hook receives args and user ID.
	 *
	 * @return void
	 */
	public function test_before_execute_dynamic_receives_args_and_user_id(): void {
		$GLOBALS['albert_test_user_id'] = 7;
		$input = [ 'key' => 'value' ];

		$ability = new StubAbility( 'test/example' );
		$ability->guarded_execute( $input );

		$hooks = $this->get_hooks( 'albert/abilities/before_execute/test/example' );
		$this->assertSame( $input, $hooks[0]['args'][0] );
		$this->assertSame( 7, $hooks[0]['args'][1] );
	}

	// ─── albert/abilities/after_execute (generic) ────────────────────

	/**
	 * Test that the generic after_execute hook fires once per execution.
	 *
	 * @return void
	 */
	public function test_after_execute_fires_on_execute(): void {
		$ability = new StubAbility();
		$ability->guarded_execute( [] );

		$this->assertCount( 1, $this->get_hooks( 'albert/abilities/after_execute' ) );
	}

	/**
	 * Test that after_execute receives the ability ID.
	 *
	 * @return void
	 */
	public function test_after_execute_receives_ability_id(): void {
		$ability = new StubAbility( 'albert/woo-find-products' );
		$ability->guarded_execute( [] );

		$hooks = $this->get_hooks( 'albert/abilities/after_execute' );
		$this->assertSame( 'albert/woo-find-products', $hooks[0]['args'][0] );
	}

	/**
	 * Test that after_execute receives the input arguments.
	 *
	 * @return void
	 */
	public function test_after_execute_receives_input_args(): void {
		$input   = [ 'search' => 'test' ];
		$ability = new StubAbility();
		$ability->guarded_execute( $input );

		$hooks = $this->get_hooks( 'albert/abilities/after_execute' );
		$this->assertSame( $input, $hooks[0]['args'][1] );
	}

	/**
	 * Test that after_execute receives a successful result array.
	 *
	 * @return void
	 */
	public function test_after_execute_receives_successful_result(): void {
		$result = [
			'id'    => 123,
			'title' => 'Created',
		];

		$ability = new StubAbility( 'test/ability', $result );
		$ability->guarded_execute( [] );

		$hooks = $this->get_hooks( 'albert/abilities/after_execute' );
		$this->assertSame( $result, $hooks[0]['args'][2] );
	}

	/**
	 * Test that after_execute receives a WP_Error result.
	 *
	 * @return void
	 */
	public function test_after_execute_receives_wp_error_result(): void {
		$error   = new WP_Error( 'test_error', 'Something failed' );
		$ability = new StubAbility( 'test/ability', $error );
		$ability->guarded_execute( [] );

		$hooks = $this->get_hooks( 'albert/abilities/after_execute' );
		$this->assertInstanceOf( WP_Error::class, $hooks[0]['args'][2] );
		$this->assertSame( 'test_error', $hooks[0]['args'][2]->get_error_code() );
	}

	/**
	 * Test that after_execute receives the current user ID.
	 *
	 * @return void
	 */
	public function test_after_execute_receives_user_id(): void {
		$GLOBALS['albert_test_user_id'] = 55;

		$ability = new StubAbility();
		$ability->guarded_execute( [] );

		$hooks = $this->get_hooks( 'albert/abilities/after_execute' );
		$this->assertSame( 55, $hooks[0]['args'][3] );
	}

	// ─── albert/abilities/after_execute/{ability_id} (dynamic) ───────

	/**
	 * Test that the dynamic after_execute hook fires with ability ID in the hook name.
	 *
	 * @return void
	 */
	public function test_after_execute_dynamic_fires_with_ability_id_in_hook_name(): void {
		$ability = new StubAbility( 'core/posts/delete' );
		$ability->guarded_execute( [] );

		$this->assertCount(
			1,
			$this->get_hooks( 'albert/abilities/after_execute/core/posts/delete' )
		);
	}

	/**
	 * Test that the dynamic after_execute hook receives args, result, and user ID.
	 *
	 * @return void
	 */
	public function test_after_execute_dynamic_receives_args_result_and_user_id(): void {
		$GLOBALS['albert_test_user_id'] = 3;

		$input  = [ 'id' => 10 ];
		$result = [ 'deleted' => true ];

		$ability = new StubAbility( 'test/delete', $result );
		$ability->guarded_execute( $input );

		$hooks = $this->get_hooks( 'albert/abilities/after_execute/test/delete' );
		$this->assertSame( $input, $hooks[0]['args'][0] );
		$this->assertSame( $result, $hooks[0]['args'][1] );
		$this->assertSame( 3, $hooks[0]['args'][2] );
	}

	/**
	 * Test that the dynamic after_execute hook does not pass ability ID as a parameter.
	 *
	 * @return void
	 */
	public function test_after_execute_dynamic_omits_ability_id_from_params(): void {
		$ability = new StubAbility( 'core/pages/update' );
		$ability->guarded_execute( [] );

		$hooks = $this->get_hooks( 'albert/abilities/after_execute/core/pages/update' );
		$this->assertCount( 3, $hooks[0]['args'] );
	}

	// ─── Hook ordering ──────────────────────────────────────────────

	/**
	 * Test that before hooks always fire before after hooks.
	 *
	 * @return void
	 */
	public function test_before_hooks_fire_before_after_hooks(): void {
		$ability = new StubAbility( 'test/order' );
		$ability->guarded_execute( [] );

		$names     = array_column( $GLOBALS['albert_test_hooks'], 'hook' );
		$before_at = array_search( 'albert/abilities/before_execute', $names, true );
		$after_at  = array_search( 'albert/abilities/after_execute', $names, true );

		$this->assertIsInt( $before_at );
		$this->assertIsInt( $after_at );
		$this->assertLessThan( $after_at, $before_at );
	}

	/**
	 * Test that all four execution hooks fire in the correct order.
	 *
	 * @return void
	 */
	public function test_all_four_hooks_fire_in_correct_order(): void {
		$ability = new StubAbility( 'test/order' );
		$ability->guarded_execute( [] );

		$names = array_column( $GLOBALS['albert_test_hooks'], 'hook' );

		$execution_hooks = array_values(
			array_filter( $names, static fn( string $h ): bool => str_contains( $h, 'execute' ) )
		);

		$this->assertSame(
			[
				'albert/abilities/before_execute',
				'albert/abilities/before_execute/test/order',
				'albert/abilities/after_execute',
				'albert/abilities/after_execute/test/order',
			],
			$execution_hooks
		);
	}

	// ─── Disabled abilities ─────────────────────────────────────────

	/**
	 * Test that no execution hooks fire when the ability is disabled.
	 *
	 * @return void
	 */
	public function test_no_hooks_fire_when_ability_is_disabled(): void {
		$GLOBALS['albert_test_options']['albert_disabled_abilities'] = [ 'test/disabled' ];

		$ability = new StubAbility( 'test/disabled' );
		$result  = $ability->guarded_execute( [] );

		$this->assertInstanceOf( WP_Error::class, $result );

		$execution_hooks = array_filter(
			$GLOBALS['albert_test_hooks'],
			static fn( array $h ): bool => str_contains( $h['hook'], 'execute' )
		);
		$this->assertEmpty( $execution_hooks );
	}

	/**
	 * Test that a disabled ability returns the ability_disabled error code.
	 *
	 * @return void
	 */
	public function test_disabled_ability_returns_ability_disabled_error_code(): void {
		$GLOBALS['albert_test_options']['albert_disabled_abilities'] = [ 'test/blocked' ];

		$ability = new StubAbility( 'test/blocked' );
		$result  = $ability->guarded_execute( [] );

		$this->assertSame( 'ability_disabled', $result->get_error_code() );
	}

	// ─── Return value passthrough ───────────────────────────────────

	/**
	 * Test that guarded_execute returns the ability result unchanged.
	 *
	 * @return void
	 */
	public function test_guarded_execute_returns_ability_result(): void {
		$expected = [
			'id'     => 1,
			'status' => 'published',
		];

		$ability = new StubAbility( 'test/ability', $expected );

		$this->assertSame( $expected, $ability->guarded_execute( [] ) );
	}

	/**
	 * Test that guarded_execute passes through WP_Error from the ability.
	 *
	 * @return void
	 */
	public function test_guarded_execute_returns_wp_error_from_ability(): void {
		$error   = new WP_Error( 'not_found', 'Post not found' );
		$ability = new StubAbility( 'test/ability', $error );

		$result = $ability->guarded_execute( [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}
}
