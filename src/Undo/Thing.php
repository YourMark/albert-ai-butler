<?php
/**
 * What a person thinks of as one changed thing.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.7.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

/**
 * The thing an entity belongs to: a post with its meta and terms, a term with
 * its meta, a user with their meta, a comment, an option, a handler's key.
 *
 * Entities are what a revert puts back; a thing is what the owner sees as one
 * row. A page's title, featured image and categories are three entities and
 * one thing.
 *
 * @since 1.7.0
 */
final class Thing {

	public const POST    = 'post';
	public const TERM    = 'term';
	public const USER    = 'user';
	public const COMMENT = 'comment';
	public const OPTION  = 'option';
	public const CUSTOM  = 'custom';

	/**
	 * Build a thing reference.
	 *
	 * @param string $type    One of the class constants.
	 * @param string $id      Its id within the type; `{handler}:{key}` for a custom thing.
	 * @param int    $blog_id The blog it lives on.
	 */
	private function __construct(
		public readonly string $type,
		public readonly string $id,
		public readonly int $blog_id,
	) {}

	/**
	 * The thing an entity belongs to.
	 *
	 * Term relationships roll up into a post: the only objects core's own
	 * screens assign terms to.
	 *
	 * @param Entity $entity The entity.
	 *
	 * @return Thing
	 * @since 1.7.0
	 */
	public static function for( Entity $entity ): Thing {
		$parts = $entity->parts;

		[ $type, $id ] = match ( $entity->type ) {
			Entity::META          => [ $parts[0], $parts[1] ?? '' ],
			Entity::RELATIONSHIPS => [ self::POST, $parts[0] ],
			Entity::CUSTOM        => [ self::CUSTOM, implode( ':', $parts ) ],
			default               => [ $entity->type, $parts[0] ],
		};

		return new self( $type, $id, $entity->blog_id );
	}

	/**
	 * The key, `{type}:{id}`, as the owner's history is looked up by.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function key(): string {
		return $this->type . ':' . $this->id;
	}

	/**
	 * A fixed-length hash of the key, for the index. Includes the blog, so a
	 * call that switched blogs never shares a thing with this blog's post of
	 * the same id.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	public function hash(): string {
		return hash( 'sha256', $this->blog_id . ':' . $this->key() );
	}
}
