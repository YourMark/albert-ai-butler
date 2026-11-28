<?php
/**
 * Skills the site's owner wrote
 *
 * @package Albert
 * @subpackage MCP\Skills
 * @since      1.7.0
 */

namespace Albert\MCP\Skills;

defined( 'ABSPATH' ) || exit;

use Albert\Contracts\Interfaces\Hookable;
use WP_Error;
use WP_Post;

/**
 * Stores only what the owner wrote, as a private post type with revisions.
 *
 * Bundled and add-on skills stay files and are never imported. A post is one of:
 * - an owner skill (`post_name` is its slug),
 * - additions on a registered skill (meta names the target), or
 * - a customised copy of one (meta names the target and the sha256 of the
 *   original when copied, which is how an update by Albert is noticed).
 *
 * Not public and not in REST: the Skills screen has its own routes, and only
 * people who can `manage_options` write. Writes over a connection are refused
 * ({@see \Albert\SafeMode\SkillGuard}): an assistant that edits its own
 * guidance could keep an injected instruction across conversations.
 *
 * @since 1.7.0
 */
class OwnerSkills implements Hookable {

	/**
	 * The post type.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	public const POST_TYPE = 'albert_skill';

	/**
	 * Meta key: `owner`, `additions` or `customised`.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	public const META_KIND = '_albert_skill_kind';

	/**
	 * Meta key: the owner skill's slug, or the registered skill additions or a customised copy belong to.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	public const META_TARGET = '_albert_skill_target';

	/**
	 * Meta key: sha256 of the original body a customised copy started from.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	public const META_ORIGINAL = '_albert_skill_original';

	/**
	 * Meta key: preconditions of an owner skill, space-separated.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	public const META_REQUIRES = '_albert_skill_requires';

	/**
	 * Meta key: abilities an owner skill is linked to, space-separated.
	 *
	 * @since 1.7.0
	 * @var string
	 */
	public const META_ABILITIES = '_albert_skill_abilities';

	/**
	 * Largest text an owner may save, in bytes.
	 *
	 * @since 1.7.0
	 * @var int
	 */
	public const MAX_BYTES = 32768;

	/**
	 * The kinds of post.
	 *
	 * @since 1.7.0
	 * @var list<string>
	 */
	public const KINDS = [ 'owner', 'additions', 'customised' ];

	/**
	 * Slugs an owner skill may not take: WordPress's own endpoints, and the
	 * prefixes this class gives the other kinds' posts.
	 *
	 * @since 1.7.0
	 * @var list<string>
	 */
	private const RESERVED = [ 'feed', 'embed', 'page', 'comments', 'trackback', 'attachment' ];

	/**
	 * Register the post type.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public function register_hooks(): void {
		add_action( 'init', [ self::class, 'register_post_type' ] );
	}

	/**
	 * Register `albert_skill`: private, out of REST and menus, revisions on, every capability `manage_options`.
	 *
	 * `map_meta_cap` off, so each listed capability is checked as is.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	public static function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		register_post_type(
			self::POST_TYPE,
			[
				'label'               => __( 'Skills', 'albert-ai-butler' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => true,
				'supports'            => [ 'title', 'editor', 'excerpt', 'revisions', 'custom-fields' ],
				'map_meta_cap'        => false,
				'capabilities'        => array_fill_keys(
					[ 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'edit_private_posts', 'edit_published_posts', 'delete_posts', 'delete_others_posts', 'delete_private_posts', 'delete_published_posts', 'publish_posts', 'read_private_posts', 'read', 'create_posts' ],
					'manage_options'
				),
			]
		);
	}

	/**
	 * The owner's own skills, as registry definitions keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.7.0
	 */
	public static function definitions(): array {
		$definitions = [];

		foreach ( self::posts( 'owner' ) as $post ) {
			$slug = (string) get_post_meta( $post->ID, self::META_TARGET, true );
			if ( $slug === '' || trim( $post->post_content ) === '' ) {
				continue;
			}

			$definitions[ $slug ] = [
				'slug'      => $slug,
				'summary'   => $post->post_excerpt,
				'body'      => $post->post_content,
				'requires'  => (string) get_post_meta( $post->ID, self::META_REQUIRES, true ),
				'abilities' => (string) get_post_meta( $post->ID, self::META_ABILITIES, true ),
				'source'    => __( 'This site', 'albert-ai-butler' ),
			];
		}

		return $definitions;
	}

	/**
	 * What the owner wrote on top of registered skills, keyed by target slug.
	 *
	 * @return array<string, array{additions?: string, customised?: string, original?: string}>
	 * @since 1.7.0
	 */
	public static function overlays(): array {
		$overlays = [];

		foreach ( [ 'additions', 'customised' ] as $kind ) {
			foreach ( self::posts( $kind ) as $post ) {
				$target = (string) get_post_meta( $post->ID, self::META_TARGET, true );
				if ( $target === '' || trim( $post->post_content ) === '' ) {
					continue;
				}

				$overlays[ $target ][ $kind ] = $post->post_content;
				if ( $kind === 'customised' ) {
					$overlays[ $target ]['original'] = (string) get_post_meta( $post->ID, self::META_ORIGINAL, true );
				}
			}
		}

		return $overlays;
	}

