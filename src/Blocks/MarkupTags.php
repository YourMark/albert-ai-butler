<?php
/**
 * Markup Tags
 *
 * Refuses a block edit that drops an HTML tag the block had, such as a plugin's
 * `<ta>`, unless `remove_tags` confirms it. What a user may store is left to
 * core's KSES on save, as in the editor.
 *
 * @package    Albert
 * @subpackage Blocks
 * @since      1.6.0
 */

namespace Albert\Blocks;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the element tag names in two pieces of markup.
 *
 * Only element names count. Attribute changes, text and HTML comments (block
 * delimiters) are ignored.
 *
 * @since 1.6.0
 */
class MarkupTags {

	/**
	 * Input key that confirms tags a change may remove.
	 *
	 * @var string
	 * @since 1.6.0
	 */
	public const INPUT = 'remove_tags';

	/**
	 * The `remove_tags` input schema, shared by every ability that checks drops.
	 *
	 * @return array<string, mixed> JSON schema for the property.
	 * @since 1.6.0
	 */
	public static function input_schema(): array {
		return [
			'type'        => 'array',
			'items'       => [ 'type' => 'string' ],
			'description' => 'Optional. Tag names this edit may remove, e.g. ["ta"]. An edit that drops a tag the block had is refused without it; send it only after the person agreed.',
		];
	}

	/**
	 * The distinct element tag names in a piece of markup.
	 *
	 * @param string $html Markup.
	 * @return array<int, string> Lowercase tag names, sorted.
	 * @since 1.6.0
	 */
	public static function names( string $html ): array {
		$html = preg_replace( '/<!--.*?-->/s', '', $html ) ?? $html;

		preg_match_all( '/<([a-z][a-z0-9-]*)(?=[\s\/>]|$)/i', $html, $matches );

		$names = array_values( array_unique( array_map( 'strtolower', $matches[1] ) ) );
		sort( $names );

		return $names;
	}

	/**
	 * Tag names in the content, leaving out each block's own wrapper element.
	 *
	 * A block's wrapper (the `<p>` of a paragraph, the `<h2>` of a heading) changes
	 * with its type, so turning a paragraph into a heading loses nothing. A tag
	 * inside the text does count. core/html has no wrapper: all of it is content.
	 *
	 * @param string $html Markup.
	 * @return array<int, string> Lowercase tag names, sorted.
	 * @since 1.6.0
	 */
	private static function content_names( string $html ): array {
		return self::names( preg_replace( '/<!--\s+wp:(?!html\s)[^>]*?-->\s*<[a-z][a-z0-9-]*/i', '', $html ) ?? $html );
	}

	/**
	 * Refuse a change that drops a tag the content had, unless `remove_tags` confirms it.
	 *
	 * @param string               $before Markup before the change.
	 * @param string               $after  Markup after the change.
	 * @param array<string, mixed> $args   Ability input; `remove_tags` lists confirmed tag names.
	 * @param string|null          $stored The saved content, when $before may hold tags an earlier edit of the same call added; only tags it has count.
	 * @return WP_Error|null Error naming the unconfirmed tags, or null.
	 * @since 1.6.0
	 */
	public static function refuse_unconfirmed_drop( string $before, string $after, array $args, ?string $stored = null ): ?WP_Error {
		$dropped = array_diff( self::content_names( $before ), self::content_names( $after ) );
		if ( $stored !== null ) {
			$dropped = array_intersect( $dropped, self::content_names( $stored ) );
		}
		$dropped = array_values( array_diff( $dropped, self::confirmed( $args ) ) );

		if ( $dropped === [] ) {
			return null;
		}

		$json = (string) wp_json_encode( $dropped );

		return new WP_Error(
			'tags_removed',
			sprintf(
				/* translators: 1: tag names, e.g. `<ta>`, 2: JSON list, e.g. ["ta"]. */
				_n(
					'This change removes %1$s, which the content had. Ask the person before removing it; if they agree, send the call again with remove_tags: %2$s. Nothing was saved.',
					'This change removes %1$s, which the content had. Ask the person before removing them; if they agree, send the call again with remove_tags: %2$s. Nothing was saved.',
					count( $dropped ),
					'albert-ai-butler'
				),
				self::format( $dropped ),
				$json
			),
			[
				'status' => 400,
				'tags'   => $dropped,
			]
		);
	}

	/**
	 * Read `remove_tags` as normalised tag names; accepts "ta" or "<ta>".
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array<int, string> Lowercase tag names.
	 * @since 1.6.0
	 */
	private static function confirmed( array $args ): array {
		$raw = $args[ self::INPUT ] ?? [];
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$names = [];
		foreach ( $raw as $name ) {
			if ( is_string( $name ) ) {
				$names[] = strtolower( trim( $name, " \t\n\r\0\x0B<>/" ) );
			}
		}

		return $names;
	}

	/**
	 * Format tag names for a message: `<ta>`, `<iframe>`.
	 *
	 * @param array<int, string> $names Tag names.
	 * @return string Formatted list.
	 * @since 1.6.0
	 */
	private static function format( array $names ): string {
		return implode( ', ', array_map( static fn ( string $name ): string => '`<' . $name . '>`', $names ) );
	}
}
