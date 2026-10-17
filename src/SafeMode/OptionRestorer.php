<?php
/**
 * Puts back protected options changed outside the option API.
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.5.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\OAuth\Server\ConnectionContext;

/**
 * The backstop behind {@see ConnectionGuard}: a write to an options table that
 * did not come through WordPress's option functions (a plugin's own
 * `$wpdb->update()`, `REPLACE`, raw SQL) passes none of the guard's hooks.
 *
 * Over a connection, such a statement makes this read the protected rows
 * first, just before it runs. When the ability finishes, the tool call ends or
 * the request shuts down, any protected row that changed is put back exactly
 * as it was, and the restore is reported. The statement itself cannot be
 * judged reliably from its text, so it is let through and its effect undone.
 *
 * Limits: a write is recognised by naming an options table, so SQL that builds
 * the table name at run time is not seen; and for the moment between the write
 * and the restore, another request could read the changed value.
 *
 * @since 1.5.0
 */
class OptionRestorer implements Hookable {

	/**
	 * Core functions whose writes the guard's hooks already see, plus
	 * `wp_set_option_autoload_values()`, which changes only the autoload flag.
	 *
	 * @since 1.5.0
	 * @var list<string>
	 */
	private const OPTION_API = [
		'add_option',
		'update_option',
		'delete_option',
		'wp_set_option_autoload_values',
		'add_network_option',
		'update_network_option',
		'delete_network_option',
	];

	/**
	 * Protected rows before the first unguarded write, per options table.
	 *
	 * @since 1.5.0
	 * @var array<string, array{blog: int, rows: array<string, array{option_name: string, option_value: string, autoload: string}|null>}>
	 */
	private array $before = [];

	/**
	 * Tables whose rows could not be read back, already reported.
	 *
	 * @since 1.5.0
	 * @var array<string, true>
	 */
	private array $unreadable = [];

	/**
	 * Whether this class's own queries are running.
	 *
	 * @since 1.5.0
	 * @var bool
	 */
	private bool $busy = false;

	/**
	 * Bind the backstop.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_hooks(): void {
		add_filter( 'query', [ $this, 'note_unguarded_write' ], PHP_INT_MAX - 1 );
		add_action( 'wp_after_execute_ability', [ $this, 'restore' ], PHP_INT_MAX, 0 );
		add_filter( 'mcp_adapter_tool_call_result', [ $this, 'restore_after_tool_call' ], PHP_INT_MAX );
		add_action( 'shutdown', [ $this, 'restore' ], 0, 0 );
	}

	/**
	 * Read the protected rows before a write that skips the option API.
	 *
	 * Runs on every query, so it returns at once outside a connection and for
	 * any statement that does not name an options table.
	 *
	 * @param string $query The SQL about to run.
	 *
	 * @return string The query, or an empty string to drop it when the rows cannot be read.
	 * @since 1.5.0
	 */
	public function note_unguarded_write( $query ) {
		if ( $this->busy || ConnectionContext::client_id() === null || ! is_string( $query ) || stripos( $query, 'options' ) === false ) {
			return $query;
		}

		if ( preg_match( '/^[\s(]*(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\s/i', $query ) === 1 ) {
			return $query;
		}

		// A plain CREATE TABLE, as site creation issues, makes an empty table that
		// reading first would fail on. Not TEMPORARY, which shadows the real table,
		// nor IF NOT EXISTS or SELECT, which can write rows into an existing one.
		if ( preg_match( '/^[\s(]*CREATE\s+TABLE\s+(?!IF\s)/i', $query ) === 1 && preg_match( '/\bSELECT\b/i', $query ) !== 1 ) {
			return $query;
		}

		$tables = $this->options_tables( $query );
		if ( $tables === [] || $this->issued_by_option_api() ) {
			return $query;
		}

		foreach ( $tables as $table => $blog ) {
			if ( isset( $this->before[ $table ] ) ) {
				continue;
			}

			$rows = $this->read( $table );
			if ( $rows === null ) {
				// Without the rows before, nothing could be put back: drop the statement.
				return '';
			}

			$this->before[ $table ] = [
				'blog' => $blog,
				'rows' => $rows,
			];
		}

		return $query;
	}

	/**
	 * Restore once the MCP adapter has the tool call's result.
	 *
	 * @param mixed $result The tool result.
	 *
	 * @return mixed The result, unchanged.
	 * @since 1.5.0
	 */
	public function restore_after_tool_call( $result ) {
		$this->restore();

		return $result;
	}

	/**
	 * Put back every protected row that changed since it was read.
	 *
	 * A row that did not exist and now does is left alone only when the option
	 * may be created over a connection (the OAuth key material).
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function restore(): void {
		if ( $this->before === [] ) {
			return;
		}

		foreach ( $this->before as $table => $state ) {
			$now = $this->read( $table );
			if ( $now === null ) {
				// Unknown is not unchanged: say so, once, and try again later.
				if ( ! isset( $this->unreadable[ $table ] ) ) {
					$this->unreadable[ $table ] = true;
					foreach ( array_keys( $state['rows'] ) as $option ) {
						$this->report( $option, false );
					}
				}
				continue;
			}

			foreach ( $state['rows'] as $option => $was ) {
				$is = $now[ $option ] ?? null;
				if ( $was === $is ) {
					continue;
				}

				if ( $was === null && ConnectionGuard::may_be_created( $option ) ) {
					$this->before[ $table ]['rows'][ $option ] = $is;
					continue;
				}

				$restored = $this->put_back( $table, $option, $was );
				$this->flush_cache( $state['blog'], [ $option, $is['option_name'] ?? $option ] );
				$this->report( $option, $restored );
			}
		}
	}

	/**
	 * Announce a restore, or a change that could not be checked or undone.
	 *
	 * @param string $option   The protected option.
	 * @param bool   $restored Whether it is back as it was.
	 *
	 * @return void
	 */
	private function report( string $option, bool $restored ): void {
		/**
		 * Fires when a protected option changed outside the option API during
		 * an assistant request and was put back, or could not be.
		 *
		 * @since 1.5.0
		 *
		 * @param string $option   The protected option.
		 * @param bool   $restored False when it could not be put back or checked.
		 */
		do_action( 'albert/safe_mode/option_restored', $option, $restored );

		AuditTrail::option_restored( $option, $restored );
	}

