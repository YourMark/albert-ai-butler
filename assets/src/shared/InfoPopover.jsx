/**
 * The info "(i)" control, shared by every Albert React screen.
 *
 * For a term sitting on the line of common knowledge: "tokens", "capability",
 * "MCP endpoint". The rule that keeps it honest is that the sentence around it
 * has to read correctly without opening it. Nothing required goes inside.
 *
 * Built on core's `Dropdown`, which handles open state, Escape, outside-click,
 * focus-on-mount and collision detection. An earlier version of the Context
 * screen hand-rolled all of that against a CSS-anchored popover, which was both
 * a second implementation of the same control and the weaker one: no focus
 * management on open, no repositioning when it would overflow, and a placement
 * that a clipping ancestor could cut off.
 *
 * Two placement details, both learned the hard way.
 *
 * `bottom-start` aligns the popover's leading edge with the trigger's, so it
 * grows into the page. Plain `bottom` centres it, and a 300px panel centred on a
 * trigger near the left of a card hangs off into the admin sidebar.
 *
 * `inline` is opt-in rather than the default. Rendered inline the popover stays
 * inside a focus trap, which the Abilities fly-in needs, but it also confines
 * positioning to the flow it was rendered in, so an ancestor that clips or a
 * narrow column can trap it. Everywhere without a focus trap, let it portal and
 * let floating-ui do collision handling properly.
 *
 * Server-rendered Albert screens cannot use this and have their own CSS-anchored
 * equivalent (`.albert-tip` in the primitives). That split is by rendering
 * context, not by screen, and it is the only reason two implementations exist.
 */
import { Button, Dropdown } from '@wordpress/components';

/**
 * Render an info control.
 *
 * @param {Object}  props          Props.
 * @param {string}  props.label    Accessible name for the trigger, e.g. "About permissions".
 * @param {Element} props.children Popover content.
 * @param {boolean} [props.inline] Render in place instead of portalling. Set it when the
 *                                 control sits inside a focus trap, as the fly-in does.
 * @return {Element} The info control.
 */
export default function InfoPopover( { label, children, inline = false } ) {
	return (
		<Dropdown
			className="albert-info"
			focusOnMount={ true }
			popoverProps={ {
				placement: 'bottom-start',
				inline,
				offset: 10,
				className: 'albert-info__popover',
			} }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					size="small"
					label={ label }
					onClick={ onToggle }
					aria-expanded={ isOpen }
				>
					<span
						className="dashicons dashicons-info-outline"
						aria-hidden="true"
					/>
				</Button>
			) }
			renderContent={ () => (
				<div className="albert-info__content">{ children }</div>
			) }
		/>
	);
}
