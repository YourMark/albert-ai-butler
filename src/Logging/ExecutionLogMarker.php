<?php
/**
 * Execution Log Marker
 *
 * @package Albert
 * @subpackage Logging
 * @since      1.2.0
 */

namespace Albert\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * ExecutionLogMarker class
 *
 * Request-scoped record of which abilities already had a log row written, so
 * an add-on that logs one call from more than one place writes it once.
 *
 * Free has had one writer per call since 1.6.0 and no longer uses it; Premium does.
 *
 * @since 1.2.0
 */
class ExecutionLogMarker {

	/**
	 * Ability names already logged this request.
	 *
	 * @since 1.2.0
	 * @var array<string, true>
	 */
	private static array $logged = [];

	/**
	 * The last log row written per ability this request.
	 *
	 * @since 1.5.0
	 * @var array<string, int>
	 */
	private static array $rows = [];

	/**
	 * Mark an ability as logged for the current request.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function mark( string $ability_name ): void {
		if ( $ability_name !== '' ) {
			self::$logged[ $ability_name ] = true;
		}
	}

	/**
	 * Whether an ability has already been logged this request.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return bool True when a row was already written for this ability.
	 * @since 1.2.0
	 */
	public static function has( string $ability_name ): bool {
		return isset( self::$logged[ $ability_name ] );
	}

	/**
	 * Remember the log row written for an ability, so the change journal can
	 * link its entry for the same call.
	 *
	 * @param string $ability_name The ability identifier.
	 * @param int    $row_id       The log row id.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function remember_row( string $ability_name, int $row_id ): void {
		if ( $ability_name !== '' && $row_id > 0 ) {
			self::$rows[ $ability_name ] = $row_id;
		}
	}

	/**
	 * The last log row written for an ability this request.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return int|null
	 * @since 1.5.0
	 */
	public static function row_id( string $ability_name ): ?int {
		return self::$rows[ $ability_name ] ?? null;
	}

	/**
	 * Forget the log row remembered for an ability, so a call that writes none
	 * is not linked to an earlier call's row in the same request.
	 *
	 * @param string $ability_name The ability identifier.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function forget_row( string $ability_name ): void {
		unset( self::$rows[ $ability_name ] );
	}

	/**
	 * Reset the marker. Primarily for test isolation.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function reset(): void {
		self::$logged = [];
		self::$rows   = [];
	}
}
