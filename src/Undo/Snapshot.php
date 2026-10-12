<?php
/**
 * Reads an entity's current state from the database.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.5.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

use Albert\Undo\Handlers\Registry;

/**
 * The state of one entity, straight from its rows.
 *
 * Raw reads, not `get_post()` or `get_option()`: the object cache can be stale
 * inside a write and filters can rewrite what those functions return. The
 * journal has to hold what is stored.
 *
 * Every read returns a map of field => value, or null when the entity does not
 * exist. Derived columns (term and comment counts) and credentials are left out,
 * so a recount is never mistaken for a change and a password never reaches the
 * journal.
 *
 * @since 1.5.0
 */
final class Snapshot {

	/**
	 * Meta tables by meta type: table property, object column, id column.
	 *
	 * @var array<string, array{0: string, 1: string, 2: string}>
	 */
	public const META_TABLES = [
		'post'    => [ 'postmeta', 'post_id', 'meta_id' ],
		'term'    => [ 'termmeta', 'term_id', 'meta_id' ],
		'user'    => [ 'usermeta', 'user_id', 'umeta_id' ],
		'comment' => [ 'commentmeta', 'comment_id', 'meta_id' ],
	];

	/**
	 * Fields WordPress rewrites on every save, left out when comparing states so
	 * they never look like somebody's edit. Still kept in the before-image.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const VOLATILE = [
		Entity::POST => [ 'post_modified', 'post_modified_gmt' ],
	];

	/**
	 * Build a reader.
	 *
	 * @param Registry $handlers Handler registry, for custom entities.
	 */
	public function __construct( private Registry $handlers ) {}

	/**
	 * Read an entity, on its own blog.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return array<string, mixed>|null Field => value, or null when it does not exist.
	 * @since 1.5.0
	 */
	public function read( Entity $entity ): ?array {
		if ( ! is_multisite() || $entity->blog_id === get_current_blog_id() ) {
			return $this->read_here( $entity );
		}

		switch_to_blog( $entity->blog_id );

		try {
			return $this->read_here( $entity );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * A state as it is compared: volatile fields out, meta by value only (a
	 * rewritten row gets a new meta_id for the same value).
	 *
	 * @param Entity                    $entity The entity.
	 * @param array<string, mixed>|null $state  Its state.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.5.0
	 */
	public static function comparable( Entity $entity, ?array $state ): ?array {
		if ( $state === null ) {
			return null;
		}

		if ( $entity->type === Entity::META ) {
			return [ 'values' => array_column( is_array( $state['values'] ?? null ) ? $state['values'] : [], 'meta_value' ) ];
		}

		return self::without( $state, self::VOLATILE[ $entity->type ] ?? [] );
	}

	/**
	 * A fingerprint of a user's credentials, kept in memory only, so a call that
	 * changes a password is noticed without the password ever being stored.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function credential_fingerprint( int $user_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row, not the cache.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT user_pass, user_activation_key FROM %i WHERE ID = %d', $wpdb->users, $user_id ), ARRAY_A );

		return hash( 'sha256', (string) wp_json_encode( $row ) );
	}

	/**
	 * Read an entity on the current blog.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read_here( Entity $entity ): ?array {
		global $wpdb;

		$parts = $entity->parts;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The journal must read the stored rows, not the cache.
		switch ( $entity->type ) {
			case Entity::POST:
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->posts, (int) $parts[0] ), ARRAY_A );
				return is_array( $row ) ? self::without( $row, [ 'comment_count' ] ) : null;

			case Entity::COMMENT:
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE comment_ID = %d', $wpdb->comments, (int) $parts[0] ), ARRAY_A );
				return is_array( $row ) ? $row : null;

			case Entity::USER:
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->users, (int) $parts[0] ), ARRAY_A );
				return is_array( $row ) ? self::without( $row, Exclusions::USER_COLUMNS ) : null;

			case Entity::OPTION:
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $parts[0] ), ARRAY_A );
				return is_array( $row ) ? $row : null;

			case Entity::TERM:
				$term = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE term_id = %d', $wpdb->terms, (int) $parts[0] ), ARRAY_A );
				if ( ! is_array( $term ) ) {
					return null;
				}
				$taxonomies         = $wpdb->get_results(
					$wpdb->prepare( 'SELECT term_taxonomy_id, taxonomy, description, parent FROM %i WHERE term_id = %d ORDER BY term_taxonomy_id', $wpdb->term_taxonomy, (int) $parts[0] ),
					ARRAY_A
				);
				$term['taxonomies'] = is_array( $taxonomies ) ? $taxonomies : [];
				return $term;

			case Entity::RELATIONSHIPS:
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT tr.term_taxonomy_id, tr.term_order FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY tr.term_taxonomy_id',
						$wpdb->term_relationships,
						$wpdb->term_taxonomy,
						(int) $parts[0],
						$parts[1]
					),
					ARRAY_A
				);
				return is_array( $rows ) && $rows !== [] ? [ 'terms' => $rows ] : null;

			case Entity::META:
				$table = self::META_TABLES[ $parts[0] ] ?? null;
				if ( $table === null ) {
					return null;
				}
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT %i AS meta_id, meta_value FROM %i WHERE %i = %d AND meta_key = %s ORDER BY %i',
						$table[2],
						$wpdb->{$table[0]},
						$table[1],
						(int) $parts[1],
						$parts[2],
						$table[2]
					),
					ARRAY_A
				);
				return is_array( $rows ) && $rows !== [] ? [ 'values' => $rows ] : null;

			case Entity::CUSTOM:
				$handler = $this->handlers->get( $parts[0] );
				return $handler !== null ? $handler->read( $parts[1] ?? '' ) : null;
		}
		// phpcs:enable

		return null;
	}

	/**
	 * Drop columns from a row.
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param array<int, string>   $columns Columns to drop.
	 *
	 * @return array<string, mixed>
	 */
	private static function without( array $row, array $columns ): array {
		return array_diff_key( $row, array_flip( $columns ) );
	}
}
