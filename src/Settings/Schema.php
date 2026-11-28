<?php
/**
 * Settings Schema
 *
 * @package Albert
 * @subpackage Settings
 * @since      1.4.0
 */

namespace Albert\Settings;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\SettingsBootstrap;
use Albert\Admin\SettingsRegistry;

/**
 * The registered settings, collected once per request.
 *
 * Three things need this list and must all see the same one: the screen that
 * renders the form, the handler that saves it, and {@see Storage}, which hands
 * every field to WordPress's `register_setting()`.
 *
 * **Memoised, and that is load-bearing rather than an optimisation.**
 * `SettingsRegistry::append_field_to_section()` appends — it does not replace —
 * so an add-on calling `albert_register_setting()` on the
 * `albert/settings/register` action contributes its field once per firing. With
 * a second caller collecting in the same request, that action would fire twice
 * and every simple-API field would render, and save, twice over. Collecting
 * once also honours what an add-on reasonably assumes: that its registration
 * callback runs once per request.
 *
 * @since 1.4.0
 */
class Schema {

	/**
	 * The collected sections for this request, or null before the first collect.
	 *
	 * @since 1.4.0
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $sections = null;

	/**
	 * Every registered section, normalised, sorted, and name-resolved.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function collect(): array {
		if ( self::$sections !== null ) {
			return self::$sections;
		}

		$registry = SettingsRegistry::instance();

		// Register Free's built-in sections FIRST so the synthetic
		// `albert/settings` card created by `albert_register_setting()` can slot
		// between them in the rendered order.
		foreach ( SettingsBootstrap::get_builtin_sections() as $builtin ) {
			$registry->register_section( $builtin );
		}

		/**
		 * Fires before the unified settings sections are collected.
		 *
		 * Add-ons hook here to call {@see albert_register_setting()} or (for
		 * advanced use) {@see albert_register_settings_section()}.
		 *
		 * Fires once per request. See the class docblock: firing it twice would
		 * duplicate every field registered through the simple API.
		 *
		 * @since 1.1.0
		 */
		do_action( 'albert/settings/register' );

		/**
		 * Filters the final list of settings sections.
		 *
		 * Last chance to add, remove, or re-order sections before render or save.
		 *
		 * @since 1.1.0
		 *
		 * @param array<int, array<string, mixed>> $sections Normalised, sorted sections.
		 */
		$sections = apply_filters( 'albert/settings/sections', $registry->get_sections() );

		self::$sections = self::resolve_option_names( is_array( $sections ) ? $sections : [] );

		return self::$sections;
	}

	/**
	 * Forget the collected sections. Intended for tests.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$sections = null;
	}

	/**
	 * Stamp every field with the option name it reads and writes.
	 *
	 * One derivation, once, for every consumer: the input's `name` and the key
	 * the save loop reads from `$_POST` are the same string because both read it
	 * from here rather than deriving it themselves. Runs after
	 * `albert/settings/sections` so a filtered-in field is stamped too.
	 *
	 * @since 1.4.0
	 *
	 * @param array<int, array<string, mixed>> $sections Normalised sections.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function resolve_option_names( array $sections ): array {
		foreach ( $sections as $s_index => $section ) {
			if ( ! isset( $section['fields'] ) || ! is_array( $section['fields'] ) ) {
				continue;
			}

			$section_id = isset( $section['id'] ) && is_string( $section['id'] ) ? $section['id'] : '';

			foreach ( $section['fields'] as $f_index => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$sections[ $s_index ]['fields'][ $f_index ]['option_name'] = SettingsRegistry::get_option_name(
					$section_id,
					isset( $field['id'] ) && is_string( $field['id'] ) ? $field['id'] : '',
					isset( $field['option_name'] ) && is_string( $field['option_name'] ) ? $field['option_name'] : null
				);
			}
		}

		return $sections;
	}
}