	/**
	 * The options tables a statement names, mapped to their blog id.
	 *
	 * @param string $query The SQL.
	 *
	 * @return array<string, int>
	 */
	private function options_tables( string $query ): array {
		global $wpdb;

		$pattern = '/(?<![A-Za-z0-9_$])' . preg_quote( $wpdb->base_prefix, '/' ) . '(?:(\d+)_)?options(?![A-Za-z0-9_$])/i';
		if ( preg_match_all( $pattern, $query, $matches, PREG_SET_ORDER ) === 0 ) {
			return [];
		}

		$tables = [];
		foreach ( $matches as $match ) {
			$blog = isset( $match[1] ) && $match[1] !== '' ? (int) $match[1] : 1;
			if ( $blog !== 1 && ! is_multisite() ) {
				continue;
			}

			$tables[ $blog === 1 ? $wpdb->base_prefix . 'options' : $wpdb->base_prefix . $blog . '_options' ] = $blog;
		}

		return $tables;
	}

	/**
	 * Whether the statement comes straight from a core option function.
	 *
	 * The issuer is the first frame outside wpdb and the hook machinery.
	 *
	 * @return bool
	 */
	private function issued_by_option_api(): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Attribution, not debugging.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			$class = $frame['class'] ?? '';
			if ( $class === self::class || $class === 'wpdb' || $class === 'WP_Hook' || is_subclass_of( $class, 'wpdb' ) ) {
				continue;
			}

			if ( in_array( $frame['function'], [ 'apply_filters', 'apply_filters_ref_array', 'do_action' ], true ) ) {
				continue;
			}

			return $class === '' && in_array( $frame['function'], self::OPTION_API, true );
		}

		return false;
	}

	/**
	 * The protected rows of one options table, keyed by protected name.
	 *
	 * The database matches each name under the column's collation, so a row
	 * stored under another spelling is found as the option it stands for.
	 *
	 * @param string $table Options table.
	 *
	 * @return array<string, array{option_name: string, option_value: string, autoload: string}|null>|null Null when the read fails.
	 */
	private function read( string $table ): ?array {
		global $wpdb;

		$names = ConnectionGuard::protected_names();
		$rows  = array_fill_keys( $names, null );

		$selects = [];
		foreach ( $names as $name ) {
			$selects[] = $wpdb->prepare( 'SELECT %s AS protected, option_name, option_value, autoload FROM %i WHERE option_name = %s', $name, $table, $name );
		}

		$found  = null;
		$failed = true;
		$this->own(
			static function () use ( $wpdb, $selects, &$found, &$failed ): void {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Each part prepared above.
				$found  = $wpdb->get_results( implode( ' UNION ALL ', $selects ), ARRAY_A );
				$failed = $wpdb->last_error !== '';
			}
		);

		if ( $failed || ! is_array( $found ) ) {
			return null;
		}

		foreach ( $found as $row ) {
			$rows[ $row['protected'] ] = [
				'option_name'  => (string) $row['option_name'],
				'option_value' => (string) $row['option_value'],
				'autoload'     => (string) $row['autoload'],
			];
		}

		return $rows;
	}

	/**
	 * Return one protected option to the row it had.
	 *
	 * @param string                                                                  $table  Options table.
	 * @param string                                                                  $option The protected option.
	 * @param array{option_name: string, option_value: string, autoload: string}|null $was    The row before, or null when there was none.
	 *
	 * @return bool Whether it worked.
	 */
	private function put_back( string $table, string $option, ?array $was ): bool {
		global $wpdb;

		$done = false;
		$this->own(
			static function () use ( $wpdb, $table, $option, $was, &$done ): void {
				// Matched under the collation, so a row under any spelling goes.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restoring a protected row; caches flushed by the caller.
				if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, $option ) ) === false ) {
					return;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Restoring a protected row; caches flushed by the caller.
				$done = $was === null || $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $table, $was['option_name'], $was['option_value'], $was['autoload'] ) ) === 1;
			}
		);

		return $done;
	}

	/**
	 * Run this class's own queries, unseen by itself.
	 *
	 * @param callable $work The queries.
	 *
	 * @return void
	 */
	private function own( callable $work ): void {
		global $wpdb;

		$this->busy = true;
		// A failure is reported by return value; printed, it would corrupt the call's JSON response.
		$suppressed = $wpdb->suppress_errors();

		try {
			$work();
		} finally {
			$wpdb->suppress_errors( $suppressed );
			$this->busy = false;
		}
	}

	/**
	 * Drop the cached copies of a restored option on its blog.
	 *
	 * @param int          $blog  Blog id.
	 * @param list<string> $names Option names to forget.
	 *
	 * @return void
	 */
	private function flush_cache( int $blog, array $names ): void {
		$switched = is_multisite() && $blog !== get_current_blog_id() && switch_to_blog( $blog );

		foreach ( array_unique( $names ) as $name ) {
			wp_cache_delete( $name, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		if ( $switched ) {
			restore_current_blog();
		}
	}
}
