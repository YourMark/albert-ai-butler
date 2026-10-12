<?php
/**
 * Change-journal handler registry.
 *
 * @package Albert
 * @subpackage Undo\Handlers
 * @since      1.5.0
 */

namespace Albert\Undo\Handlers;

defined( 'ABSPATH' ) || exit;

use Albert\Database\Tables;
use Albert\Undo\Entity;

/**
 * The handlers registered through `albert/undo/handlers`, resolved once per window.
 *
 * @since 1.5.0
 */
final class Registry {

	/**
	 * Handlers by id.
	 *
	 * @var array<string, Handler>
	 */
	private array $handlers = [];

	/**
	 * Ids of handlers dropped for failing.
	 *
	 * @var array<int, string>
	 */
	private array $dropped = [];

	/**
	 * Collect handlers from the filter. Anything that is not a {@see Handler}, or
	 * whose id is empty or holds a colon, is ignored.
	 *
	 * @return Registry
	 * @since 1.5.0
	 */
	public static function load(): Registry {
		$registry = new self();

		/**
		 * Filters the change-journal handlers for custom tables.
		 *
		 * A handler captures and restores a plugin's own tables through that
		 * plugin's API. Its tables then count as captured instead of as a gap in
		 * an entry's coverage. See {@see Handler}.
		 *
		 * @since 1.5.0
		 *
		 * @param array<int, Handler> $handlers Registered handlers.
		 */
		$handlers = apply_filters( 'albert/undo/handlers', [] );

		foreach ( is_array( $handlers ) ? $handlers : [] as $handler ) {
			if ( ! $handler instanceof Handler ) {
				continue;
			}

			$id = $handler->id();
			if ( $id === '' || str_contains( $id, ':' ) ) {
				continue;
			}

			$registry->handlers[ $id ] = $handler;
		}

		return $registry;
	}

	/**
	 * Every handler.
	 *
	 * @return array<string, Handler>
	 * @since 1.5.0
	 */
	public function all(): array {
		return $this->handlers;
	}

	/**
	 * One handler by id.
	 *
	 * @param string $id Handler id.
	 *
	 * @return Handler|null
	 * @since 1.5.0
	 */
	public function get( string $id ): ?Handler {
		return $this->handlers[ $id ] ?? null;
	}

	/**
	 * Drop a handler for the rest of the window, after it failed. Its tables are
	 * then no longer covered, so its writes are noted instead of trusted.
	 *
	 * @param string $id Handler id.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function drop( string $id ): void {
		if ( isset( $this->handlers[ $id ] ) ) {
			$this->dropped[] = $id;
		}

		unset( $this->handlers[ $id ] );
	}

	/**
	 * Handlers dropped for failing. Their tables may already have been trusted
	 * as covered, so the entry notes them.
	 *
	 * @return array<int, string>
	 * @since 1.5.0
	 */
	public function dropped(): array {
		return $this->dropped;
	}

	/**
	 * Whether a table is captured or declared derived by a handler.
	 *
	 * WordPress's and Albert's own tables are never a handler's to declare: a
	 * handler naming one would hide writes the journal accounts for itself.
	 *
	 * @param string $table Table name, prefixed.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function covers_table( string $table ): bool {
		if ( self::reserved( $table ) ) {
			return false;
		}

		foreach ( $this->handlers as $id => $handler ) {
			try {
				if ( self::in( $table, $handler->tables() ) || self::in( $table, $handler->derived_tables() ) ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				$this->drop( $id );
			}
		}

		return false;
	}

	/**
	 * The id of the handler claiming an entity, if any.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return string|null
	 * @since 1.5.0
	 */
	public function claimant( Entity $entity ): ?string {
		if ( $entity->type === Entity::CUSTOM ) {
			return null;
		}

		foreach ( $this->handlers as $id => $handler ) {
			try {
				if ( $handler->claims( $entity ) ) {
					return $id;
				}
			} catch ( \Throwable $e ) {
				$this->drop( $id );
			}
		}

		return null;
	}

	/**
	 * Whether a table belongs to WordPress core or to Albert.
	 *
	 * @param string $table Table name, prefixed.
	 *
	 * @return bool
	 */
	private static function reserved( string $table ): bool {
		global $wpdb;

		$table = strtolower( $table );

		// Every blog's core tables, not only this one's: `wp_2_posts` is core too.
		$core = implode( '|', array_map( 'preg_quote', array_values( $wpdb->tables( 'all', false ) ) ) );
		if ( preg_match( '/^' . preg_quote( strtolower( $wpdb->base_prefix ), '/' ) . '(?:\d+_)?(?:' . $core . ')$/', $table ) ) {
			return true;
		}

		foreach ( array_merge( array_values( $wpdb->tables( 'all' ) ), Tables::all() ) as $name ) {
			if ( strtolower( (string) $name ) === $table ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match a prefixed table against unprefixed names (or already-prefixed ones).
	 *
	 * @param string            $table Prefixed table name.
	 * @param array<int, mixed> $names Declared names.
	 *
	 * @return bool
	 */
	private static function in( string $table, array $names ): bool {
		global $wpdb;

		foreach ( $names as $name ) {
			if ( is_string( $name ) && ( $table === $name || $table === $wpdb->prefix . $name || $table === $wpdb->base_prefix . $name ) ) {
				return true;
			}
		}

		return false;
	}
}
