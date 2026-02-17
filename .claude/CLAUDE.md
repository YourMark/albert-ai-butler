# Albert

WordPress plugin that exposes WordPress functionality to AI assistants via MCP (Model Context Protocol).

**Stack:** PHP 8.2+ | WordPress 6.9+ | OAuth 2.0 (league/oauth2-server) | PSR-4 autoloading

## Rules

- [Code Style](rules/code-style.md) - PHP brace rules, naming conventions, DocBlocks, JS/CSS
- [Testing](rules/testing.md) - Unit vs integration tests, TDD guidance
- [Development Methodology](rules/development-methodology.md) - DDD bounded contexts, ubiquitous language, workflow
- [Patterns](rules/patterns.md) - Albert-specific class patterns, bounded contexts, testing stubs

## Commands

```bash
composer install          # Install dependencies
composer phpcs            # Check coding standards (WordPress CS)
composer phpcbf           # Auto-fix coding standards
composer test             # Run unit tests
composer test:integration # Run integration tests (requires WP test suite)
```

## Directory Structure

```
src/
  Abstracts/       # BaseAbility (all abilities extend this)
  Abilities/       # Ability implementations (WordPress/, WooCommerce/)
  Admin/           # Admin pages (abilities toggles, settings, connections)
  Contracts/       # Interfaces (Ability, Hookable)
  Core/            # Plugin bootstrap, AbilitiesManager, AbilitiesRegistry
  MCP/             # MCP protocol server
  OAuth/           # Full OAuth 2.0 server (entities, repos, endpoints)
tests/
  Unit/            # PHPUnit tests (no WordPress dependency)
  Integration/     # WP_UnitTestCase tests
assets/            # CSS and JS for admin UI
```

## Critical Warnings

- **NEVER use alternative PHP syntax** (`: endif`, `: endforeach`). ALWAYS use `{ }` braces.
- **NEVER use jQuery.** Vanilla ES6+ only.
- **NEVER commit without explicit request.** Run `composer phpcs` first.
- **NEVER bump version without approval.**
- The root `CLAUDE.md` is the canonical project reference (checked into git). This file supplements it.

## WooCommerce mcp-adapter Timing Bug

`Plugin::init()` skips `McpAdapter::instance()` when `is_admin()` to avoid a timing conflict where WooCommerce's REST preloading triggers `wp_get_ability()` for tools that aren't registered yet. See root `CLAUDE.md` for full details.

## Extensibility Hooks

All hooks follow `albert/{location}/{hook_name}` convention:

| Hook | Type | Purpose |
|------|------|---------|
| `albert/abilities/register` | action | Register custom abilities |
| `albert/abilities/before_execute` | action | Before any ability runs |
| `albert/abilities/after_execute` | action | After any ability runs |
| `albert/abilities/before_execute/{id}` | action | Before a specific ability |
| `albert/abilities/after_execute/{id}` | action | After a specific ability |
| `albert/admin/submenu_pages` | filter | Add addon admin pages |
| `albert/abilities/groups` | filter | Modify ability group definitions |
| `albert/abilities_icons` | filter | Customize category icons |
| `albert/developer_mode` | filter | Toggle developer mode |
| `albert/activated` | action | Plugin activated |
| `albert/deactivated` | action | Plugin deactivated |
