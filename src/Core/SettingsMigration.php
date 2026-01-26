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

use Albert\Admin\Abilities;

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
			$permissions = Abilities::get_default_permissions();
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
			'albert/posts/list'               => 'posts_read',
			'albert/posts/get'                => 'posts_read',
			'albert/posts/create'             => 'posts_write',
			'albert/posts/update'             => 'posts_write',
			'albert/posts/delete'             => 'posts_write',

			// Pages.
			'albert/pages/list'               => 'pages_read',
			'albert/pages/get'                => 'pages_read',
			'albert/pages/create'             => 'pages_write',
			'albert/pages/update'             => 'pages_write',
			'albert/pages/delete'             => 'pages_write',

			// Users.
			'albert/users/list'               => 'users_read',
			'albert/users/get'                => 'users_read',
			'albert/users/create'             => 'users_write',
			'albert/users/update'             => 'users_write',
			'albert/users/delete'             => 'users_write',

			// Media.
			'albert/media/upload'             => 'media_write',
			'albert/media/set-featured-image' => 'media_write',
			'albert/media/list'               => 'media_read',
			'albert/media/get'                => 'media_read',

			// Taxonomies/Terms.
			'albert/taxonomies/list'          => 'taxonomies_read',
			'albert/terms/list'               => 'taxonomies_read',
			'albert/terms/get'                => 'taxonomies_read',
			'albert/terms/create'             => 'taxonomies_write',
			'albert/terms/update'             => 'taxonomies_write',
			'albert/terms/delete'             => 'taxonomies_write',
		];
	}
}
