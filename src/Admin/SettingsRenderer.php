<?php
/**
 * Settings Renderer
 *
 * Renders individual fields for the unified Albert Settings page.
 *
 * @package    Albert
 * @subpackage Admin
 * @since      1.1.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

use Albert\Settings\Lock;
use Albert\Settings\Value;
use Throwable;

/**
 * SettingsRenderer class.
 *
 * Stateless helper that knows how to render each built-in field type
 * and how to safely invoke a custom field's render callback. The
 * renderer NEVER opens a `<form>` — that is owned by the page.
 *
 * @since 1.1.0
 */
class SettingsRenderer {

	/**
	 * Render a single field.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current saved value (or default).
	 *
	 * @return void
	 */
	public function render_field( array $field, $current_value ): void {
		$option_name = $this->resolve_option_name( $field );

		// Whether something in code — a wp-config.php constant, a filter — owns
		// this value rather than the stored option. Asked once and passed down,
		// so the control, the value it shows and the note under it cannot
		// disagree about what is in force.
		$override = Value::override( $option_name );

		// A field may compute what it shows from something other than the stored
		// option. Applied before anything reads $current_value, so every branch
		// below sees the displayed value, not the stored one. An explicit
		// `display_value` wins over the automatic path: it is the escape hatch
		// for a value the generic resolver cannot express.
		if ( isset( $field['display_value'] ) && is_callable( $field['display_value'] ) ) {
			$current_value = call_user_func( $field['display_value'], $current_value );
		} elseif ( $override !== null ) {
			$current_value = $override['value'];
		}

		$current_value = $this->canonical_choice( $field, $current_value );

		$type        = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text';
		$label       = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
		$description = isset( $field['description'] ) && is_string( $field['description'] ) ? $field['description'] : '';
		$badge       = isset( $field['badge'] ) && is_string( $field['badge'] ) ? $field['badge'] : '';
		$input_id    = 'albert-field-' . str_replace( '/', '_', $option_name );

		if ( $type === 'custom' || $type === 'radio-cards' ) {
			// Custom and radio-cards fields keep a single-column layout: a custom
			// render callback (licenses table, copy-to-clipboard widgets) or a stack
			// of full-width cards neither fit the two-column grid below.
			//
			// A group of radios is wrapped in a <fieldset> and named by its
			// <legend>, which is how HTML names a group and what a screen reader
			// announces before each option. A <label> cannot do that job: it
			// names one control, and there is no single control here to name.
			// The `for` pointed at an id nothing on the page carried, so the
			// group had no accessible name at all and the label was inert.
			$is_group = $type === 'radio-cards';

			echo '<div class="albert-field-group albert-field-group--custom">';
			echo $is_group ? '<fieldset class="albert-field-fieldset">' : '';

			if ( $label !== '' ) {
				// The <legend> IS the label row, rather than sitting inside one.
				// A legend only captions its fieldset when it is that fieldset's
				// first child, so wrapping it in the row div would have left the
				// group unnamed while looking correct on screen, the same
				// silent failure as the `for` it replaces.
				if ( $is_group ) {
					echo '<legend class="albert-field-label albert-field-label-row">' . esc_html( $label );
				} else {
					echo '<div class="albert-field-label-row">';
					echo '<label class="albert-field-label" for="' . esc_attr( $input_id ) . '">' . esc_html( $label );
				}

				if ( $badge !== '' ) {
					echo ' <span class="albert-badge albert-badge--warning">' . esc_html( $badge ) . '</span>';
				}

				echo $is_group ? '' : '</label>';
				$this->render_info( $field, $label, $input_id );
				echo $is_group ? '</legend>' : '</div>';
			}
			if ( $description !== '' ) {
				printf(
					'<p class="albert-field-description" id="%1$s">%2$s</p>',
					esc_attr( $input_id . '-description' ),
					esc_html( $description )
				);
			}
			if ( $is_group ) {
				// The two-column branch assembles aria-describedby; this one
				// never did, so on a locked group neither the description nor
				// the hint explaining what owns the value was referenced by
				// anything at all.
				$group_described = [];

				if ( $description !== '' ) {
					$group_described[] = $input_id . '-description';
				}

				if ( $this->resolve_hint( $field, $override ) !== null ) {
					$group_described[] = $input_id . '-hint';
				}

				$this->render_radio_cards(
					$field,
					$current_value,
					$option_name,
					Lock::is_locked( $field, $override ),
					implode( ' ', $group_described )
				);
			} else {
				$this->render_custom( $field, $current_value );
			}
			$this->render_hint( $this->resolve_hint( $field, $override ), $input_id . '-hint' );
			echo $is_group ? '</fieldset>' : '';
			echo '</div>';
			return;
		}

		// Two-column compact row layout for built-in input types.
		echo '<div class="albert-field-group">';
		echo '<div class="albert-field-label-wrap">';
		// The label and its info control share a row; the description sits under
		// both. Without the row the wrap's flex column puts the "(i)" on its own
		// line, orphaned between the label and the text it explains.
		echo '<div class="albert-field-label-row">';
		echo '<label class="albert-field-label" for="' . esc_attr( $input_id ) . '">' . esc_html( $label );
		if ( $badge !== '' ) {
			echo ' <span class="albert-badge albert-badge--warning">' . esc_html( $badge ) . '</span>';
		}
		echo '</label>';
		$this->render_info( $field, $label, $input_id );
		echo '</div>';
		if ( $description !== '' ) {
			echo '<p class="albert-field-description">' . esc_html( $description ) . '</p>';
		}
		echo '</div>';

		$suffix    = isset( $field['suffix'] ) && is_string( $field['suffix'] ) ? $field['suffix'] : '';
		$suffix_id = $input_id . '-suffix';
		$hint      = $this->resolve_hint( $field, $override );
		$hint_id   = $input_id . '-hint';

		// `min` and `max` are field-level declarations that feed both the control
		// and the sanitiser, so the browser and the stored value cannot disagree
		// about the range. Copied into attributes here rather than duplicated in
		// every field definition. Anything already in `attributes` wins, so a
		// field that predates this keeps whatever it set.
		foreach ( [ 'min', 'max' ] as $bound ) {
			if ( isset( $field[ $bound ] ) && is_numeric( $field[ $bound ] ) && ! isset( $field['attributes'][ $bound ] ) ) {
				$field['attributes'][ $bound ] = $field[ $bound ];
			}
		}

		// A field whose value is currently owned by something else — a filter, a
		// constant, a network policy — is locked and says why. Resolved here
		// rather than by the caller so a field only has to declare the
		// condition, not hand-roll an <input> to express it.
		//
		// **`readonly` where the element supports it, not `disabled`.** A
		// disabled control leaves the tab order, so a keyboard or screen-reader
		// user could reach neither the value in force nor the hint underneath
		// explaining why they cannot change it: the one part of the field that
		// answers the question the state raises was the part that became
		// unreachable. `readonly` keeps it focusable and announced, and the
		// save loop skips a locked field outright ({@see Lock}), so it does not
		// matter that a readonly control is still submitted.
		//
		// `select`, `checkbox` and radio have no `readonly`, so those stay
		// `disabled`; `aria-disabled` states it either way.
		if ( Lock::is_locked( $field, $override ) ) {
			$takes_readonly = in_array( $type, [ 'text', 'url', 'number', 'textarea' ], true );

			$field['attributes'][ $takes_readonly ? 'readonly' : 'disabled' ] = true;
			$field['attributes']['aria-disabled']                             = 'true';
		}

		// A unit is part of what the value means, so it has to reach assistive
		// tech, not just the eye — "90" alone is not the same field as "90
		// days". The hint goes the same way, and matters more when it is the
		// sentence saying a constant owns this value. Associating both by id
		// rather than leaving them as loose adjacent text (which is not
		// reliably announced with the control) is what makes them part of the
		// field's description. Appended, never assigned, so a field that
		// already points at its own help text keeps it.
		$described_by = [];

		if ( isset( $field['attributes']['aria-describedby'] ) && is_string( $field['attributes']['aria-describedby'] ) ) {
			$described_by[] = trim( $field['attributes']['aria-describedby'] );
		}

		if ( $suffix !== '' ) {
			$described_by[] = $suffix_id;
		}

		if ( $hint !== null ) {
			$described_by[] = $hint_id;
		}

		$described_by = array_filter( $described_by );

		if ( $described_by !== [] ) {
			$field['attributes']['aria-describedby'] = implode( ' ', $described_by );
		}

		echo '<div class="albert-field-input-wrap">';
		switch ( $type ) {
			case 'textarea':
				$this->render_textarea( $field, $current_value, $option_name, $input_id );
				break;
			case 'select':
				$this->render_select( $field, $current_value, $option_name, $input_id );
				break;
			case 'checkbox':
				$this->render_checkbox( $field, $current_value, $option_name, $input_id );
				break;
			case 'number':
				$this->render_input( $field, $current_value, $option_name, $input_id, 'number' );
				break;
			case 'url':
				$this->render_input( $field, $current_value, $option_name, $input_id, 'url' );
				break;
			case 'text':
			default:
				$this->render_input( $field, $current_value, $option_name, $input_id, 'text' );
				break;
		}

		// The unit that qualifies the value, on the same line as it: "90 days".
		// Not a <label> — the field already has one, and a second one would be
		// announced as a competing name for the same control.
		if ( $suffix !== '' ) {
			echo '<span class="albert-field-suffix" id="' . esc_attr( $suffix_id ) . '">' . esc_html( $suffix ) . '</span>';
		}

		$this->render_hint( $hint, $hint_id );

		echo '</div>';

		echo '</div>';
	}

