<?php
/**
 * Short text of what a call left.
 *
 * @package Albert
 * @subpackage Undo
 * @since      1.7.0
 */

namespace Albert\Undo;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text previews of the fields and blocks a call changed, so the owner
 * can read "Now: Pricing" after the live value has moved on. "After" is
 * otherwise kept as hashes only.
 *
 * Shape: `{ "post_title": "Pricing", "blocks": { "<hash>": "Frequently asked questions" } }`.
 *
 * @since 1.7.0
 */
final class Preview {

	/**
	 * Longest preview, in characters.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 120;

	/**
	 * Most block previews per item. A page built from scratch would otherwise
	 * store its whole text a second time.
	 *
	 * @var int
	 */
	public const MAX_BLOCKS = 50;

	/**
	 * Key the block previews are kept under.
	 *
	 * @var string
	 */
	public const BLOCKS = 'blocks';

	/**
	 * Previews of what changed between two states.
	 *
	 * @param array<string, mixed>|null $after          The state the call left; null when it deleted the entity.
	 * @param array<string, string>     $before_hashes  Field hashes before; empty for a create.
	 * @param array<string, string>     $after_hashes   Field hashes after.
	 * @param string|null               $before_content The post content before, when the entity is a post whose content changed.
	 *
	 * @return array<string, mixed>
	 * @since 1.7.0
	 */
	public static function of( ?array $after, array $before_hashes, array $after_hashes, ?string $before_content = null ): array {
		if ( $after === null ) {
			return [];
		}

		$preview = [];

		foreach ( $after_hashes as $field => $hash ) {
			if ( ( $before_hashes[ $field ] ?? null ) === $hash || $field === 'post_content' || ! array_key_exists( $field, $after ) ) {
				continue;
			}

			// A create lists only what it set, not every empty column of the row.
			if ( $before_hashes === [] && self::is_blank( $after[ $field ] ) ) {
				continue;
			}

			$text = self::text( $after[ $field ] );
			if ( $text !== null ) {
				$preview[ $field ] = $text;
			}
		}

		if ( is_string( $after['post_content'] ?? null ) && ( $before_hashes['post_content'] ?? null ) !== ( $after_hashes['post_content'] ?? null ) ) {
			$blocks = self::blocks( $after['post_content'], $before_content );
			if ( $blocks !== [] ) {
				$preview[ self::BLOCKS ] = $blocks;
			}
		}

		return $preview;
	}

	/**
	 * Previews of the blocks in `$after` that `$before` does not hold, by hash.
	 *
	 * Matched as a multiset: a block that appears twice before and three times
	 * after is one added block.
	 *
	 * @param string      $after  Content after.
	 * @param string|null $before Content before; null when unknown, so every block counts.
	 *
	 * @return array<string, string>
	 */
	private static function blocks( string $after, ?string $before ): array {
		$known = $before === null ? [] : array_count_values( BlockTree::hashes( BlockTree::of( $before ) ) );
		$found = [];

		BlockTree::of(
			$after,
			static function ( string $hash, array $block ) use ( &$known, &$found ): void {
				if ( ( $known[ $hash ] ?? 0 ) > 0 ) {
					--$known[ $hash ];
					return;
				}

				if ( count( $found ) >= self::MAX_BLOCKS || isset( $found[ $hash ] ) ) {
					return;
				}

				// Its own text only: a container's text is its children's, previewed with them.
				$text = self::text( (string) ( $block['innerHTML'] ?? '' ) );
				if ( $text !== null && $text !== '' ) {
					$found[ $hash ] = $text;
				}
			}
		);

		return $found;
	}

	/**
	 * A value as at most {@see self::MAX_LENGTH} characters of plain text.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null Null when the value looks like a credential.
	 * @since 1.7.0
	 */
	public static function text( $value ): ?string {
		if ( is_array( $value ) ) {
			$scalars = array_filter( $value, 'is_scalar' );
			$value   = array_is_list( $value ) && count( $scalars ) === count( $value ) ? implode( ', ', $value ) : (string) wp_json_encode( $value );
		}

		if ( ! is_scalar( $value ) && $value !== null ) {
			return null;
		}

		$raw = (string) $value;
		if ( Exclusions::is_secret_value( $raw ) ) {
			return null;
		}

		$text = html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_strlen( $text ) > self::MAX_LENGTH ? mb_substr( $text, 0, self::MAX_LENGTH - 1 ) . "\u{2026}" : $text;
	}

	/**
	 * Whether a created field holds nothing worth listing.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function is_blank( $value ): bool {
		return $value === null || $value === '' || $value === '0' || $value === 0 || $value === '0000-00-00 00:00:00' || $value === [];
	}
}
