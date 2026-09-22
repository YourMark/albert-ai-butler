<?php
/**
 * Pattern Catalog
 *
 * Reads the site's block patterns into a compact, serialisable form for the
 * find/view-pattern abilities: theme- and plugin-registered patterns from
 * WP_Block_Patterns_Registry, and user-created patterns from the wp_block CPT.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.5.0
 */

namespace Albert\Blocks;

use WP_Block_Patterns_Registry;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes registered and user block patterns.
 *
 * Registered patterns are read-only; user patterns (wp_block posts) are the ones
 * an owner can edit. The `source` field carries that distinction, which is the
 * one that matters for reuse: an assistant can build on either, but only a user
 * pattern can later be written back to.
 *
 * @since 1.5.0
 */
class PatternCatalog {

	/**
	 * Registered patterns, injected for tests or read lazily from the registry.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $registered;

	/**
	 * User patterns (wp_block posts), injected for tests or read lazily.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $user;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>>|null $registered Registered patterns, or null to read the registry.
	 * @param array<int, array<string, mixed>>|null $user       User patterns, or null to read the wp_block CPT.
	 *
	 * @since 1.5.0
	 */
	public function __construct( ?array $registered = null, ?array $user = null ) {
		$this->registered = $registered;
		$this->user       = $user;
	}

	/**
	 * List pattern summaries, optionally filtered by a search term and category.
	 *
	 * Summaries omit the block markup; fetch a single pattern with {@see get()}
	 * to read its content.
	 *
	 * @param string|null $search   Case-insensitive term matched against name, title, description and keywords.
	 * @param string|null $category Category slug the pattern must carry.
	 * @return array<int, array<string, mixed>> Pattern summaries.
	 *
	 * @since 1.5.0
	 */
	public function all( ?string $search = null, ?string $category = null ): array {
		$out = [];

		foreach ( $this->patterns() as $pattern ) {
			if ( $category !== null && ! in_array( $category, $pattern['categories'], true ) ) {
				continue;
			}

			if ( $search !== null && ! $this->matches( $pattern, $search ) ) {
				continue;
			}

			$out[] = $this->summary( $pattern );
		}

		return $out;
	}

	/**
	 * Get one pattern by name, including its block markup, or null if unknown.
	 *
	 * @param string $name Pattern name (registered pattern name, or a user pattern slug).
	 * @return array<string, mixed>|null Full pattern, or null.
	 *
	 * @since 1.5.0
	 */
	public function get( string $name ): ?array {
		// A registered pattern is already in memory from the registry, so match
		// those first. Only when the name is not a registered one do we look up a
		// single user pattern by slug, rather than reading the whole wp_block CPT
		// just to find one.
		foreach ( $this->registered() as $pattern ) {
			if ( $pattern['name'] === $name ) {
				return $pattern;
			}
		}

		return $this->user_pattern( $name );
	}

	/**
	 * All patterns (registered then user), each a full record including content.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @since 1.5.0
	 */
	private function patterns(): array {
		return array_merge( $this->registered(), $this->users() );
	}

	/**
	 * Registered patterns, read once and cached.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @since 1.5.0
	 */
	private function registered(): array {
		return $this->registered ??= $this->read_registered();
	}

	/**
	 * User patterns, read once and cached.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @since 1.5.0
	 */
	private function users(): array {
		return $this->user ??= $this->read_user();
	}

	/**
	 * Resolve one user pattern by slug without reading the whole CPT.
	 *
	 * An injected user set (tests) is searched in memory; otherwise a single
	 * post is loaded by its slug.
	 *
	 * @param string $name User pattern slug.
	 * @return array<string, mixed>|null
	 *
	 * @since 1.5.0
	 */
	private function user_pattern( string $name ): ?array {
		if ( $this->user !== null ) {
			foreach ( $this->user as $pattern ) {
				if ( $pattern['name'] === $name ) {
					return $pattern;
				}
			}

			return null;
		}

		$post = get_page_by_path( $name, OBJECT, 'wp_block' );

		if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
			return null;
		}

