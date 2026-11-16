<?php
/**
 * Unit tests for AbilitiesManager::reconcile_new_abilities().
 *
 * Covers the upgrade default-state reconciliation: every ability added after a
 * site has saved its toggles is disabled by default (the admin opts in), without
 * ever retroactively changing toggles the admin already set.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Core\AbilitiesManager;
use Albert\Admin\AbilitiesPage;
use PHPUnit\Framework\TestCase;

/**
 * Lightweight ability double exposing the surface get_default_disabled_abilities()
 * and reconcile_new_abilities() read: get_name() and get_meta().
 */
class ReconcileAbilityDouble {

	/**
	 * Ability id.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Whether the ability is read-only (read = enabled by default).
	 *
	 * @var bool
	 */
	private bool $readonly;

	/**
	 * Constructor.
	 *
	 * @param string $name        Ability id.
	 * @param bool   $is_readonly Whether the ability is read-only.
	 */
	public function __construct( string $name, bool $is_readonly ) {
		$this->name     = $name;
		$this->readonly = $is_readonly;
	}

	/**
	 * Ability id.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Ability meta with a readonly annotation.
	 *
	 * @return array<string, mixed>
	 */
	public function get_meta(): array {
		return [
			'annotations' => [
				'readonly' => $this->readonly,
			],
		];
	}
}

/**
 * AbilitiesManager reconciliation tests.
 *
 * @covers \Albert\Core\AbilitiesManager::reconcile_new_abilities
 */
class AbilitiesReconcileTest extends TestCase {

	/**
	 * Reset option, transient and abilities globals before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_options']    = [];
		$GLOBALS['albert_test_transients'] = [];
		$GLOBALS['albert_test_abilities']  = [];
		$GLOBALS['albert_test_hooks']      = [];
	}

	/**
	 * Register the given abilities with the wp_get_abilities() stub.
	 *
	 * @param array<int, ReconcileAbilityDouble> $abilities Ability doubles.
	 *
	 * @return void
	 */
	private function set_registered( array $abilities ): void {
		$GLOBALS['albert_test_abilities'] = $abilities;
	}

	/**
	 * A read ability double (enabled by default).
	 *
	 * @param string $name Ability id.
	 *
	 * @return ReconcileAbilityDouble
	 */
	private function read( string $name ): ReconcileAbilityDouble {
		return new ReconcileAbilityDouble( $name, true );
	}

	/**
	 * A write ability double (disabled by default).
	 *
	 * @param string $name Ability id.
	 *
	 * @return ReconcileAbilityDouble
	 */
	private function write( string $name ): ReconcileAbilityDouble {
		return new ReconcileAbilityDouble( $name, false );
	}

	// ─── Fresh install ──────────────────────────────────────────────

	/**
	 * On a fresh install (albert_abilities_saved unset) the reconcile is a no-op:
	 * neither the disabled list nor the known list is written.
	 *
	 * @return void
	 */
	public function test_fresh_install_is_a_noop(): void {
		$this->set_registered( [ $this->read( 'albert/find-posts' ), $this->write( 'albert/create-post' ) ] );

		( new AbilitiesManager() )->reconcile_new_abilities();

		$this->assertArrayNotHasKey( 'albert_known_abilities', $GLOBALS['albert_test_options'] );
		$this->assertArrayNotHasKey( AbilitiesPage::DISABLED_ABILITIES_OPTION, $GLOBALS['albert_test_options'] );
		$this->assertSame( [], $GLOBALS['albert_test_transients'] );
	}

	// ─── Baseline (already configured, known unset) ─────────────────

	/**
	 * An already-configured site seeing the feature for the first time records
	 * the current registered set as known and leaves the disabled list UNTOUCHED.
	 * This is the critical no-retroactive-disabling safety case.
	 *
	 * @return void
	 */
	public function test_baseline_records_known_without_disabling(): void {
		$GLOBALS['albert_test_options']['albert_abilities_saved'] = true;
		// Admin previously enabled everything (empty disabled list).
		$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ] = [];

		$this->set_registered( [ $this->read( 'albert/find-posts' ), $this->write( 'albert/create-post' ) ] );

		( new AbilitiesManager() )->reconcile_new_abilities();

