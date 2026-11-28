<?php
/**
 * Design token reader: the site's real palette, not WordPress's.
 *
 * @package Albert
 * @subpackage Context\Readers
 * @since      1.4.0
 */

namespace Albert\Context\Readers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the design tokens the theme genuinely declares.
 *
 * The point of sending these is that a model asked for "a button in our brand
 * colour" should use a colour that exists on the site instead of inventing one.
 * That only works if the values are actually the site's.
 *
 * **Provenance is the whole design.** `wp_get_global_settings()` never comes
 * back empty: WordPress ships its own `theme.json`, so a theme that declares
 * nothing still yields core's generic palette. Handing that to a model dressed
 * as brand tokens is worse than sending nothing, it would confidently paint the
 * site in WordPress's defaults. So every value here is read from the `theme` and
 * `custom` origins only, and the section is omitted when those are empty.
 *
 * Reading by origin is also what makes one gate cover both theme kinds: a block
 * theme's `theme.json` and a classic theme's
 * `add_theme_support( 'editor-color-palette' )` both land in the `theme` origin,
 * and the Styles editor's overrides land in `custom`. No `wp_is_block_theme()`
 * branch is needed, and none is wanted, a block theme without a `theme.json`
 * would pass that check while declaring nothing.
 *
 * @since 1.4.0
 */
class DesignTokens {

	/**
	 * Origins that represent a real decision by this site.
	 *
	 * `default` is WordPress's own and is never read.
	 *
	 * @since 1.4.0
	 * @var list<string>
	 */
	private const SITE_ORIGINS = [ 'theme', 'custom' ];