		return $this->map_user_post( $post );
	}

	/**
	 * Reduce a full pattern record to its summary (no content).
	 *
	 * @param array<string, mixed> $pattern Full pattern.
	 * @return array<string, mixed> Summary.
	 *
	 * @since 1.5.0
	 */
	private function summary( array $pattern ): array {
		return [
			'name'        => $pattern['name'],
			'title'       => $pattern['title'],
			'description' => $pattern['description'],
			'categories'  => $pattern['categories'],
			'source'      => $pattern['source'],
			'id'          => $pattern['id'],
			'syncStatus'  => $pattern['syncStatus'],
		];
	}

	/**
	 * Whether a pattern matches a search term.
	 *
	 * @param array<string, mixed> $pattern Full pattern.
	 * @param string               $search  Lower-cased comparison happens here.
	 * @return bool
	 *
	 * @since 1.5.0
	 */
	private function matches( array $pattern, string $search ): bool {
		$search = strtolower( $search );
		$hay    = strtolower(
			$pattern['name'] . ' ' . $pattern['title'] . ' ' . $pattern['description'] . ' ' . implode( ' ', $pattern['keywords'] )
		);

		return str_contains( $hay, $search );
	}

	/**
	 * Read theme- and plugin-registered patterns from the registry.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @since 1.5.0
	 */
	private function read_registered(): array {
		$out = [];

		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			$out[] = [
				'name'          => (string) ( $pattern['name'] ?? '' ),
				'title'         => (string) ( $pattern['title'] ?? '' ),
				'description'   => (string) ( $pattern['description'] ?? '' ),
				'categories'    => array_map( 'strval', (array) ( $pattern['categories'] ?? [] ) ),
				'keywords'      => array_map( 'strval', (array) ( $pattern['keywords'] ?? [] ) ),
				'viewportWidth' => isset( $pattern['viewportWidth'] ) ? (int) $pattern['viewportWidth'] : null,
				'content'       => (string) ( $pattern['content'] ?? '' ),
				'source'        => 'registered',
				// Registered patterns are always inserted as an independent copy;
				// there is no reference to sync back to.
				'id'            => null,
				'syncStatus'    => 'unsynced',
			];
		}

		return $out;
	}

	/**
	 * Read user-created patterns from the wp_block CPT.
	 *
	 * A user pattern is named by its slug so it reads back the same way a
	 * registered pattern's name does.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @since 1.5.0
	 */
	private function read_user(): array {
		$posts = get_posts(
			[
				'post_type'     => 'wp_block',
				'post_status'   => 'publish',
				'numberposts'   => -1,
				// This read never paginates, so skip the extra COUNT(*) query.
				'no_found_rows' => true,
			]
		);

		return array_map( [ $this, 'map_user_post' ], $posts );
	}

	/**
	 * Map a wp_block post to a full pattern record.
	 *
	 * @param WP_Post $post The wp_block post.
	 * @return array<string, mixed>
	 *
	 * @since 1.5.0
	 */
	private function map_user_post( WP_Post $post ): array {
		$categories = wp_get_object_terms( $post->ID, 'wp_pattern_category', [ 'fields' => 'slugs' ] );

		// A user pattern is synced by default; the 'unsynced' meta marks the
		// copy-on-insert ones. A synced pattern is inserted by reference
		// (core/block, so edits propagate); an unsynced one as a copy.
		$sync = get_post_meta( $post->ID, 'wp_pattern_sync_status', true ) === 'unsynced' ? 'unsynced' : 'synced';

		return [
			'name'          => $post->post_name,
			'title'         => $post->post_title,
			'description'   => '',
			'categories'    => is_wp_error( $categories ) ? [] : array_map( 'strval', $categories ),
			'keywords'      => [],
			'viewportWidth' => null,
			'content'       => $post->post_content,
			'source'        => 'user',
			'id'            => (int) $post->ID,
			'syncStatus'    => $sync,
		];
	}
}
