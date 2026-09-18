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
`albert/woo-find-products` style IDs. All new abilities use `albert/{verb}-{noun}` (e.g. `albert/create-post`, `albert/find-users`).
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
- **Branch names are `feature/`, `fix/`, or `release/<version>` (release branches only). Nothing else.** Not `chore/`, not
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

All hooks follow `albert/{location}/{hook_name}`. The full hook table, the admin
asset/menu contracts, and the agent-context model live in
[Hooks & extension reference](rules/hooks-reference.md).
