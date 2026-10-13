<?php
/**
 * Database table registry.
 *
 * @package Albert
 * @subpackage Database
 * @since      1.2.0
 */

namespace Albert\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Tables class
 *
 * Single source of truth for Albert's database table names. Runtime callers
 * (repositories, admin screens) resolve table names here; the schema/DDL lives
 * in {@see Installer}. Keeping names out of the domain folders means those
 * folders hold logging/OAuth logic only, never database plumbing.
 *
 * @since 1.2.0
 */
class Tables {

	/**
	 * The shared ability-execution log table.
	 *
	 * @return string Prefixed table name.
	 * @since 1.2.0
	 */
	public static function ability_log(): string {
		global $wpdb;

		return $wpdb->prefix . 'albert_ability_log';
	}

	/**
	 * The OAuth tables, keyed by role.
	 *
	 * @return array{clients: string, access_tokens: string, refresh_tokens: string, auth_codes: string}
	 * @since 1.2.0
	 */
	public static function oauth(): array {
		global $wpdb;

		return [
			'clients'        => $wpdb->prefix . 'albert_oauth_clients',
			'access_tokens'  => $wpdb->prefix . 'albert_oauth_access_tokens',
			'refresh_tokens' => $wpdb->prefix . 'albert_oauth_refresh_tokens',
			'auth_codes'     => $wpdb->prefix . 'albert_oauth_auth_codes',
		];
	}

	/**
	 * The generic single-use hashed token table, backing {@see \Albert\Core\Tokens\TokenService}.
	 *
	 * @return string Prefixed table name.
	 * @since 1.4.0
	 */
	public static function single_use_tokens(): string {
		global $wpdb;

		return $wpdb->prefix . 'albert_single_use_tokens';
	}

	/**
	 * The safe-mode pending-actions queue, backing {@see \Albert\SafeMode\Repository}.
	 *
	 * Holds destructive ability calls intercepted before execution, staged with
	 * their resolved input so a person can approve or reject them out of band.
	 *
	 * @return string Prefixed table name.
	 * @since 1.5.0
	 */
	public static function pending_actions(): string {
		global $wpdb;

		return $wpdb->prefix . 'albert_pending_actions';
	}

	/**
	 * The change journal: one row per recorded assistant call.
	 *
	 * @param int|null $blog_id The blog whose journal; the current one by default.
	 *
	 * @return string Prefixed table name.
	 * @since 1.5.0
	 */
	public static function changes( ?int $blog_id = null ): string {
		global $wpdb;

		return $wpdb->get_blog_prefix( $blog_id ) . 'albert_changes';
	}

	/**
	 * The change journal's before-images: one row per entity a recorded call changed.
	 *
	 * @param int|null $blog_id The blog whose journal; the current one by default.
	 *
	 * @return string Prefixed table name.
	 * @since 1.5.0
	 */
	public static function change_items( ?int $blog_id = null ): string {
		global $wpdb;

		return $wpdb->get_blog_prefix( $blog_id ) . 'albert_change_items';
	}

	/**
	 * Every Albert table name, flat.
	 *
	 * @return array<int, string> All prefixed table names.
	 * @since 1.2.0
	 */
	public static function all(): array {
		return array_merge(
			[ self::ability_log(), self::single_use_tokens(), self::pending_actions(), self::changes(), self::change_items() ],
			array_values( self::oauth() )
		);
	}

	/**
	 * Whether a table is one of Albert's, on any blog of the network.
	 *
	 * @param string $table   Table name.
	 * @param bool   $journal Only the change journal's two tables.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function is_albert_table( string $table, bool $journal = false ): bool {
		global $wpdb;

		$tables = $journal ? [ self::changes(), self::change_items() ] : self::all();
		$names  = [];
		foreach ( $tables as $name ) {
			// Unprefixed, so the same table on another blog matches too.
			$names[] = preg_quote( strtolower( substr( $name, strlen( $wpdb->prefix ) ) ), '/' );
		}

		$pattern = '/^' . preg_quote( strtolower( $wpdb->base_prefix ), '/' ) . '(?:\d+_)?(?:' . implode( '|', $names ) . ')$/';

		return preg_match( $pattern, strtolower( $table ) ) === 1;
	}
}