		$this->assertSame(
			[ 'albert/find-posts', 'albert/create-post' ],
			$GLOBALS['albert_test_options']['albert_known_abilities']
		);
		// Disabled list unchanged — the write ability is NOT retroactively disabled.
		$this->assertSame( [], $GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ] );
		$this->assertSame( [], $GLOBALS['albert_test_transients'] );
	}

	// ─── New write ability appears ──────────────────────────────────

	/**
	 * A new write ability added by an upgrade is disabled by default: added to
	 * the disabled option, folded into known, and flagged in the transient.
	 *
	 * @return void
	 */
	public function test_new_write_ability_is_disabled(): void {
		$GLOBALS['albert_test_options']['albert_abilities_saved']                   = true;
		$GLOBALS['albert_test_options']['albert_known_abilities']                   = [ 'albert/find-posts', 'albert/create-post' ];
		$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ] = [ 'albert/create-post' ];

		$this->set_registered(
			[
				$this->read( 'albert/find-posts' ),
				$this->write( 'albert/create-post' ),
				$this->write( 'albert/move-page-block' ),
			]
		);

		( new AbilitiesManager() )->reconcile_new_abilities();

		$this->assertSame(
			[ 'albert/create-post', 'albert/move-page-block' ],
			$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ]
		);
		$this->assertSame(
			[ 'albert/move-page-block' ],
			$GLOBALS['albert_test_transients']['albert_new_abilities_disabled']
		);
		$this->assertContains( 'albert/move-page-block', $GLOBALS['albert_test_options']['albert_known_abilities'] );
	}

	// ─── New read ability appears ───────────────────────────────────

	/**
	 * A new READ ability is also disabled by default on an already-configured
	 * site: expanding what the AI can reach (even a read, which may expose a new
	 * category of data) is the admin's explicit choice.
	 *
	 * @return void
	 */
	public function test_new_read_ability_is_also_disabled(): void {
		$GLOBALS['albert_test_options']['albert_abilities_saved']                   = true;
		$GLOBALS['albert_test_options']['albert_known_abilities']                   = [ 'albert/find-posts' ];
		$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ] = [];

		$this->set_registered(
			[
				$this->read( 'albert/find-posts' ),
				$this->read( 'albert/find-customers' ),
			]
		);

		( new AbilitiesManager() )->reconcile_new_abilities();

		$this->assertSame( [ 'albert/find-customers' ], $GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ] );
		$this->assertSame( [ 'albert/find-customers' ], $GLOBALS['albert_test_transients']['albert_new_abilities_disabled'] );
		$this->assertContains( 'albert/find-customers', $GLOBALS['albert_test_options']['albert_known_abilities'] );
	}

	// ─── Steady state ───────────────────────────────────────────────

	/**
	 * When the registered set equals the known set, no option or transient
	 * writes occur — the steady-state path is idempotent.
	 *
	 * @return void
	 */
	public function test_steady_state_does_not_write_options(): void {
		$GLOBALS['albert_test_options']['albert_abilities_saved']                   = true;
		$GLOBALS['albert_test_options']['albert_known_abilities']                   = [ 'albert/find-posts', 'albert/create-post' ];
		$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ] = [ 'albert/create-post' ];

		$this->set_registered( [ $this->read( 'albert/find-posts' ), $this->write( 'albert/create-post' ) ] );

		$before = $GLOBALS['albert_test_options'];

		( new AbilitiesManager() )->reconcile_new_abilities();

		$this->assertSame( $before, $GLOBALS['albert_test_options'] );
		$this->assertSame( [], $GLOBALS['albert_test_transients'] );
	}

	// ─── Transport meta-tools are never auto-disabled ───────────────

	/**
	 * The MCP transport meta-tools must never be auto-disabled, even when they are
	 * newly seen. A genuinely new ability alongside them is still disabled by
	 * default, but none of the `mcp-adapter/*` meta-tools ever land in the disabled
	 * option (nor in the transient).
	 *
	 * @return void
	 */
	public function test_transport_meta_tools_are_never_auto_disabled(): void {
		$GLOBALS['albert_test_options']['albert_abilities_saved']                   = true;
		$GLOBALS['albert_test_options']['albert_known_abilities']                   = [ 'albert/find-posts' ];
		$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ] = [];

		$this->set_registered(
			[
				$this->read( 'albert/find-posts' ),
				$this->write( 'mcp-adapter/discover-abilities' ),
				$this->write( 'mcp-adapter/get-ability-info' ),
				$this->write( 'mcp-adapter/execute-ability' ),
				$this->write( 'albert/create-post' ),
			]
		);

		( new AbilitiesManager() )->reconcile_new_abilities();

		$disabled = $GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ];

		// The genuinely new write is disabled by default…
		$this->assertContains( 'albert/create-post', $disabled );
		// …but none of the transport meta-tools ever are.
		$this->assertNotContains( 'mcp-adapter/discover-abilities', $disabled );
		$this->assertNotContains( 'mcp-adapter/get-ability-info', $disabled );
		$this->assertNotContains( 'mcp-adapter/execute-ability', $disabled );

		$this->assertSame( [ 'albert/create-post' ], $GLOBALS['albert_test_transients']['albert_new_abilities_disabled'] );
	}

	// ─── Admin notice ───────────────────────────────────────────────

	/**
	 * An admin sees the notice once and the transient is cleared after render.
	 *
	 * @return void
	 */
	public function test_notice_renders_for_admin_and_clears_transient(): void {
		// Caps unset → the stub treats the user as capable (admin).
		$GLOBALS['albert_test_transients']['albert_new_abilities_disabled'] = [ 'albert/move-page-block' ];

		ob_start();
		( new AbilitiesManager() )->render_new_abilities_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-info', $html );
		$this->assertStringContainsString( 'switched it off for assistants', $html );
		// Shown only once: the transient is consumed.
		$this->assertArrayNotHasKey( 'albert_new_abilities_disabled', $GLOBALS['albert_test_transients'] );
	}

	/**
	 * A non-admin sees nothing and the transient is preserved for an admin.
	 *
	 * @return void
	 */
	public function test_notice_hidden_from_non_admins(): void {
		$GLOBALS['albert_test_caps']                                        = []; // No capabilities.
		$GLOBALS['albert_test_transients']['albert_new_abilities_disabled'] = [ 'albert/move-page-block' ];

		ob_start();
		( new AbilitiesManager() )->render_new_abilities_notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
		// Not consumed — an admin can still see it later.
		$this->assertArrayHasKey( 'albert_new_abilities_disabled', $GLOBALS['albert_test_transients'] );
	}
}