	/**
	 * Spell a closed-vocabulary value the way its own options spell it.
	 *
	 * A `select` or `radio-cards` control marks the option whose key matches
	 * the value exactly, so a value that means the right thing but is written
	 * differently marks nothing at all: the screen shows a group with no
	 * selection while the site runs perfectly well.
	 *
	 * That is reachable. {@see Value} hands back whatever the winning layer
	 * held, and a validator judges *meaning*, not spelling —
	 * `albert_privacy_mode`'s asks {@see \Albert\Privacy\PrivacyMode::try_parse()},
	 * which trims and lower-cases. So `define( 'ALBERT_PRIVACY_MODE', 'Strict' )`
	 * is accepted, the site runs Strict, and the group renders with no card
	 * chosen. The stored value can be off-vocabulary too: `register_setting()`
	 * only guards writes made during an admin request, so a cron or WP-CLI
	 * write reaches this the same way.
	 *
	 * The field's own `sanitize_callback` is what settles it, because that is
	 * already the one thing that knows how this option spells itself. Only
	 * consulted when the raw value is not an option key, and only kept when the
	 * result is one, so a field without a sanitiser, or one that answers with
	 * something equally unrecognised, is left exactly as it was rather than
	 * having a default quietly put in front of the reader.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value The value about to be displayed.
	 *
	 * @return mixed
	 */
	private function canonical_choice( array $field, $current_value ) {
		$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : '';

		if ( $type !== 'select' && $type !== 'radio-cards' ) {
			return $current_value;
		}

		$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];