	/**
	 * Save an owner skill, additions or a customised copy.
	 *
	 * Takes the whole record, not a partial update: omitted fields are cleared.
	 * The text is checked against the size limit here, so every caller gets
	 * the same refusal.
	 *
	 * @param string               $kind One of {@see self::KINDS}.
	 * @param string               $slug The owner skill's slug, or the target's.
	 * @param array<string, mixed> $data `body`; for an owner skill also `title`, `summary`, `requires`, `abilities`; for a customised copy `original` (sha256).
	 *
	 * @return int|WP_Error The post id.
	 * @since 1.7.0
	 */
	public static function save( string $kind, string $slug, array $data ): int|WP_Error {
		$body = is_string( $data['body'] ?? null ) ? $data['body'] : '';

		$reserved = $kind === 'owner' && ( in_array( $slug, self::RESERVED, true ) || preg_match( '/^(additions|customised)-/', $slug ) === 1 );
		if ( ! in_array( $kind, self::KINDS, true ) || preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug ) !== 1 || strlen( $slug ) > 64 || $reserved ) {
			return new WP_Error( 'albert_skill_invalid', __( 'That is not a valid skill name.', 'albert-ai-butler' ), [ 'status' => 400 ] );
		}

		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error(
				'albert_skill_too_large',
				sprintf(
					/* translators: 1: size of the text, 2: the limit, both in KB. */
					__( 'This text is %1$s KB; a skill can be at most %2$s KB.', 'albert-ai-butler' ),
					number_format_i18n( strlen( $body ) / 1024, 1 ),
					number_format_i18n( self::MAX_BYTES / 1024 )
				),
				[ 'status' => 400 ]
			);
		}

		$existing = self::find( $kind, $slug );
		$postarr  = [
			'ID'           => $existing->ID ?? 0,
			'post_type'    => self::POST_TYPE,
			'post_status'  => 'private',
			'post_name'    => $kind === 'owner' ? $slug : $kind . '-' . $slug,
			'post_title'   => is_string( $data['title'] ?? null ) ? $data['title'] : $slug,
			'post_excerpt' => is_string( $data['summary'] ?? null ) ? $data['summary'] : '',
			'post_content' => $body,
		];

		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// The slug lives in meta: WordPress may suffix `post_name` to keep it unique.
		update_post_meta( $id, self::META_KIND, $kind );
		update_post_meta( $id, self::META_TARGET, $slug );
		if ( $kind === 'customised' && is_string( $data['original'] ?? null ) ) {
			update_post_meta( $id, self::META_ORIGINAL, $data['original'] );
		}
		if ( $kind === 'owner' ) {
			update_post_meta( $id, self::META_REQUIRES, implode( ' ', SkillFileParser::words( $data['requires'] ?? [] ) ) );
			update_post_meta( $id, self::META_ABILITIES, implode( ' ', SkillFileParser::words( $data['abilities'] ?? [] ) ) );
		}

		SkillRegistry::reset_cache();

		return (int) $id;
	}

	/**
	 * Delete an owner skill, additions or a customised copy, for good.
	 *
	 * @param string $kind One of {@see self::KINDS}.
	 * @param string $slug The owner skill's slug, or the target's.
	 *
	 * @return bool Whether something was deleted.
	 * @since 1.7.0
	 */
	public static function delete( string $kind, string $slug ): bool {
		$post = self::find( $kind, $slug );
		if ( $post === null ) {
			return false;
		}

		$deleted = wp_delete_post( $post->ID, true );
		SkillRegistry::reset_cache();

		return (bool) $deleted;
	}

	/**
	 * The post of one kind for one slug.
	 *
	 * @param string $kind One of {@see self::KINDS}.
	 * @param string $slug The owner skill's slug, or the target's.
	 *
	 * @return WP_Post|null
	 * @since 1.7.0
	 */
	public static function find( string $kind, string $slug ): ?WP_Post {
		foreach ( self::posts( $kind ) as $post ) {
			if ( (string) get_post_meta( $post->ID, self::META_TARGET, true ) === $slug ) {
				return $post;
			}
		}

		return null;
	}

	/**
	 * Every post of one kind.
	 *
	 * @param string $kind One of {@see self::KINDS}.
	 *
	 * @return list<WP_Post>
	 * @since 1.7.0
	 */
	private static function posts( string $kind ): array {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return [];
		}

		$posts = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'private',
				'posts_per_page'   => 100,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
				'meta_key'         => self::META_KIND, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A handful of private posts.
				'meta_value'       => $kind, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- A handful of private posts.
			]
		);

		return array_values( array_filter( $posts, static fn( $post ): bool => $post instanceof WP_Post ) );
	}
}
