<?php
/**
 * The recording window.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.5.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

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
 * @since 1.5.0
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
	 * @since 1.5.0
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
			$recorder = new Recorder( $context, new Repository(), $handlers, new Snapshot( $handlers ) );
			$recorder->start();
		} catch ( \Throwable $e ) {
			// Abandon first, so the counter's own option write is not recorded.
			if ( isset( $recorder ) ) {
				self::abandon( $recorder );
			}
			FailureCounter::record( 1 );
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
	 * @since 1.5.0
	 */
	public static function close( string $status = Recorder::STATUS_RECORDED ): ?int {
		$recorder = self::$recorder;

		self::$recorder = null;
		self::$source   = null;

		if ( $recorder === null ) {
			return null;
		}

		try {
			return $recorder->finish( $status );
		} catch ( \Throwable $e ) {
			// Recording is a safety net for the call, never a reason for it to fail.
			FailureCounter::record( 1 );
			return null;
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
	 * Close a window the call never closed. Bound to `shutdown`.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function interrupt(): void {
		self::close( Recorder::STATUS_INTERRUPTED );
	}

	/**
	 * The open window's source, or null when none is open.
	 *
	 * @return string|null
	 * @since 1.5.0
	 */
	public static function source(): ?string {
		return self::$source;
	}

	/**
	 * The open window's recorder, for handlers.
	 *
	 * @return Recorder|null
	 * @since 1.5.0
	 */
	public static function recorder(): ?Recorder {
		return self::$recorder;
	}
}
