<?php
/**
 * Info Tip
 *
 * @package Albert
 * @subpackage Admin
 * @since      1.4.0
 */

namespace Albert\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The "(i)" control, for server-rendered screens.
 *
 * One implementation of a control Albert uses everywhere: beside a settings
 * label, a card title, a table heading, a toggle row — anywhere a term sits on
 * the line of common knowledge, or a rule has an edge case that would bloat the
 * sentence next to it.
 *
 * **The rule, from docs/design-system.md:** the sentence must read correctly
 * without opening the tip. It adds the mechanism, the edge case, the
 * consequence. It never carries information the reader needs in order to
 * understand what the control does.
 *
 * This is the server-rendered half of a pair. React screens use
 * `assets/src/shared/InfoPopover.jsx`, which wraps core's `Dropdown` and gets
 * collision detection and focus management from it. The split is by rendering
 * context, never by screen: reaching for this one inside a React tree is how
 * the Context screen once ended up with a second, worse copy of a control the
 * Abilities screen already had.
 *
 * Presentation lives in `albert-primitives.css` (`.albert-tip`) and behaviour
 * in `assets/js/admin-popover.js`, which {@see Assets::enqueue_on_albert_screens()}
 * loads on every Albert screen. Nothing needs enqueueing to use this.
 *
 * @since 1.4.0
 */
class InfoTip {

	/**
	 * The markup an info tip is made of, for wp_kses() at output.
	 *
	 * @since 1.5.1
	 * @var array<string, array<string, bool>>
	 */
	public const ALLOWED_HTML = [
		'span'   => [
			'class'       => true,
			'id'          => true,
			'role'        => true,
			'hidden'      => true,
			'aria-hidden' => true,
		],
		'button' => [
			'type'          => true,
			'class'         => true,
			'aria-expanded' => true,
			'aria-controls' => true,
			'aria-label'    => true,
		],
		'code'   => [],
	];

	/**
	 * Counter guaranteeing a unique id per tip within one request.
	 *
	 * The popover needs an id so the trigger can point `aria-controls` at it.
	 * Callers rarely have a natural id to hand, and two tips sharing one would
	 * make the attribute a lie, so an unspecified id is generated rather than
	 * omitted.
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private static int $sequence = 0;

	/**
	 * Echo an info tip.
	 *
	 * @since 1.4.0
	 *
	 * @param string $text  The explanation. May contain `<code>`, and nothing
	 *                      else: these strings name filters, constants and
	 *                      capabilities, and are authored in the codebase rather
	 *                      than supplied by a user.
	 * @param string $label What the tip is about, used to build the trigger's
	 *                      accessible name ("More about Invitation expiry"). A
	 *                      bare "(i)" button announces as nothing useful when a
	 *                      screen has several.
	 * @param string $id    Optional explicit popover id.
	 *
	 * @return void
	 */
	public static function render( string $text, string $label, string $id = '' ): void {
		echo wp_kses( self::get( $text, $label, $id ), self::ALLOWED_HTML );
	}

	/**
	 * Build an info tip and return it.
	 *
	 * Use when the markup has to be composed into a larger string rather than
	 * echoed in place, as {@see SettingsRenderer} does inside a printf().
	 *
	 * @since 1.4.0
	 *
	 * @param string $text  The explanation (see {@see self::render()}).
	 * @param string $label What the tip is about.
	 * @param string $id    Optional explicit popover id.
	 *
	 * @return string Escaped markup, or an empty string when there is nothing to say.
	 */
	public static function get( string $text, string $label, string $id = '' ): string {
		if ( trim( $text ) === '' ) {
			return '';
		}

		if ( $id === '' ) {
			$id = 'albert-tip-' . ( ++self::$sequence );
		}

		return sprintf(
			// The popover is the trigger's *next sibling* on purpose:
			// admin-popover.js finds it with `nextElementSibling`, so anything
			// inserted between the two silently breaks the control.
			// Every element here is phrasing content, `<span>` rather than the
			// `<div>` the popover used to be. That is what makes this control
			// legal, and so reliably rendered, inside a `<legend>`, a `<p>` or
			// a `<label>`, which is exactly where a tip beside a field's name
			// belongs. It is absolutely positioned either way, so nothing about
			// the appearance depends on the tag.
			'<span class="albert-tip"><button type="button" class="albert-tip__trigger" aria-expanded="false" aria-controls="%1$s" aria-label="%2$s"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span></button><span class="albert-tip__popover" id="%1$s" role="note" hidden>%3$s</span></span>',
			esc_attr( $id ),
			esc_attr(
				sprintf(
					/* translators: %s: the label of the thing being explained. */
					__( 'More about %s', 'albert-ai-butler' ),
					$label
				)
			),
			wp_kses( $text, [ 'code' => [] ] )
		);
	}
}
