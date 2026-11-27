<?php
/**
 * Integration tests for privacy mode on abilities an assistant calls.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\Privacy;

use Albert\OAuth\Server\ConnectionContext;
use Albert\Tests\TestCase;

/**
 * Privacy mode through core's execution path, for another plugin's abilities.
 *
 * @covers \Albert\Privacy\ConnectedResultRedactor
 */
class ConnectedRedactionTest extends TestCase {

	/**
	 * Test-only ability category.
	 *
	 * @var string
	 */
	private const TEST_CATEGORY = 'albert-test';

	/**
	 * Another plugin's ability whose schema forbids input it does not declare.
	 *
	 * @var string
	 */
	private const CLOSED = 'other-plugin/view-contact';

	/**
	 * Another plugin's ability without input.
	 *
	 * @var string
	 */
	private const NO_INPUT = 'other-plugin/site-settings';

	/**
	 * What each callback was called with, per ability.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private static array $received = [];

	/**
	 * Register both abilities on Balanced and connect as a subscriber.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		update_option( 'albert_privacy_mode', 'balanced' );
		self::$received = [];

		if ( ! wp_has_ability_category( self::TEST_CATEGORY ) ) {
			$this->during(
				'wp_abilities_api_categories_init',
				static function (): void {
					wp_register_ability_category(
						self::TEST_CATEGORY,
						[
							'label'       => 'Albert Test',
							'description' => 'Test-only category.',
						]
					);
				}
			);
		}

		$this->during(
			'wp_abilities_api_init',
			static function (): void {
				wp_register_ability(
					self::CLOSED,
					self::args(
						static function ( $input ): array {
							self::$received[ self::CLOSED ] = func_get_args();

							return [
								'id'    => $input['id'],
								'name'  => 'Jane Doe',
								'email' => 'jane@example.com',
							];
						},
						[
							'type'                 => 'object',
							'properties'           => [ 'id' => [ 'type' => 'integer' ] ],
							'required'             => [ 'id' ],
							'additionalProperties' => false,
						]
					)
				);
				wp_register_ability(
					self::NO_INPUT,
					self::args(
						static function (): array {
							self::$received[ self::NO_INPUT ] = func_get_args();

							return [
								'admin_email' => 'owner@example.com',
								'breakpoint'  => [ 'mobile' => 768 ],
							];
						}
					)
				);
			}
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		ConnectionContext::set( 'client-1' );
	}

	/**
	 * Unregister the abilities and close the connection.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( [ self::CLOSED, self::NO_INPUT ] as $id ) {
			if ( wp_has_ability( $id ) ) {
				wp_unregister_ability( $id );
			}
		}

		ConnectionContext::reset();
		parent::tear_down();
	}

	/**
	 * The assistant sees the reveal input even where the schema forbids extra input.
	 *
	 * @return void
	 */
	public function test_a_closed_schema_offers_the_reveal_input(): void {
		$schema = wp_get_ability( self::CLOSED )->get_input_schema();

		$this->assertSame( 'boolean', $schema['properties']['reveal_personal_data']['type'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( 'object', wp_get_ability( self::NO_INPUT )->get_input_schema()['type'] );
	}

	/**
	 * Asking for the real data passes validation, reveals it, and never reaches the callback.
	 *
	 * @return void
	 */
	public function test_reveal_on_a_closed_schema(): void {
		$masked   = wp_get_ability( self::CLOSED )->execute( [ 'id' => 3 ] );
		$revealed = wp_get_ability( self::CLOSED )->execute(
			[
				'id'                   => 3,
				'reveal_personal_data' => true,
			]
		);

		$this->assertSame( 'j***@e***.com', $masked['email'] );
		$this->assertSame( 'Jane Doe', $masked['name'] );
		$this->assertSame( 'jane@example.com', $revealed['email'] );
		$this->assertSame( [ [ 'id' => 3 ] ], self::$received[ self::CLOSED ] );
	}

	/**
	 * A site that raises the reveal capability keeps a subscriber's request masked.
	 *
	 * @return void
	 */
	public function test_the_reveal_capability_stays_a_floor(): void {
		add_filter( 'albert/privacy/reveal_capability', static fn() => 'list_users' );

		$result = wp_get_ability( self::CLOSED )->execute(
			[
				'id'                   => 3,
				'reveal_personal_data' => true,
			]
		);

		$this->assertSame( 'j***@e***.com', $result['email'] );
	}

	/**
	 * An ability without input still runs without input, with or without a reveal request.
	 *
	 * @return void
	 */
	public function test_an_ability_without_input(): void {
		$masked   = wp_get_ability( self::NO_INPUT )->execute();
		$no_args  = self::$received[ self::NO_INPUT ];
		$revealed = wp_get_ability( self::NO_INPUT )->execute( [ 'reveal_personal_data' => true ] );

		$this->assertSame( 'o***@e***.com', $masked['admin_email'] );
		$this->assertSame( [ 'mobile' => 768 ], $masked['breakpoint'] );
		$this->assertSame( 'owner@example.com', $revealed['admin_email'] );
		$this->assertSame( [], $no_args );
		$this->assertSame( [], self::$received[ self::NO_INPUT ] );
	}

	/**
	 * Registration arguments for another plugin's read-only ability.
	 *
	 * @param callable             $callback The execute callback.
	 * @param array<string, mixed> $schema   The input schema, if any.
	 *
	 * @return array<string, mixed>
	 */
	private static function args( callable $callback, array $schema = [] ): array {
		$args = [
			'label'               => 'Other plugin',
			'description'         => 'Another plugin\'s ability.',
			'category'            => self::TEST_CATEGORY,
			'execute_callback'    => $callback,
			// An ability without input is asked with null, never with Albert's input.
			'permission_callback' => static fn( $input = null ): bool => $schema === [] ? $input === null : is_array( $input ),
			'meta'                => [
				'annotations' => [
					'readonly'    => true,
					'destructive' => false,
				],
				'mcp'         => [ 'public' => true ],
			],
		];

		if ( $schema !== [] ) {
			$args['input_schema'] = $schema;
		}

		return $args;
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
