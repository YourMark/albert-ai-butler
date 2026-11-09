# Extending the Abilities screen

The `Albert → Abilities` screen (the `@wordpress/dataviews` admin app) exposes extension points so
add-ons — notably **Albert Premium Service** — can add UI to the detail fly-in and surface row
indicators, without Core depending on the add-on.

| Seam | Type | Where it runs |
|---|---|---|
| `albert.abilities.permissions_section` | JS filter (`@wordpress/hooks`) | The fly-in "Permissions" section (replace) |
| `albert.abilities.panel_sections` | JS filter (`@wordpress/hooks`) | The fly-in body (append generic sections) |
| `albert/abilities/payload_row` | PHP filter | Each row, as the screen payload is built |
| `albert/abilities/required_capability` | PHP filter | When the screen resolves an ability's capability |

The two JS seams need the add-on script enqueued on the abilities page; see
[Enqueuing the add-on script](#enqueuing-the-add-on-script).

---

## 1. Replace the Permissions section — `albert.abilities.permissions_section`

The fly-in renders one **Permissions** section. Free's default shows the required capability; an
add-on can replace it with its own UI (this is how Premium adds the per-role/per-user rule builder).
The filter receives Free's default node and returns the node to render in its place; returning the
default unchanged keeps Free's behaviour.

```js
import { addFilter } from '@wordpress/hooks';
import { createElement } from '@wordpress/element';

addFilter(
	'albert.abilities.permissions_section',
	'albert-premium/permissions',
	( defaultNode, api ) =>
		createElement( PermissionsSection, {
			ability: api.ability,
			api,
			capabilityContent: api.capabilityContent,
		} )
);
```

- **`api`** — `{ ability, roles, capabilityContent, registerCloseGuard }`:
  - **`ability`** — the normalized row (see the shape under [payload_row](#3-surface-a-row-indicator--albertabilitiespayload_row)).
  - **`roles`** — `[ { value: <slug>, label } ]` for every site role (use for a role picker). For
    per-user pickers, query users via core REST `/wp/v2/users`.
  - **`capabilityContent`** — Free's required-capability block (a React node). Render it inside your
    own UI (e.g. a "Capability only" tab) so the capability is shown identically with or without the
    add-on.
  - **`registerCloseGuard( fn )`** — see [Confirming on close](#confirming-on-close).

### Confirming on close

`registerCloseGuard( fn )` lets the section veto the fly-in's close to confirm unsaved/incomplete
state. Call it once (it returns a cleanup); `fn` runs on every close attempt (X, footer, Escape,
backdrop) and returns either `null` (close immediately) or a descriptor for a styled confirm dialog:

```js
const cleanup = api.registerCloseGuard( () => {
	if ( /* nothing to confirm */ ) {
		return null;
	}
	return {
		title: __( 'Unfinished custom rules', 'albert-premium' ),
		body: __( 'Nobody will be able to use this ability. Save it, or discard?', 'albert-premium' ),
		keepLabel: __( 'Save anyway', 'albert-premium' ),
		discardLabel: __( 'Discard', 'albert-premium' ),
		onKeep: () => persist(),   // optional; runs, then the fly-in closes
		onDiscard: () => {},        // optional; runs, then the fly-in closes
	};
} );
// return cleanup; from your effect
```

The guard reads current state, so back it with refs (not a stale closure). Dismissing the dialog
(its X) cancels the close and keeps the fly-in open.

### Saving

The section **auto-saves** on change (matching the instant-save Enabled toggle) via the add-on's own
REST route/option — there is no Save button. (Premium intentionally holds back an *incomplete*
selection and resolves it through the close guard above.)

---

## 2. Append a generic section — `albert.abilities.panel_sections`

For UI that isn't the Permissions section, append sections to the fly-in body. Each is rendered
inside an error boundary.

```js
addFilter(
	'albert.abilities.panel_sections',
	'my-addon/extra',
	( sections, ability, api ) => [
		...sections,
		{
			id: 'my-extra',
			priority: 10, // optional; ascending (default 10)
			render: ( { ability, api } ) => createElement( MySection, { ability, api } ),
		},
	]
);
```

- **`api`** — `{ ability, roles }`.
- Each entry is `{ id: string, priority?: number, render: ({ ability, api }) => Element }`.

### Enqueuing the add-on script

Enqueue on the abilities page hook, depending on Core's app bundle and `wp-hooks` so filters are
registered before the app renders:

```php
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'albert_page_albert-abilities' !== $hook ) {
		return;
	}
	$asset = require PREMIUM_DIR . 'assets/build/js/permissions.asset.php';
	wp_enqueue_script(
		'albert-premium-permissions',
		PREMIUM_URL . 'assets/build/js/permissions.js',
		array_merge( $asset['dependencies'], [ 'albert-abilities-app', 'wp-hooks' ] ),
		$asset['version'],
		true
	);
} );
```

---

## 3. Surface a row indicator — `albert/abilities/payload_row`

Each ability is normalized into a row by `Albert\Admin\AbilitiesPayload`. This PHP filter runs on
every row, on **both** paths that build payload data: the bulk build (`AbilitiesPayload::build()`)
and the single-row rebuild returned after a toggle (`AbilitiesPayload::row()`).

Use it to **append** a `badges` entry — a generic, branding-free way to flag a row in the overview
cell and the fly-in header. The screen also builds a "Filter by badge" toolbar dropdown from the
deduped union of every badge present.

```php
add_filter(
	'albert/abilities/payload_row',
	function ( array $row, \WP_Ability $ability ): array {
		// Append — never remove or overwrite Core keys.
		$row['badges'][] = [
			'id'    => 'custom-access',                                   // Required, stable; the filter value.
			'label' => __( 'Custom access', 'albert-premium' ),          // Required; the pill text.
			'tone'  => 'info',                                           // Required; info | warning | neutral.
			'title' => __( 'This ability has a custom access rule.', 'albert-premium' ), // Optional; tooltip.
		];
		return $row;
	},
	10,
	2
);
```

- **`$row`** — `{ id, label, description, category, categoryLabel, source, sourceLabel,
  operation, enabled, capability, capabilityRoles, lastUsed, badges, inputs, output, annotations }`.
  `operation` is `read`, `write` or `destructive` (`delete` before 1.6.0).
  Free always sets `badges` to `[]`. `supplier`/`supplierLabel` are still present too, holding the
  same values as `source`/`sourceLabel` — deprecated since 1.4.0 in favour of the latter, kept for
  any add-on already reading them, and scheduled for removal in a future major version.
- **Badge shape** — `{ id, label, tone, title? }`; `tone` maps to the `albert-badge--{tone}` pill.
  Since 1.4.0 that pill is the shared `.albert-badge` primitive in
  `assets/css/albert-primitives.css` — there is one definition, not one per screen. Its size,
  radius and tone colours come from the design tokens, so a badge looks the same wherever it
  appears and follows the admin colour scheme. Do not restyle `.albert-badge` from an add-on
  stylesheet: add-on sheets load after the primitives, so any property you redeclare silently
  takes ownership from the primitive and the two drift apart. Add a modifier class instead.
- **Social contract** — add-ons may **append** keys to `$row` (and entries to `badges`) but must
  **not** remove or overwrite Core keys.

Core only ever talks about "badges", never "permissions" — a badge is a generic row indicator; what
it *means* is the add-on's concern.

---

## 4. Correct the displayed capability — `albert/abilities/required_capability`

The screen shows a best-effort "Required capability". Override it precisely:

```php
add_filter(
	'albert/abilities/required_capability',
	function ( string $cap, string $ability_id, \WP_Ability $ability ) {
		return $ability_id === 'my/ability' ? 'manage_my_thing' : $cap;
	},
	10,
	3
);
```
