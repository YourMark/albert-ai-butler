<?php
/**
 * Integration tests for the overlap with WordPress 7.2's core abilities.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Core;

use Albert\Abilities\WordPress\Users\ViewUser;
use Albert\Admin\AbilitiesPage;
use Albert\Core\AbilitiesManager;
use Albert\Core\AbilitiesRegistry;
use Albert\Core\CoreAbilityOverlap;
use Albert\Tests\TestCase;

/**
 * One tool per job: core replaces `view-user`, `content-query` starts off, and both say which to use.
 *
 * Core's abilities are unhooked in WordPress's test suite, so stand-ins with
 * their ids are registered: what is tested is how Albert treats those ids.
 *
 * @covers \Albert\Core\CoreAbilityOverlap
 * @covers \Albert\Core\AbilitiesManager::reconcile_new_abilities
 * @covers \Albert\Core\AbilitiesRegistry::get_default_disabled_abilities
 */
class CoreAbilityOverlapTest extends TestCase {

	/**
	 * The core ids this test registers.
	 *
	 * @var list<string>
	 */
	private const CORE_IDS = [ 'core/users-query', 'core/content-query' ];

	/**
	 * Stand-ins registered here, to remove afterwards.
	 *
	 * @var list<string>
	 */
	private array $registered = [];

	/**
	 * Register the stand-ins.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		$this->during(
			'wp_abilities_api_init',
			function (): void {
				foreach ( self::CORE_IDS as $id ) {
					if ( wp_has_ability( $id ) ) {
						continue;
					}
					wp_register_ability(
						$id,
						[
							'label'               => $id,
							'description'         => 'Core stand-in.',
							'category'            => 'site',
							'execute_callback'    => '__return_empty_array',
							'permission_callback' => '__return_true',
							'meta'                => [
								'annotations' => [
									'readonly'    => true,
									'destructive' => false,
								],
								'mcp'         => [ 'public' => true ],
							],
						]
					);
					$this->registered[] = $id;
				}
			}
		);
	}

	/**
	 * Remove the stand-ins.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->registered as $id ) {
			wp_unregister_ability( $id );
		}

		parent::tear_down();
	}

	/**
	 * Where core has the user query, it replaces `view-user`; where it does not, nothing changes.
	 *
	 * @return void
	 */
	public function test_core_replaces_view_user_when_it_is_there(): void {
		if ( is_multisite() ) {
			$this->assertFalse( CoreAbilityOverlap::is_superseded( 'albert/view-user' ), 'On a network view-user stays.' );
			return;
		}

		$this->assertSame( 'core/users-query', CoreAbilityOverlap::replacement( 'albert/view-user' ) );
		$this->assertFalse( CoreAbilityOverlap::is_superseded( 'albert/find-users' ) );

		wp_unregister_ability( 'core/users-query' );
		$this->assertNull( CoreAbilityOverlap::replacement( 'albert/view-user' ) );
		$this->registered = array_values( array_diff( $this->registered, [ 'core/users-query' ] ) );
	}

