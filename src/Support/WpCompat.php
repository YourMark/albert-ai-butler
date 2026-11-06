<?php
/**
 * WordPress version-capability detection.
 *
 * @package Albert
 * @subpackage Support
 * @since      1.4.0
 */

namespace Albert\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Detection of WordPress 7.1 Abilities-API capabilities.
 *
 * Albert requires 7.1 since 1.6.0 and no longer calls these itself. Kept
 * because add-ons (Premium's activity log) still ask; both answer true on
 * every supported version.
 *
 * @since 1.4.0
 */
class WpCompat {

	/**
	 * Whether WordPress can prepare a JSON schema for client consumption.
	 *
	 * WordPress 7.1 adds `wp_prepare_json_schema_for_client()`, which strips the
	 * server-only keys from a schema (validation/sanitisation callbacks, argument
	 * options) before it is handed to an API client. Albert runs its MCP tool
	 * schemas through it at the output boundary; below 7.1 the canonical schema
	 * is emitted unchanged.
	 *
	 * @return bool True on WordPress 7.1+.
	 * @since 1.4.0
	 */
	public static function supports_client_schema_prep(): bool {
		return function_exists( 'wp_prepare_json_schema_for_client' );
	}

	/**
	 * Whether the Abilities API exposes its execution lifecycle hooks.
	 *
	 * WordPress 7.1 rebuilt `WP_Ability::execute()` around a lifecycle: the
	 * `wp_ability_invoked` action (every call, whatever the outcome), the
	 * `wp_pre_execute_ability` short-circuit filter, `wp_ability_validate_input`
	 * / `wp_ability_validate_output`, and `wp_ability_execute_result`. They ship
	 * together, so one check covers the set.
	 *
	 * Detected through `WP_Filter_Sentinel`, the marker class
	 * `wp_pre_execute_ability` uses as its "no filter touched this" default.
	 * Actions and filters cannot be feature-detected directly — nothing
	 * registers them until they fire — so this is a proxy, and it is worth being
	 * honest about how good a one it is. `WP_Filter_Sentinel` is a
	 * general-purpose Filter Sentinel API rather than an abilities class; it is
	 * used by the lifecycle but not owned by it. It ships in the same release,
	 * so the probe answers correctly on every shipping version, and it beats
	 * reading `$wp_version`. But the two are only coupled by release, not by
	 * design: if the lifecycle hooks were renamed while the sentinel stayed, this
	 * would report true for hooks that never fire.
	 *
	 * Callers must stay correct when this returns false: below 7.1 the hooks
	 * simply never fire, so a listener registered against them is inert rather
	 * than broken.
	 *
	 * @return bool True on WordPress 7.1+.
	 * @since 1.4.0
	 */
	public static function supports_execution_lifecycle(): bool {
		return class_exists( '\WP_Filter_Sentinel' );
	}
}
