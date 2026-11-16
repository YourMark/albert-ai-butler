<?php
/**
 * Unit tests for AbilitiesManager::enforce_disabled().
 *
 * Switching an ability off never unregisters it; only an add-on's refusal
 * through `albert/abilities/is_executable` does.
 *
 * @package Albert
 */

namespace Albert\Tests\Unit\Core;

require_once dirname( __DIR__ ) . '/stubs/wordpress.php';

use Albert\Admin\AbilitiesPage;
use Albert\Core\AbilitiesManager;
use Albert\Tests\Unit\StubAbility;
use WP_Error;
use PHPUnit\Framework\TestCase;

/**
 * AbilitiesManager enforcement tests.
 *
 * @covers \Albert\Core\AbilitiesManager::enforce_disabled
 */
class AbilitiesEnforceTest extends TestCase {

	/**
	 * Reset the option/ability/hook globals and the management-context request
	 * state before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['albert_test_options']                = [];
		$GLOBALS['albert_test_abilities']              = [];
		$GLOBALS['albert_test_hooks']                  = [];
		$GLOBALS['albert_test_unregistered_abilities'] = [];
		$GLOBALS['albert_test_is_admin']               = false;
		$GLOBALS['albert_test_filter_returns']         = [];
		$GLOBALS['albert_test_filter_callbacks']       = [];
		unset( $_GET['page'] );
	}

	/**
	 * A minimal ability double exposing get_name(), which is all
	 * enforce_disabled() reads for non-Albert-managed abilities.
	 *
	 * @param string $name Ability id.
	 *
	 * @return object
	 */
	private function ability( string $name ): object {
		return new class( $name ) {
			/**
			 * Test double used by the case below.
			 *
			 * @param string $name Ability id.
			 */
			public function __construct( private string $name ) {}

			/**
			 * Test double used by the case below.
			 *
			 * @return string
			 */
			public function get_name(): string {
				return $this->name;
			}
		};
	}

	/**
	 * A switched-off ability, Albert's or another plugin's, stays registered,
	 * so other plugins relying on it keep working.
	 *
	 * @return void
	 */
	public function test_switched_off_abilities_stay_registered(): void {
		$GLOBALS['albert_test_options']['albert_abilities_saved']                   = true;
		$GLOBALS['albert_test_options'][ AbilitiesPage::DISABLED_ABILITIES_OPTION ] = [
			'albert/create-post',
			'other/do-thing',
		];

		$GLOBALS['albert_test_abilities'] = [
			$this->ability( 'albert/create-post' ),
			$this->ability( 'other/do-thing' ),
		];

		$manager = new AbilitiesManager();
		$manager->add_ability( new StubAbility( 'albert/create-post' ) );
		$manager->enforce_disabled();

		$this->assertSame( [], $GLOBALS['albert_test_unregistered_abilities'] );
	}

	/**
	 * An Albert ability an add-on refuses (a lapsed licence) is unregistered;
	 * the others are left alone.
	 *
	 * @return void
	 */
	public function test_unregisters_abilities_an_add_on_refuses(): void {
		$GLOBALS['albert_test_options']['albert_abilities_saved'] = true;

		$GLOBALS['albert_test_abilities'] = [
			$this->ability( 'albert/licensed' ),
			$this->ability( 'albert/free' ),
		];

		$GLOBALS['albert_test_filter_callbacks']['albert/abilities/is_executable'] = static fn( $result, $ability ) => $ability->get_id() === 'albert/licensed'
			? new WP_Error( 'license_expired', 'Plan expired.' )
			: $result;

		$manager = new AbilitiesManager();
		$manager->add_ability( new StubAbility( 'albert/licensed' ) );
		$manager->add_ability( new StubAbility( 'albert/free' ) );
		$manager->enforce_disabled();

		$this->assertSame( [ 'albert/licensed' ], $GLOBALS['albert_test_unregistered_abilities'] );
	}
}