	/**
	 * Build the design section, or null when the theme declares nothing.
	 *
	 * Null is meaningful and is not the same as an empty array: it is what tells
	 * the Context screen to show the classic-theme steer instead of an empty
	 * detected row.
	 *
	 * @return array{palette?: list<array{name: string, slug: string, color: string}>, fonts?: list<string>, spacing?: list<string>}|null Sections the theme declares, or null when it declares none.
	 * @since 1.4.0
	 */
	public function read(): ?array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return null;
		}

		$design = [];

		$palette = $this->palette();
		if ( $palette !== [] ) {
			$design['palette'] = $palette;
		}

		$fonts = $this->fonts();
		if ( $fonts !== [] ) {
			$design['fonts'] = $fonts;
		}

		// A spacing scale on its own says nothing about the brand, so it rides
		// along with real colour or type evidence rather than standing the
		// section up by itself.
		if ( $design === [] ) {
			return null;
		}

		$spacing = $this->spacing();
		if ( $spacing !== [] ) {
			$design['spacing'] = $spacing;
		}

		return $design;
	}

	/**
	 * The declared colour palette.
	 *
	 * Every declared colour is sent, uncapped. An entry costs a handful of
	 * tokens, so a limit saves almost nothing and silently drops colours,
	 * including the owner's own: a colour added in the Styles editor sits after
	 * the theme's, and nothing on this screen or in the response would say it
	 * had gone.
	 *
	 * @return list<array{name: string, slug: string, color: string}>
	 * @since 1.4.0
	 */
	private function palette(): array {
		$colours = [];

		foreach ( $this->site_origin_values( [ 'color', 'palette' ] ) as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['color'] ) || ! is_string( $entry['color'] ) ) {
				continue;
			}

			$slug = isset( $entry['slug'] ) && is_string( $entry['slug'] ) ? $entry['slug'] : '';

			// A later origin (custom) overriding an earlier one (theme) reuses
			// the slug, so keying on it keeps the override and drops the
			// original rather than sending both.
			$colours[ $slug !== '' ? $slug : $entry['color'] ] = [
				'name'  => isset( $entry['name'] ) && is_string( $entry['name'] ) ? $entry['name'] : $slug,
				'slug'  => $slug,
				'color' => $entry['color'],
			];
		}

		return array_values( $colours );
	}

	/**
	 * The declared font families, as the names a person would say.
	 *
	 * A `fontFamily` value is a full CSS stack: `Cardo, Georgia, serif`. The
	 * model wants the family the site chose, so the declared `name` is preferred
	 * and the stack's first entry is the fallback.
	 *
	 * @return list<string> Font family names.
	 * @since 1.4.0
	 */
	private function fonts(): array {
		$fonts = [];

		foreach ( $this->site_origin_values( [ 'typography', 'fontFamilies' ] ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$name = '';

			if ( isset( $entry['name'] ) && is_string( $entry['name'] ) && $entry['name'] !== '' ) {
				$name = $entry['name'];
			} elseif ( isset( $entry['fontFamily'] ) && is_string( $entry['fontFamily'] ) ) {
				$stack = explode( ',', $entry['fontFamily'] );
				$name  = trim( $stack[0], " \t\n\r\0\x0B\"'" );
			}

			if ( $name !== '' ) {
				$fonts[ strtolower( $name ) ] = $name;
			}
		}

		return array_values( $fonts );
	}

	/**
	 * The declared spacing scale, as preset slugs.
	 *
	 * Slugs, not sizes, and the difference is the whole value of the line. A
	 * theme's spacing sizes are `clamp(1.5rem, 4vw, 2rem)` expressions, long,
	 * and useless to a model, which cannot put a raw clamp into block markup.
	 * What block markup takes is the preset reference built from the slug
	 * (`var:preset|spacing|medium`), so the slug is the actionable half and the
	 * cheap one.
	 *
	 * @return list<string> Spacing preset slugs, as block markup references them.
	 * @since 1.4.0
	 */
	private function spacing(): array {
		$slugs = [];

		foreach ( $this->site_origin_values( [ 'spacing', 'spacingSizes' ] ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['slug'] ) && is_string( $entry['slug'] ) && $entry['slug'] !== '' ) {
				$slugs[ $entry['slug'] ] = $entry['slug'];
			}
		}

		return array_values( $slugs );
	}

	/**
	 * Flatten one origin-split global setting down to this site's own entries.
	 *
	 * Core returns these settings keyed by origin: `default`, `theme`,
	 * `custom`, but only when more than one origin has values; a single-origin
	 * setting comes back as a plain list. Both shapes are handled, and the
	 * `default` origin is dropped either way.
	 *
	 * @param list<string> $path Global settings path, e.g. `[ 'color', 'palette' ]`.
	 *
	 * @return array<int, mixed> Entries declared by the theme or the site owner.
	 * @since 1.4.0
	 */
	private function site_origin_values( array $path ): array {
		$setting = wp_get_global_settings( $path );

		if ( ! is_array( $setting ) || $setting === [] ) {
			return [];
		}

		// Origin-keyed shape: every key is an origin name.
		$keys       = array_keys( $setting );
		$is_origins = $keys === array_values( array_intersect( $keys, [ 'default', 'theme', 'custom' ] ) );

		if ( ! $is_origins ) {
			// A plain list means one origin supplied everything. That origin is
			// `default` exactly when the theme declares no theme.json and no
			// editor-palette support, which is the case this class exists to
			// exclude.
			return $this->theme_declares_tokens() ? array_values( $setting ) : [];
		}

		$values = [];
		foreach ( self::SITE_ORIGINS as $origin ) {
			if ( isset( $setting[ $origin ] ) && is_array( $setting[ $origin ] ) ) {
				$values = array_merge( $values, array_values( $setting[ $origin ] ) );
			}
		}

		return $values;
	}

	/**
	 * Whether the active theme declares tokens of its own, either way it can.
	 *
	 * Only consulted for the single-origin shape above, where the origin is not
	 * named in the returned data and has to be established some other way.
	 *
	 * @return bool True when the theme ships a theme.json or declares editor presets.
	 * @since 1.4.0
	 */
	private function theme_declares_tokens(): bool {
		if ( function_exists( 'wp_theme_has_theme_json' ) && wp_theme_has_theme_json() ) {
			return true;
		}

		return current_theme_supports( 'editor-color-palette' )
			|| current_theme_supports( 'editor-font-sizes' );
	}
}
