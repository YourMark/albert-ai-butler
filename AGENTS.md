# Albert

WordPress plugin that exposes WordPress functionality to AI assistants via MCP (Model Context Protocol).

**Stack:** PHP 8.1+ | WordPress 6.9+ | OAuth 2.0 (league/oauth2-server) | PSR-4 autoloading

This is the canonical, agent-neutral instruction file for this repo — read it whatever
AI agent (or if you're a human) you are. Tool-specific files import it: `.claude/CLAUDE.md`
is a thin `@`-import of this file. The deep architecture reference is the root
[`CLAUDE.md`](CLAUDE.md) — read that too for full detail.

## What goes where
- **AGENTS.md** — always loaded. Product identity, architecture map, hard "never" rules, API index. Keep it lean.
- **rules/** (`.claude/rules/`) — loaded on demand. Product-specific depth.
- **agent-rules/** — shared rules across all Albert repos (a git submodule).
- **Enforcement** — native git hooks (any agent or human) plus an optional Claude Code adapter.
- **root `CLAUDE.md`** — the deep canonical project reference (checked into git); AGENTS.md supplements it.

## Rules

Shared (submodule — `git@github.com:YourMark/albert-agent-rules`):
- [Git Workflow](agent-rules/git-workflow.md) - branches, commits, PRs, commit/push/merge gates
- [Code Style](agent-rules/code-style.md) - PHP / JS / CSS / security / accessibility
- [Development Methodology](agent-rules/development-methodology.md) - DDD, naming, TDD scope
- [Testing](agent-rules/testing.md) - test structure and conventions
- [Changelog](agent-rules/changelog.md) - changelog & readme.txt conventions

Product-specific:
- [Patterns](.claude/rules/patterns.md) - Albert class patterns, bounded contexts, testing stubs
- [Hooks & extension reference](.claude/rules/hooks-reference.md) - full hook table, admin asset/menu contracts, agent-context model

## Enforcement (run once per clone)

```bash
bash agent-rules/setup.sh      # installs the git hooks (branch + commit naming)
```
The git hooks enforce for **any** agent or a human. Claude Code users also get inline
checks from `.claude/settings.json`. See `agent-rules/README.md`.

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
                   #   Tokens/         : TokenService, the reusable single-use hashed token primitive
  Context/         # Agent context: what a connected assistant is told
                   #   SiteContext / PayloadRenderer / Payload / Readers/
  Media/           # Shared media handling + upload links (MimeAllowlist, AttachmentImporter, UploadLinks/)
  Settings/        # What a setting's value IS (constant -> filter -> option); read on MCP requests, cron, WP-CLI
  Support/         # WpCompat : WordPress version-capability detection (7.1 feature probes)
  MCP/             # MCP protocol server
  OAuth/           # Full OAuth 2.0 server (entities, repos, endpoints)
  Utilities/       # Standalone helpers (BlockConverter)
tests/
  Unit/            # PHPUnit tests (no WordPress dependency)
  Integration/     # WP_UnitTestCase tests
assets/            # CSS and JS for admin UI
```

Full per-directory detail is in the root [`CLAUDE.md`](CLAUDE.md).

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
- **NEVER add `declare(strict_types=1);` to any PHP file in this codebase, ever.** This overrides any global personal preference for it — no exceptions, not even in a brand-new file.
- **NEVER use jQuery.** Vanilla ES6+ only.
- **NEVER commit without explicit request.** Run `composer phpcs` and `composer phpstan` first.
- **NEVER merge a pull request.** Not your own, not one that is green, not one the
  user approved the *contents* of. Open it, report the check results, and stop.
  Every PR in these repos is reviewed by a human before it lands — a green CI run is
  not a substitute for it.
  - "Yes, do this" authorises the work described. It never authorises merging what
    that work produces, and it never extends to a PR the user has not seen.
  - Permission to merge one specific PR covers that PR only. It does not carry to the next.
  - A merged PR cannot be reopened on GitHub; reverting the merge commit does not undo it.
    The gate is before the merge, not after.
  - This is enforced: `.claude/settings.json` puts `Bash(gh pr merge:*)` behind an `ask`
    rule (Claude Code). Never route around it with `gh api`, a git merge-and-push, or the web UI.
- **Branch names are `feature/`, `fix/`, or `release/<version>` (release branches only). Nothing else.**
  Not `chore/`, not `feat/`, not `refactor/`, not `docs/`. If it corrects something wrong it is
  `fix/`; if it adds something new it is `feature/`. Renaming a branch on GitHub closes its PR.
- **NEVER use `chore`. Anywhere.** Not in a branch name, a commit message, or a PR title.
  If a commit does not fit `fix:` or `feat:`, write a plain sentence: "Set the release version to 1.4.0".
- **NEVER bump version without approval.** Version bumps only happen in release branches, never on `development`, feature branches, or `main`.
- **PR titles are plain, human-readable sentences** — never conventional-commit prefixes. Example: "Upgrade MCP adapter to 0.5.0", not "chore(deps): upgrade...".

## WooCommerce mcp-adapter Timing Bug

`Plugin::init()` skips `McpAdapter::instance()` when `is_admin()` to avoid a timing conflict where WooCommerce's REST preloading triggers `wp_get_ability()` for tools that aren't registered yet. See root `CLAUDE.md` for full details.

## Extensibility Hooks

All hooks follow `albert/{location}/{hook_name}`. The full hook table, the admin
asset/menu contracts, and the agent-context model live in
[Hooks & extension reference](.claude/rules/hooks-reference.md).
