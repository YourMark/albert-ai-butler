<?php
/**
 * Integration tests for AbilitiesManager::enforce_disabled().
 *
 * Boots a real WP environment so wp_register_ability / wp_unregister_ability /
 * wp_get_abilities exercise the actual core registry rather than a stub.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration;

use Albert\Abstracts\BaseAbility;
use Albert\Admin\AbilitiesPage;
use Albert\Core\AbilitiesManager;
use Albert\Tests\TestCase;

/**
 * Test enforce_disabled() against the real WP abilities registry.
 *
 * @phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
 */
class EnforceDisabledTest extends TestCase {

	/**
	 * Manager under test.
	 *
	 * @var AbilitiesManager
	 */
	private AbilitiesManager $manager;

	/**
	 * IDs registered during a test, unregistered in tear_down.
	 *
	 * @var array<int, string>
	 */
	private array $registered_ids = [];

	/**
	 * Create a fresh manager and ensure the registry singleton has fired its init action.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->manager        = new AbilitiesManager();
		$this->registered_ids = [];

		// Trigger the registry singleton if the test runner has not done so yet.
		if ( function_exists( 'wp_get_abilities' ) ) {
			wp_get_abilities();
		}

		// Reset request state.
		unset( $_GET['page'] );
		delete_option( AbilitiesPage::DISABLED_ABILITIES_OPTION );
		update_option( 'albert_abilities_saved', true );
	}

	/**
	 * Remove every ability we registered so subsequent tests start clean.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->registered_ids as $id ) {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $id ) ) {
				wp_unregister_ability( $id );
			}
		}

		remove_all_filters( 'albert/abilities/is_executable' );
		remove_all_filters( 'albert/abilities/is_management_context' );
		remove_all_filters( 'albert/abilities/disabled_list' );

		unset( $_GET['page'] );

		parent::tear_down();
	}

	/**
	 * Register an Albert-managed ability with the manager and the WP registry.
	 *
	 * @param string $id Ability ID.
	 *
	 * @return EnforceDisabledStubAbility
	 */
	private function register_albert_ability( string $id ): EnforceDisabledStubAbility {
		$ability = new EnforceDisabledStubAbility( $id );
		$this->manager->add_ability( $ability );

		add_action(
			'wp_abilities_api_init',
			static function () use ( $ability ): void {
				$ability->register_ability();
			}
		);
		do_action( 'wp_abilities_api_init' );

		$this->registered_ids[] = $id;
		return $ability;
	}

	/**
	 * Register a third-party ability directly with the WP registry.
	 *
	 * @param string $id Ability ID.
	 *
	 * @return void
	 */
	private function register_third_party_ability( string $id ): void {
		add_action(
			'wp_abilities_api_init',
			static function () use ( $id ): void {
				wp_register_ability(
					$id,
					[
						'label'               => 'Third Party',
						'description'         => 'Registered directly, not via Albert.',
						'category'            => 'site',
						'execute_callback'    => static fn(): array => [ 'ok' => true ],
						'permission_callback' => '__return_true',
					]
				);
			}
		);
		do_action( 'wp_abilities_api_init' );

		$this->registered_ids[] = $id;
	}

	// ─── Albert-managed abilities ───────────────────────────────────

	/**
	 * Disabled Albert-managed abilities are unregistered.
	 *
	 * @return void
	 */
	public function test_disabled_albert_ability_is_unregistered(): void {
		$this->register_albert_ability( 'albert/test-disabled-target' );

		update_option(
			AbilitiesPage::DISABLED_ABILITIES_OPTION,
			[ 'albert/test-disabled-target' ]
		);

		$this->manager->enforce_disabled();

		$this->assertFalse( wp_has_ability( 'albert/test-disabled-target' ) );
	}

	/**
	 * Enabled Albert-managed abilities are left registered.
	 *
	 * @return void
	 */
	public function test_enabled_albert_ability_is_kept_registered(): void {
		$this->register_albert_ability( 'albert/test-keep-target' );

		$this->manager->enforce_disabled();

		$this->assertTrue( wp_has_ability( 'albert/test-keep-target' ) );
	}

	// ─── Third-party abilities ──────────────────────────────────────

	/**
	 * A third-party ability listed in the disabled option is unregistered.
	 *
	 * @return void
	 */
	public function test_third_party_ability_is_unregistered_when_disabled(): void {
		$this->register_third_party_ability( 'thirdparty/test-disabled' );

		update_option(
			AbilitiesPage::DISABLED_ABILITIES_OPTION,
			[ 'thirdparty/test-disabled' ]
		);

		$this->manager->enforce_disabled();

		$this->assertFalse( wp_has_ability( 'thirdparty/test-disabled' ) );
	}

