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
composer phpstan          # Static analysis (level 7)
composer test             # Run unit tests
composer test:integration # Run integration tests (requires WP test suite)
```

## Directory Structure

```
src/
  Abstracts/       # BaseAbility, AbstractAddon (addon registry, singleton, license helpers)
  Abilities/       # Ability implementations (WordPress/, WooCommerce/)
  Admin/           # Admin pages (abilities toggles, settings, connections)
  Contracts/       # Interfaces (Ability, Hookable)
  Core/            # Plugin bootstrap, AbilitiesManager, AbilitiesRegistry
  MCP/             # MCP protocol server
  OAuth/           # Full OAuth 2.0 server (entities, repos, endpoints)
  Utilities/       # Standalone helpers (BlockConverter)
tests/
  Unit/            # PHPUnit tests (no WordPress dependency)
  Integration/     # WP_UnitTestCase tests
assets/            # CSS and JS for admin UI
```

## Public API (what add-ons may use)

| Surface | How |
|---|---|
| Ability registration | `do_action('albert/abilities/register')` |
| Admin pages | `apply_filters('albert/admin/submenu_pages', $pages)` |
| Ability lifecycle | `albert/abilities/before_execute`, `albert/abilities/after_execute` |
| Group definitions | `apply_filters('albert/abilities/groups', $groups)` |
| License check | `albert_has_valid_license(string $addon_slug): bool` |
| Supplier registry | `apply_filters('albert/abilities/suppliers', $suppliers)` |

Base classes: `Albert\Abstracts\BaseAbility`, `Albert\Abstracts\AbstractAddon`

## Ecosystem

Free is the **core**. All add-ons depend on it. The core never depends on add-ons.

```
Addons → Core    (allowed)
Core   → Addons  (NEVER)
Addon  → Addon   (NEVER — use Core hooks as mediator)
```

### Known add-ons

| Plugin | Folder |
|---|---|
| Albert Premium Service | `albert-premium-service` |
| Albert WooCommerce | `albert-woocommerce` |

### Deprecation policy

Before changing or removing any public function, hook, or class:

1. Keep the old API working — wrap it, do not remove it
2. Call the appropriate deprecation helper:
   - Functions/methods: `_deprecated_function( __FUNCTION__, '1.x.0', 'replacement' )`
   - Actions: `do_action_deprecated( 'old/hook', $args, '1.x.0', 'new/hook' )`
   - Filters: `apply_filters_deprecated( 'old/hook', $args, '1.x.0', 'new/hook' )`
3. Minimum deprecation window: 2 minor versions or 1 major version, whichever is longer
4. Add-ons only see a debug notice — nothing breaks on production

### Legacy ability ID note

Free WooCommerce read-only abilities predate the naming convention and use
`albert/woo-find-products` style IDs. All new abilities use `{namespace}/{resource}/{action}`.
Never rename the legacy IDs — they are part of the public API.

## Critical Warnings

- **NEVER use alternative PHP syntax** (`: endif`, `: endforeach`). ALWAYS use `{ }` braces.
- **NEVER use jQuery.** Vanilla ES6+ only.
- **NEVER commit without explicit request.** Run `composer phpcs` and `composer phpstan` first.
- **NEVER bump version without approval.**

## WooCommerce mcp-adapter Timing Bug

`Plugin::init()` skips `McpAdapter::instance()` when `is_admin()` to avoid a timing conflict
where WooCommerce's REST preloading triggers `wp_get_ability()` for tools that aren't
registered yet.
