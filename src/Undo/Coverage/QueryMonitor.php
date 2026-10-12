<?php
/**
 * Notes writes the capture hooks cannot see.
 *
 * @package Albert
 * @subpackage Undo\Coverage
 * @since      1.5.0
 */

namespace Albert\Undo\Coverage;

defined( 'ABSPATH' ) || exit;

use Albert\Undo\Exclusions;
use Albert\Undo\Handlers\Registry;
use Albert\Undo\Recorder;

/**
 * Reads the target table of every query while a call is recorded.
 *
 * WordPress's own tables are covered by the capture hooks. Any other table, or
 * a query whose target cannot be read, is noted on the entry, so it is never
 * offered for revert as if it were complete. Only the table name is read, never
 * the rows: knowing which table was written is enough to be honest, and a full
 * parser is a large surface to keep correct for writes Albert's abilities never
 * make.
 *
 * Known gap, accepted: a few WordPress internals write their own tables without
 * a hook (`wp_publish_post()`, `wp_set_comment_status()`, reparenting on
 * delete). None of them is something an ability does on its own.
 *
 * It never changes, delays or blocks a query, and does no database work itself.
 *
 * @since 1.5.0
 */
final class QueryMonitor {

	/**
	 * The attached filter callback.
	 *
	 * @var callable|null
	 */
	private $callback = null;

	/**
	 * Build a monitor.
	 *
	 * @param Recorder $recorder The recorder to report to.
	 * @param Registry $handlers Custom-table handlers.
	 */
	public function __construct( private Recorder $recorder, private Registry $handlers ) {}

	/**
	 * Attach, last, so the monitor sees the query that will actually run.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function attach(): void {
		$this->callback = [ $this, 'filter' ];
		add_filter( 'query', $this->callback, PHP_INT_MAX );
	}

	/**
	 * Detach.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function detach(): void {
		if ( $this->callback !== null ) {
			remove_filter( 'query', $this->callback, PHP_INT_MAX );
			$this->callback = null;
		}
	}

	/**
	 * The `query` filter.
	 *
	 * @param mixed $query The query about to run.
	 *
	 * @return mixed The same query.
	 * @since 1.5.0
	 */
	public function filter( $query ) {
		if ( ! is_string( $query ) || $this->recorder->is_busy() ) {
			return $query;
		}

		try {
			$this->inspect( $query );
		} catch ( \Throwable $e ) {
			$this->recorder->untracked( Recorder::UNREADABLE );
		}

		return $query;
	}

	/**
	 * Note one query's target, if it is not covered.
	 *
	 * @param string $query The query.
	 *
	 * @return void
	 */
	private function inspect( string $query ): void {
		$target = WriteTarget::of( $query );

		if ( $target->kind === WriteTarget::READ ) {
			return;
		}

		if ( ! $target->is_write() || $target->table === null ) {
			$this->recorder->untracked( Recorder::UNREADABLE );
			return;
		}

		$table = $target->table;

		// The journal's own writes run while it is busy, so one seen here was
		// made by somebody else during the call: tamper evidence.
		if ( Exclusions::journal_table( $table ) ) {
			$this->recorder->untracked( $table );
			return;
		}

		if ( Exclusions::own_table( $table ) ) {
			return;
		}

		if ( $target->structure ) {
			$this->recorder->untracked( $table );
			return;
		}

		if ( self::captured_core_table( $table ) || $this->handlers->covers_table( $table ) ) {
			return;
		}

		if ( self::is_network_noise( $table, $query ) ) {
			return;
		}

		$this->recorder->untracked( $table );
	}

	/**
	 * Whether a table is one of WordPress's own whose writes the hooks capture.
	 *
	 * @param string $table Table name.
	 *
	 * @return bool
	 */
	private static function captured_core_table( string $table ): bool {
		global $wpdb;

		$covered = [ $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta, $wpdb->options, $wpdb->comments, $wpdb->commentmeta, $wpdb->users, $wpdb->usermeta ];

		return in_array( strtolower( $table ), array_map( 'strtolower', $covered ), true );
	}

	/**
	 * Whether a multisite `sitemeta` write is a site transient. Network options
	 * are not captured, but their transients are noise as options' are.
	 *
	 * @param string $table Table name.
	 * @param string $query The query.
	 *
	 * @return bool
	 */
	private static function is_network_noise( string $table, string $query ): bool {
		global $wpdb;

		return is_multisite() && strcasecmp( $table, $wpdb->sitemeta ) === 0 && str_contains( $query, '_site_transient_' );
	}
}
