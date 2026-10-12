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
 * Reads the target table of every query while a call is recorded, and notes
 * what the capture hooks cannot have seen.
 *
 * A write to WordPress's own tables is covered only when it comes from one of
 * the core functions in {@see self::COVERED_WRITERS}, which fire the hooks
 * that take the before-image. Anything else writing those tables, like
 * WooCommerce's atomic stock update, is noted with the function that wrote it.
 * Any other table, or a query it cannot read, is noted too.
 *
 * It never changes, delays or blocks a query, and does no database work itself.
 *
 * @since 1.5.0
 */
final class QueryMonitor {

	/**
	 * Core functions whose writes to WordPress's tables the capture hooks cover,
	 * or that only update derived counts.
	 *
	 * @var array<int, string>
	 */
	private const COVERED_WRITERS = [
		'add_metadata',
		'update_metadata',
		'delete_metadata',
		'update_metadata_by_mid',
		'delete_metadata_by_mid',
		'add_option',
		'update_option',
		'delete_option',
		'wp_set_option_autoload_values',
		'wp_insert_post',
		'_transition_post_status',
		'wp_delete_post',
		'wp_delete_attachment',
		'wp_update_comment_count_now',
		'wp_insert_term',
		'wp_update_term',
		'wp_delete_term',
		'wp_set_object_terms',
		'wp_remove_object_terms',
		'_update_post_term_count',
		'_update_generic_term_count',
		'wp_insert_user',
		'wp_delete_user',
		'wpmu_delete_user',
		'wp_insert_comment',
		'wp_update_comment',
		'wp_delete_comment',
		'wp_trash_post_comments',
		'wp_untrash_post_comments',
	];

	/**
	 * Core writers covered only when called from a given function: the trashed
	 * suffix is captured when `wp_insert_post()` trashes the post itself, but
	 * not when it renames another trashed post that wanted the same slug.
	 *
	 * @var array<string, string>
	 */
	private const COVERED_WHEN_CALLED_BY = [
		'wp_add_trashed_suffix_to_post_name_for_post' => 'wp_insert_post',
	];

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

		if ( $target->structure ) {
			$this->recorder->untracked( $table );
			return;
		}

		if ( Exclusions::own_table( $table ) ) {
			return;
		}

		if ( self::captured_core_table( $table ) ) {
			$writer = self::writer();
			if ( $writer !== null && ! self::is_transient_write( $table, $query ) ) {
				$this->recorder->direct_write( $table, $writer );
			}
			return;
		}

		if ( $this->handlers->covers_table( $table ) ) {
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
	 * Who issued the current query, unless it is a covered core function.
	 *
	 * The caller is the frame just outside wpdb's own methods (or a subclass's,
	 * like a database drop-in), so a direct write made from a hook inside
	 * `wp_insert_post()` is still attributed to the plugin that made it.
	 *
	 * @return string|null `Class::method() (plugin)`, or null when covered.
	 */
	private static function writer(): ?string {
		$frames  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Attributes a direct write to its caller.
		$in_wpdb = false;
		$file    = '';

		foreach ( $frames as $i => $frame ) {
			if ( isset( $frame['class'] ) && is_a( $frame['class'], 'wpdb', true ) ) {
				$in_wpdb = true;
				$file    = (string) ( $frame['file'] ?? '' );
				continue;
			}

			if ( ! $in_wpdb ) {
				continue;
			}

			$function = $frame['function'];
			$class    = $frame['class'] ?? '';

			if ( $class === '' && self::is_core_file( $file ) ) {
				if ( in_array( $function, self::COVERED_WRITERS, true ) ) {
					return null;
				}
				if ( isset( self::COVERED_WHEN_CALLED_BY[ $function ] ) && ( $frames[ $i + 1 ]['function'] ?? '' ) === self::COVERED_WHEN_CALLED_BY[ $function ] ) {
					return null;
				}
			}

			return ( $class !== '' ? "{$class}::{$function}()" : "{$function}()" ) . self::origin( $file );
		}

		return 'an unknown caller';
	}

	/**
	 * Whether a file is part of WordPress itself.
	 *
	 * @param string $file Path.
	 *
	 * @return bool
	 */
	private static function is_core_file( string $file ): bool {
		$file = self::real( $file );

		return str_starts_with( $file, self::real( ABSPATH . 'wp-includes' ) . '/' ) || str_starts_with( $file, self::real( ABSPATH . 'wp-admin' ) . '/' );
	}

	/**
	 * A normalised real path, so a symlinked install compares equal to its files.
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 */
	private static function real( string $path ): string {
		$real = realpath( $path );

		return wp_normalize_path( $real === false ? $path : $real );
	}

	/**
	 * Where a file comes from, for the note: ` (woocommerce)`, ` (theme)`.
	 *
	 * @param string $file Path.
	 *
	 * @return string
	 */
	private static function origin( string $file ): string {
		$file = self::real( $file );

		foreach ( [ WP_PLUGIN_DIR, WPMU_PLUGIN_DIR ] as $dir ) {
			$plugins = glob( wp_normalize_path( $dir ) . '/*', GLOB_ONLYDIR );
			foreach ( is_array( $plugins ) ? $plugins : [] as $plugin ) {
				if ( str_starts_with( $file, self::real( $plugin ) . '/' ) ) {
					return ' (' . basename( $plugin ) . ')';
				}
			}
		}

		if ( str_starts_with( $file, self::real( get_theme_root() ) . '/' ) ) {
			return ' (theme)';
		}

		return self::is_core_file( $file ) ? ' (WordPress)' : '';
	}

	/**
	 * Whether a direct `options` write is to transients, which are noise however they are written.
	 *
	 * @param string $table Table name.
	 * @param string $query The query.
	 *
	 * @return bool
	 */
	private static function is_transient_write( string $table, string $query ): bool {
		global $wpdb;

		return strcasecmp( $table, $wpdb->options ) === 0 && str_contains( $query, '_transient_' );
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
