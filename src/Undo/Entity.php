<?php
/**
 * A recorded entity.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.5.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

/**
 * One thing the journal keeps a before-image of, named in WordPress's own terms.
 *
 * The unit is what a revert puts back as a whole: a post row, every value of one
 * meta key on one object, a term with its taxonomy rows, the terms one object
 * holds in one taxonomy, an option, a user, a comment. Menus, widgets and theme
 * mods are not types of their own, they are these.
 *
 * Carries its blog, so on multisite a call that switches blogs never reads or
 * compares another blog's row under the same key.
 *
 * @since 1.5.0
 */
final class Entity {

	public const POST          = 'post';
	public const META          = 'meta';
	public const TERM          = 'term';
	public const RELATIONSHIPS = 'relationships';
	public const OPTION        = 'option';
	public const USER          = 'user';
	public const COMMENT       = 'comment';
	public const CUSTOM        = 'custom';

	private const TYPES = [ self::POST, self::META, self::TERM, self::RELATIONSHIPS, self::OPTION, self::USER, self::COMMENT, self::CUSTOM ];

	/**
	 * Build an entity reference.
	 *
	 * @param string             $type    One of the class constants.
	 * @param array<int, string> $parts   Identifying parts, most significant first.
	 * @param int                $blog_id The blog it lives on.
	 */
	private function __construct(
		public readonly string $type,
		public readonly array $parts,
		public readonly int $blog_id,
	) {}

	/**
	 * A post row (any post type).
	 *
	 * @param int $id Post ID.
	 *
	 * @return Entity
	 */
	public static function post( int $id ): Entity {
		return self::here( self::POST, [ (string) $id ] );
	}

	/**
	 * Every value of one meta key on one object.
	 *
	 * @param string $meta_type post, term, user or comment.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Meta key.
	 *
	 * @return Entity
	 */
	public static function meta( string $meta_type, int $object_id, string $meta_key ): Entity {
		return self::here( self::META, [ $meta_type, (string) $object_id, $meta_key ] );
	}

	/**
	 * A term and its term_taxonomy rows.
	 *
	 * @param int $term_id Term ID.
	 *
	 * @return Entity
	 */
	public static function term( int $term_id ): Entity {
		return self::here( self::TERM, [ (string) $term_id ] );
	}

	/**
	 * The terms one object holds in one taxonomy.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $taxonomy  Taxonomy.
	 *
	 * @return Entity
	 */
	public static function relationships( int $object_id, string $taxonomy ): Entity {
		return self::here( self::RELATIONSHIPS, [ (string) $object_id, $taxonomy ] );
	}

	/**
	 * An option.
	 *
	 * @param string $name Option name.
	 *
	 * @return Entity
	 */
	public static function option( string $name ): Entity {
		return self::here( self::OPTION, [ $name ] );
	}

	/**
	 * A user row, without credentials. Users are network-wide, but the blog is
	 * still recorded: it is where the call ran.
	 *
	 * @param int $id User ID.
	 *
	 * @return Entity
	 */
	public static function user( int $id ): Entity {
		return self::here( self::USER, [ (string) $id ] );
	}

	/**
	 * A comment row.
	 *
	 * @param int $id Comment ID.
	 *
	 * @return Entity
	 */
	public static function comment( int $id ): Entity {
		return self::here( self::COMMENT, [ (string) $id ] );
	}

	/**
	 * Something a registered handler captures and reads itself.
	 *
	 * @param string $handler Handler id.
	 * @param string $key     The handler's own key.
	 *
	 * @return Entity
	 */
	public static function custom( string $handler, string $key ): Entity {
		return self::here( self::CUSTOM, [ $handler, $key ] );
	}

	/**
	 * Rebuild an entity from its stored type, key and blog.
	 *
	 * @param string $type    Stored type.
	 * @param string $key     Stored key, as {@see self::key()} produced it.
	 * @param int    $blog_id Stored blog.
	 *
	 * @return Entity|null
	 */
	public static function from_key( string $type, string $key, int $blog_id ): ?Entity {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return null;
		}

		$limits = [
			self::META          => 3,
			self::CUSTOM        => 2,
			self::RELATIONSHIPS => 2,
		];

		return new self( $type, explode( ':', $key, $limits[ $type ] ?? 1 ), $blog_id );
	}

	/**
	 * The stored key. The free-text part (meta key, option name, handler key) is
	 * always last, so a colon inside it cannot shift the others.
	 *
	 * @return string
	 */
	public function key(): string {
		return implode( ':', $this->parts );
	}

	/**
	 * A key unique across types and blogs, for de-duplicating within a window.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->blog_id . ':' . $this->type . ':' . $this->key();
	}

	/**
	 * An entity on the current blog.
	 *
	 * @param string             $type  Type.
	 * @param array<int, string> $parts Parts.
	 *
	 * @return Entity
	 */
	private static function here( string $type, array $parts ): Entity {
		return new self( $type, $parts, get_current_blog_id() );
	}
}
