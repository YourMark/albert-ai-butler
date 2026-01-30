<?php
/**
 * Settings Migration
 *
 * Handles migration from old ability-based settings to new permission-based settings.
 *
 * @package Albert
 * @subpackage Core
 * @since      1.0.0
 */

namespace Albert\Core;

use Albert\Core\AbilitiesRegistry;

/**
 * Settings Migration class
 *
 * Migrates settings from the old format to the new permission groups format.
 *
 * @since 1.0.0
 */
class SettingsMigration {

	/**
	 * Old option name (ability IDs as keys).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const OLD_OPTION_NAME = 'albert_options';

	/**
	 * New option name (permission keys as values).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const NEW_OPTION_NAME = 'albert_enabled_permissions';

	/**
	 * Migration status option name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const MIGRATION_STATUS_OPTION = 'albert_settings_migrated';

	/**
	 * Run the migration if needed.
	 *
	 * This should be called on admin_init hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function maybe_migrate(): void {
		// Check if migration already ran.
		if ( get_option( self::MIGRATION_STATUS_OPTION, false ) ) {
			return;
		}

		// Check if old settings exist.
		$old_settings = get_option( self::OLD_OPTION_NAME, null );

		if ( $old_settings === null || ! is_array( $old_settings ) ) {
			// No old settings to migrate, mark as migrated.
			update_option( self::MIGRATION_STATUS_OPTION, true );
			return;
		}

		// Perform migration.
		self::migrate_settings( $old_settings );

		// Mark migration as complete.
		update_option( self::MIGRATION_STATUS_OPTION, true );
	}

	/**
	 * Migrate old settings to new format.
	 *
	 * @param array<string, mixed> $old_settings Old settings array (ability IDs as keys).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function migrate_settings( array $old_settings ): void {
		$permissions = [];

		// Mapping of old ability IDs to new permissions.
		$ability_to_permission_map = self::get_migration_map();

		foreach ( array_keys( $old_settings ) as $ability_id ) {
			if ( isset( $ability_to_permission_map[ $ability_id ] ) ) {
				$permission = $ability_to_permission_map[ $ability_id ];
				if ( ! in_array( $permission, $permissions, true ) ) {
					$permissions[] = $permission;
				}
			}
		}

		// If no permissions were migrated, use defaults.
		if ( empty( $permissions ) ) {
			$permissions = AbilitiesRegistry::get_default_permissions();
		}

		// Save new settings.
		update_option( self::NEW_OPTION_NAME, $permissions );

		// Delete old settings.
		delete_option( self::OLD_OPTION_NAME );
	}

	/**
	 * Get migration map from old ability IDs to new permission keys.
	 *
	 * @return array<string, string> Map of ability ID => permission key.
	 * @since 1.0.0
	 */
	private static function get_migration_map(): array {
		return [
			// Posts - all abilities map to read or write.
			'albert/find-posts'         => 'posts_read',
			'albert/view-post'          => 'posts_read',
			'albert/create-post'        => 'posts_write',
			'albert/update-post'        => 'posts_write',
			'albert/delete-post'        => 'posts_write',

			// Pages.
			'albert/find-pages'         => 'pages_read',
			'albert/view-page'          => 'pages_read',
			'albert/create-page'        => 'pages_write',
			'albert/update-page'        => 'pages_write',
			'albert/delete-page'        => 'pages_write',

			// Users.
			'albert/find-users'         => 'users_read',
			'albert/view-user'          => 'users_read',
			'albert/create-user'        => 'users_write',
			'albert/update-user'        => 'users_write',
			'albert/delete-user'        => 'users_write',

			// Media.
			'albert/upload-media'       => 'media_write',
			'albert/set-featured-image' => 'media_write',
			'albert/find-media'         => 'media_read',
			'albert/view-media'         => 'media_read',

			// Taxonomies/Terms.
			'albert/find-taxonomies'    => 'taxonomies_read',
			'albert/find-terms'         => 'taxonomies_read',
			'albert/view-term'          => 'taxonomies_read',
			'albert/create-term'        => 'taxonomies_write',
			'albert/update-term'        => 'taxonomies_write',
			'albert/delete-term'        => 'taxonomies_write',
		];
	}
}
