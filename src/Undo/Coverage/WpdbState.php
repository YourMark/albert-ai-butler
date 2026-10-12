<?php
/**
 * Keeps wpdb's per-query state intact across a read made inside its `query` filter.
 *
 * @package Albert
 * @subpackage Undo\Coverage
 * @since      1.5.0
 */

namespace Albert\Undo\Coverage;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the journal's own queries without the caller being able to tell.
 *
 * The journal reads between a caller's statements, and the caller then reads
 * `insert_id`, `rows_affected` or `last_error`, so all of it is put back. So is
 * the protected `check_current_query` flag: a nested query re-arms it, which
 * would make wpdb refuse a valid insert the caller already checked per field.
 *
 * @since 1.5.0
 */
final class WpdbState {

	/**
	 * Public per-query properties callers read after a query.
	 *
	 * @var array<int, string>
	 */
	private const STATE = [ 'insert_id', 'last_query', 'last_error', 'rows_affected', 'num_rows', 'last_result' ];

	/**
	 * Run the journal's own queries and put wpdb's per-query state back afterwards.
	 *
	 * @template T
	 * @param callable(): T $read The queries.
	 *
	 * @return T
	 * @since 1.5.0
	 */
	public static function preserve( callable $read ) {
		global $wpdb;

		$flag = \Closure::bind(
			static function ( \wpdb $db, ?bool $value = null ): bool {
				if ( $value !== null ) {
					$db->check_current_query = $value;
				}
				return (bool) $db->check_current_query;
			},
			null,
			\wpdb::class
		);

		$check = $flag( $wpdb );
		$saved = [];
		foreach ( self::STATE as $property ) {
			$saved[ $property ] = $wpdb->$property;
		}

		try {
			return $read();
		} finally {
			$flag( $wpdb, $check );
			foreach ( $saved as $property => $value ) {
				$wpdb->$property = $value;
			}
		}
	}
}
