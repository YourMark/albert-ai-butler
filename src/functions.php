<?php
/**
 * Global Helper Functions
 *
 * @package Albert
 * @since   1.1.0
 */

// Guard against loading outside WordPress (e.g. Composer dump-autoload).
use Albert\Abstracts\AbstractAddon;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! function_exists( 'albert_refresh_licenses_table' ) ) {
	/**
	 * AJAX handler: return fresh licenses table HTML.
	 *
	 * Called by albert-licenses.js after the EDD SL SDK finishes
	 * activating or deactivating a license.
	 *
	 * Guarded like every other function here, and not merely for symmetry: an
	 * unconditional declaration is hoisted at compile time, so it came into
	 * existence even when this file returned at the ABSPATH guard above without
	 * executing a line. Including the file a second time — which is exactly
	 * what has to happen when Composer's `files` autoload runs before WordPress
	 * does — then collided with that hoisted copy and killed the process.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	function albert_refresh_licenses_table(): void {
		check_ajax_referer( 'albert_license_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'albert-ai-butler' ) ] );
		}

		ob_start();
		\Albert\Admin\Settings::render_licenses_table();
		wp_send_json_success( [ 'table_html' => ob_get_clean() ] );
	}
}

add_action( 'wp_ajax_albert_refresh_licenses_table', 'albert_refresh_licenses_table' );

if ( ! function_exists( 'albert_register_settings_section' ) ) {
	/**
	 * Register a settings section with the unified Albert settings page.
	 *
	 * Call this from the `albert/settings/register` action. See
	 * `docs/settings-api.md` for the full schema.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $section Section configuration.
	 *
	 * @return void
	 */
	function albert_register_settings_section( array $section ): void {
		\Albert\Admin\SettingsRegistry::instance()->register_section( $section );
	}
}

