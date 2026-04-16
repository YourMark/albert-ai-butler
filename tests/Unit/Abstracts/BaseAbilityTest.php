<?php
/**
 * Unit tests for the BaseAbility contract.
 *
 * Covers the public contract that every built-in and add-on ability relies on:
 * enabled()/require_capability()/register_ability()/get_* accessors.
 * Hook firing is covered separately in HooksTest.php.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Abstracts;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';
require_once dirname( __DIR__ ) . '/stubs/StubAbility.php';

use Albert\Tests\Unit\StubAbility;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * BaseAbility contract tests.
 *
 * @covers \Albert\Abstracts\BaseAbility
 */
class BaseAbilityTest extends TestCase {

	/**
	 * Reset globals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_hooks']                = [];
		$GLOBALS['albert_test_user_id']              = 1;
		$GLOBALS['albert_test_options']              = [
			'albert_abilities_saved'    => true,
			'albert_disabled_abilities' => [],
		];
		$GLOBALS['albert_test_registered_abilities'] = [];
		unset( $GLOBALS['albert_test_caps'], $GLOBALS['albert_test_abilities'] );
	}

	// ─── enabled() ───────────────────────────────────────────────────

	/**
	 * A fresh ability not in the disabled list reports enabled.
	 *
	 * @return void
	 */
	public function test_enabled_returns_true_when_not_in_disabled_list(): void {
		$ability = new StubAbility( 'albert/test-one' );

		$this->assertTrue( $ability->enabled() );
	}

	/**
	 * An ability whose id appears in the disabled option reports disabled.
	 *
	 * @return void
	 */
	public function test_enabled_returns_false_when_in_disabled_list(): void {
		$GLOBALS['albert_test_options']['albert_disabled_abilities'] = [ 'albert/test-two' ];

		$ability = new StubAbility( 'albert/test-two' );

		$this->assertFalse( $ability->enabled() );
	}

	/**
	 * On a fresh install (no disabled option, no saved flag) the registry
	 * default-disabled list is consulted. An empty default keeps abilities on.
	 *
	 * @return void
	 */
	public function test_enabled_falls_back_to_registry_defaults_on_fresh_install(): void {
		$GLOBALS['albert_test_options']   = [];
		$GLOBALS['albert_test_abilities'] = [];

		$ability = new StubAbility( 'albert/fresh-one' );

		$this->assertTrue( $ability->enabled() );
	}

	/**
	 * A fresh install where the registry heuristic flags this id as
	 * default-disabled (write prefix) returns false.
	 *
	 * @return void
	 */
	public function test_enabled_returns_false_when_id_is_default_disabled(): void {
		$GLOBALS['albert_test_options']   = [];
		$GLOBALS['albert_test_abilities'] = [
			new AbilityDouble( 'albert/delete-post', [] ),
		];

		$ability = new StubAbility( 'albert/delete-post' );

		$this->assertFalse( $ability->enabled() );
	}

	// ─── require_capability() ────────────────────────────────────────

	/**
	 * Returns true when the user has the capability.
	 *
	 * Uses DefaultPermissionAbility so BaseAbility's own check_permission
	 * (which calls require_capability) is exercised — StubAbility overrides
	 * check_permission to always return true and would skip the contract.
	 *
	 * @return void
	 */
	public function test_require_capability_returns_true_when_user_has_cap(): void {
		$GLOBALS['albert_test_caps'] = [ 'manage_options' ];

		$ability = new DefaultPermissionAbility( 'albert/cap-pass' );

		$this->assertTrue( $ability->check_permission() );
	}

	/**
	 * Returns WP_Error with code ability_permission_denied on missing cap.
	 *
	 * @return void
	 */
	public function test_require_capability_returns_wp_error_when_user_lacks_cap(): void {
		$GLOBALS['albert_test_caps'] = [ 'read' ]; // manage_options missing.

		$ability = new DefaultPermissionAbility( 'albert/cap-fail' );
		$result  = $ability->check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_permission_denied', $result->get_error_code() );
	}

