<?php
/**
 * Integration tests for the adapter tool abilities Albert's server lists.
 *
 * A plugin returning false from `mcp_adapter_create_default_server` stops the
 * adapter registering these three abilities, and Albert's server then answers
 * `tools/list` with nothing while OAuth still works.
 *
 * @package Albert
 */

namespace Albert\Tests\Integration\MCP;

use Albert\MCP\AdapterAbilities;
use Albert\MCP\Server;
use Albert\Tests\TestCase;

/**
 * Adapter ability registration tests.
 *
 * @covers \Albert\MCP\AdapterAbilities
 */
class AdapterAbilitiesTest extends TestCase {

	/**
	 * Abilities unregistered by a test, restored in tear_down().
	 *
	 * @var array<string, \WP_Ability>
	 */
	private array $removed = [];

	/**
	 * Put back whatever a test took away, so later tests see the normal registry.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( array_keys( AdapterAbilities::ABILITIES ) as $id ) {
			if ( wp_has_ability( $id ) ) {
				wp_unregister_ability( $id );
			}
		}

		$removed = $this->removed;
		$this->run_abilities_init(
			static function () use ( $removed ): void {
				foreach ( $removed as $id => $ability ) {
					wp_register_ability(
						$id,
						[
							'label'               => $ability->get_label(),
							'description'         => $ability->get_description(),
							'category'            => $ability->get_category(),
							'input_schema'        => $ability->get_input_schema(),
							'output_schema'       => $ability->get_output_schema(),
							'execute_callback'    => [ $ability, 'execute' ],
							'permission_callback' => [ $ability, 'check_permissions' ],
							'meta'                => $ability->get_meta(),
						]
					);
				}
			}
		);

		parent::tear_down();
	}

	/**
	 * Unregister the three abilities, as on a site where the default server is off.
	 *
	 * @return void
	 */
	private function remove_adapter_abilities(): void {
		foreach ( array_keys( AdapterAbilities::ABILITIES ) as $id ) {
			if ( wp_has_ability( $id ) ) {
				$this->removed[ $id ] = wp_get_ability( $id );
				wp_unregister_ability( $id );
			}
		}
	}

	/**
	 * Fire `wp_abilities_api_init` with only the given callback attached.
	 *
	 * The registry initialises once per process, so the real action cannot be
	 * replayed without every other plugin registering its abilities twice.
	 *
	 * @param callable $callback The callback to run inside the action.
	 *
	 * @return void
	 */
	private function run_abilities_init( callable $callback ): void {
		global $wp_filter;

		$original = $wp_filter['wp_abilities_api_init'] ?? null;
		unset( $wp_filter['wp_abilities_api_init'] );

		add_action( 'wp_abilities_api_init', $callback );
		do_action( 'wp_abilities_api_init' );

		if ( $original === null ) {
			unset( $wp_filter['wp_abilities_api_init'] );
		} else {
			$wp_filter['wp_abilities_api_init'] = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the hook this test replaced.
		}
	}

	/**
	 * The ids Albert fills in are exactly the ones its server lists as tools.
	 *
	 * @return void
	 */
	public function test_it_covers_every_tool_albert_server_lists(): void {
		$this->assertSame( Server::CORE_TOOL_ABILITIES, array_keys( AdapterAbilities::ABILITIES ) );
	}

	/**
	 * With the adapter's registration skipped, Albert registers all three itself.
	 *
	 * @return void
	 */
	public function test_it_registers_the_abilities_when_the_adapter_did_not(): void {
		$this->remove_adapter_abilities();

		$this->run_abilities_init( [ new AdapterAbilities(), 'register_abilities' ] );

		foreach ( Server::CORE_TOOL_ABILITIES as $id ) {
			$ability = wp_get_ability( $id );
			$this->assertNotNull( $ability, "{$id} should be registered." );
			$this->assertSame( AdapterAbilities::CATEGORY, $ability->get_category() );
		}
	}

	/**
	 * An ability somebody else already registered is left as it is.
	 *
	 * @return void
	 */
	public function test_it_never_replaces_an_existing_registration(): void {
		$this->remove_adapter_abilities();

		$this->run_abilities_init(
			static function (): void {
				wp_register_ability(
					'mcp-adapter/discover-abilities',
					[
						'label'               => 'Another plugin\'s discovery',
						'description'         => 'Registered first by someone else.',
						'category'            => AdapterAbilities::CATEGORY,
						'execute_callback'    => '__return_empty_array',
						'permission_callback' => '__return_true',
					]
				);
				( new AdapterAbilities() )->register_abilities();
			}
		);

		$this->assertSame( 'Another plugin\'s discovery', wp_get_ability( 'mcp-adapter/discover-abilities' )->get_label() );
		$this->assertNotNull( wp_get_ability( 'mcp-adapter/get-ability-info' ) );
		$this->assertNotNull( wp_get_ability( 'mcp-adapter/execute-ability' ) );
	}
}
