# Albert admin design system

One visual language for every Albert screen, in the free plugin and in every
add-on. This document is the reference: if you are building a new Albert screen,
everything you need is named here.

**Source of truth is the code, not this file.** `assets/css/albert-tokens.css`
holds the values; `assets/css/albert-primitives.css` holds the components. Every
number below was measured against the shipped stylesheet, not transcribed from a
design file.

## How to use it

Declare the primitives handle as a dependency. It pulls the tokens in ahead of
itself, so naming one handle is enough:

```php
wp_enqueue_style( 'my-screen', $url, [ 'albert-primitives' ], $version );
```

In the free plugin, use the constants: `Albert\Admin\Assets::PRIMITIVES_HANDLE`
and `Albert\Admin\Assets::TOKENS_HANDLE`. In an add-on, use the literal strings
`'albert-primitives'` / `'albert-tokens'`, so an older Albert release without
those classes cannot fatal your plugin.

Any page registered under the Albert menu also gets the primitives enqueued
automatically, because the page navigation renders on every Albert screen and
has to be styled wherever it appears.

### Two rules

1. **Consume tokens; declare no raw colour.** If a value you need is missing, add
   it to the token sheet rather than inlining it. A hex in a component stylesheet
   is a value that cannot follow the admin colour scheme and cannot be measured
   from one place.
2. **Every accessibility claim carries its measurement.** "AA compliant" is not a
   claim. "`text-faint` on `surface` = 4.92:1, SC 1.4.3 requires 4.5:1" is. The
   tables below are generated from the stylesheet so they cannot drift from it.

## Buttons and switches: use WordPress's

There is deliberately no Albert button. WordPress ships `.button`,
`.button-primary` and `.button-secondary`; they already follow the admin colour
scheme, they are maintained by core, and they make Albert look like the rest of
wp-admin. Use them.

The same goes for switches: use `FormToggle` from `@wordpress/components`.

A `.albert-switch` primitive briefly existed and was removed in 1.4.0 without
ever shipping. It measured 40 × 22px while the Abilities screen, built earlier on
core's `FormToggle`, measured 32 × 16px, so the two Albert screens sitting next
to each other in the same menu had visibly different switches. The rule that
already governed buttons answers this too, and it should have been applied the
first time: if core ships the control, Albert does not draw its own.

Name the control after the thing it switches and let the checked state carry
on/off: `aria-label="Design tokens included"`, not `"Enable Design tokens"`,
which announces "Enable Design tokens, checked" for a section already included.

## Colour

The palette is WordPress's own wherever WordPress has one: 17 of 21 values are
literal core colours. Four deviate, each for a stated reason:

| Token | Value | Why not core's |
|---|---|---|
| `success` | `#0a7a3b` | Core's greens are for dots and icons, not text. `#00a32a` measures 3.35:1 on white and fails SC 1.4.3; our pills put text in this colour. |
| `warning` | `#9a5b00` | Same reason, worse: core's `#dba617` is 2.22:1 and `#bd8600` is 3.19:1. |
| `surface-sunken` | `#fbfbfc` | A near-white a shade lighter than core's `#f6f7f7`, so a sunken area reads as recessed next to `surface` rather than as a separate card. |
| `text-faint` | `#6c7177` | A third grey tier below `text-muted`, for micro labels and ids. |

Everything is `oklch()`, so a lightness change is only a lightness change and
hues stay put. Values converted from the source design's hex round-trip to that
hex exactly.

### The accent comes from the site

`--albert-color-accent` is `var(--wp-admin-theme-color, …)` and is declared on
`body`, **not** on `:root`. This matters and is easy to get wrong: WordPress sets
the scheme colour on the body class (`body.admin-color-modern { … }`) while
`:root` carries only a base default. Resolving the `var()` at `:root` captures
the default instead of the scheme the user chose, and because custom properties
inherit, that wrong value then reaches every element. Everything derived from the
accent (the tints and the focus ring) is declared alongside it for the same
reason.

All nine admin colour schemes WordPress ships clear 4.5:1 against
`accent-contrast`; the tightest is "light" at 4.57:1. A custom scheme registered
by another plugin is not covered by that guarantee.

### Three corrections to the source design

Each failed a WCAG success criterion as specified, and was corrected by moving
OKLCh lightness only. Hue and chroma stay as designed, and the value moves the
least distance that clears the threshold.

