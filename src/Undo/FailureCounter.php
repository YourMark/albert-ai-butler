<?php
/**
 * Counts the journal's own failures.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.5.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

/**
 * How often recording failed, for the Changes screen to report (issue 06 PR 3).
 *
 * The journal swallows its own errors to keep the call alive, which would make
 * "nothing was recorded" look exactly like "nothing changed". This keeps a count
 * and the time of the last failure in an option. Written only once the window
 * has closed, so the write is never recorded as part of a call.
 *
 * @since 1.5.0
 */
final class FailureCounter {

	/**
	 * Option holding `{ count, last_at }`.
	 *
	 * @var string
	 */
	public const OPTION = 'albert_undo_failures';

	/**
	 * Add failures.
	 *
	 * @param int $count How many.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function record( int $count ): void {
		try {
			$current = self::get();

			update_option(
				self::OPTION,
				[
					'count'   => $current['count'] + max( 1, $count ),
					'last_at' => gmdate( 'Y-m-d H:i:s' ),
				],
				false
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The count so far and when the last failure happened (UTC).
	 *
	 * @return array{count: int, last_at: string|null}
	 * @since 1.5.0
	 */
	public static function get(): array {
		$value = get_option( self::OPTION, [] );

		return [
			'count'   => is_array( $value ) ? (int) ( $value['count'] ?? 0 ) : 0,
			'last_at' => is_array( $value ) && is_string( $value['last_at'] ?? null ) ? $value['last_at'] : null,
		];
	}
}
