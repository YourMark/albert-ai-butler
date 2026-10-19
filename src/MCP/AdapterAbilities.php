<?php
/**
 * Keeps the adapter's three tool abilities registered for Albert's server.
 *
 * @package Albert
 * @subpackage MCP
 * @since      1.4.3
 */

namespace Albert\MCP;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use WP\MCP\Abilities\DiscoverAbilitiesAbility;
use WP\MCP\Abilities\ExecuteAbilityAbility;
use WP\MCP\Abilities\GetAbilityInfoAbility;

/**
 * Registers the adapter's discover / get-info / execute abilities when nothing else has.
 *
 * Albert's server lists these three as its only tools, but the adapter registers
 * them only while its default server is enabled. A plugin that returns false from
 * `mcp_adapter_create_default_server` therefore leaves Albert's server with zero
 * tools, while OAuth and the endpoint otherwise look healthy.
 *
 * This fills the gap and never replaces: an ability or category already registered,
 * by the adapter or by another plugin, is left alone.
 *
 * @since 1.4.3
 */
class AdapterAbilities implements Hookable {

	/**
	 * The category the adapter files these abilities under.
	 *
	 * @since 1.4.3
	 * @var string
	 */
	const CATEGORY = 'mcp-adapter';

	/**
	 * Hook priority. After the adapter's own (10), so it registers first whenever it registers at all.
	 *
	 * @since 1.4.3
	 * @var int
	 */
	const PRIORITY = 100;

	/**
	 * The ability classes, keyed by the ability id each registers.
	 *
	 * @since 1.4.3
	 * @var array<string, class-string>
	 */
	const ABILITIES = [
		'mcp-adapter/discover-abilities' => DiscoverAbilitiesAbility::class,
		'mcp-adapter/get-ability-info'   => GetAbilityInfoAbility::class,
		'mcp-adapter/execute-ability'    => ExecuteAbilityAbility::class,
	];

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.3
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ], self::PRIORITY );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ], self::PRIORITY );
	}

	/**
	 * Register the `mcp-adapter` category if it is missing.
	 *
	 * @return void
	 * @since 1.4.3
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		// Same untranslated strings the adapter uses, so the category reads the same whoever registered it.
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => 'MCP Adapter',
				'description' => 'Abilities for the MCP Adapter',
			]
		);
	}

	/**
	 * Register each missing ability through the adapter's own class.
	 *
	 * @return void
	 * @since 1.4.3
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		foreach ( self::ABILITIES as $id => $class ) {
			// The loaded adapter copy may be another plugin's; skip what it does not provide.
			if ( wp_has_ability( $id ) || ! method_exists( $class, 'register' ) ) {
				continue;
			}

			$class::register();
		}
	}
}