if ( ! function_exists( 'albert_register_setting' ) ) {
	/**
	 * Register a single setting on the shared Albert &rarr; Settings page.
	 *
	 * Simplified add-on API. The first call lazily creates a synthetic
	 * `albert/settings` section that collects all settings registered by
	 * add-ons, then appends the field to it. See `docs/settings-api.md` for
	 * the full schema.
	 *
	 * Required keys:
	 *  - `title`       (string)  — visible label above the input.
	 *  - `option_name` (string)  — `wp_options` key (also doubles as the
	 *                              internal field id).
	 *  - `type`        (string)  — one of text|url|number|textarea|select|
	 *                              checkbox|radio-cards.
	 *
	 * Optional keys: `description`, `default`, `options` (required for `select`
	 * and `radio-cards`), `min` and `max` (a `number` field's allowed range,
	 * enforced by the sanitiser as well as rendered on the control),
	 * `attributes` (step/placeholder, and min/max if you prefer them there),
	 * `badge`, `suffix`, `info`, `show_in_rest`, `section`.
	 *
	 * `section` is the id of the card to land in, and is how an add-on gets a
	 * heading that says what its settings are about:
	 *
	 *  - Name a card that already exists — one of Free's, or one another add-on
	 *    registered — to add a field to it.
	 *  - Name one of your own and pass `section_title` to create it here, in
	 *    the same call. `section_priority` orders it against the rest (default
	 *    50); anything more involved wants
	 *    {@see albert_register_settings_section()}.
	 *
	 * Ids must be namespaced (`myplugin/logging`). Omitting `section`, or
	 * naming one that does not exist without a `section_title` to create it,
	 * puts the field in the shared "Other" card — the 1.1.0 behaviour, kept so
	 * an add-on written against that version still works, not the destination
	 * to aim for.
	 *
	 * @since 1.1.0
	 * @since 1.4.0 Accepts `min`, `max`, `suffix`, `info`, `show_in_rest`,
	 *              `section`, `section_title` and `section_priority`.
	 *
	 * @param array<string, mixed> $setting Field definition (simplified schema).
	 *
	 * @return void
	 */
	function albert_register_setting( array $setting ): void {
		$allowed_types = [ 'text', 'url', 'number', 'textarea', 'select', 'checkbox', 'radio-cards' ];

		$title       = isset( $setting['title'] ) && is_string( $setting['title'] ) ? $setting['title'] : '';
		$option_name = isset( $setting['option_name'] ) && is_string( $setting['option_name'] ) ? $setting['option_name'] : '';
		$type        = isset( $setting['type'] ) && is_string( $setting['type'] ) ? $setting['type'] : '';

		if ( $title === '' || $option_name === '' || $type === '' ) {
			_doing_it_wrong(
				'albert_register_setting',
				esc_html__( 'albert_register_setting() requires non-empty "title", "option_name", and "type" keys.', 'albert-ai-butler' ),
				'1.1.0'
			);
			return;
		}

		if ( ! in_array( $type, $allowed_types, true ) ) {
			_doing_it_wrong(
				'albert_register_setting',
				sprintf(
					/* translators: 1: option name, 2: comma-separated list of allowed types */
					esc_html__( 'albert_register_setting( "%1$s" ): "type" must be one of %2$s.', 'albert-ai-butler' ),
					esc_html( $option_name ),
					esc_html( implode( ', ', $allowed_types ) )
				),
				'1.1.0'
			);
			return;
		}

		if ( $type === 'select' || $type === 'radio-cards' ) {
			$options = $setting['options'] ?? null;
			if ( ! is_array( $options ) || empty( $options ) ) {
				_doing_it_wrong(
					'albert_register_setting',
					sprintf(
						/* translators: 1: option name, 2: field type */
						esc_html__( 'albert_register_setting( "%1$s" ): "%2$s" settings require a non-empty "options" array.', 'albert-ai-butler' ),
						esc_html( $option_name ),
						esc_html( $type )
					),
					'1.1.0'
				);
				return;
			}
		}

		// Translate the simplified schema to the internal field schema.
		$internal = [
			'id'          => $option_name,
			'option_name' => $option_name,
			'type'        => $type,
			'label'       => $title,
		];

		if ( isset( $setting['description'] ) && is_string( $setting['description'] ) ) {
			$internal['description'] = $setting['description'];
		}
		if ( array_key_exists( 'default', $setting ) ) {
			$internal['default'] = $setting['default'];
		}
		if ( isset( $setting['options'] ) && is_array( $setting['options'] ) ) {
			$internal['options'] = $setting['options'];
		}
		if ( isset( $setting['attributes'] ) && is_array( $setting['attributes'] ) ) {
			$internal['attributes'] = $setting['attributes'];
		}
		if ( isset( $setting['badge'] ) && is_string( $setting['badge'] ) ) {
			$internal['badge'] = $setting['badge'];
		}
		if ( isset( $setting['suffix'] ) && is_string( $setting['suffix'] ) ) {
			$internal['suffix'] = $setting['suffix'];
		}
		if ( isset( $setting['info'] ) && is_string( $setting['info'] ) ) {
			$internal['info'] = $setting['info'];
		}
		if ( ! empty( $setting['show_in_rest'] ) ) {
			$internal['show_in_rest'] = true;
		}

		// The declared range, which drives both the control's own attributes and
		// the sanitiser that clamps what is stored. Forwarded here because this
		// function rebuilds the field from a whitelist rather than merging, so a
		// key missing from that whitelist is dropped in silence: a field
		// declaring `max => 3650` through this API reached the sanitiser with no
		// bound at all and happily stored 99999.
		foreach ( [ 'min', 'max' ] as $bound ) {
			if ( isset( $setting[ $bound ] ) && is_numeric( $setting[ $bound ] ) ) {
				$internal[ $bound ] = $setting[ $bound ];
			}
		}

		$registry = \Albert\Admin\SettingsRegistry::instance();

		// A field may name the card it belongs in, and create that card in the
		// same call by giving it a title. Two ways to use it: name a card that
		// already exists (Free's own, or one another add-on registered) to add
		// to it, or name one of your own with a `section_title` to get a card
		// headed by what it is about.
		$section       = isset( $setting['section'] ) && is_string( $setting['section'] ) ? $setting['section'] : '';
		$section_title = isset( $setting['section_title'] ) && is_string( $setting['section_title'] ) ? $setting['section_title'] : '';

		if ( $section !== '' && strpos( $section, '/' ) === false ) {
			_doing_it_wrong(
				'albert_register_setting',
				sprintf(
					/* translators: %s: the section id that was passed */
					esc_html__( 'albert_register_setting(): "section" id "%s" must be namespaced (contain a "/"), e.g. "myplugin/logging".', 'albert-ai-butler' ),
					esc_html( $section )
				),
				'1.4.0'
			);
			$section = '';
		}

		if ( $section !== '' ) {
			if ( ! $registry->has_section( $section ) && $section_title !== '' ) {
				$registry->ensure_section_exists(
					[
						'id'       => $section,
						'title'    => $section_title,
						'priority' => isset( $setting['section_priority'] ) && is_int( $setting['section_priority'] )
							? $setting['section_priority']
							: 50,
					]
				);
			}

			// Still absent means a section was named that does not exist and no
			// title was given to create it. Fall through rather than fail: a
			// mistyped id should misplace the field, never lose it.
			if ( $registry->has_section( $section ) ) {
				$registry->append_field_to_section( $section, $internal );

				return;
			}
		}

		/*
		 * The last-resort card, for a registration that names no section.
		 *
		 * "Other", because nothing truer can be said about it. It cannot be
		 * named after what is inside: it holds whatever no add-on placed, so a
		 * title borrowed from the first occupant would be wrong the moment a
		 * second, unrelated setting landed beside it. Nor "Add-ons", which names
		 * who registered the setting rather than what the setting is, which is
		 * not what a heading on a settings screen is for.
		 *
		 * It exists for compatibility, not as the intended destination. Pass
		 * `section` and `section_title` and this card stays empty, which is
		 * where it should end up.
		 */
		$registry->ensure_section_exists(
			[
				'id'       => 'albert/settings',
				'title'    => __( 'Other', 'albert-ai-butler' ),
				'priority' => 50,
				'icon'     => 'admin-generic',
			]
		);
		$registry->append_field_to_section( 'albert/settings', $internal );
	}
}

