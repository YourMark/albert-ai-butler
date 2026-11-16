<?php
/**
 * Integration tests for the environment policy against the real abilities registry.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Core;

use Albert\Admin\AbilitiesPayload;
use Albert\Core\AbilitiesState;
use Albert\Core\EnvironmentPolicy;
use Albert\Tests\TestCase;

/**
 * EnvironmentPolicy integration tests.
 *
 * @covers \Albert\Core\EnvironmentPolicy
 */
class EnvironmentPolicyTest extends TestCase {

	/**
	 * Category the test abilities register under.
	 */
	private const CATEGORY = 'albert-test-environment-policy';

	/**
	 * Annotation sets keyed by the ability that carries them.
	 */
	private const ABILITIES = [
		'albert-test/env-read'   => [
			'readonly'    => true,
			'destructive' => false,
		],
		'albert-test/env-write'  => [
			'readonly'    => false,
			'destructive' => false,
		],
		'albert-test/env-delete' => [
			'readonly'    => false,
			'destructive' => true,
		],
	];

	/**
	 * Register the abilities and save toggles that switch all three off.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		wp_get_abilities();

		$this->during(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( self::CATEGORY ) ) {
					wp_register_ability_category(
						self::CATEGORY,
						[
							'label'       => 'Albert Test',
							'description' => 'Environment policy tests.',
						]
					);
				}
			}
		);

		$this->during(
			'wp_abilities_api_init',
			static function (): void {
				foreach ( self::ABILITIES as $id => $annotations ) {
					wp_register_ability(
						$id,
						[
							'label'               => $id,
							'description'         => 'Environment policy test ability.',
							'category'            => self::CATEGORY,
							'execute_callback'    => static fn(): array => [ 'ok' => true ],
							'permission_callback' => '__return_true',
							'meta'                => [ 'annotations' => $annotations ],
						]
					);
				}
			}
		);

		update_option( AbilitiesState::OPTION, array_keys( self::ABILITIES ) );
		update_option( AbilitiesState::SAVED_OPTION, true );
	}

	/**
	 * Unregister the abilities and drop the policy.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( array_keys( self::ABILITIES ) as $id ) {
			if ( wp_has_ability( $id ) ) {
				wp_unregister_ability( $id );
			}
		}

		remove_all_filters( 'albert/abilities/environment_policy' );
		delete_option( AbilitiesState::OPTION );

		parent::tear_down();
	}

	/**
	 * Run a registration callback while core believes the given action is firing.
	 *
	 * @param string   $action   The registration action core requires.
	 * @param callable $register Registration callback.
	 *
	 * @return void
	 */
	private function during( string $action, callable $register ): void {
		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WP core's own abilities tests use this pattern to satisfy doing_action().
		$wp_current_filter[] = $action;
		try {
			$register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Apply a policy through the filter.
	 *
	 * @param string $policy Policy name.
	 *
	 * @return void
	 */
	private function policy( string $policy ): void {
		add_filter( 'albert/abilities/environment_policy', static fn(): string => $policy );
	}

	/**
	 * With nothing configured the saved toggles decide, as before.
	 *
	 * @return void
	 */
	public function test_without_a_policy_the_toggles_decide(): void {
		foreach ( array_keys( self::ABILITIES ) as $id ) {
			$this->assertFalse( AbilitiesState::is_enabled( $id ) );
		}

		$this->assertNull( AbilitiesPayload::build()['policy'] );
	}

	/**
	 * `read-write` reads the registry's annotations: reads and writes on, deletes still off.
	 *
	 * @return void
	 */
	public function test_read_write_switches_on_what_deletes_nothing(): void {
		$this->policy( EnvironmentPolicy::READ_WRITE );

		$this->assertTrue( AbilitiesState::is_enabled( 'albert-test/env-read' ) );
		$this->assertTrue( AbilitiesState::is_enabled( 'albert-test/env-write' ) );
		$this->assertFalse( AbilitiesState::is_enabled( 'albert-test/env-delete' ) );
	}

	/**
	 * `all` switches everything on and writes nothing to the toggles.
	 *
	 * @return void
	 */
	public function test_all_switches_everything_on_without_writing_the_toggles(): void {
		$this->policy( EnvironmentPolicy::ALL );

		$this->assertTrue( AbilitiesState::is_enabled( 'albert-test/env-delete' ) );
		$this->assertSame( array_keys( self::ABILITIES ), get_option( AbilitiesState::OPTION ) );
	}

	/**
	 * The screen keeps showing the saved toggles and is told the policy is in force.
	 *
	 * @return void
	 */
	public function test_screen_shows_the_toggles_and_the_policy(): void {
		$this->policy( EnvironmentPolicy::ALL );

		$payload = AbilitiesPayload::build();
		$rows    = array_column( $payload['abilities'], 'enabled', 'id' );

		$this->assertFalse( $rows['albert-test/env-delete'] );
		$this->assertSame( EnvironmentPolicy::ALL, $payload['policy']['policy'] );
		$this->assertSame( wp_get_environment_type(), $payload['policy']['environment'] );
		$this->assertSame( 'filter', $payload['policy']['source'] );
	}

	/**
	 * Saving one toggle while a policy is active stores only that change, not the policy's list.
	 *
	 * @return void
	 */
	public function test_saving_a_toggle_under_a_policy_keeps_the_others(): void {
		$this->policy( EnvironmentPolicy::ALL );

		AbilitiesState::set_enabled( 'albert-test/env-read', true );

		$this->assertSame( [ 'albert-test/env-write', 'albert-test/env-delete' ], get_option( AbilitiesState::OPTION ) );
	}

	/**
	 * Albert's destructive writes declare it, so every reader of the flag agrees.
	 *
	 * @dataProvider destructive_writes
	 *
	 * @param string $class_name The ability class.
	 *
	 * @return void
	 */
	public function test_destructive_writes_declare_it( string $class_name ): void {
		$meta = new \ReflectionProperty( $class_name, 'meta' );
		$meta->setAccessible( true );

		$this->assertTrue( $meta->getValue( new $class_name() )['annotations']['destructive'] );
	}

	/**
	 * Writes that remove content or manage accounts.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function destructive_writes(): array {
		return [
			'remove post block' => [ \Albert\Abilities\WordPress\Posts\RemoveBlock::class ],
			'remove page block' => [ \Albert\Abilities\WordPress\Pages\RemoveBlock::class ],
			'update user'       => [ \Albert\Abilities\WordPress\Users\Update::class ],
			'create user'       => [ \Albert\Abilities\WordPress\Users\Create::class ],
		];
	}
}