	/**
	 * Albert does not register view-user next to core's, and does again once core's is gone.
	 *
	 * @return void
	 */
	public function test_view_user_is_not_registered_next_to_core(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'On a network view-user stays.' );
		}

		if ( wp_has_ability( 'albert/view-user' ) ) {
			wp_unregister_ability( 'albert/view-user' );
		}

		$manager = new AbilitiesManager();
		$manager->add_ability( new ViewUser() );

		$this->during( 'wp_abilities_api_init', [ $manager, 'register_abilities' ] );
		$this->assertFalse( wp_has_ability( 'albert/view-user' ) );

		wp_unregister_ability( 'core/users-query' );
		$this->registered = array_values( array_diff( $this->registered, [ 'core/users-query' ] ) );

		$this->during( 'wp_abilities_api_init', [ $manager, 'register_abilities' ] );
		$this->assertTrue( wp_has_ability( 'albert/view-user' ) );
	}

	/**
	 * Both core abilities say which of Albert's to use, on the Abilities screen and to the assistant.
	 *
	 * @return void
	 */
	public function test_core_descriptions_point_to_alberts(): void {
		$this->assertStringContainsString( 'albert/find-users', wp_get_ability( 'core/users-query' )->get_description() );
		$this->assertStringContainsString( 'albert/find-posts', wp_get_ability( 'core/content-query' )->get_description() );
	}

	/**
	 * On a fresh install the content query starts off for connections; the user query starts on.
	 *
	 * @return void
	 */
	public function test_content_query_is_off_on_a_fresh_install(): void {
		$defaults = AbilitiesRegistry::get_default_disabled_abilities();

		$this->assertContains( 'core/content-query', $defaults );
		$this->assertNotContains( 'core/users-query', $defaults );
	}

	/**
	 * The user query takes over view-user's switch, whichever was updated first.
	 *
	 * @dataProvider viewUserStates
	 *
	 * @param bool $view_user_on Whether view-user was on before the update.
	 * @param bool $core_known   Whether WordPress 7.2 came first, so the user query is already known and off.
	 *
	 * @return void
	 */
	public function test_the_user_query_takes_over_view_users_switch( bool $view_user_on, bool $core_known ): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'On a network view-user stays, so nothing is carried over.' );
		}

		$registered = array_map( static fn( $ability ) => $ability->get_name(), AbilitiesRegistry::get_all_raw() );
		$known      = array_values( array_diff( $registered, self::CORE_IDS ) );
		$known[]    = 'albert/view-user';
		$disabled   = $view_user_on ? [] : [ 'albert/view-user' ];
		if ( $core_known ) {
			$known[]    = 'core/users-query';
			$disabled[] = 'core/users-query';
		}

		update_option( 'albert_abilities_saved', 1 );
		update_option( 'albert_known_abilities', array_values( array_unique( $known ) ) );
		update_option( AbilitiesPage::DISABLED_ABILITIES_OPTION, $disabled );
		delete_option( 'albert_superseded_carried' );
		delete_transient( 'albert_new_abilities_disabled' );

		( new AbilitiesManager() )->reconcile_new_abilities();

		$after = (array) get_option( AbilitiesPage::DISABLED_ABILITIES_OPTION );
		$this->assertSame( ! $view_user_on, in_array( 'core/users-query', $after, true ) );
		$this->assertContains( 'core/content-query', $after, 'A new core ability without an Albert twin still starts off.' );
		$this->assertContains( 'core/users-query', (array) get_option( 'albert_known_abilities' ) );

		// Once: the owner's later choice stands.
		update_option( AbilitiesPage::DISABLED_ABILITIES_OPTION, $view_user_on ? [ 'core/users-query' ] : [] );
		( new AbilitiesManager() )->reconcile_new_abilities();
		$this->assertSame( $view_user_on, in_array( 'core/users-query', (array) get_option( AbilitiesPage::DISABLED_ABILITIES_OPTION ), true ) );
	}

	/**
	 * View-user on and off, with Albert or WordPress updated first.
	 *
	 * @return array<string, array{bool, bool}>
	 */
	public static function viewUserStates(): array {
		return [
			'was on, Albert first'     => [ true, false ],
			'was off, Albert first'    => [ false, false ],
			'was on, WordPress first'  => [ true, true ],
			'was off, WordPress first' => [ false, true ],
		];
	}

	/**
	 * Run a callback while the given one-shot action reports as in progress.
	 *
	 * @param string   $action   Action name.
	 * @param callable $callback What to run.
	 *
	 * @return void
	 */
	private function during( string $action, callable $callback ): void {
		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP core's own abilities tests use this pattern.
		$wp_current_filter[] = $action;

		try {
			$callback();
		} finally {
			array_pop( $wp_current_filter );
		}
	}
}
