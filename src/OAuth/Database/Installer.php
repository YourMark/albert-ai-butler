<?php
/**
 * OAuth Database Installer
 *
 * @package Albert
 * @subpackage OAuth\Database
 * @since      1.0.0
 */

namespace Albert\OAuth\Database;

/**
 * Installer class
 *
 * Handles creation and management of OAuth database tables.
 *
 * @since 1.0.0
 */
class Installer {

	/**
	 * Database version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option name for storing database version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const DB_VERSION_OPTION = 'albert_oauth_db_version';

	/**
	 * Install database tables.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function install(): void {
		$installed_version = get_option( self::DB_VERSION_OPTION, '0' );

		if ( version_compare( $installed_version, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Create database tables.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = self::get_clients_table_sql( $charset_collate )
			. self::get_access_tokens_table_sql( $charset_collate )
			. self::get_refresh_tokens_table_sql( $charset_collate )
			. self::get_auth_codes_table_sql( $charset_collate );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get OAuth clients table SQL.
	 *
	 * @param string $charset_collate Database charset and collation.
	 *
	 * @return string SQL statement.
	 * @since 1.0.0
	 */
	private static function get_clients_table_sql( string $charset_collate ): string {
		global $wpdb;

		$table_name = $wpdb->prefix . 'albert_oauth_clients';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id varchar(80) NOT NULL,
			client_secret varchar(255) DEFAULT NULL,
			name varchar(255) NOT NULL,
			redirect_uri text NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			is_confidential tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY client_id (client_id),
			KEY user_id (user_id)
		) $charset_collate;\n\n";
	}

	/**
	 * Get OAuth access tokens table SQL.
	 *
	 * @param string $charset_collate Database charset and collation.
	 *
	 * @return string SQL statement.
	 * @since 1.0.0
	 */
	private static function get_access_tokens_table_sql( string $charset_collate ): string {
		global $wpdb;

		$table_name = $wpdb->prefix . 'albert_oauth_access_tokens';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_id varchar(100) NOT NULL,
			client_id varchar(80) NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			scopes text DEFAULT NULL,
			revoked tinyint(1) NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY token_id (token_id),
			KEY client_id (client_id),
			KEY user_id (user_id),
			KEY revoked (revoked),
			KEY expires_at (expires_at)
		) $charset_collate;\n\n";
	}

	/**
	 * Get OAuth refresh tokens table SQL.
	 *
	 * @param string $charset_collate Database charset and collation.
	 *
	 * @return string SQL statement.
	 * @since 1.0.0
	 */
	private static function get_refresh_tokens_table_sql( string $charset_collate ): string {
		global $wpdb;

		$table_name = $wpdb->prefix . 'albert_oauth_refresh_tokens';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_id varchar(100) NOT NULL,
			access_token_id varchar(100) NOT NULL,
			revoked tinyint(1) NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY token_id (token_id),
			KEY access_token_id (access_token_id),
			KEY revoked (revoked),
			KEY expires_at (expires_at)
		) $charset_collate;\n\n";
	}

	/**
	 * Get OAuth authorization codes table SQL.
	 *
	 * @param string $charset_collate Database charset and collation.
	 *
	 * @return string SQL statement.
	 * @since 1.0.0
	 */
	private static function get_auth_codes_table_sql( string $charset_collate ): string {
		global $wpdb;

		$table_name = $wpdb->prefix . 'albert_oauth_auth_codes';

		return "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code_id varchar(100) NOT NULL,
			client_id varchar(80) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			scopes text DEFAULT NULL,
			revoked tinyint(1) NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY code_id (code_id),
			KEY client_id (client_id),
			KEY user_id (user_id),
			KEY revoked (revoked),
			KEY expires_at (expires_at)
		) $charset_collate;\n\n";
	}

	/**
	 * Get table names.
	 *
	 * @return array<string, string> Array of table names.
	 * @since 1.0.0
	 */
	public static function get_table_names(): array {
		global $wpdb;

		return [
			'clients'        => $wpdb->prefix . 'albert_oauth_clients',
			'access_tokens'  => $wpdb->prefix . 'albert_oauth_access_tokens',
			'refresh_tokens' => $wpdb->prefix . 'albert_oauth_refresh_tokens',
			'auth_codes'     => $wpdb->prefix . 'albert_oauth_auth_codes',
		];
	}

	/**
	 * Uninstall database tables.
	 *
	 * Only call this on plugin uninstall, not deactivation.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function uninstall(): void {
		global $wpdb;

		$tables = self::get_table_names();

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema change required for uninstall.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( self::DB_VERSION_OPTION );
	}
}