if ( ! function_exists( 'albert_has_valid_license' ) ) {
	/**
	 * Check if an addon has a valid license.
	 *
	 * Both Albert Free and the Addon SDK define this function.
	 * Both read the same wp_options — whichever loads first wins.
	 *
	 * The function resolves the option key via the addon registry so
	 * callers can use either the addon's display slug ('extended-service')
	 * or the full option slug ('albert-extended-service').
	 *
	 * @since 1.1.0
	 *
	 * @param string $slug The addon slug (e.g., 'extended-service').
	 *
	 * @return bool True if the addon has a valid license.
	 */
	function albert_has_valid_license( string $slug ): bool {
		// Resolve the option slug from the addon registry.
		$option_slug = $slug;
		$addons      = AbstractAddon::get_registered_addons();
		if ( isset( $addons[ $slug ]['option_slug'] ) ) {
			$option_slug = $addons[ $slug ]['option_slug'];
		}

		$license_data = get_option( "{$option_slug}_license", false );

		if ( ! is_object( $license_data ) ) {
			return false;
		}

		if ( ( $license_data->license ?? '' ) !== 'valid' ) {
			return false;
		}

		if ( isset( $license_data->expires ) && $license_data->expires !== 'lifetime' ) {
			if ( strtotime( $license_data->expires ) < time() ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'albert_get_setting' ) ) {
	/**
	 * The value in force for one Albert setting.
	 *
	 * Prefer this over a bare `get_option()` when reading a setting an owner can
	 * see on the Settings screen: it honours a `wp-config.php` constant and the
	 * `albert/settings/value/{option_name}` filter, so a value pinned in code is
	 * what the site actually uses rather than what happens to be stored.
	 *
	 * Passing the default matters outside the admin. `register_setting()` runs
	 * on `admin_init`, so its registered default does not exist in cron, WP-CLI
	 * or an MCP request.
	 *
	 * @since 1.4.0
	 *
	 * @param string $option_name   The option name, e.g. `albert_privacy_mode`.
	 * @param mixed  $default_value Returned when nothing is stored.
	 *
	 * @return mixed
	 */
	function albert_get_setting( string $option_name, $default_value = null ) {
		return \Albert\Settings\Value::get( $option_name, $default_value );
	}
}