| Token | Was | Measured | Now | Criterion |
|---|---|---|---|---|
| `border-input` | `#dcdcde` | 1.37:1 | `#949494` (core's own input border) | 1.4.11 |
| `switch-off` | `#a7aaad` | 2.33:1 | `oklch(0.66 0.01 265)` | 1.4.11 |
| `*-contrast` | did not exist | n/a | new tokens | 1.4.3 |

The `*-contrast` tokens are the label colour on any filled control. The source
design specified "accent surface, white text" but shipped no token for the
label, so every filled control hardcoded white, which is rule 1's failure mode,
not a colour bug. Naming the role is what lets a component consume a token
instead.

### Tints

Tints are derived with `color-mix(in oklab, …)` off the semantic tokens rather
than declared as a second set of literals, so a tint cannot drift from the colour
it tints. Mixed in oklab because sRGB mixing darkens and desaturates through the
middle of the range.

Tints are backgrounds only. None is ever a text colour, so they carry no contrast
obligation of their own; the text that sits on them does.

## Measured contrast

Generated from `assets/css/albert-tokens.css`. Text pairs are held to SC 1.4.3
(4.5:1); control boundaries, switch tracks and meter fills to SC 1.4.11 (3:1).

| Pair | Measured | Required | SC | Used for |
|---|---|---|---|---|
| `text` on `surface` | **16.67:1** | 4.5:1 | 1.4.3 | Body text on a card |
| `text` on `canvas` | **14.62:1** | 4.5:1 | 1.4.3 | Text on the page background |
| `text` on `surface-sunken` | **16.10:1** | 4.5:1 | 1.4.3 | Text on a sunken surface |
| `text-muted` on `surface` | **5.53:1** | 4.5:1 | 1.4.3 | Descriptions |
| `text-muted` on `canvas` | **4.85:1** | 4.5:1 | 1.4.3 | Descriptions on canvas |
| `text-faint` on `surface` | **4.93:1** | 4.5:1 | 1.4.3 | Micro labels, mono ids |
| `text-faint` on `surface-sunken` | **4.76:1** | 4.5:1 | 1.4.3 | Table head labels |
| `link` on `surface` | **5.16:1** | 4.5:1 | 1.4.3 | Links, ability names |
| `accent-contrast` on `accent` | **5.60:1** | 4.5:1 | 1.4.3 | Label on the primary button |
| `danger-contrast` on `danger` | **6.31:1** | 4.5:1 | 1.4.3 | Label on a destructive button |
| `success-contrast` on `success` | **5.45:1** | 4.5:1 | 1.4.3 | Label on a success fill |
| `success` on `success-surface` | **4.80:1** | 4.5:1 | 1.4.3 | Success pill |
| `warning` on `warning-surface` | **4.84:1** | 4.5:1 | 1.4.3 | Warning pill |
| `danger` on `danger-surface` | **5.51:1** | 4.5:1 | 1.4.3 | Danger pill |
| `info` on `info-surface` | **5.96:1** | 4.5:1 | 1.4.3 | Info pill |
| `neutral` on `neutral-surface` | **6.41:1** | 4.5:1 | 1.4.3 | Neutral pill |
| `accent` on `surface` | **5.60:1** | 3.0:1 | 1.4.11 | Focus ring, switch on, meter fill |
| `border-input` on `surface` | **3.03:1** | 3.0:1 | 1.4.11 | Input and control boundary |
| `switch-off` on `surface` | **3.11:1** | 3.0:1 | 1.4.11 | Muted status dot |
| `success-dot` on `surface` | **3.47:1** | 3.0:1 | 1.4.11 | Status dot |

## Page width

**Three tiers, and a screen picks one.** A screen never invents a number; it
names a tier, and the tier's value lives in `albert-tokens.css`.

| Tier | Class | Value | Used by |
|---|---|---|---|
| Narrow | `.albert-page--narrow` | `--albert-page-width-narrow` `54rem` | Settings, Context |
| Wide | `.albert-page` (default) | `--albert-page-width` `72rem` | Dashboard, Connections |
| Full | `.albert-page--full` | `max-inline-size: none` | Abilities, Skills |

The tier is a judgement about the **content**, not about the screen:

- **Narrow** is for one column of form rows or prose. A wide container puts a
  canyon between a label and its control, and stretches a paragraph past a
  readable measure.
- **Wide** is for content that is itself multi-column and has something to put
  in the extra room — Dashboard's stat row and 2fr/1fr body, Connections' two
  cards side by side.
- **Full** is for a DataViews table, which takes whatever the viewport gives
  it. Full has no token, because it is the absence of a cap rather than a
  value.

That rule is the entire system. The moment a screen sets its own `max-width`
the set drifts, and this is not hypothetical: before 1.4.0 Albert shipped
Settings at 860px, Context at 1000px, Connections at 1280px and Abilities
uncapped — four numbers in four stylesheets, none aware of the others.

If a screen seems to want a width between tiers, the thing that actually needs
constraining is usually a run of text inside it. Use a measure token instead.

React screens name their tier in the JSX (`ContextApp.jsx`) and PHP screens in
the markup, so the choice is always visible where the page is built, never
buried in a per-screen stylesheet.

**Measures** cap a run of text by role, so a screen constrains its prose rather
than its shell:

| Token | Value | Used for |
|---|---|---|
| `--albert-measure-description` | `40rem` | Page and screen descriptions |
| `--albert-measure-prose` | `90ch` | Owner-authored prose in a textarea |
| `--albert-measure-field` | `26rem` | Text and URL inputs |

## Scales

**Spacing** `--albert-space-100` … `600`: 0.25, 0.5, 0.75, 1, 1.25, 1.5rem
(4–24px at the default root size).

**Card padding** `--albert-card-padding` 1.125rem (18px) is the inline edge every
card, row and card-aligned table cell shares, so a row list lines up with the
header above it. `--albert-card-padding-block` 0.875rem (14px) is the header's
block padding. Named because the literal was previously repeated across four
stylesheets, and anything flush to a card edge has to match it or the column
looks bent.

### rem, except where px is the right answer

Sizes, spacing, type and measures are in `rem`, so the interface scales with the
reader's own base size instead of pinning Albert at whatever 13px happens to
mean on their display.

Three things stay in px deliberately: **border widths**, **border radius**, and
**dashicon glyph boxes**. A corner radius is a fixed optical detail rather than a
measure — scaling a "4px corner" to 6px for a reader at 150% reads as a different
shape, not as larger text. A dashicon's `font-size` *is* its box, so it has to
match the width and height it is given.

**Radius** `--albert-radius-sm` 3px (small controls), `--albert-radius-md` 4px
(cards, inputs, code blocks), `--albert-radius-pill` 11px (badges, meters).
There is deliberately no 6px or 8px step. Earlier drafts used softer radii and
they were dropped for core's 4px.

**Type**: the wp-admin system stack, never a webfont. Sizes are named by role,
not by size, so a screen asks for `--albert-font-size-card-title` rather than
guessing which of two 14px tokens was meant.

**Motion** `--albert-duration-fast` 150ms (hover, switch, meter fill),
`--albert-duration-panel` 220ms with `--albert-easing-panel` (fly-in). Every
consumer wraps motion in `@media (prefers-reduced-motion: no-preference)`.

**Focus**: one ring for everything interactive, `--albert-focus-width` 2px solid
`--albert-focus-color` at `--albert-focus-offset`. Rows that open a panel and
accordion summaries included; those are the ones most often missed. Core's
controls bring their own ring.

## Primitives

| Primitive | Class root | Notes |
|---|---|---|
| Page shell | `.albert-page` | Header (title, description, optional actions) then body. Caps at 1280px; a table-shaped screen wanting the full width simply doesn't use this wrapper. |
| Card | `.albert-card` | Optional `__header`; `__body--flush` when the body is a row list that manages its own dividers. |
| Toggle row | `.albert-toggle-row` | Label, description, optional mono `__peek`. A switched-off state disables each control individually, never `inert` on the row, that removes readable text from the accessibility tree along with the controls. |
| Badge | `.albert-badge` | One definition, replacing the two shapes shipped before. Tones: neutral, info, success, warning, danger, outline. |
| Inline hint | `.albert-hint` | Scoped to what it explains. Never a full-width banner; that is what WordPress notices are for. Tone is a surface tint and a hairline, never a thick coloured edge: see below. |
| Save state | `.albert-savestate` | Put it inside `aria-live="polite"`. Replaces a submit button on instant-save screens. |
| Payload preview | `.albert-preview` | Mono, `pre-wrap`, capped height. Deliberately has no region highlight: see below. |
| Swatch | `.albert-swatch` | Give it a `title` with the value it shows. |
| Info control | `.albert-info` / `.albert-tip` | The "(i)" for a term on the line of common knowledge, or a rule with an edge case. React screens use `shared/InfoPopover.jsx`; server-rendered screens call `Admin\InfoTip::render( $text, $label )`. The sentence must read correctly without opening it. |
| Navigation | `.albert-nav` | Rendered for you on every Albert screen. Real links with `aria-current="page"`, never `role="tab"`. |
| Radio cards | `.albert-radio-cards` / `.albert-radio-card` | A stack of full-width, self-describing options for a choice worth explaining rather than listing bare in a `<select>` — title, description, optional "Recommended" `.albert-badge`. Selected state is border + tinted background (`:has(input:checked)`), never colour alone: the radio's own dot already carries "which one". `SettingsRenderer`'s `radio-cards` field type renders it (`docs/settings-api.md`). |
| Save bar | `.albert-savebar` | A sticky footer for a screen that saves via a real, page-reloading submit — keeps the button reachable on a long one-column settings page. Not `.albert-savestate`: that primitive *replaces* a submit button on an instant-save screen; here the button stays real and the bar only keeps it in view. |
| Stat row | `.albert-stat-row` / `.albert-stat` | A row of plain numbers, never a chart or sparkline. Micro `__label` above a 28px `__value`, with an optional `__meta` line under it. Four fixed tracks, not `auto-fit`: the row ends early when a screen has fewer figures rather than stretching two tiles to half the page each. A tile renders only for a figure the site can actually compute — see "No figure the site can't compute" below. |
| Endpoint field | `.albert-endpoint` | A read-only address plus its Copy button, shared by Connections and the Dashboard. Mono, on the sunken surface, so it reads as a value to copy rather than a box to type in. The field yields before the button wraps. |
| Field | `.albert-field-group` | A labelled control: label (+ optional badge), description, then the control and its optional unit. See "Adding a field" below. |
| Form control | `.albert-text-input` / `.albert-select` / `.albert-textarea` | One appearance for every control, whatever its tag. Boundary is `border-input` (3.17:1), not the card hairline, because SC 1.4.11 asks 3:1 of anything delimiting a control. |
| Notices | `Admin\Notices::render( $group )` | The one call every screen uses for its `add_settings_error()` queue. Wraps WordPress's own `settings_errors()` in `aria-live="polite"`, which core's own output does not carry on its own. Use this instead of calling `settings_errors()` directly, so a notice announces itself to a screen reader identically everywhere it appears. |

### One info control, two renderings

Both halves are components you call, not markup you copy:

```php
// Server-rendered: anywhere, any screen.
\Albert\Admin\InfoTip::render(
    __( 'Once they approve one, their access no longer expires.', 'albert-ai-butler' ),
    __( 'Invitation expiry', 'albert-ai-butler' )
);
```

```jsx
// React screens.
<InfoPopover text={ __( '…' ) } label={ __( '…' ) } />
```

`InfoTip` generates the popover id, wires `aria-controls`, and builds the
trigger's accessible name from the label — "More about Invitation expiry", not a
row of identical "(i)" buttons. The popover **must** stay the trigger's next
sibling, because `admin-popover.js` finds it with `nextElementSibling`.

A settings field gets one by declaring `'info' => '…'`; the field renderer calls
`InfoTip` for you (`docs/settings-api.md`).

**Keep the visible line to one line.** The description under a label says what
the setting does; the tip carries the edge case, the consequence, and what `0`
means. This is what stops a settings screen turning into an essay — and the rule
below still governs what may go behind it.


`.albert-info` styles core's `Dropdown` and is what every React screen uses,
through `assets/src/shared/InfoPopover.jsx`. Core handles open state, Escape,
outside-click, focus-on-mount and collision detection, and `popoverProps.inline`
keeps the popover inside the Abilities fly-in's focus trap.

`.albert-tip` is the CSS-anchored equivalent for server-rendered screens, which
have no `Dropdown` to reach for. It is the weaker control by design: placement is
fixed, and `admin-popover.js` only opens, closes and returns focus.

The split is by rendering context, never by screen. Picking the CSS one for a
React screen is how the Context screen ended up with a second, worse
implementation of a control the Abilities screen already had, and the two class
names sat one hyphen apart until they collided.

### No coloured edge on a tinted panel

`.albert-hint` used to carry a 3px accent rule down one side, and the Context
screen's closing note copied it in the warning colour. Both were removed, and the
`notice-accent` token with them.

A rounded, tinted panel with a heavy coloured edge is the house style of more or
less every AI product shipping today. On a WordPress admin screen it reads as
"an assistant wrote this", which is precisely backwards for a note the *site* is
making to its owner. WordPress's own `.notice` does use a left border, but
square-cornered, full width and on white; the two do not read alike, and copying
the shape without the context borrows the wrong association.

Tone now comes from the surface tint and a matching hairline. It is quieter, it
sits inside the page rather than on top of it, and it survives someone looking at
the screen and asking what the colour is for.

### Why the payload preview marks nothing

An earlier version carried a `__highlight` for "the region the site owner
contributed", and the Context screen used it on the owner's instructions. It was
removed after two rounds of "what does that actually do for a user".

The answer was nothing. The payload names that section in its own text, on the
line directly above it, and the owner had typed those words into a field on the
same screen. The marking restated a fact twice established, and cost a colour
decision (accent read as "the AI part", warning read as "something is wrong") for
no information.

If a preview ever needs to distinguish authorship, the lesson is that the text
should say so rather than the colour.

### The `__text` wrapper

`.albert-page__header`, `.albert-card__header` and `.albert-toggle-row` are all
two-child flexes with `space-between`: a text half and a control. The text half
has to be one element (`.albert-page__text`, `.albert-card__text`,
`.albert-toggle-row__text`) because as siblings the title, the description and
the control all space apart from each other, and the description lands beside the
title instead of under it.

This was found by building the first screen on these primitives rather than by
reading them. A primitive with no consumer has not been checked.

### Why the navigation is links, not tabs

Each entry is a separate admin page load. `role="tablist"` promises in-page
panels and arrow-key navigation; announcing tabs and then navigating away is
worse for a screen-reader user than plain links, which is what these are.

The strip renders on `in_admin_header`, which is what lets it run edge to edge
without negative margins fighting `.wrap`'s gutters. It reads the registered
submenu rather than a hardcoded list, so an add-on page appears automatically,
and it runs a capability check per entry so a page the user cannot reach never
shows.

**It scrolls, and it scrolls itself to where you are.** `.albert-nav__list` is
`overflow-x: auto`, so on a narrow viewport the later entries sit outside the
scroll port. Settings is last, which means the person least able to see the
current entry is the one sitting on it. `assets/js/albert-nav.js` scrolls the
`aria-current="page"` entry into view on load, with `inline: 'nearest'` so the
neighbours stay visible and `block: 'nearest'` so the page itself does not jump.
It is a no-op when the strip is not scrolling, and it honours
`prefers-reduced-motion`. Nothing about the markup, tab order or announced state
changes; only a scroll offset moves.

`Assets::enqueue_on_albert_screens()` enqueues it, for the same reason it
enqueues the primitives: the navigation appears on add-on screens too, so
anything belonging to it cannot live in one screen's own callback.

### Adding a field

A field is label, description, control and optional unit, and there is one way
to make one. On the Settings screen — and from any add-on — declare it and the
renderer builds it:

```php
albert_register_setting( [
    'title'       => __( 'Invitation expiry', 'my-addon' ),
    'option_name' => 'my_addon_expiry_days',
    'type'        => 'number',
    'description' => __( 'How long an unused invitation stays valid.', 'my-addon' ),
    'suffix'      => __( 'days', 'my-addon' ),
    'badge'       => __( 'Premium', 'my-addon' ),
    'default'     => 14,
] );
```

Types: `text`, `url`, `number`, `textarea`, `select`, `checkbox`, `radio-cards`,
`custom`. Full schema in `docs/settings-api.md`.

On a screen of your own, hand the same array to the renderer:

```php
( new \Albert\Admin\SettingsRenderer() )->render_field( $field, $current_value );
```

`SettingsRenderer` is stateless and never opens a `<form>`, so a screen can call
it anywhere inside its own form.

**This works on every Albert screen, which it did not before 1.4.0.** The field
system lived in `admin-settings.css`, and that stylesheet loads only on Settings
and Dashboard — so Connections and Context could not render a labelled field at
all, and an add-on screen would have had to reinvent one. It now lives in
`albert-primitives.css` with the rest of the shared vocabulary.

The markup, if you are writing it by hand rather than through the renderer:

```html
<div class="albert-field-group">
  <div class="albert-field-label-wrap">
    <label class="albert-field-label" for="x">Label
      <span class="albert-badge albert-badge--warning">Premium</span>
    </label>
    <p class="albert-field-description">What this setting does.</p>
  </div>
  <div class="albert-field-input-wrap">
    <input id="x" type="number" class="albert-text-input" />
    <span class="albert-field-suffix" id="x-suffix">days</span>
  </div>
</div>
```

A unit gets an `id` and the control an `aria-describedby` pointing at it, so
"90" and "90 days" are not the same field to a screen reader. The renderer does
this for you.

### A class Free ships is add-on surface, even if Free stops using it

Before deleting a selector as dead, check the sibling plugins, not just this
one. `albert-premium-service` renders `.albert-settings-card`,
`.albert-settings-card-body` and `.albert-page__intro` on its Activity Log
screen and declares Free's `albert-admin` / `albert-primitives` handles to style
them. All three were removed during the 1.4.0 rebuild — the sweep asked "does
anything in this plugin still render it?", which was the wrong question — and
had to be restored.

They now live as **aliases on the rules that replaced them**
(`.albert-card`, `.albert-card__body`, `.albert-page__description`) rather than
as copies, so an add-on's card cannot drift away from Albert's own. They go once
Premium migrates.

The check before removing a selector:

```bash
grep -rn "albert-the-class" ../albert-premium-service ../albert-woocommerce
```

This is the CSS half of the boundary rule in `AGENTS.md`: core never
depends on add-ons, but core is depended *on*, and a published class is as much
a contract as a hook name or a stylesheet handle.

### One badge, for real this time

The Primitives table above has said "one definition, replacing the two shapes
shipped before" since this file was written. It was not quite true: a field
label's "Premium"-style badge (`SettingsRenderer::render_field()`) still drew
its own `.albert-field-badge` — a second pill shape, accent-tinted rather than
toned, that never got migrated when `.albert-badge` was introduced. Caught
while rebuilding the Settings screen in 1.4.0's admin-screens pass. Both the
built-in and custom field branches now render `.albert-badge
albert-badge--warning`, the same pill a section-level `badge` renders. There
is now exactly one badge in the codebase, not one-and-a-half.

### No figure the site can't compute

The Dashboard's stat row exists to answer §0's rule at the component level:
never show a number nobody measured. Free can compute exactly two figures —
enabled abilities and active connections — because it only retains 2 rows per
`(ability_name, status)` in its ability log (`Logging/Repository.php`), which
is not enough history for a 7-day call count, a failure rate, or a median
duration.

Rather than hardcode a `class_exists( 'AlbertPremiumService' )` check for
stats Premium does not compute yet, `Dashboard::render_stat_row()` seeds the
row with Free's two tiles and runs it through the `albert/dashboard/stats`
filter. An add-on with the history to back a figure appends its own tile;
nothing hooking the filter today means nothing beyond Free's two renders —
never a zero, never an empty tile shaped like a promise.

## One palette

There is no dark palette, and that is a decision rather than an omission.

An earlier version of this system shipped a full dark set behind a
`[data-albert-color-scheme="dark"]` selector, on the reasoning that the values
should be ready for the day WordPress ships a dark admin scheme. Nothing ever
set that attribute. So roughly 64 declared values could not be rendered by any
browser, could not be screenshotted, and could not be asserted by a test, and
three genuine dark-only bugs reached review precisely because there was no way
to look at them. Values nobody can see are not a head start; they are
unfalsifiable claims that cost maintenance on every change.

WordPress has no dark admin scheme. When it ships one, or when Albert has a
reason to add a setting of its own, dark becomes a project with a trigger, a
verification path and its own measurements, the same standard every value in
the table above meets. Until then, one palette, verified.

## Adding a token

1. Add it to `albert-tokens.css`, in the `:root` block (or on `body`, if it
   derives from the admin colour scheme, see "The accent comes from the site").
2. If it can carry text or is a control boundary, measure it against every
   surface it will sit on and record the pair here.
3. Check it is inside the sRGB gamut. An `oklch()` value the browser clips is not
   the value you specified.