	/**
	 * The returned WP_Error carries a 403 status in its data bag.
	 *
	 * @return void
	 */
	public function test_require_capability_error_has_403_status(): void {
		$GLOBALS['albert_test_caps'] = [];

		$ability = new DefaultPermissionAbility( 'albert/cap-status' );
		$result  = $ability->check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 403, $data['status'] ?? null );
	}

	/**
	 * The permission-denied message mentions both ability label and capability.
	 *
	 * @return void
	 */
	public function test_require_capability_message_mentions_label_and_capability(): void {
		$GLOBALS['albert_test_caps'] = [];

		$ability = new DefaultPermissionAbility( 'albert/cap-message' );
		$result  = $ability->check_permission();

		$message = $result->get_error_message();
		$this->assertStringContainsString( 'Default-Permission Ability', $message );
		$this->assertStringContainsString( 'manage_options', $message );
	}

	// ─── register_ability() ─────────────────────────────────────────

	/**
	 * Forwards a correctly-shaped args array to wp_register_ability.
	 *
	 * @return void
	 */
	public function test_register_ability_calls_wp_register_ability_with_expected_shape(): void {
		$ability = new StubAbility( 'albert/register-me' );
		$ability->register_ability();

		$this->assertArrayHasKey( 'albert/register-me', $GLOBALS['albert_test_registered_abilities'] );

		$args = $GLOBALS['albert_test_registered_abilities']['albert/register-me'];

		$this->assertArrayHasKey( 'label', $args );
		$this->assertArrayHasKey( 'description', $args );
		$this->assertArrayHasKey( 'input_schema', $args );
		$this->assertArrayHasKey( 'output_schema', $args );
		$this->assertArrayHasKey( 'category', $args );
		$this->assertArrayHasKey( 'execute_callback', $args );
		$this->assertArrayHasKey( 'permission_callback', $args );
		$this->assertArrayHasKey( 'meta', $args );
	}

	/**
	 * The execute_callback routes through guarded_execute (not execute directly),
	 * so the disabled check and hooks always run.
	 *
	 * @return void
	 */
	public function test_register_ability_uses_guarded_execute_as_callback(): void {
		$ability = new StubAbility( 'albert/callback-check' );
		$ability->register_ability();

		$args     = $GLOBALS['albert_test_registered_abilities']['albert/callback-check'];
		$callback = $args['execute_callback'];

		$this->assertIsArray( $callback );
		$this->assertSame( $ability, $callback[0] );
		$this->assertSame( 'guarded_execute', $callback[1] );
	}

	/**
	 * Uses the ability's configured category verbatim.
	 *
	 * @return void
	 */
	public function test_register_ability_passes_category(): void {
		$ability = new StubAbility( 'albert/cat-check' );
		$ability->register_ability();

		$args = $GLOBALS['albert_test_registered_abilities']['albert/cat-check'];
		$this->assertSame( 'test', $args['category'] );
	}

	// ─── Accessors ──────────────────────────────────────────────────

	/**
	 * Accessors expose the ability metadata set by the constructor.
	 *
	 * @return void
	 */
	public function test_accessors_expose_configured_metadata(): void {
		$ability = new StubAbility( 'albert/accessor-check' );

		$this->assertSame( 'albert/accessor-check', $ability->get_id() );
		$this->assertSame( 'Test Ability', $ability->get_label() );
		$this->assertSame( 'A stub ability for testing hooks.', $ability->get_description() );
		$this->assertSame( 'test', $ability->get_category() );
		$this->assertSame( '', $ability->get_group() );
	}

	/**
	 * Returns the label/description/group trio the admin UI needs.
	 *
	 * @return void
	 */
	public function test_get_settings_data_returns_label_description_group(): void {
		$ability = new StubAbility( 'albert/settings-check' );

		$data = $ability->get_settings_data();

		$this->assertSame(
			[
				'label'       => 'Test Ability',
				'description' => 'A stub ability for testing hooks.',
				'group'       => '',
			],
			$data
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.ClassComment.Missing

/**
 * Ability that does NOT override check_permission().
 *
 * Exists purely to exercise BaseAbility's default check_permission →
 * require_capability('manage_options') path, which StubAbility bypasses.
 */
final class DefaultPermissionAbility extends \Albert\Abstracts\BaseAbility {

	/**
	 * Constructor.
	 *
	 * @param string $id Ability id.
	 */
	public function __construct( string $id = 'albert/default-perm' ) {
		$this->id          = $id;
		$this->label       = 'Default-Permission Ability';
		$this->description = 'Exercises BaseAbility default check_permission.';
		$this->category    = 'test';

		parent::__construct();
	}

	/**
	 * Execute — unused by these tests, returns an empty success payload.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $args ): array|\WP_Error {
		return [ 'ok' => true ];
	}
}

/**
 * Minimal WP 6.9 ability double for wp_get_abilities().
 *
 * Only the two methods the registry reads are implemented.
 */
final class AbilityDouble {

	/**
	 * Ability id.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Meta bag.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta;

	/**
	 * Constructor.
	 *
	 * @param string               $name Ability id.
	 * @param array<string, mixed> $meta Meta bag.
	 */
	public function __construct( string $name, array $meta ) {
		$this->name = $name;
		$this->meta = $meta;
	}

	/**
	 * Return the ability id.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Return the meta bag.
	 *
	 * @return array<string, mixed>
	 */
	public function get_meta(): array {
		return $this->meta;
	}
}
