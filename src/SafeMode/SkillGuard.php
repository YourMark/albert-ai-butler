<?php
/**
 * Refuses an assistant's writes to the owner's skills
 *
 * @package Albert
 * @subpackage SafeMode
 * @since      1.7.0
 */

namespace Albert\SafeMode;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use Albert\MCP\Skills\OwnerSkills;
use Albert\OAuth\Server\ConnectionContext;

/**
 * Over a connection, no route writes, trashes or deletes an `albert_skill` post or its meta.
 *
 * Skills are guidance the assistant follows in every conversation. One that
 * could change its own guidance could keep an injected instruction for good,
 * so this is refused, not held: approving it would still be the assistant
 * editing what governs it. The switch is a protected option
 * ({@see ConnectionGuard}). Like the option guard, it acts only on calls
 * with an Albert connection; an owner on the Skills screen is unaffected.
 *
 * @since 1.7.0
 */
class SkillGuard implements Hookable {

	/**
	 * Register the refusals.
	 *
	 * Last, so no other filter can let a write through after this one refused it.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function register_hooks(): void {
		add_filter( 'wp_insert_post_empty_content', [ $this, 'refuse_insert' ], PHP_INT_MAX, 2 );
		add_filter( 'wp_insert_post_data', [ $this, 'refuse_retype' ], PHP_INT_MAX, 2 );
		foreach ( [ 'pre_delete_post', 'pre_trash_post', 'pre_untrash_post' ] as $hook ) {
			add_filter( $hook, [ $this, 'refuse_post' ], PHP_INT_MAX, 2 );
		}
		foreach ( [ 'add', 'update' ] as $operation ) {
			add_filter( "{$operation}_post_metadata", [ $this, 'refuse_meta' ], PHP_INT_MAX, 2 );
		}
		add_filter( 'delete_post_metadata', [ $this, 'refuse_meta_delete' ], PHP_INT_MAX, 5 );
		foreach ( [ 'update', 'delete' ] as $operation ) {
			add_filter( "{$operation}_post_metadata_by_mid", [ $this, 'refuse_meta_by_id' ], PHP_INT_MAX, 2 );
		}
	}

	/**
	 * Refuse creating or updating a skill post.
	 *
	 * `wp_insert_post_empty_content` is the one filter that stops
	 * `wp_insert_post()` before it writes, for creates and updates alike.
	 *
	 * @param mixed                $maybe_empty Whether WordPress treats the post as empty.
	 * @param array<string, mixed> $postarr     The post being saved.
	 *
	 * @return mixed True to refuse.
	 * @since 1.7.0
	 */
	public function refuse_insert( $maybe_empty, $postarr ) {
		if ( ! self::connected() || ! is_array( $postarr ) ) {
			return $maybe_empty;
		}

		// The parent too: a forged revision under a skill would poison the history an owner restores from.
		$id     = (int) ( $postarr['ID'] ?? 0 );
		$parent = (int) ( $postarr['post_parent'] ?? 0 );
		if ( ( $postarr['post_type'] ?? '' ) === OwnerSkills::POST_TYPE || self::is_skill( $id ) || self::is_skill( $parent ) ) {
			self::note( 'write' );
			return true;
		}

		return $maybe_empty;
	}

	/**
	 * Refuse turning another post into a skill post.
	 *
	 * A backstop: `wp_insert_post_empty_content` already refuses through `wp_insert_post()`.
	 *
	 * @param mixed $data    Slashed post data about to be saved.
	 * @param mixed $postarr The raw post array.
	 *
	 * @return mixed The data, or the data with the type put back.
	 * @since 1.7.0
	 */
	public function refuse_retype( $data, $postarr ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( self::connected() && is_array( $data ) && ( $data['post_type'] ?? '' ) === OwnerSkills::POST_TYPE ) {
			self::note( 'write' );
			$data['post_type']   = 'post';
			$data['post_status'] = 'draft';
		}

		return $data;
	}

	/**
	 * Refuse deleting, trashing or restoring a skill post.
	 *
	 * @param mixed $check Null to let it proceed.
	 * @param mixed $post  The post.
	 *
	 * @return mixed False to refuse.
	 * @since 1.7.0
	 */
	public function refuse_post( $check, $post ) {
		if ( self::connected() && $post instanceof \WP_Post && $post->post_type === OwnerSkills::POST_TYPE ) {
			self::note( 'delete' );
			return false;
		}

		return $check;
	}

	/**
	 * Refuse adding, changing or deleting a skill post's meta.
	 *
	 * @param mixed $check     Null to let it proceed.
	 * @param mixed $object_id The post.
	 *
	 * @return mixed False to refuse.
	 * @since 1.7.0
	 */
	public function refuse_meta( $check, $object_id ) {
		if ( self::connected() && is_numeric( $object_id ) && self::is_skill( (int) $object_id ) ) {
			self::note( 'write' );
			return false;
		}

		return $check;
	}

	/**
	 * Refuse deleting a skill post's meta, including a delete across every post by key.
	 *
	 * `delete_post_meta_by_key()` passes no post, so the key decides: Albert's
	 * own skill keys are never deleted site-wide over a connection.
	 *
	 * @param mixed $check      Null to let it proceed.
	 * @param mixed $object_id  The post, or 0 for every post.
	 * @param mixed $meta_key   The key.
	 * @param mixed $meta_value The value.
	 * @param mixed $delete_all Whether every post's row is deleted.
	 *
	 * @return mixed False to refuse.
	 * @since 1.7.0
	 */
	public function refuse_meta_delete( $check, $object_id, $meta_key, $meta_value, $delete_all ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		if ( ! self::connected() ) {
			return $check;
		}

		$site_wide = ! empty( $delete_all ) && is_string( $meta_key ) && str_starts_with( $meta_key, '_albert_skill_' );
		if ( $site_wide || ( is_numeric( $object_id ) && self::is_skill( (int) $object_id ) ) ) {
			self::note( 'delete' );
			return false;
		}

		return $check;
	}

	/**
	 * Refuse changing or deleting a skill post's meta row by its id.
	 *
	 * @param mixed $check   Null to let it proceed.
	 * @param mixed $meta_id The meta row.
	 *
	 * @return mixed False to refuse.
	 * @since 1.7.0
	 */
	public function refuse_meta_by_id( $check, $meta_id ) {
		if ( ! self::connected() || ! is_numeric( $meta_id ) ) {
			return $check;
		}

		$row = get_metadata_by_mid( 'post', (int) $meta_id );
		if ( is_object( $row ) && self::is_skill( (int) $row->post_id ) ) {
			self::note( 'write' );
			return false;
		}

		return $check;
	}

	/**
	 * Whether a post id is a skill post.
	 *
	 * @param int $post_id The post.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	private static function is_skill( int $post_id ): bool {
		return $post_id > 0 && get_post_type( $post_id ) === OwnerSkills::POST_TYPE;
	}

	/**
	 * Whether the call is an assistant's.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	private static function connected(): bool {
		return ConnectionContext::client_id() !== null;
	}

	/**
	 * Report the refusal as the option guard reports its own.
	 *
	 * @param string $operation `write` or `delete`.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	private static function note( string $operation ): void {
		/** This action is documented in src/SafeMode/ConnectionGuard.php */
		do_action( 'albert/safe_mode/option_write_blocked', OwnerSkills::POST_TYPE, $operation );
	}
}
