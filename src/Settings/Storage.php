<?php
/**
 * Settings Storage
 *
 * @package Albert
 * @subpackage Settings
 * @since      1.4.0
 */

namespace Albert\Settings;

defined( 'ABSPATH' ) || exit;

use Albert\Admin\SettingsSanitizer;
use Albert\Contracts\Interfaces\Hookable;

/**
 * Hands every registered Albert setting to WordPress's `register_setting()`.
 *
 * This is the storage half of WordPress's Settings API, and only that half.
 * `add_settings_field()`, `do_settings_sections()` and `settings_fields()` — the
 * half that emits a `<table class="form-table">` — are deliberately never
 * called. Albert renders every control itself and the form posts to
 * `admin-post.php` with Albert's own nonce, so nothing here changes a pixel.
 *
 * What registering buys, in order of how much it matters:
 *
 * 1. **Sanitisation becomes a property of the option rather than of the form.**
 *    `register_setting()` hooks `sanitize_option_{$option}`, and
 *    `update_option()` applies that filter itself. Without it a sanitiser runs
 *    only on the settings-screen POST, so an add-on calling
 *    `update_option( 'albert_upload_link_max_mb', 'nonsense' )` stores nonsense.
 *    This is the one benefit that cannot be had by writing more of our own
 *    code: the enforcement point is inside core.
 * 2. **One default, declared once.** `register_setting()`'s `default` is served
 *    by `get_option()` without every caller restating it, which is what stops a
 *    default drifting between the field definition and the class that reads it.
 * 3. **Opt-in REST exposure**, per field, never blanket.
 *
 * **Scope of (1), stated plainly.** A registration only protects a write that
 * happens after it. This runs on `admin_init`, so the guarantee covers admin
 * requests, admin-ajax and the settings POST — **not** WP-Cron and not WP-CLI.
 * Registering on `init` instead would cover everything but would run every
 * add-on's registration callback on every front-end request, which is not worth
 * it until a real bypass turns up. Do not assume a cron write is sanitised.
 *
 * **What that costs add-ons.** The schema is collected on every admin request,
 * admin-ajax included, because that is what `admin_init` means — so
 * `albert/settings/register` fires there too, and every add-on's registration
 * callback runs with it. {@see Schema::collect()}
 * memoises, so it is once per request rather than once per consumer, but a
 * registration callback must be cheap and free of side effects. It is a
 * declaration, not a place to do work.
 *
 * **Sanitisers must be idempotent.** The save loop sanitises before calling
 * `update_option()`, which sanitises again through the registered callback. A
 * callback therefore has to return clean input unchanged, and must not repeat a
 * side effect on a second pass. Free's own clamping sanitiser satisfies this:
 * it raises an `add_settings_error()` when it clamps, and the second pass sees
 * the already-clamped value and raises nothing.
 *
 * @since 1.4.0
 */
class Storage implements Hookable {

	/**
	 * The option group every Albert setting is registered under.
	 *
	 * Vestigial, and named here so nobody goes hunting for its other half. A
	 * group exists so `settings_fields()` can emit the matching hidden inputs
	 * for an `options.php` submission — a flow Albert does not use, because the
	 * screen posts to `admin-post.php` with its own nonce. `register_setting()`
	 * simply requires the argument.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	public const GROUP = 'albert';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register every field the schema knows about.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register_settings(): void {
		foreach ( Schema::collect() as $section ) {
			$fields = isset( $section['fields'] ) && is_array( $section['fields'] ) ? $section['fields'] : [];

			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}

				$this->register_field( $field );
			}
		}
	}

	/**
	 * Register one field, if it stores anything.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $field Normalised field, name already resolved.
	 *
	 * @return void
	 */
	private function register_field( array $field ): void {
		$option_name = isset( $field['option_name'] ) && is_string( $field['option_name'] ) ? $field['option_name'] : '';

		if ( $option_name === '' ) {
			return;
		}

		// A read-only custom field (the licences table) marks itself with the
		// `__return_null` sanitiser and never persists a value, so there is no
		// option to register.
		$sanitize = $field['sanitize_callback'] ?? null;
		if ( is_string( $sanitize ) && $sanitize === '__return_null' ) {
			return;
		}

		$args = [
			'type'              => $this->rest_type( $field ),
			'sanitize_callback' => $this->sanitizer_for( $field ),
			// Opt-in, per field. A setting is not exposed over REST because it
			// happens to exist.
			'show_in_rest'      => ! empty( $field['show_in_rest'] ),
		];

		// Distinguish "no default" from "a default of null": array_key_exists,
		// not isset, and only pass the key when there is one to pass — handing
		// `null` to register_setting() would make get_option() return null
		// instead of false for an unset option, which is a different contract.
		if ( array_key_exists( 'default', $field ) && $field['default'] !== null ) {
			$args['default'] = $field['default'];
		}

		register_setting( self::GROUP, $option_name, $args );
	}

	/**
	 * The schema type core should record for this field.
	 *
	 * Only meaningful to REST and to core's own type coercion; Albert's
	 * sanitisers remain the authority on the stored shape.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $field Normalised field.
	 *
	 * @return string One of integer|number|boolean|string.
	 */
	private function rest_type( array $field ): string {
		$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text';

		if ( $type === 'checkbox' ) {
			return 'boolean';
		}

		if ( $type === 'number' ) {
			// Mirrors SettingsSanitizer: a decimal `step` means a float field.
			$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];
			$step       = $attributes['step'] ?? null;

			return is_string( $step ) && strpos( $step, '.' ) !== false ? 'number' : 'integer';
		}

		return 'string';
	}

	/**
	 * A core-shaped sanitiser for this field.
	 *
	 * Core calls `sanitize_callback( $value )`; Albert's takes the field
	 * definition too. Binding the field into a closure keeps one sanitiser
	 * implementation rather than a second one shaped for core.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $field Normalised field.
	 *
	 * @return callable
	 */
	private function sanitizer_for( array $field ): callable {
		return static function ( $value ) use ( $field ) {
			return ( new SettingsSanitizer() )->sanitize_field( $field, $value );
		};
	}
}