		if ( ! is_scalar( $current_value ) || array_key_exists( (string) $current_value, $options ) ) {
			return $current_value;
		}

		$callback = isset( $field['sanitize_callback'] ) && is_callable( $field['sanitize_callback'] )
			? $field['sanitize_callback']
			: null;

		if ( $callback === null ) {
			return $current_value;
		}

		try {
			$canonical = call_user_func( $callback, $current_value );
		} catch ( Throwable $e ) {
			return $current_value;
		}

		return is_scalar( $canonical ) && array_key_exists( (string) $canonical, $options )
			? $canonical
			: $current_value;
	}

	/**
	 * Render the field's info control, if it has one.
	 *
	 * The description under a label is one line. Anything a reader needs only
	 * occasionally — an edge case, a consequence, what 0 does — goes here
	 * instead of lengthening that line. The rule the design system already
	 * states holds: **the sentence must work without opening the tip.** Never
	 * put required information behind it.
	 *
	 * The control itself is {@see InfoTip}, shared with every other screen, so
	 * a settings field and a card heading get the same one.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $field    Normalised field definition.
	 * @param string               $label    The field's visible label, for the accessible name.
	 * @param string               $input_id The control's id, used to derive the popover's.
	 *
	 * @return void
	 */
	private function render_info( array $field, string $label, string $input_id ): void {
		$info = isset( $field['info'] ) && is_string( $field['info'] ) ? $field['info'] : '';

		InfoTip::render( $info, $label, $input_id . '-info' );
	}

	/**
	 * What this field's hint says right now, if it says anything.
	 *
	 * Resolved separately from rendering because the control needs to know
	 * whether a hint exists *before* it is drawn: a locked control points
	 * `aria-describedby` at the hint's id, and there is no id to point at when
	 * there is no hint. Asking twice would mean calling a field's hint callback
	 * twice per render, which is not a promise this class wants to make to the
	 * add-ons that write them.
	 *
	 * `hint` is a callable or an array `[ 'text' => string, 'tone' => string ]`.
	 * A callable may return null when there is nothing to say, which is the
	 * common case: most fields have a hint only in an unusual state.
	 *
	 * A field that says nothing while an override is active gets a generated
	 * hint naming the source, so a locked control always explains itself. A
	 * field's own hint wins, because it can say more: the upload size limit
	 * gives the exact size in force, which the generic text cannot know.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed>                                   $field    Normalised field definition.
	 * @param array{source: string, value: mixed, name: string}|null $override Active override, if any.
	 *
	 * @return array{text: string, tone: string}|null
	 */
	private function resolve_hint( array $field, ?array $override = null ): ?array {
		$hint = $field['hint'] ?? null;

		if ( is_callable( $hint ) ) {
			$hint = call_user_func( $hint );
		}

		if ( ( ! is_array( $hint ) || empty( $hint['text'] ) ) && $override !== null ) {
			$hint = $this->override_hint( $override );
		}

		if ( ! is_array( $hint ) || empty( $hint['text'] ) || ! is_string( $hint['text'] ) ) {
			return null;
		}

		return [
			'text' => $hint['text'],
			'tone' => isset( $hint['tone'] ) && in_array( $hint['tone'], [ 'info', 'warning' ], true )
				? (string) $hint['tone']
				: 'info',
		];
	}

	/**
	 * Draw a resolved hint.
	 *
	 * The hint sits under the control, inside the control column, because it
	 * explains the control rather than the setting — "a filter is overriding
	 * this" is about the state of the box, not about what the setting means.
	 * What the setting means is the description, beside the label.
	 *
	 * The text may contain `<code>`, and only `<code>`: these strings name
	 * filters and constants, and every one is built by the field's own author
	 * through sprintf(), never from user input.
	 *
	 * @since 1.4.0
	 *
	 * @param array{text: string, tone: string}|null $hint Resolved hint, or null for none.
	 * @param string                                 $id   Id the control's `aria-describedby` points at.
	 *
	 * @return void
	 */
	private function render_hint( ?array $hint, string $id ): void {
		if ( $hint === null ) {
			return;
		}

		$icon = $hint['tone'] === 'warning' ? 'warning' : 'info';

		echo '<div class="albert-hint albert-hint--' . esc_attr( $hint['tone'] ) . ' albert-field-hint" id="' . esc_attr( $id ) . '">';
		echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		echo '<p>' . wp_kses( $hint['text'], [ 'code' => [] ] ) . '</p>';
		echo '</div>';
	}

	/**
	 * Resolve the wp_options key for a field, honouring the optional override.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field Normalised field definition.
	 *
	 * @return string
	 */
	private function resolve_option_name( array $field ): string {
		// Read, never derive. The Settings screen stamps every field with its
		// resolved name in `Settings::resolve_option_names()`, and that is the
		// single derivation for both the input's `name` and the key the save
		// loop reads from $_POST.
		//
		// This used to fall back to `$field['id']` when `option_name` was
		// absent, which is where the privacy-mode bug lived: the save loop
		// derived `{section}_{field}` instead, so the two never matched and the
		// setting could not be saved at all. There is deliberately no fallback
		// now — a field arriving here without a name is a caller error, and a
		// control with `name=""` submits nothing, which is a visible failure
		// rather than a value silently reverting on every save.
		return isset( $field['option_name'] ) && is_string( $field['option_name'] )
			? $field['option_name']
			: '';
	}

	/**
	 * Print a field's `attributes` array as HTML attributes, escaped here.
	 *
	 * Skips `name`, `id`, `type`, `value`, and `checked` — those are owned
	 * by the renderer for each input type — and any name that is not a valid
	 * attribute name.
	 *
	 * @since 1.1.0
	 * @since 1.5.1 Prints instead of returning a string, so it escapes at output.
	 *
	 * @param array<string, mixed> $attributes Attribute => value pairs.
	 *
	 * @return void
	 */
	private function print_attributes( array $attributes ): void {
		$reserved = [ 'name', 'id', 'type', 'value', 'checked' ];
		foreach ( $attributes as $attr => $value ) {
			if ( ! is_string( $attr ) || preg_match( '/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $attr ) !== 1 ) {
				continue;
			}
			if ( in_array( $attr, $reserved, true ) ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				if ( $value ) {
					echo ' ' . esc_attr( $attr );
				}
				continue;
			}
			if ( is_scalar( $value ) ) {
				echo ' ' . esc_attr( $attr ) . '="' . esc_attr( (string) $value ) . '"';
			}
		}
	}

	/**
	 * Render a text/url/number input.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current value.
	 * @param string               $option_name   wp_options key.
	 * @param string               $input_id      HTML id attribute.
	 * @param string               $html_type     HTML input type.
	 *
	 * @return void
	 */
	private function render_input( array $field, $current_value, string $option_name, string $input_id, string $html_type ): void {
		$value      = $current_value === null ? '' : (string) $current_value;
		$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];

		printf(
			'<input type="%1$s" name="%2$s" id="%3$s" value="%4$s" class="albert-text-input"',
			esc_attr( $html_type ),
			esc_attr( $option_name ),
			esc_attr( $input_id ),
			esc_attr( $value )
		);
		$this->print_attributes( $attributes );
		echo ' />';
	}

	/**
	 * Render a textarea.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current value.
	 * @param string               $option_name   wp_options key.
	 * @param string               $input_id      HTML id attribute.
	 *
	 * @return void
	 */
	private function render_textarea( array $field, $current_value, string $option_name, string $input_id ): void {
		$value      = $current_value === null ? '' : (string) $current_value;
		$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];

		printf(
			'<textarea name="%1$s" id="%2$s" class="albert-textarea"',
			esc_attr( $option_name ),
			esc_attr( $input_id )
		);
		$this->print_attributes( $attributes );
		echo '>' . esc_textarea( $value ) . '</textarea>';
	}

	/**
	 * Render a select dropdown.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current value.
	 * @param string               $option_name   wp_options key.
	 * @param string               $input_id      HTML id attribute.
	 *
	 * @return void
	 */
	private function render_select( array $field, $current_value, string $option_name, string $input_id ): void {
		$options    = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];
		$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];
		$value      = $current_value === null ? '' : (string) $current_value;

		printf(
			'<select name="%1$s" id="%2$s" class="albert-select"',
			esc_attr( $option_name ),
			esc_attr( $input_id )
		);
		$this->print_attributes( $attributes );
		echo '>';

		foreach ( $options as $opt_value => $opt_label ) {
			$opt_value_str = (string) $opt_value;
			$opt_label_str = is_scalar( $opt_label ) ? (string) $opt_label : '';
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $opt_value_str ),
				selected( $value, $opt_value_str, false ),
				esc_html( $opt_label_str )
			);
		}

		echo '</select>';
	}

	/**
	 * Render a set of radio cards: one option per card, each with its own
	 * title, description, and an optional "Recommended" badge.
	 *
	 * `options` takes the same shape select's does — `value => label` — plus
	 * two optional per-option keys the plain `<select>` has no way to carry:
	 * `description` (shown under the title) and `recommended` (renders the
	 * badge). A bare string label works too, matching `select`'s shape.
	 *
	 * @since 1.4.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current value.
	 * @param string               $option_name   wp_options key.
	 * @param bool                 $disabled      Whether code owns this value.
	 * @param string               $describedby   Space-separated ids that describe the group.
	 *
	 * @return void
	 */
	private function render_radio_cards( array $field, $current_value, string $option_name, bool $disabled = false, string $describedby = '' ): void {
		$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];
		$value   = $current_value === null ? '' : (string) $current_value;

		// No `role="radiogroup"`: render_field() wraps this in a <fieldset> with
		// a <legend>, which is the native grouping and the only one of the two
		// that carries a name. An ARIA role on top would announce a second,
		// anonymous group around the same radios.
		//
		// Disabled rather than hidden: the choices stay readable, so somebody
		// can still see which one is in force and what the alternatives are.
		printf(
			'<div class="albert-radio-cards%s">',
			esc_attr( $disabled ? ' albert-radio-cards--disabled' : '' )
		);

		foreach ( $options as $opt_value => $opt ) {
			$opt_value_str   = (string) $opt_value;
			$title           = is_array( $opt ) ? (string) ( $opt['label'] ?? $opt['title'] ?? '' ) : (string) $opt;
			$opt_description = is_array( $opt ) ? (string) ( $opt['description'] ?? '' ) : '';
			$recommended     = is_array( $opt ) && ! empty( $opt['recommended'] );
			$checked         = $value === $opt_value_str;
			$radio_id        = $option_name . '-' . sanitize_key( $opt_value_str );

			printf(
				'<label for="%1$s" class="albert-radio-card%2$s">',
				esc_attr( $radio_id ),
				esc_attr( $checked ? ' albert-radio-card--checked' : '' )
			);
			// aria-disabled, not disabled. This file argues at length elsewhere
			// that a locked field must stay reachable, so a keyboard or screen
			// reader user can find both the value in force and the sentence
			// explaining what owns it — and then the radio branch used
			// disabled(), which removes the whole group from the tab order and
			// takes the explanation with it. HTML radios have no readonly, so
			// the equivalent is to leave them focusable, mark them
			// aria-disabled, and have the script put back any change.
			printf(
				'<input type="radio" name="%1$s" id="%2$s" value="%3$s"%4$s%5$s%6$s />',
				esc_attr( $option_name ),
				esc_attr( $radio_id ),
				esc_attr( $opt_value_str ),
				checked( $checked, true, false ),
				$disabled ? ' aria-disabled="true" data-albert-locked="1"' : '',
				$describedby !== '' ? ' aria-describedby="' . esc_attr( $describedby ) . '"' : ''
			);
			echo '<span class="albert-radio-card__body">';
			echo '<span class="albert-radio-card__title">' . esc_html( $title );
			if ( $recommended ) {
				echo ' <span class="albert-badge albert-badge--info">' . esc_html__( 'Recommended', 'albert-ai-butler' ) . '</span>';
			}
			echo '</span>';
			if ( $opt_description !== '' ) {
				echo '<span class="albert-radio-card__description">' . esc_html( $opt_description ) . '</span>';
			}
			echo '</span>';
			echo '</label>';
		}

		echo '</div>';
	}

	/**
	 * Render a checkbox with a paired hidden input so unchecked submits as "0".
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current value.
	 * @param string               $option_name   wp_options key.
	 * @param string               $input_id      HTML id attribute.
	 *
	 * @return void
	 */
	private function render_checkbox( array $field, $current_value, string $option_name, string $input_id ): void {
		$attributes = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : [];
		$checked    = (bool) $current_value;

		// The hidden input is intentionally rendered FIRST so PHP only sees "0" when the
		// checkbox is unchecked — when checked, the visible input overrides it with "1".
		printf(
			'<input type="hidden" name="%1$s" value="0" />',
			esc_attr( $option_name )
		);
		printf(
			'<label class="albert-checkbox-wrap"><input type="checkbox" name="%1$s" id="%2$s" value="1"%3$s',
			esc_attr( $option_name ),
			esc_attr( $input_id ),
			checked( $checked, true, false )
		);
		$this->print_attributes( $attributes );
		echo ' />';
		echo '</label>';
	}

	/**
	 * Invoke a custom render callback safely.
	 *
	 * Catches any throwable so a buggy addon callback can't take down the
	 * whole settings page.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $field         Normalised field definition.
	 * @param mixed                $current_value Current value.
	 *
	 * @return void
	 */
	private function render_custom( array $field, $current_value ): void {
		$callback = isset( $field['render_callback'] ) && is_callable( $field['render_callback'] ) ? $field['render_callback'] : null;
		if ( $callback === null ) {
			return;
		}

		try {
			call_user_func( $callback, $field, $current_value );
		} catch ( Throwable $e ) {
			$field_id = isset( $field['id'] ) && is_string( $field['id'] ) ? $field['id'] : '(unknown)';
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional error trail for addon authors.
				sprintf(
					'[Albert Settings] render_callback for field "%s" threw: %s',
					$field_id,
					$e->getMessage()
				)
			);
			echo '<div class="notice notice-error inline"><p>'
				. esc_html__( 'A settings field could not be rendered. Check the PHP error log for details.', 'albert-ai-butler' )
				. '</p></div>';
		}
	}

	/**
	 * The generated hint for a setting owned by code.
	 *
	 * Names the constant or filter, because "this is set elsewhere" without
	 * saying where leaves somebody grepping. Both names are developer-authored
	 * identifiers, never user input, and reach the page through the same
	 * `<code>`-only allowlist as any other hint.
	 *
	 * @since 1.4.0
	 *
	 * @param array{source: string, value: mixed, name: string} $override Active override.
	 *
	 * @return array{text: string, tone: string}
	 */
	private function override_hint( array $override ): array {
		$name = isset( $override['name'] ) && is_string( $override['name'] ) ? $override['name'] : '';

		if ( ( $override['source'] ?? '' ) === 'constant' ) {
			$text = sprintf(
				/* translators: 1: opening <code>, 2: closing </code> wrapping a PHP constant name */
				__( 'Set by the %1$s%3$s%2$s constant in this site\'s configuration, so it can\'t be changed here.', 'albert-ai-butler' ),
				'<code>',
				'</code>',
				$name
			);
		} else {
			$text = sprintf(
				/* translators: 1: opening <code>, 2: closing </code> wrapping a filter name */
				__( 'Set in code by the %1$s%3$s%2$s filter, so it can\'t be changed here.', 'albert-ai-butler' ),
				'<code>',
				'</code>',
				$name
			);
		}

		return [
			'text' => $text,
			'tone' => 'info',
		];
	}
}