	/**
	 * A third-party ability not listed in the disabled option survives.
	 *
	 * @return void
	 */
	public function test_third_party_ability_is_kept_when_not_disabled(): void {
		$this->register_third_party_ability( 'thirdparty/test-keep' );

		$this->manager->enforce_disabled();

		$this->assertTrue( wp_has_ability( 'thirdparty/test-keep' ) );
	}

	// ─── Management-context skip ────────────────────────────────────

	/**
	 * On the abilities management page, no abilities are unregistered.
	 *
	 * @return void
	 */
	public function test_no_unregistration_on_abilities_management_page(): void {
		$this->register_albert_ability( 'albert/test-mgmt-target' );
		update_option(
			AbilitiesPage::DISABLED_ABILITIES_OPTION,
			[ 'albert/test-mgmt-target' ]
		);

		set_current_screen( 'admin.php' );
		$_GET['page'] = AbilitiesPage::PAGE_SLUG;

		$this->manager->enforce_disabled();

		$this->assertTrue( wp_has_ability( 'albert/test-mgmt-target' ) );
	}

	/**
	 * The management-context filter forces "show all" semantics for any request.
	 *
	 * @return void
	 */
	public function test_management_context_filter_overrides_detection(): void {
		$this->register_albert_ability( 'albert/test-filter-target' );
		update_option(
			AbilitiesPage::DISABLED_ABILITIES_OPTION,
			[ 'albert/test-filter-target' ]
		);

		add_filter( 'albert/abilities/is_management_context', '__return_true' );

		$this->manager->enforce_disabled();

		$this->assertTrue( wp_has_ability( 'albert/test-filter-target' ) );
	}

	// ─── disabled_list filter ───────────────────────────────────────

	/**
	 * The disabled_list filter unregisters Albert-managed abilities at runtime.
	 *
	 * @return void
	 */
	public function test_disabled_list_filter_unregisters_albert_ability(): void {
		$this->register_albert_ability( 'albert/test-runtime-albert' );

		add_filter(
			'albert/abilities/disabled_list',
			static function ( array $disabled ): array {
				$disabled[] = 'albert/test-runtime-albert';
				return $disabled;
			}
		);

		$this->manager->enforce_disabled();

		$this->assertFalse( wp_has_ability( 'albert/test-runtime-albert' ) );
	}

	/**
	 * The disabled_list filter unregisters third-party abilities at runtime.
	 *
	 * @return void
	 */
	public function test_disabled_list_filter_unregisters_third_party_ability(): void {
		$this->register_third_party_ability( 'thirdparty/test-runtime' );

		add_filter(
			'albert/abilities/disabled_list',
			static function ( array $disabled ): array {
				$disabled[] = 'thirdparty/test-runtime';
				return $disabled;
			}
		);

		$this->manager->enforce_disabled();

		$this->assertFalse( wp_has_ability( 'thirdparty/test-runtime' ) );
	}

	// ─── is_executable filter ───────────────────────────────────────

	/**
	 * An Albert-managed ability whose is_executable filter returns WP_Error
	 * is unregistered (e.g. licence-blocked by the Premium add-on).
	 *
	 * @return void
	 */
	public function test_is_executable_wp_error_unregisters_ability(): void {
		$this->register_albert_ability( 'albert/test-license-target' );

		add_filter(
			'albert/abilities/is_executable',
			static function ( $result, BaseAbility $ability ) {
				if ( $ability->get_id() === 'albert/test-license-target' ) {
					return new \WP_Error( 'license_expired', 'Plan expired.' );
				}
				return $result;
			},
			10,
			2
		);

		$this->manager->enforce_disabled();

		$this->assertFalse( wp_has_ability( 'albert/test-license-target' ) );
	}
}

/**
 * Concrete BaseAbility used by enforce_disabled() integration tests.
 *
 * @phpcs:disable Squiz.Commenting.ClassComment.Missing
 */
final class EnforceDisabledStubAbility extends BaseAbility {

	/**
	 * Constructor.
	 *
	 * @param string $id Ability ID.
	 */
	public function __construct( string $id ) {
		$this->id          = $id;
		$this->label       = 'Enforce Disabled Stub';
		$this->description = 'Test ability used by EnforceDisabledTest.';
		$this->category    = 'site';
		$this->meta        = [ 'mcp' => [ 'public' => true ] ];

		parent::__construct();
	}

	/**
	 * Permission callback — open for tests.
	 *
	 * @return bool
	 */
	public function check_permission(): bool|\WP_Error {
		return true;
	}

	/**
	 * Execute callback — returns a fixed payload.
	 *
	 * @param array<string, mixed> $args Input parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function execute( array $args ): array|\WP_Error {
		return [ 'ok' => true ];
	}
}
