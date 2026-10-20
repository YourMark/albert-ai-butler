---
paths:
  - "assets/src/abilities/**"
  - "src/Admin/AbilitiesPage.php"
  - "src/Admin/AbilitiesPayload.php"
  - "src/Admin/Rest/**"
  - "src/Core/AbilitiesRegistry.php"
  - "docs/extending-the-abilities-screen.md"
---

# Abilities screen

## Unified AbilitiesPage (1.1+, rebuilt on DataViews in 1.3)

Since 1.1, the Core / ACF / WooCommerce admin pages are merged into a single `Albert → Abilities` page. Every registered ability appears as a row in a flat, filterable list, and custom abilities registered via `albert/abilities/register` appear in the same list automatically.

Since 1.3 the screen is a React app built on `@wordpress/dataviews` (source in `assets/src/abilities/`, compiled to `assets/build/` via `npm run build`). `src/Admin/AbilitiesPage.php` only renders the mount point (`#albert-abilities-root`) and enqueues the bundle; all data flows over REST (`src/Admin/Rest/AbilitiesController.php`: `GET /albert/v1/abilities`, per-ability and bulk `POST`s). Search/filter/sort/pagination are client-side via `filterSortAndPaginate`; per-row and bulk enable/disable save instantly (no Save Changes button), and clicking a row opens a detail fly-in with add-on seams (`albert.abilities.permissions_section` to replace the Permissions section, `albert.abilities.panel_sections` to append generic sections). The legacy server-rendered list and its `wp_ajax_albert_toggle_ability` / view-mode AJAX were removed in 1.3. If the build is missing (a dev checkout that hasn't run `npm install && npm run build`), the page shows an actionable notice instead of mounting.

## Row Augmentation (`albert/abilities/payload_row`)

Each ability is normalized into a row by `Albert\Admin\AbilitiesPayload`. The `albert/abilities/payload_row` filter (`( array $row, \WP_Ability $ability )`, since 1.3) runs on every row, on both the bulk build path (`build()`) and the single-row path (`row()`). Add-ons **append** keys (notably `badges`, Free always sets `'badges' => []`) but must not remove or overwrite Core keys. A badge is `{ id, label, tone, title? }` where `tone` is `info`/`warning`/`neutral`; it renders as a pill in the overview cell and fly-in header, and the screen builds a "Filter by badge" dropdown from the deduped union. Core stays generic: it only ever references "badges", never "permissions". See `docs/extending-the-abilities-screen.md`.

## Source Registry (`albert/abilities/sources`)

The filter dropdown's source labels — where an ability comes from: Albert, a third-party plugin,
WordPress core, a theme, or custom code — come from a curated prefix→label map in
`AbilitiesRegistry::get_sources()`. Built-in entries cover `core` → "WordPress core", `albert` →
"Albert", `woo` → "WooCommerce", and `acf` → "ACF". Addons can register their own prefix under a
branded name via the `albert/abilities/sources` filter:

```php
add_filter( 'albert/abilities/sources', function ( array $sources ): array {
    $sources['mycompany'] = 'My Company';
    return $sources;
} );
```

Unknown prefixes fall back to a prettified version of the prefix itself, so every ability always has
a sensible source label.

**Renamed from "Supplier" in 1.4.0**, to match Skills' own `source` concept and to read sensibly for
every kind of origin, including a theme or a person's own custom code — "supplier" reads oddly for
those. `get_suppliers()` and the `albert/abilities/suppliers` filter still work: `get_suppliers()` is
a deprecated wrapper around `get_sources()` (`_deprecated_function`), and `get_sources()` still
applies `albert/abilities/suppliers` first via `apply_filters_deprecated()` before applying
`albert/abilities/sources`, so an addon hooked only to the old filter name keeps working unchanged.
The abilities-screen payload row likewise still carries `supplier`/`supplierLabel` alongside the new
`source`/`sourceLabel` — see `docs/extending-the-abilities-screen.md`.
