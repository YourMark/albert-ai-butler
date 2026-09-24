# Hooks & extension reference

On-demand reference for Albert Free's public extension surface. Moved out of
CLAUDE.md to keep the always-loaded surface lean; open it when working on hooks,
the admin asset/menu contracts, or agent context.

## Extensibility Hooks

All hooks follow `albert/{location}/{hook_name}` convention:

| Hook | Type | Purpose |
|------|------|---------|
| `albert/abilities/register` | action | Register custom abilities |
| `albert/context/enabled` | filter | Whether Albert sends any context with the discovery response |
| `albert/context/instructions` | filter | The site owner's context instructions, set in code to manage a fleet |
| `albert/context/sections` | filter | Which auto-detected sections are included, keyed by section |
| `albert/context/site` | filter | The assembled site context array, before it is rendered to the wire text. Add, drop or rewrite a section; unrecognised sections render generically. The untrusted-data framing is deliberately outside this array and cannot be filtered away |
| `albert/context/skills` | filter | The skills index entries, after preconditions have been applied |
| `albert/skills/registry` | filter | Register a skill: `slug`, `summary`, and either `file` or `body`, plus optional `requires` / `when` preconditions |
| `albert/abilities/payload_row` | filter | Augment a normalized ability row before it reaches the Abilities screen (e.g. append `badges`). Fires on both the bulk build and single-row paths. See `docs/extending-the-abilities-screen.md` |
| `albert/abilities/required_capability` | filter | Override the best-effort capability shown on the Abilities screen |
| `albert/abilities/before_execute` | action | Before any ability runs |
| `BaseAbility::$sensitive_output_keys` | property | Not a hook, but part of the ability contract (1.4.0). Top-level result keys holding a credential. The caller receives them intact; every `after_execute` observer is handed a copy with them replaced by `[redacted]`. Observers are loggers (Albert's own writes a DB row, Premium captures the whole success payload), so a short-lived secret would otherwise outlive its own expiry in plaintext. Masks rather than drops, so a log still records the field was returned. Top-level keys only |
| `albert/abilities/after_execute` | action | After any ability runs |
| `albert/abilities/before_execute/{id}` | action | Before a specific ability |
| `albert/abilities/after_execute/{id}` | action | After a specific ability |
| `albert/abilities/invoked` | action | Relayed from WP 7.1's `wp_ability_invoked`. Fires for EVERY invocation whatever the outcome (denied, invalid, short-circuited) and for abilities Albert does not own, which is wider than `after_execute` can see. Inert below 7.1; ask `WpCompat::supports_execution_lifecycle()`. Observer Throwables are swallowed |
| `albert/safe_mode/high_risk_abilities` | filter | Ability ids safe mode always holds, whatever their annotation. Add-ons append their own high-risk surface |
| `albert/safe_mode/exempt_abilities` | filter | Ability ids safe mode never holds. Wins over the high-risk list: naming an ability here is a statement about *this* site, where the risk list is a general default. A filter and not a checkbox on purpose, a deliberate act by somebody who went looking beats a click next to the thing protecting them. Excuses staging only: permissions still run and `ConnectionGuard` still refuses control-option writes |
| `albert/safe_mode/high_risk_options` | filter | Option names treated as high-risk to *write*, so the call is held for approval. Distinct from `protected_options`, which are refused outright |
| `albert/safe_mode/protected_options` | filter | Albert's own gate-controlling options, refused over a connection rather than held. Approving "turn safe mode off" is self-defeating, so there is no coherent way to consent to it |
| `albert/safe_mode/retention_days` | filter | How long a decided action is kept before deletion (default 30). These rows hold each call's captured input, so keeping them forever keeps request payloads in every backup |
| `albert/safe_mode/display_input` | filter | The staged input as the approvals screen prints it, after known secret-looking keys are masked. **Display only**: the stored input is untouched, because approval replays the real call. Equally, it is a convenience and not a control, it catches the key names it knows and nothing else |
| `albert/approvals/view_capability` | filter | Capability needed to *reach* the approvals screen (default `edit_posts`). The coarse gate only: who may decide what stays ownership plus the ability's own permission check, so loosening this hands nobody a decision they could not already make. Deliberately not `read`, which would put an Approvals item in every subscriber's menu pointing at a permanently empty screen |
| `albert/safe_mode/held` | action | A destructive call was held. One per genuinely new hold; a de-duped retry does not re-fire |
| `albert/safe_mode/approved` | action | A person approved a held call |
| `albert/safe_mode/rejected` | action | A person rejected a held call. The ability never ran |
| `albert/safe_mode/expired` | action | A held call lapsed without a decision |
| `albert/safe_mode/abandoned` | action | An approved run never reported back. It may or may not have completed |
| `albert/safe_mode/option_write_blocked` | action | An assistant's write to one of Albert's control options was refused |
| `albert/safe_mode/option_delete_detected` | action | An assistant *deleted* one of those options. Detection only: WordPress has no `pre_delete_option` filter, so this cannot be prevented, and the hook is named separately from the one above because that one claims a refusal this cannot make |
| `albert/admin/submenu_pages` | filter | Add addon admin pages |
| `albert/abilities_icons` | filter | Customize category icons |
| `albert/developer_mode` | filter | Toggle developer mode |
| `albert/logging/enabled` | filter | Disable Free's ability log (Premium uses this) |
| `albert/logging/outcome` | filter | `( string $status, string $ability_name, WP_Error $error ): string` — the outcome recorded for a *failed* invocation, one of `success`, `warning`, `error`. Albert classifies by its own conventions: anything ending `_not_found` (plus `not_found` / `invalid_post` / `invalid_attachment`) is a `success` — the ability ran correctly and the answer is legitimately negative; anything ending `_permission_denied` (plus `ability_invalid_permissions` / `ability_disabled` / `capability_revoked` / `rest_forbidden` / `forbidden`), or any row whose `failure_stage` is `permission`, is a `warning` — blocked by policy, nothing broke. Third-party abilities do not follow those conventions, and this is their way out. Neither `success` nor `warning` fires `albert/logging/ability_failed`; only `success` has `failure_stage` forced to NULL. See `Logging\Outcome`. Since 1.5.0 `Outcome::HELD_CODE` (`albert_awaiting_approval`, safe mode holding a call) classifies as `warning` on its own branch rather than joining `POLICY_CODES`: that set means "you may not" and "this is switched off", both final, where a hold means *not yet decided* |
| `albert/blocks/read_block_limit` | filter | Default top-level blocks per read window (default 200; 0 = unlimited) |
| `albert/blocks/read_max_bytes` | filter | Per-field byte cap on read text representations (default 50000) |
| `albert/media/upload_link_max_bytes` | filter | Default byte cap for a media upload link when a caller doesn't request one; accepts an int, a float, or a php.ini-shorthand string (`"10M"`, `"2G"`) via `wp_convert_hr_to_bytes()`, clamped to `MAX_SETTABLE_MB` (2 GB); return `null` to defer to the `albert_upload_link_max_mb` option/default (10 MB). See `UploadLinkService::get_default_max_bytes_filter_state()`. Not deprecated. Since 1.4.0 `Settings\Overrides` bridges it onto the generic settings chain, so the Settings screen renders the field read-only and names this filter while it is active; the limit *enforced* still comes from here in exact bytes, not the megabyte figure the screen shows |
| `albert/connections/allowed_user_capability` | filter | The capability a user must hold to be *offered* in the Connections screen's allowed-users picker (default `edit_posts`; `''` offers everyone). Changes who is suggested, never who may authorise: that stays the stored `albert_allowed_users` list. See `Admin\Connections::allowed_user_capability()` |
| `albert/mcp/hide_unauthorized_tools` | filter | Hide MCP tools the connected user can't execute from `tools/list`, so discovery matches what's callable (default true; false = list all, deny on call) |
| `albert/dashboard/stats` | filter | Append tiles to the Dashboard's stat row. A tile is `[ 'label' => string, 'value' => string, 'meta' => string, 'indicator' => string ]`; `label` and `value` are escaped as text, `meta` is `wp_kses`'d down to `<a href>`, and `indicator` names a status dot (`strict`/`balanced`/`off`) rather than passing markup. Free contributes three (abilities, connections, privacy) and the grid is auto-fit, so an add-on tile becomes a fourth rather than overflowing a fixed row. See `Dashboard::render_stat_row()` |
| `albert/dashboard/attention` | filter | Append findings to the Dashboard's "Needs your attention" card. Keep to the rule the built-in checks follow: a standing condition or a pending automatic action, never a restatement of the activity log, and never a setting the owner chose deliberately. Items disappear on their own when the condition clears. See `Admin\Dashboard\Attention` |
| `albert/dashboard/suggestions` | filter | Register example prompts for the "Try asking your assistant" card. Each names the ability ids it `requires` and is shown only when every one is enabled, so the card never suggests something that would fail. See `Admin\Dashboard\Suggestions` |
| `albert/dashboard/recommendations` | filter | Register an add-on Albert may recommend. Shown only when `host_symbol` exists and `addon_symbol` does not, so a site is never sold what it already owns or told about a plugin it does not run. One at a time, and never something the Dashboard already pitches elsewhere: Premium is deliberately absent because the activity card owns that story. Carry an `inactive_detail` for the installed-but-off wording. See `Admin\Dashboard\Recommendations` |
| `albert/privacy/mode` | filter | Override the active PII privacy mode (`strict`/`balanced`/`off`); return `null` to defer to the `albert_privacy_mode` option/default (`balanced`). See `PrivacyMode::resolve()`. Not deprecated. Since 1.4.0 it resolves through `Settings\Value`, which also makes the Settings screen show the mode as owned by code instead of offering an editable control that saves nothing |
| `albert/settings/value/{option_name}` | filter | The value in force for one setting, ahead of the stored option; return `null` to defer. A constant named after the option in upper case (`ALBERT_PRIVACY_MODE`) beats it. An overridden setting renders read-only on the Settings screen with a note naming the source. See `Settings\Value` and `docs/settings-api.md` |
| `albert/settings/value_source/{option_name}` | filter | The hook or constant name reported on screen as the source of an override. For code answering the value filter on another hook's behalf, so an owner is shown the hook they actually wrote |
| `albert/settings/validator/{option_name}` | filter | Returns `fn( $value ): bool` deciding whether an override of this setting is usable. One rejected is skipped and resolution falls through to the next layer. Declared against the OPTION, never at a call site: the Settings screen and the code reading the setting both consult it, and that is what stops the screen locking a field to a value the site is not using. See `Settings\Validators` |
| `albert/privacy/pii_fields` | filter | Extend the anonymiser's PII allow-list, grouped by rule (`email`/`phone`/`name`/`context_name`/`postcode`/`empty`/`strip`/`redact`). A returned category REPLACES that category: read the incoming value and append to extend. See `Anonymizer` |
| `albert/privacy/payment_keys` | filter | Register payment/card keys hard-removed from every result at any depth and in every mode. Shape `[ 'keys' => [], 'prefixes' => [] ]`; empty in Free (add-ons own gateway keys). See `Anonymizer` |
| `albert/privacy/reveal_capability` | filter | Capability gating a `reveal_personal_data` request (default `manage_options`). Consulted only when an ability passes no explicit `reveal_capability` option. See `PiiPolicy` |
| `albert/activated` | action | Plugin activated |
| `albert/deactivated` | action | Plugin deactivated |

### Admin asset and menu contracts (1.4.0+)

Add-ons that render a screen under the Albert menu depend on two published
handles and one set of constants. All three are part of the public contract.

| Handle / constant | What it is |
|---|---|
| `albert-tokens` (`Admin\Assets::TOKENS_HANDLE`) | The design tokens. Colour, spacing, type, radius, motion. |
| `albert-primitives` (`Admin\Assets::PRIMITIVES_HANDLE`) | The shared components. Depends on the tokens, so naming this one alone is enough. |
| `Admin\Menu::POSITION_*` | `admin_menu` priorities that fix submenu order. Add-ons use `POSITION_ADDONS` or later so a future core screen cannot be pushed below third-party entries. |

```php
// In an add-on. Literal strings, not the class constants, so an older Albert
// without those classes cannot fatal the add-on.
wp_enqueue_style( 'my-screen', $url, [ 'albert-primitives' ], $version );
```

Naming a handle that does not exist is not a soft failure: WordPress drops the
dependent stylesheet from the queue entirely. That is the intended degradation
for an add-on running against an Albert too old to have the tokens: the screen
falls back to core admin styling rather than rendering half-painted. Do **not**
express the same requirement as a plugin-wide version floor; `ALBERT_VERSION`
reads the *previous* release on `development` (version bumps happen only in
release branches), so a floor naming the upcoming version stops the add-on
booting against the very branch it is developed against.

Albert renders its page navigation on every screen under its menu, add-on pages
included, and enqueues the primitives there itself, so an add-on page is
styled whether or not it declares the dependency. Declare it anyway; that is
what guarantees load order for the add-on's own stylesheet.

Full token and primitive reference: `docs/design-system.md`.

### Agent context: array in, text out

`site` is **built** as a structured array and **rendered** to a compact labeled
text block at the MCP boundary. The array is the API, filters run on it, tests
assert its keys, the screen prices its sections. The text is the wire format,
and it is what the Context screen's payload preview shows, byte for byte: the
preview is `Payload::segments()`, and the wire fields are the join of those same
segments. Never emit the array as nested JSON, and never render the payload a
second time for display, a screen with its own opinion of what gets sent stops
being a preview.

The token estimate is script-aware, not characters ÷ 4; that shortcut was
measured at −67% on Japanese. See `docs/context-token-budget.md` and re-check any
change with `php bin/calibrate-token-estimator.php`.

JS (client-side) seams for the Abilities DataViews screen (`@wordpress/hooks` filters):
`albert.abilities.permissions_section` replaces the fly-in's Permissions section (its `api` exposes
`capabilityContent` to reuse and `registerCloseGuard` to confirm before close), and
`albert.abilities.panel_sections` appends generic sections. Full contract for every Abilities-screen
seam: `docs/extending-the-abilities-screen.md`.
