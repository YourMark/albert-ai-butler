<?php
/**
 * The recording window.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.7.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

use Albert\Cron\ChangeJournalPrune;
use Albert\Undo\Handlers\Registry;
use Albert\Undo\Storage\Repository;

/**
 * Opens and closes recording around one assistant call. Request-scoped.
 *
 * One window at a time: a second open while one is running returns false and
 * the inner work is recorded as part of the outer call. If the call dies before
 * its window closes, `shutdown` closes it and marks it interrupted, so a fatal
 * never leaves the journal listening or an entry stuck as "recording".
 *
 * @since 1.7.0
 */
final class Window {

	/**
	 * The open window's recorder.
	 *
	 * @var Recorder|null
	 */
	private static ?Recorder $recorder = null;

	/**
	 * The open window's source.
	 *
	 * @var string|null
	 */
	private static ?string $source = null;

	/**
	 * The blog the open window started on, whose journal it writes to.
	 *
	 * @var int
	 */
	private static int $blog_id = 0;

	/**
	 * Entries closed this request, announced at shutdown: `[ entry id, blog id ]`.
	 *
	 * @var array<int, array{0: int, 1: int}>
	 */
	private static array $recorded = [];

	/**
	 * Whether the shutdown fallback is registered.
	 *
	 * @var bool
	 */
	private static bool $shutdown_hooked = false;

	/**
	 * Open a window.
	 *
	 * @param WindowContext $context The call.
	 *
	 * @return bool False when a window is already open.
	 * @since 1.7.0
	 */
	public static function open( WindowContext $context ): bool {
		if ( self::$recorder !== null ) {
			return false;
		}

		try {
			$handlers = Registry::load();
		} catch ( \Throwable $e ) {
			// A filter that throws costs the handlers, never the recording.
			$handlers = new Registry();
		}

		try {
			self::$blog_id = get_current_blog_id();
			$recorder      = new Recorder( $context, new Repository( self::$blog_id ), $handlers, new Snapshot( $handlers ) );
			$recorder->start();
		} catch ( \Throwable $e ) {
			// Abandon first, so the counter's own option write is not recorded.
			if ( isset( $recorder ) ) {
				self::abandon( $recorder );
			}
			FailureCounter::record( 1 );
			self::not_recorded( $context );
			return false;
		}

		self::$recorder = $recorder;
		self::$source   = $context->source;

		if ( ! self::$shutdown_hooked ) {
			// Early, so other plugins' shutdown writes are not recorded as the call's.
			add_action( 'shutdown', [ self::class, 'interrupt' ], PHP_INT_MIN );
			self::$shutdown_hooked = true;
		}

		return true;
	}

	/**
	 * Close the open window, if any.
	 *
	 * @param string $status Recorder::STATUS_RECORDED or STATUS_INTERRUPTED.
	 *
	 * @return int|null The entry id, or null when nothing was recorded.
	 * @since 1.7.0
	 */
	public static function close( string $status = Recorder::STATUS_RECORDED ): ?int {
		$recorder = self::$recorder;

		self::$recorder = null;
		self::$source   = null;

		if ( $recorder === null ) {
			return null;
		}

		try {
			$entry_id = $recorder->finish( $status );
			if ( $entry_id !== null ) {
				ChangeJournalPrune::soon( self::$blog_id );
				self::queue( $entry_id );
			}
			return $entry_id;
		} catch ( \Throwable $e ) {
			// Recording is a safety net for the call, never a reason for it to fail.
			FailureCounter::record( 1 );
			return null;
		}
	}

	/**
	 * List a call whose recording could not start, unless it only reads.
	 *
	 * @param WindowContext $context The call.
	 *
	 * @return void
	 */
	private static function not_recorded( WindowContext $context ): void {
		$ability     = function_exists( 'wp_get_ability' ) ? wp_get_ability( $context->ability_name ) : null;
		$annotations = $ability !== null ? $ability->get_meta_item( 'annotations', [] ) : [];
		if ( is_array( $annotations ) && ( $annotations['readonly'] ?? null ) === true ) {
			return;
		}

		try {
			( new Repository( get_current_blog_id() ) )->not_recorded( $context );
		} catch ( \Throwable $e ) {
			// Counted above; nothing more can be said.
			unset( $e );
		}
	}

	/**
	 * Detach a recorder that failed to start, without recording anything.
	 *
	 * @param Recorder $recorder The recorder.
	 *
	 * @return void
	 */
	private static function abandon( Recorder $recorder ): void {
		try {
			$recorder->finish( Recorder::STATUS_INTERRUPTED );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Announce an entry once the request is done with it.
	 *
	 * @param int $entry_id The entry.
	 *
	 * @return void
	 */
	private static function queue( int $entry_id ): void {
		if ( self::$recorded === [] ) {
			// After every window is closed, so observers' own writes are never recorded.
			add_action( 'shutdown', [ self::class, 'announce' ], PHP_INT_MAX - 1 );
		}

		self::$recorded[] = [ $entry_id, self::$blog_id ];
	}

	/**
	 * Fire `albert/changes/recorded` for every entry closed this request.
	 * Bound to `shutdown`; an observer that throws never stops the next one.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public static function announce(): void {
		$recorded       = self::$recorded;
		self::$recorded = [];
		remove_action( 'shutdown', [ self::class, 'announce' ], PHP_INT_MAX - 1 );

		foreach ( $recorded as [ $entry_id, $blog_id ] ) {
			try {
				$view = ( new Repository( $blog_id ) )->view( $entry_id );
				if ( $view === null ) {
					continue;
				}

				/**
				 * Fires once a change journal entry is written, at the end of
				 * the request and outside any recording window.
				 *
				 * There is no ChangeView class yet: `$change` is the array
				 * {@see Repository::view()} documents.
				 *
				 * @since 1.7.0
				 *
				 * @param array<string, mixed> $change  The entry.
				 * @param array<string, mixed> $context `blog_id` and `source`.
				 */
				do_action(
					'albert/changes/recorded',
					$view,
					[
						'blog_id' => $blog_id,
						'source'  => $view['source'],
					]
				);
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * Close a window the call never closed. Bound to `shutdown`.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public static function interrupt(): void {
		self::close( Recorder::STATUS_INTERRUPTED );
	}

	/**
	 * The open window's source, or null when none is open.
	 *
	 * @return string|null
	 * @since 1.7.0
	 */
	public static function source(): ?string {
		return self::$source;
	}

	/**
	 * The open window's recorder, for handlers.
	 *
	 * @return Recorder|null
	 * @since 1.7.0
	 */
	public static function recorder(): ?Recorder {
		return self::$recorder;
	}
}
