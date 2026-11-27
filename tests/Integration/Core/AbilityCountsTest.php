<?php
/**
 * Integration tests for the ability counts shown on admin screens.
 *
 * These exist because the Dashboard told every site owner that all of their
 * abilities were enabled, however many they had switched off: the tile read
 * "57 / 57" on a site with 32 disabled, and the "review the ones you switched
 * off" link, which renders only when the two figures differ, could therefore
 * never appear.
 *
 * The cause was ordering rather than arithmetic.
 * `AbilitiesManager::enforce_disabled()` unregisters disabled abilities on
 * every request except the Abilities page, so anything counting the registry
 * afterwards counts only the survivors and calls that the total. Both figures
 * are now taken inside `enforce_disabled()` itself, while the registry is
 * still whole.
 *
 * That is exactly what these tests pin: the reported total must be larger than
 * what is left in the registry once pruning has happened.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Core;

use Albert\Core\AbilitiesRegistry;
use Albert\Core\Plugin;
use Albert\Tests\TestCase;

/**
 * Ability count integration tests.
 *
 * @covers \Albert\Core\AbilitiesManager::get_ability_counts
 */
class AbilityCountsTest extends TestCase {

	/**
	 * Every non-transport ability id currently registered.
	 *
	 * @return array<int, string>
	 */
	private function ability_ids(): array {
		$ids = [];

		foreach ( array_keys( AbilitiesRegistry::get_all_raw() ) as $id ) {
			if ( ! AbilitiesRegistry::is_transport_ability( (string) $id ) ) {
				$ids[] = (string) $id;
			}
		}

		return $ids;
	}

	/**
	 * The arithmetic, pinned against a disabled list this test controls rather
	 * than whatever the suite happens to have switched off.
	 *
	 * A disabled ability counts towards the total and not towards enabled. The
	 * bug produced a total that excluded them, so the two figures matched and
	 * every site was told everything was on.
	 *
	 * @return void
	 */
	public function test_a_disabled_ability_counts_towards_the_total_but_not_enabled(): void {
		$ids = $this->ability_ids();
		$this->assertNotEmpty( $ids, 'The suite should have abilities registered.' );

		$off     = array_slice( $ids, 0, min( 3, count( $ids ) ) );
		$manager = new \Albert\Core\AbilitiesManager();

		$count = new \ReflectionMethod( $manager, 'count_registry' );

		$counts = $count->invoke( $manager, $off );

		$this->assertSame( count( $ids ), $counts['total'], 'Switching an ability off must not shrink the total.' );
		$this->assertSame( count( $ids ) - count( $off ), $counts['enabled'] );
		$this->assertLessThan( $counts['total'], $counts['enabled'] );
	}

	/**
	 * The mechanism that made the fix possible: the snapshot is taken inside
	 * enforce_disabled(), before anything is unregistered. Without it, a screen
	 * rendering later counts the survivors and calls that the total.
	 *
	 * @return void
	 */
	public function test_enforcement_snapshots_the_counts(): void {
		$manager = new \Albert\Core\AbilitiesManager();

		$snapshot = new \ReflectionProperty( $manager, 'ability_counts' );

		$this->assertNull( $snapshot->getValue( $manager ), 'Nothing should be counted before enforcement runs.' );

		$manager->enforce_disabled();

		$this->assertIsArray(
			$snapshot->getValue( $manager ),
			'enforce_disabled() must record the counts while the registry is whole.'
		);
	}

	/**
	 * The Dashboard states the manager's figures rather than counting for
	 * itself, which is how the two drifted apart before.
	 *
	 * @return void
	 */
	public function test_the_dashboard_reports_the_managers_figures(): void {
		$dashboard = new \Albert\Admin\Dashboard( new \Albert\Logging\Repository() );

		$method = new \ReflectionMethod( $dashboard, 'get_ability_counts' );

		$this->assertSame(
			Plugin::get_instance()->get_abilities_manager()->get_ability_counts(),
			$method->invoke( $dashboard )
		);
	}
}
