# Albert

WordPress plugin that exposes WordPress functionality to AI assistants via MCP (Model Context Protocol).

**Stack:** PHP 8.1+ | WordPress 6.9+ | OAuth 2.0 (league/oauth2-server) | PSR-4 autoloading

**Requires:** MySQL 8.0+ or MariaDB 10.5+, HTTPS for OAuth, any permalink structure except Plain.
WooCommerce is optional; support is the current stable X.Y and the two before it, which is what CI tests.

This is the canonical, agent-neutral instruction file for this repo, for any agent or
person. `.claude/CLAUDE.md` imports it for Claude Code.

Claude Code loads each area rule below on its own when a matching file is read or edited.
Any other agent: before changing an area, open its rule and follow it.

## What goes where
- **AGENTS.md**: always loaded, in every session and every subagent. Product identity, architecture map, hard "never" rules. Keep it lean.
- **`.claude/rules/<area>.md`**: one per area, each with a `paths:` list so it loads only when that code is touched. The why behind each area's design lives here.
- **`.claude/skills/`**: reference material loaded only when the task calls for it (the hooks reference).
- **agent-rules/**: shared rules across all Albert repos (a git submodule).
- **Enforcement**: native git hooks (any agent or human) plus an optional Claude Code adapter.

A note about one area goes in that area's rule, never here. A line the code already
says (a directory tree, an ability list, an endpoint table) goes nowhere.

## Rules

Shared (submodule, `git@github.com:YourMark/albert-agent-rules`):
- [Git Workflow](agent-rules/git-workflow.md) - branches, commits, PRs, commit/push/merge gates
- [Code Style](agent-rules/code-style.md) - PHP / JS / CSS / security / accessibility
- [Development Methodology](agent-rules/development-methodology.md) - DDD, naming, TDD scope
- [Testing](agent-rules/testing.md) - test structure and conventions
- [Changelog](agent-rules/changelog.md) - changelog & readme.txt conventions

Product-specific:
- [Patterns](.claude/rules/patterns.md) - Albert class patterns, bounded contexts, testing stubs
- [Abilities](.claude/rules/abilities.md) - creating an ability
- [Abilities screen](.claude/rules/abilities-screen.md) - the DataViews screen, row augmentation, source labels
- [Agent context](.claude/rules/agent-context.md) - what `discover-abilities` tells an assistant, the skills registry
- [Safe mode](.claude/rules/safe-mode.md) - what is held for approval, and why
- [Connections](.claude/rules/connections.md) - the Connections screen, connect dialog, allowed users
- [OAuth](.claude/rules/oauth.md) - how the OAuth endpoints are served, token validation
- [Logging](.claude/rules/logging.md) - the three outcomes, schema, failure capture
- [Bundled MCP adapter](.claude/rules/mcp-adapter.md) - plugin first, shared, never scoped; why the adapter does not start on admin pages
- [Hooks & extension reference](.claude/skills/albert-hooks/SKILL.md) (a skill) - full hook table, admin asset/menu contracts, agent-context model

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
- **NEVER add `declare(strict_types=1);` to any PHP file in this codebase, ever.** This overrides any global personal preference for it, with no exceptions, not even in a brand-new file.
- **NEVER use jQuery.** Vanilla ES6+ only.
- **NEVER commit without explicit request.** Run `composer phpcs` and `composer phpstan` first.
- **NEVER merge a pull request.** Not your own, not one that is green, not one the
  user approved the *contents* of. Open it, report the check results, and stop.
  Every PR in these repos is reviewed by a human before it lands; a green CI run is
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
- **NEVER scope the MCP adapter or require it from the root `composer.json`**, and never reintroduce `Albert\Vendor\`. It is shared through Jetpack Autoloader; see `.claude/rules/mcp-adapter.md`.
- **NEVER bump version without approval.** Version bumps only happen in release branches, never on `development`, feature branches, or `main`.
- **PR titles are plain, human-readable sentences**, never conventional-commit prefixes. Example: "Upgrade MCP adapter to 0.5.0", not "chore(deps): upgrade...".

## Extensibility Hooks

All hooks follow `albert/{location}/{hook_name}`. The full hook table, the admin
asset/menu contracts, and the agent-context model live in
[Hooks & extension reference](.claude/skills/albert-hooks/SKILL.md).
