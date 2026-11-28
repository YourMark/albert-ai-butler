<?php
/**
 * Settings Sanitizer
 *
 * Maps each built-in field type to a default sanitizer and dispatches to the
 * field's `sanitize_callback` when one is provided.
 *
 * @package    Albert
 * @subpackage Admin
 * @since      1.1.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Throwable;

/**
 * SettingsSanitizer class.
 *
 * Stateless helper invoked by the save loop on the unified Settings page.
 *
 * @since 1.1.0
 */
class SettingsSanitizer {

	/**
	 * Sanitize a field value before saving it to wp_options.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field     Normalised field definition.
	 * @param mixed                $raw_value Raw value pulled from $_POST.
	 *
	 * @return mixed Sanitized value.
	 */
	public function sanitize_field( array $field, $raw_value ) {
		$callback = isset( $field['sanitize_callback'] ) && is_callable( $field['sanitize_callback'] )
			? $field['sanitize_callback']
			: null;

		if ( $callback !== null ) {
			try {
				return call_user_func( $callback, $raw_value );
			} catch ( Throwable $e ) {
				$field_id = isset( $field['id'] ) && is_string( $field['id'] ) ? $field['id'] : '(unknown)';
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional error trail for addon authors.
					sprintf(
						'[Albert Settings] sanitize_callback for field "%s" threw: %s',
						$field_id,
						$e->getMessage()
					)
				);
				return array_key_exists( 'default', $field ) ? $field['default'] : null;
			}
		}

		$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text';

		switch ( $type ) {
			case 'url':
				$url = is_scalar( $raw_value ) ? esc_url_raw( (string) $raw_value ) : '';
				return rtrim( $url, '/' );

			case 'number':
				$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];
				$step       = $attributes['step'] ?? null;
				$is_decimal = is_string( $step ) && strpos( $step, '.' ) !== false;
				$has_min    = isset( $field['min'] ) && is_numeric( $field['min'] );
				$has_min    = $has_min || ( isset( $attributes['min'] ) && is_numeric( $attributes['min'] ) );

				if ( $is_decimal ) {
					$decimal = is_scalar( $raw_value ) ? (float) $raw_value : 0.0;

					// The integer branch below keeps its absint() floor when no
					// `min` is declared. Without the same floor here, a decimal
					// field declaring only a `max` stored an unbounded negative
					// silently — the one shape of number field where a missing
					// lower bound was not caught by anything.
					if ( ! $has_min && $decimal < 0.0 ) {
						$decimal = 0.0;
					}

					return $this->clamp( $field, $decimal );
				}

				// absint() takes the *absolute* value, so -5 arrives as 5 — a
				// floor of 0 would never see the negative it was declared to
				// catch, and somebody who typed -5 gets 5 with no explanation.
				// A declared `min` is the authority on the lower bound, so cast
				// plainly and let the clamp do its job. Without one, absint()
				// stays: it is what has kept these fields non-negative since
				// 1.1.0 and nothing should start storing negatives now.
				if ( ! is_scalar( $raw_value ) ) {
					return $this->clamp( $field, 0 );
				}

				return $this->clamp( $field, $has_min ? (int) $raw_value : absint( $raw_value ) );

			case 'textarea':
				return is_scalar( $raw_value ) ? sanitize_textarea_field( (string) $raw_value ) : '';

			// Radio cards are a select in everything that matters here: a closed
			// set of values, one chosen. They validate against the same option
			// keys rather than falling through to the text branch, where any
			// value POSTed for a field with no explicit sanitize_callback would
			// be stored as-is.
			case 'select':
			case 'radio-cards':
				$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];
				$value   = is_scalar( $raw_value ) ? (string) $raw_value : '';
				if ( array_key_exists( $value, $options ) ) {
					return $value;
				}
				return array_key_exists( 'default', $field ) ? $field['default'] : '';

			case 'checkbox':
				return $raw_value === '1' || $raw_value === 1 || $raw_value === true;

			case 'custom':
				// Custom fields without a sanitize_callback are skipped at the save layer; if we
				// somehow arrive here, return the default rather than persisting unsanitized data.
				return array_key_exists( 'default', $field ) ? $field['default'] : null;

			case 'text':
			default:
				return is_scalar( $raw_value ) ? sanitize_text_field( (string) $raw_value ) : '';
		}
	}

	/**
	 * Hold a number to the range its own field declares.
	 *
	 * `min` and `max` reach the `<input>` as attributes, where they are a hint to
	 * the browser and nothing else: a crafted POST ignores them, and so does any
	 * `update_option()` call on the registered option. Clamping here makes one
	 * declaration drive both the attribute and the stored value.
	 *
	 * Clamping is also *said*, not done quietly. Rewriting somebody's 99999 to
	 * 3650 without a word leaves them looking at a number they did not type and
	 * no way to know why.
	 *
	 * Idempotent, which matters because a registered option is sanitised twice
	 * on save — once by the settings screen and again inside `update_option()`.
	 * The second pass sees an in-range value and says nothing.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $field Normalised field definition.
	 * @param int|float            $value Coerced numeric value.
	 *
	 * @return int|float
	 */
	private function clamp( array $field, $value ) {
		$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];

		// Top level wins; `attributes` is where these lived before 1.4.0 and
		// keeps working, so a field declaring them either way is bounded.
		$min = $field['min'] ?? ( $attributes['min'] ?? null );
		$max = $field['max'] ?? ( $attributes['max'] ?? null );

		$clamped = $value;

		if ( is_numeric( $min ) && $clamped < $min ) {
			$clamped = $min + 0;
		}

		if ( is_numeric( $max ) && $clamped > $max ) {
			$clamped = $max + 0;
		}

		if ( $clamped === $value ) {
			return $value;
		}

		$this->report_clamp( $field, $value, $clamped );

		// Match the type the branch above produced: `$min + 0` on a string
		// attribute yields an int or float by PHP's own rules, which is what
		// is wanted, but an int field must not come back as a float.
		return is_float( $value ) ? (float) $clamped : (int) $clamped;
	}

	/**
	 * Tell the person saving that their value was changed, and to what.
	 *
	 * Guarded on the function rather than assumed: `add_settings_error()` lives
	 * in wp-admin, and a registered option's sanitiser can run from anywhere
	 * `update_option()` is called. There is nobody reading a settings screen in
	 * those contexts anyway.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $field    Normalised field definition.
	 * @param int|float            $value    What arrived.
	 * @param int|float            $clamped  What will be stored.
	 *
	 * @return void
	 */
	private function report_clamp( array $field, $value, $clamped ): void {
		if ( ! function_exists( 'add_settings_error' ) ) {
			return;
		}

		$label = isset( $field['label'] ) && is_string( $field['label'] ) && $field['label'] !== ''
			? $field['label']
			: ( isset( $field['id'] ) && is_string( $field['id'] ) ? $field['id'] : '' );

		$id = isset( $field['id'] ) && is_string( $field['id'] ) ? $field['id'] : 'field';

		add_settings_error(
			'albert_settings',
			$id . '_clamped',
			sprintf(
				/* translators: 1: field label, 2: the value that was entered, 3: the value saved instead */
				__( '%1$s: %2$s is outside the allowed range, so %3$s was saved instead.', 'albert-ai-butler' ),
				$label,
				$value,
				$clamped
			),
			'warning'
		);
	}
}
