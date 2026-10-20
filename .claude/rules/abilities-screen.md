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

A React app on `@wordpress/dataviews` (`assets/src/abilities/`, built to `assets/build/` with `npm run build`). `AbilitiesPage` renders only the mount point; data comes over REST from `Admin\Rest\AbilitiesController`. Toggles save instantly. A missing build shows a notice instead of mounting.

- Rows come from `AbilitiesPayload`, through `albert/abilities/payload_row` on both `build()` and `row()`. Add-ons append keys (notably `badges`) and never remove or overwrite core keys. Core only ever says "badges", never "permissions".
- JS seams: `albert.abilities.permissions_section` and `albert.abilities.panel_sections`. Contract: `docs/extending-the-abilities-screen.md`; change it together with the code.
- Source labels come from `AbilitiesRegistry::get_sources()` (`albert/abilities/sources`); an unknown prefix is prettified. The old names stay working: `get_suppliers()` is a deprecated wrapper, `albert/abilities/suppliers` still applies first via `apply_filters_deprecated()`, and rows still carry `supplier` / `supplierLabel`. Do not remove them.
