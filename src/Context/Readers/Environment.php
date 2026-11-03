<?php
/**
 * Environment reader: where the assistant is standing.
 *
 * @package Albert
 * @subpackage Context\Readers
 * @since      1.4.0
 */

namespace Albert\Context\Readers;

defined( 'ABSPATH' ) || exit;

use Albert\Blocks\EditorMode;
use Albert\Context\Symbols;
use Albert\MCP\AdapterStatus;

/**
 * Reads the resolved facts about this WordPress install.
 *
 * Resolved decisions, not inventory: which editor writes content here, which
 * language the site speaks, which theme is answering. An assistant that knows
 * the site is Dutch and block-based stops writing English into a classic editor.
 *
 * Every value is something the assistant would otherwise guess at, and nothing
 * here identifies a plugin the model has no use for, the installed-plugins list
 * is deliberately absent.
 *
 * @since 1.4.0
 */
class Environment {

	/**
	 * Multilingual plugins we can name, in detection order.
	 *
	 * Keyed by a symbol the plugin defines. See {@see Symbols} for why detection
	 * is by symbol rather than by plugin path.
	 *
	 * @since 1.4.0
	 * @var array<string, string> Label keyed by the symbol that proves it is running.
	 */
	private const MULTILINGUAL = [
		'pll_languages_list'  => 'Polylang',
		'SitePress'           => 'WPML',
		'TRP_Translate_Press' => 'TranslatePress',
		'weglot_get_service'  => 'Weglot',
	];

	/**
	 * Build the environment section.
	 *
	 * @return array<string, mixed> The structured environment facts.
	 * @since 1.4.0
	 */
	public function read(): array {
		$theme = wp_get_theme();

		$environment = [
			'wordpress' => get_bloginfo( 'version' ),
			'php'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'locale'    => get_locale(),
			'timezone'  => $this->timezone(),
			'theme'     => [
				'name'        => (string) $theme->get( 'Name' ),
				'version'     => (string) $theme->get( 'Version' ),
				'block_theme' => wp_is_block_theme(),
			],
			'editor'    => $this->editor(),
		];

		$parent = $theme->parent();
		if ( $parent instanceof \WP_Theme ) {
			$environment['theme']['parent'] = (string) $parent->get( 'Name' );
		}

		// Versions are what a bug report needs first, and an assistant cannot find them on its own.
		if ( defined( 'ALBERT_VERSION' ) ) {
			$environment['albert'] = (string) ALBERT_VERSION;
		}

		$adapter_version = AdapterStatus::adapter_version();
		if ( $adapter_version !== null ) {
			$environment['mcp_adapter'] = [ 'version' => $adapter_version ];

			$source = AdapterStatus::adapter_source();
			if ( $source !== null ) {
				$environment['mcp_adapter']['loaded_from'] = $source;
			}
		}

		$multilingual = $this->multilingual();
		if ( $multilingual !== null ) {
			$environment['multilingual'] = $multilingual;
		}

		return $environment;
	}

	/**
	 * The site's timezone, preferring a named zone over a bare offset.
	 *
	 * `wp_timezone_string()` falls back to a UTC offset like `+00:00` when the
	 * site is configured by offset rather than city. An offset is still worth
	 * sending, it is what "publish this at 9am" resolves against, but a name
	 * carries the daylight-saving rule too, so the name wins when there is one.
	 *
	 * @return string A named zone when the site has one, otherwise a UTC offset.
	 * @since 1.4.0
	 */
	private function timezone(): string {
		$named = (string) get_option( 'timezone_string', '' );

		return $named !== '' ? $named : wp_timezone_string();
	}

	/**
	 * Which editor writes content on this site.
	 *
	 * Reported per post type only when posts and pages disagree, which is what
	 * the Classic Editor plugin's per-type setting produces. When they agree,
	 * the overwhelmingly common case. One word is enough there, and the extra
	 * detail would be budget spent on nothing.
	 *
	 * @return string `block`, `classic`, or a per-type breakdown.
	 * @since 1.4.0
	 */
	private function editor(): string {
		$post = EditorMode::editor( 'post' );
		$page = EditorMode::editor( 'page' );

		if ( $post === $page ) {
			return $post;
		}

		return sprintf( 'post: %1$s, page: %2$s', $post, $page );
	}

	/**
	 * The multilingual plugin in charge, and the languages it serves.
	 *
	 * Returns null when the site is monolingual, so the renderer can omit the
	 * line entirely rather than assert "no translation plugin", an absence the
	 * assistant can already infer from the single locale.
	 *
	 * @return array{plugin: string, languages?: list<string>}|null
	 * @since 1.4.0
	 */
	private function multilingual(): ?array {
		foreach ( self::MULTILINGUAL as $symbol => $label ) {
			if ( ! Symbols::defined( (string) $symbol ) ) {
				continue;
			}

			$detected = [ 'plugin' => $label ];

			$languages = $this->languages();
			if ( $languages !== [] ) {
				$detected['languages'] = $languages;
			}

			return $detected;
		}

		return null;
	}

	/**
	 * The active language codes, when the detected plugin will tell us.
	 *
	 * Only Polylang and WPML expose a stable public reader for this. The others
	 * are named without their language list rather than probed through internal
	 * APIs that change between releases: knowing the site is multilingual is
	 * most of the value, and a wrong list is worse than none.
	 *
	 * @return list<string> Language codes, or an empty list when the plugin exposes no stable reader.
	 * @since 1.4.0
	 */
	private function languages(): array {
		if ( function_exists( 'pll_languages_list' ) ) {
			$languages = pll_languages_list();

			if ( is_array( $languages ) ) {
				return array_values( array_map( 'strval', $languages ) );
			}
		}

		if ( function_exists( 'icl_get_languages' ) ) {
			$languages = icl_get_languages( 'skip_missing=0' );

			if ( is_array( $languages ) ) {
				return array_values( array_map( 'strval', array_keys( $languages ) ) );
			}
		}

		return [];
	}
}
