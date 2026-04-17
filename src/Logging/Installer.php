<?php
/**
 * Logging Database Installer
 *
 * @package Albert
 * @subpackage Logging
 * @since      1.1.0
 */

namespace Albert\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Installer class
 *
 * Handles creation and management of the ability log database table.
 *
 * @since 1.1.0
 */
class Installer {

	/**
	 * Database version.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option name for storing database version.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const DB_VERSION_OPTION = 'albert_logging_db_version';

	/**
	 * Install database table.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public static function install(): void {
		$installed_version = get_option( self::DB_VERSION_OPTION, '0' );

		if ( version_compare( $installed_version, self::DB_VERSION, '<' ) ) {
			self::create_table();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Create database table.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private static function create_table(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = self::get_table_name();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ability_name varchar(191) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY ability_created (ability_name, created_at)
		) $charset_collate;\n\n";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get the table name.
	 *
	 * @return string The full table name with prefix.
	 * @since 1.1.0
	 */
	public static function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'albert_ability_log';
	}

	/**
	 * Uninstall database table.
	 *
	 * Only call this on plugin uninstall, not deactivation.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public static function uninstall(): void {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema change required for uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );

		delete_option( self::DB_VERSION_OPTION );
	}
}
