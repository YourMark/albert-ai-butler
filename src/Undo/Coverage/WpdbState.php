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
 * The journal queries between a caller's statements (in a before-write hook,
 * or inside wpdb's `query` filter), and code reads wpdb's per-query state
 * afterwards: `insert_id` after an insert, `rows_affected`, `last_error`. All
 * of it is put back. So is one protected flag: `wpdb::insert()`/`update()`
 * switch off the whole-query charset check (`check_current_query`) because
 * they already checked each field, and every completed query switches it back
 * on, so a read nested in the filter would re-arm a check the caller disabled
 * and a valid insert could be refused. wpdb's magic setter refuses that flag,
 * so it is restored through a closure bound to wpdb's scope.
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
