<?php
/**
 * An app's logo, inline.
 *
 * @package Albert
 * @subpackage Admin\Connections
 * @since      1.5.0
 */

namespace Albert\Admin\Connections;

defined( 'ABSPATH' ) || exit;

/**
 * Prints an app's monochrome logo from `assets/images/apps/`.
 *
 * Inline rather than an `<img>`, so it takes the text colour and needs no
 * request. Always decorative: every app is named in text beside its logo, so
 * the logo only helps people recognise it and is hidden from screen readers.
 *
 * The files are Albert's own, reduced to their paths when they were added,
 * so they are printed as they are.
 *
 * @since 1.5.0
 */
class AppIcon {

	/**
	 * The logos that exist, by the guide's `icon` key.
	 *
	 * @since 1.5.0
	 * @var array<int, string>
	 */
	private const ICONS = [ 'claude', 'claude-code', 'chatgpt', 'cursor', 'vscode', 'other' ];

	/**
	 * Print a logo, or nothing for an unknown key.
	 *
	 * @param string $icon The guide's `icon` key.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function render( string $icon ): void {
		if ( ! in_array( $icon, self::ICONS, true ) ) {
			return;
		}

		$svg = (string) file_get_contents( ALBERT_PLUGIN_DIR . 'assets/images/apps/' . $icon . '.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A bundled file.

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Albert's own SVG, paths only.
		echo str_replace( '<svg ', '<svg class="albert-app-icon" aria-hidden="true" focusable="false" ', trim( $svg ) );
	}
}
