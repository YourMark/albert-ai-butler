<?php
/**
 * Editor mode detection.
 *
 * @package Albert
 * @subpackage Blocks
 * @since      1.2.0
 */

namespace Albert\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Detects whether a given target uses the block editor or the classic editor.
 *
 * The Classic Editor plugin allows the choice to be made per post type and even
 * per post, so detection is always made against a concrete target. For an
 * existing post the stored post object drives the decision; for a not-yet-saved
 * target only the post type is known.
 *
 * Block is the modern WordPress default, so when the detection functions are
 * unavailable (e.g. in unit tests or a stripped environment) this class assumes
 * the block editor.
 *
 * @since 1.2.0
 */
class EditorMode {

	/**
	 * Per-request cache of resolved editor modes, keyed by post type + id.
	 *
	 * @var array<string, bool>
	 * @since 1.2.0
	 */
	private static array $cache = [];

	/**
	 * Determine whether the target uses the block editor.
	 *
	 * When a post id is given and resolves to a post, the decision is made
	 * against that post object (honouring per-post editor choices). Otherwise
	 * the post type alone is consulted. Defaults to true (block editor) when the
	 * WordPress detection functions are unavailable.
	 *
	 * @param string   $post_type Post type slug (e.g. 'post', 'page').
	 * @param int|null $post_id   Optional existing post id.
	 * @return bool True when the target uses the block editor, false for classic.
	 * @since 1.2.0
	 */
	public static function is_block_editor( string $post_type, ?int $post_id = null ): bool {
		$cache_key = $post_type . ':' . (int) $post_id;
		if ( isset( self::$cache[ $cache_key ] ) ) {
			return self::$cache[ $cache_key ];
		}

		$is_block = self::resolve( $post_type, $post_id );

		self::$cache[ $cache_key ] = $is_block;

		return $is_block;
	}

	/**
	 * Resolve the editor name for the target.
	 *
	 * @param string   $post_type Post type slug.
	 * @param int|null $post_id   Optional existing post id.
	 * @return string 'block' or 'classic'.
	 * @since 1.2.0
	 */
	public static function editor( string $post_type, ?int $post_id = null ): string {
		return self::is_block_editor( $post_type, $post_id ) ? 'block' : 'classic';
	}

	/**
	 * Build the read-side editor signal for a post.
	 *
	 * Returns the two fields the read abilities (view/find for posts and pages)
	 * expose for every item: `editor` (which editor the post's type/instance
	 * uses) and `has_blocks` (whether the stored content actually contains block
	 * markup). The two are independent — classic content can live on a
	 * block-editor type and vice versa — so both are reported.
	 *
	 * @param object $post Post object with `ID`, `post_type` and `post_content` (a WP_Post).
	 * @phpstan-param object{ID: int, post_type: string, post_content: string} $post
	 * @return array{editor: string, has_blocks: bool} Editor name and block-markup flag.
	 * @since 1.2.0
	 */
	public static function signal( object $post ): array {
		return [
			'editor'     => self::editor( (string) $post->post_type, (int) $post->ID ),
			'has_blocks' => self::content_has_blocks( (string) $post->post_content ),
		];
	}

	/**
	 * Build the editor signal for content that has no resolvable post.
	 *
	 * Used as a fallback by the find abilities: when `get_post()` does not return
	 * a post for a row in the REST response the per-instance editor cannot be
	 * determined, so the block-editor default is reported alongside the actual
	 * block-markup flag of the content. Keeps `has_blocks` guarded the same way
	 * {@see self::signal()} does, so both code paths behave identically.
	 *
	 * @param string $content Stored post content to test for block markup.
	 * @return array{editor: string, has_blocks: bool} Editor name and block-markup flag.
	 * @since 1.2.0
	 */
	public static function signal_for_content( string $content ): array {
		return [
			'editor'     => 'block',
			'has_blocks' => self::content_has_blocks( $content ),
		];
	}

	/**
	 * Whether the given content contains block markup.
	 *
	 * Wraps `has_blocks()` with a `function_exists()` guard so callers behave
	 * sanely in a stripped or test environment where the function is absent.
	 *
	 * @param string $content Stored post content.
	 * @return bool True when the content contains block markup.
	 * @since 1.2.0
	 */
	private static function content_has_blocks( string $content ): bool {
		return function_exists( 'has_blocks' ) ? has_blocks( $content ) : false;
	}

	/**
	 * Compute the editor mode without the per-request cache.
	 *
	 * @param string   $post_type Post type slug.
	 * @param int|null $post_id   Optional existing post id.
	 * @return bool True when the target uses the block editor.
	 * @since 1.2.0
	 */
	private static function resolve( string $post_type, ?int $post_id ): bool {
		// Core answers no for navigation menus: they have no editor screen of their own, but the Site Editor stores them as blocks.
		if ( $post_type === 'wp_navigation' ) {
			return true;
		}

		if ( $post_id !== null && $post_id > 0 && function_exists( 'use_block_editor_for_post' ) ) {
			$post = get_post( $post_id );
			if ( $post ) {
				return (bool) use_block_editor_for_post( $post );
			}
		}

		if ( function_exists( 'use_block_editor_for_post_type' ) ) {
			return (bool) use_block_editor_for_post_type( $post_type );
		}

		// Block editor is the modern default when detection is unavailable.
		return true;
	}

	/**
	 * Clear the per-request cache.
	 *
	 * Intended for tests; production resolves once per request.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function reset_cache(): void {
		self::$cache = [];
	}
}
