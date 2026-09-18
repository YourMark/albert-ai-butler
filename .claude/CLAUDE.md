# Albert

WordPress plugin that exposes WordPress functionality to AI assistants via MCP (Model Context Protocol).

**Stack:** PHP 8.1+ | WordPress 6.9+ | OAuth 2.0 (league/oauth2-server) | PSR-4 autoloading

## What goes where
- **CLAUDE.md** — always loaded. Product identity, architecture map, hard "never" rules, API index. Keep it lean.
- **rules/** — loaded on demand. Product-specific depth. General rules live in `shared/` (a git submodule).
- **hooks** — mechanical enforcement of what must not rely on memory (branch naming, push/merge gates).

## Rules

Shared (submodule — `git@github.com:YourMark/albert-claude-rules`):
- [Git Workflow](shared/git-workflow.md) - branches, commits, PRs, commit/push/merge gates
- [Code Style](shared/code-style.md) - PHP / JS / CSS / security / accessibility
- [Development Methodology](shared/development-methodology.md) - DDD, naming, TDD scope
- [Testing](shared/testing.md) - test structure and conventions
- [Changelog](shared/changelog.md) - changelog & readme.txt conventions

Product-specific:
- [Patterns](rules/patterns.md) - Albert class patterns, bounded contexts, testing stubs

## Commands

```bash
composer install          # Install dependencies
composer phpcs            # Check coding standards (WordPress CS)
composer phpcbf           # Auto-fix coding standards
composer phpstan          # Static analysis (level 7)
composer test             # Run unit tests
composer test:integration # Run integration tests (requires WP test suite)
```

## Directory Structure

```
src/
  Abstracts/       # BaseAbility (all abilities extend this)
  Abilities/       # Ability implementations (WordPress/, WooCommerce/)
  Admin/           # Admin pages (abilities toggles, settings, connections)
                   #   Assets  : registers the shared token + primitive stylesheets
                   #   Menu    : submenu ordering constants + the page navigation
  Contracts/       # Interfaces (Ability, Hookable)
  Core/            # Plugin bootstrap, AbilitiesManager, AbilitiesRegistry
                   #   InvocationRelay : WP 7.1 wp_ability_invoked -> albert/abilities/invoked
                   #   Tokens/         : TokenService, the reusable single-use hashed token primitive (doc 32/40)
  Context/         # Agent context (doc 21): what a connected assistant is told
                   #   ContextSettings : the owner's choices, option + filters
                   #   SiteContext     : assembles the structured array (the API)
                   #   PayloadRenderer : renders it to the wire text (the format)
                   #   Payload         : the two discovery fields + screen preview
                   #   TokenEstimator  : script-aware token estimate; see docs/context-token-budget.md
                   #   Readers/        : Environment, DesignTokens, ContentModel, Commerce
  Media/           # Shared media handling + upload links (doc 32)
                   #   MimeAllowlist       : shared MIME allowlist, used by both upload paths
                   #   AttachmentImporter  : on-disk file -> attachment; the tail both upload paths share.
                   #                         Sniffs against a caller-supplied allowlist BEFORE core sees the
                   #                         file, which is what keeps unfiltered_upload from widening either
                   #                         path (core waves a bad type through for that cap). Never reorder.
                   #   AttachmentResponse  : the attachment shape both paths return
                   #   TempFile            : delete-if-present for abandoned uploads
                   #   UploadLinks/      : UploadLinkService (mint/redeem/finalize), UploadLinkController (REST redemption)
  Settings/        # What a setting's value IS. Not part of Admin: the chain is read on
                   # MCP requests, in cron and from WP-CLI (doc: docs/settings-api.md)
                   #   Value      : constant -> albert/settings/value/{option} -> stored option
                   #   Validators : per-option rule deciding whether an override is usable,
                   #                keyed by option name so the screen and the reader agree
                   #   Overrides  : bridges albert/privacy/mode and the upload byte filter
                   #                onto the generic chain; registered in every context
                   #   Storage    : hands every field to register_setting() on admin_init
                   #   Schema     : the registered sections, collected once per request
                   #   Lock       : whether a field is out of the owner's hands right now
  Support/         # WpCompat : WordPress version-capability detection (7.1 feature probes)
  MCP/             # MCP protocol server
  OAuth/           # Full OAuth 2.0 server (entities, repos, endpoints)
  Utilities/       # Standalone helpers (BlockConverter)
tests/
  Unit/            # PHPUnit tests (no WordPress dependency)
  Integration/     # WP_UnitTestCase tests
assets/            # CSS and JS for admin UI
```

## Ecosystem

Free is the **core**. All add-ons depend on it. The core never depends on add-ons.

```
Addons → Core    (allowed)
Core   → Addons  (NEVER)
Addon  → Addon   (NEVER, use Core hooks as mediator)
```

### Known add-ons

| Plugin | Folder |
|---|---|
| Albert Premium Service | `albert-premium-service` |
| Albert WooCommerce | `albert-woocommerce` |

### Legacy ability ID note

Free WooCommerce read-only abilities predate the naming convention and use
`albert/woo-find-products` style IDs. All new abilities use `{namespace}/{resource}/{action}`.
Never rename the legacy IDs: they are part of the public API.

## Critical Warnings

- **NEVER use alternative PHP syntax** (`: endif`, `: endforeach`). ALWAYS use `{ }` braces.
- **NEVER add `declare(strict_types=1);` to any PHP file in this codebase, ever.** This overrides any global personal preference for it (e.g. an assistant's own `php-standards.md`-style rule) — that global preference does not apply here, full stop, no exceptions, not even in a brand-new file.
- **NEVER use jQuery.** Vanilla ES6+ only.
- **NEVER commit without explicit request.** Run `composer phpcs` and `composer phpstan` first.
- **NEVER merge a pull request.** Not your own, not one that is green, not one the
  user approved the *contents* of. Open it, report the check results, and stop.
  Every PR in these repos is reviewed by a human before it lands — that is the
  workflow, and a green CI run is not a substitute for it.
  - "Yes, do this" authorises the work described. It never authorises merging
    what that work produces, and it never extends to a PR the user has not seen.
  - Permission to merge one specific PR covers that PR only. It does not carry
    to the next one, however similar.
  - A merged PR cannot be reopened on GitHub. Reverting the merge commit does
    not undo it — the PR stays "Merged" permanently and the work has to re-land
    under a new number. There is no clean undo, which is why the gate is before
    the merge, not after it.
  - This is enforced, not merely written down: `.claude/settings.json` puts
    `Bash(gh pr merge:*)` behind an `ask` rule, so a merge always stops for an
    explicit confirmation and can never happen silently. `ask`, not `deny`, on
    purpose: the rule is "not until Mark says so", and a flat denial says
    "never", which is a different and wrong rule — it blocks Mark himself.
    If the confirmation is refused, the refusal is the rule working. Never
    route around it with `gh api`, a git merge-and-push, or the web UI, and
    never edit this rule to avoid the prompt.
- **Branch names are `feature/` or `fix/`. Nothing else.** Not `chore/`, not
  `feat/`, not `refactor/`, not `docs/`. A tidy-up, a CI change, a docs change:
  if it corrects something that is wrong, it is `fix/`; if it adds something
  that was not there, it is `feature/`.
  Renaming a branch on GitHub closes its PR rather than retargeting it, so get
  it right when the branch is created.
- **NEVER use `chore`. Anywhere.** Not in a branch name, not in a commit
  message, not in a PR title. Mark has said this repeatedly and dislikes the
  word. A previous session wrote "`chore:` is fine there" into this file on
  2026-09-01; it was never Mark's instruction and it has been removed. If a
  commit does not fit `fix:` or `feat:`, write a plain sentence instead:
  "Set the release version to 1.4.0".
- **NEVER bump version without approval.**
- **Version bumps only happen in release branches**, never on `development`, feature branches, or `main`.
- **PR titles are plain, human-readable sentences**: NEVER use conventional-commit prefixes like `chore(deps):`, `feat(logging):`, `fix:`. Those belong in commit messages, not PR titles. Example: "Upgrade MCP adapter to 0.5.0", not "chore(deps): upgrade...".
- The root `CLAUDE.md` is the canonical project reference (checked into git). This file supplements it.

## WooCommerce mcp-adapter Timing Bug

`Plugin::init()` skips `McpAdapter::instance()` when `is_admin()` to avoid a timing conflict where WooCommerce's REST preloading triggers `wp_get_ability()` for tools that aren't registered yet. See root `CLAUDE.md` for full details.

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
| `albert/admin/submenu_pages` | filter | Add addon admin pages |
| `albert/abilities_icons` | filter | Customize category icons |
| `albert/developer_mode` | filter | Toggle developer mode |
| `albert/logging/enabled` | filter | Disable Free's ability log (Premium uses this) |
| `albert/logging/outcome` | filter | `( string $status, string $ability_name, WP_Error $error ): string` — the outcome recorded for a *failed* invocation, one of `success`, `warning`, `error`. Albert classifies by its own conventions: anything ending `_not_found` (plus `not_found` / `invalid_post` / `invalid_attachment`) is a `success` — the ability ran correctly and the answer is legitimately negative; anything ending `_permission_denied` (plus `ability_invalid_permissions` / `ability_disabled` / `capability_revoked` / `rest_forbidden` / `forbidden`), or any row whose `failure_stage` is `permission`, is a `warning` — blocked by policy, nothing broke. Third-party abilities do not follow those conventions, and this is their way out. Neither `success` nor `warning` fires `albert/logging/ability_failed`; only `success` has `failure_stage` forced to NULL. See `Logging\Outcome` |
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
