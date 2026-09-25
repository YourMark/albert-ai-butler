# Albert

![Requires PHP](https://img.shields.io/badge/Requires%20PHP-8.1+-blue)
![Requires WordPress](https://img.shields.io/badge/Requires%20WordPress-6.9+-blue)
![Tested up to](https://img.shields.io/badge/Tested%20up%20to-7.1-blue)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green)

**Connect your WordPress site to AI assistants with secure OAuth 2.0 authentication and the Model Context Protocol (MCP).**

## Description

Albert provides a powerful API that exposes WordPress functionality to AI assistants through the Model Context Protocol (MCP). This plugin acts as a secure bridge between your WordPress site and AI-powered tools like Claude, enabling them to interact with and control various aspects of your website through a standardized interface.

Think of abilities as superpowers that you can grant to AI assistants - from managing content and products to handling complex workflows. The abilities API provides a standardized way for AI assistants to:

- Discover available actions they can perform on your site
- Execute those actions with proper authentication and authorization
- Receive structured responses they can understand and act upon
- Extend WordPress and WooCommerce functionality in AI-friendly ways

## Requirements

- **WordPress**: 6.9 or higher
- **PHP**: 8.1 or higher (8.3+ recommended)
- **WooCommerce**: 10.4 or higher (if WooCommerce integration is used)
- **MySQL**: 8.0+ or MariaDB 10.5+

## Installation

### Via WordPress Plugin Directory

1. Install through the WordPress plugin directory
2. Activate the plugin through the 'Plugins' menu in WordPress

### Manual Installation

1. Download the plugin files
2. Upload the `albert-ai-butler` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress

## Development

### Setup Development Environment

1. Clone this repository
2. Navigate to the plugin directory:
   ```bash
   cd wp-content/plugins/albert-ai-butler
   ```
3. Install dependencies:
   ```bash
   composer install
   ```

### Code Standards

This plugin follows WordPress Coding Standards. Check your code:

```bash
composer phpcs
```

Automatically fix code standards issues:

```bash
composer phpcbf
```

### Running Tests

```bash
composer test
```

## Architecture

The plugin uses a modern, modular architecture:

- **Singleton Pattern**: Main Plugin class ensures single instance
- **Hookable Interface**: Clean hook registration pattern
- **Component System**: Modular, extensible components
- **PSR-4 Autoloading**: Composer-based autoloading
- **Type Safety**: Full PHP type declarations

See `CLAUDE.md` for detailed architectural documentation.

## License

This plugin is licensed under GPL v2 or later.

## Credits

Developed by Mark Jansen - Your Mark Media
Website: https://yourmark.nl

## Changelog

### 1.5.0

**Fixes**

- Asking your assistant to build a page with a layout block that holds other content (such as a Cover block wrapped around a heading and text) could silently save the block empty, losing everything inside it. These blocks now keep their contents.
- In the rare case where a block's content genuinely can't be reproduced, the assistant is now told clearly, instead of the block being saved empty as if it had worked.
- Pages your assistant built with blocks no longer trigger WordPress debug notices ("The type schema keyword for content...") each time they are displayed. Text is now saved only in the block itself, the way the block editor saves it.

**Developer**

- `BlockSerializer` no longer treats the presence of a `render_callback` as proof a block stores no markup. Whether a block can be safely self-closed is now decided from what it actually stores — inner blocks, an attribute sourced from the saved markup, or raw content in the spec — read live from the block registry. Hybrid core blocks (`core/cover`, `core/media-text`) and third-party or custom blocks are all handled with no per-block or per-WordPress-version list.
- Content stored in a text-sourced attribute (`core/verse`'s `content`, and the documented "put text in attributes" contract) is materialised into the block's markup rather than left inert in the comment JSON, where it would be lost on reload. Content stored in a structural source that can't be reproduced without the block's own `save()` (an element attribute, a `query`) is refused with an actionable error instead of silently dropped.
- Adds `BlockSchema::markup_sourced_attributes()` and `BlockSchema::text_sourced_attributes()`, the registry readers behind those decisions.
- `BlockSerializer::make_block()` now emits one inner-block placeholder per child even when a template supplies no `%children%` marker, so inner blocks can never be dropped from `innerContent`; nesting blocks under a block that doesn't accept them now warns rather than surprising the caller later.
- Sourced block attributes (`content`, `text`, `url`, ...) are no longer written into the block comment JSON. Adds `BlockSchema::sourced_attributes()`.

### 1.4.1

Fixes AI assistants failing to connect, including on managed hosts (such as SiteGround and Servebolt) that handle the sign-in discovery address themselves.

**Fixes**

- AI assistants using the newest sign-in method could fail to connect. They connect again now.
- AI assistants could not sign in on some managed hosts (such as SiteGround and Servebolt) that answer part of the sign-in discovery themselves. Albert now serves it where those hosts pass it through, so sign-in works with no manual setup.

**Developer**

- Discovery is now RFC 9728 / RFC 8414 conformant: `resource_metadata` in `WWW-Authenticate`, one Protected Resource Metadata source listing the issuer, and the issuer served at a mid-path `.well-known` URL that hosts intercepting a root `/.well-known/` leave alone. Adds the `albert_oauth_discovery` Site Health test.
- The issuer is now a path, so a connected client that re-runs discovery re-authorises once; live connections keep working.

### 1.4.0

Release date: 2026-09-03

Albert 1.4.0 tells connected assistants what your site actually is, so they stop guessing. Assistants can also send you files directly, and every admin screen has been rebuilt on one design system. Day-one support for WordPress 7.1.

**Features**

- New **Albert -> Context** screen. Write instructions for connected assistants, and send your site's language, timezone, theme colours and fonts, content types and shop settings with every conversation.
- Preview exactly what is sent, and switch off any section an assistant does not need.
- Text from your site (your instructions, post content, product descriptions) is now marked as information rather than orders, so it can never change what an assistant is allowed to do.
- Assistants can send you a file directly. Where Albert could previously only fetch media from a web address, an assistant that already has the file now requests a single-use upload link and posts the bytes straight to your media library.
- New size limit for those uploads under **Albert -> Settings -> Uploads**.
- New **Albert -> Skills** screen, listing the task guides Albert and its add-ons ship. Guides tell an assistant how to handle a job on your site, such as writing blocks correctly. Open one to read the exact guidance an assistant follows.
- Assistants now read those guides themselves. Each is offered by name at the start of a conversation, and only when it applies to your site.
- New Site Health check that reports when the MCP endpoint is not registered, instead of failing with a 401 that looks like an authentication problem.

**Enhancements**

- Every Albert screen has been rebuilt: Dashboard, Settings, Connections, Abilities and the two new screens now share one design system, one set of controls and one visual language.
- Albert picks up the admin colour scheme from your WordPress profile instead of always being blue.
- A row of links across the top of every Albert screen replaces trips back to the sidebar.
- The Connections screen now shows the endpoint, who is connected, who may connect, and setup steps per assistant. You can name or rename a connection inline.
- Choosing who may connect is a searchable picker rather than a dropdown listing every user, so it stays fast on large sites.
- You can name a connection at the moment you approve it.
- Invitations nobody accepts now expire, connections approved but never used are dropped, and idle connections can be expired on a schedule. All configurable, all logged.
- A call Albert refused now reads as **Blocked** rather than Failed. A tool that ran and truthfully answered "there is no such post" counts as a success.
- An assistant that uses a wrong parameter name is now told which name it used and which names the tool takes. Previously the wrong name was silently discarded and the assistant could get a successful answer that ignored half its request.
- An expired session now tells the client its token expired, instead of a bare refusal indistinguishable from never having connected.
- A failed connection records which of six reasons it actually was, rather than one generic message for all of them.
- Many tools carry a short note about the mistake that is easiest to make with them. Assistants read these only when they use the tool.
- New installs start on Strict privacy. Existing sites keep their setting.
- Settings are now sanitised on the way into the database, so a value written by code or WP-CLI is checked exactly like one typed into the form.
- A setting fixed in code shows as read-only and names what owns it, rather than appearing editable and being silently overwritten.
- The Abilities and Skills screens switch to a stacked list on narrower screens, so nothing is hidden behind a sideways scrollbar on a laptop or tablet. Pick a layout yourself and it stays picked.
- Accessibility pass across the admin: contrast on faint borders and switches, keyboard reachability, focus after dismissing an item, touch target sizes and screen reader announcements.
- On WordPress 7.1, server-only details are stripped from tool descriptions before they reach an assistant. This covers tools added by other plugins too.

**Bugfixes**

- Fixes the block editor guide never reaching your site. It has been in the source since 1.2.0 but was left out of every release package, so assistants have been writing blocks without the guidance the 1.2.0 release described. The Skills screen is what made it visible, by counting the guides out loud.
- Fixes the Abilities table scrolling sideways on smaller screens. Below roughly 1450px the columns overflowed, and the on/off switch sits at the right-hand end, so the one control the screen exists for was the first thing to disappear.
- Fixes assistants being unable to read product categories, or any category or tag set without an explicit REST base. Albert asked the wrong question and reported them as unavailable.
- Fixes `albert/find-taxonomies` and `albert/find-terms` failing every call on WordPress 7.1, which made taxonomy discovery impossible.
- Fixes Albert's MCP endpoint failing to register when another plugin bundles its own copy of the MCP library. Albert now shares one copy, so both endpoints work.
- Fixes data migrations being skipped after a deactivate, update, reactivate cycle, which left the update permanently marked as done without having run.
- Fixes the dashboard reporting that all abilities were enabled, because the total and the enabled figure were the same number counted twice.
- Fixes privacy mode not saving, because the screen and the save routine disagreed about the option name.
- Fixes a privacy mode set in code showing as editable.
- Fixes the nightly cleanup hiding connections that still worked. A connection idle overnight disappeared from the admin while continuing to call the site, so it could not be revoked.
- Fixes connections being counted elsewhere by a looser rule with no expiry check.
- Fixes declared minimums and maximums not being enforced outside the browser, and negative numbers losing their sign instead of being clamped.
- Fixes the activity list fade permanently covering its last row.
- Fixes "ability not found" notices on the dashboard.
- Fixes the `.well-known` discovery document not advertising the authentication method Albert issues desktop clients with.
- Fixes a rejected endpoint override filter falling back silently. The endpoint card now says where the address comes from.

**Security**

- Revoking a single session now revokes that session's refresh token. It previously ended only the access token, so the assistant reconnected on its own within the hour while the screen said the session had been revoked. Revoking all sessions was never affected.
- `albert/upload-media` now checks file types against the current user's own allowed types instead of the site-wide default. An `unfiltered_upload` capability can no longer widen what it accepts.
- Failed database writes when issuing OAuth authorisation codes, access tokens and refresh tokens are no longer ignored. All three now fail immediately instead of surfacing later as an unrelated error.
- `WWW-Authenticate` is now sent on every 401 from the MCP endpoint. Previously an expired token skipped the header entirely.

**Developer**

- New `Albert\Context` module, with filters `albert/context/enabled`, `albert/context/instructions`, `albert/context/sections`, `albert/context/site` and `albert/context/skills`.
- New `albert/skills/registry` filter registers a skill as data, with declared preconditions. A skill is offered only when its preconditions hold, and an unrecognised condition fails closed.
- New `albert/get-skill` ability returns a guide's Markdown body by slug, gated on `edit_posts`.
- New `albert/create-upload-link` ability and `POST|PUT /albert/v1/media/uploads` endpoint, backed by the reusable `Core\Tokens\TokenService`. New `albert/media/upload_link_max_bytes` filter.
- Abilities' "Supplier" is renamed to "Source": `AbilitiesRegistry::get_sources()` and `albert/abilities/sources`. The old method, filter and payload keys still work, deprecated rather than removed.
- New `albert/abilities/invoked` action, relayed from WordPress 7.1's `wp_ability_invoked`. Fires for every invocation whatever the outcome, including denied and short-circuited calls. Inert below 7.1.
- New `Albert\Logging\Outcome` classifies every logged outcome as `success`, `warning` or `error`. The ability log gains `failure_stage` and `privacy_mode`, and drops `ip_address`, `referrer` and `request_id`.
- New `albert/logging/api_surface_codes` filter, so an add-on's own not-found codes classify correctly.
- Settings API: add-ons can name their own card, attach a unit with `suffix`, add detail with `info`, and declare `min` and `max` once to drive both the control and the sanitiser. New filters `albert/settings/value/{option}`, `albert/settings/value_source/{option}` and `albert/settings/validator/{option}`. `show_in_rest` now works.
- New dashboard filters: `albert/dashboard/stats`, `albert/dashboard/attention`, `albert/dashboard/suggestions`, `albert/dashboard/recommendations` and `albert/dashboard/show_resources`.
- New OAuth filters `albert/oauth/access_token_ttl`, `albert/oauth/refresh_token_ttl` and `albert/oauth/auth_code_ttl`, plus an `albert/oauth/token_request_failed` action carrying the specific failure reason.
- Every object-typed input schema now registers with `additionalProperties => false`, set once in `BaseAbility::prepare_input_schema()`, so add-ons inherit it.
- New design system. `albert-tokens.css` holds colour, spacing, type, radius and motion in `oklch()`; `albert-primitives.css` holds shared components. Add-ons declare `albert-primitives` as a stylesheet dependency.
- **Breaking for add-ons.** The 56 value-named custom properties in `admin-settings.css` (`--albert-primary`, `--albert-font-lg`) are replaced by role-named ones (`--albert-color-accent`, `--albert-font-size-section-title`) with no alias layer. Albert Premium Service ships the matching migration and must be updated alongside this release.
- The bundled MCP adapter moves to 0.6.1 and now loads unscoped through Jetpack Autoloader instead of being prefixed with Mozart. Albert, WooCommerce and the standalone MCP Adapter plugin share one adapter and each keep their own server. Mozart, `vendor-prefixed/` and the `Albert\Vendor\` namespace are gone.
- WordPress 7.1 support is feature-detected in `Albert\Support\WpCompat`. Tool schemas pass through `wp_prepare_json_schema_for_client()`, `is_mcp_public()` adopts the `meta.mcp.public ?? meta.public` precedence, and `get_all_raw()` reads the registry directly so a third-party filter cannot make Albert lose track of a registered ability.
- Object-typed input schemas no longer claim their default is an array.
- Stylesheets and scripts are versioned on file modification time, so a change during a release cycle is never served stale.
- CI compiles and lints the admin screens on every pull request, and adds WordPress 7.0 and trunk rows.

**Credits**

- [Marinus Klasen](https://github.com/mklasen) for the idea behind direct file uploads.
- [Jonathan de Jong](https://github.com/jonathan-dejong) for reporting the taxonomy failures, the output schema failures and the MCP adapter conflict.

### 1.3.1

A security update for how AI assistants connect.

**Security**
- Connecting an assistant now requires the exact web address it will return you to. The old catch-all that accepted any address is gone, and every connection request is matched against that exact address.
- The approval screen now shows where the assistant will send you, and how recently the app was set up, so an unexpected request is easier to spot.
- Apps that connect through their own link (such as some desktop assistants) are checked more strictly and secured without relying on a shared secret.
- Stricter checking of return addresses, a limit on how many a single app can register, and a cap on the total number of connected apps.

**Developer**
- New `albert/oauth/allowed_redirect_schemes` filter to restrict redirect URI schemes to an explicit allowlist.

### 1.3.0

Two headline changes: the Abilities screen has been rebuilt from scratch, and personal data is now kept private from AI assistants automatically.

**Features**
- Rebuilt Abilities screen — a fast, modern list with search, filter by category or supplier, sort, and pagination, matching the rest of the WordPress admin.
- Switch abilities on or off instantly, one at a time or in bulk — no Save button.
- A detail panel on every ability showing its inputs, the permission it needs, and when it last ran.
- Privacy mode — personal data (names, email addresses, phone numbers, postal addresses) is redacted from AI results before it leaves your site. Choose Strict, Balanced, or Off.
- Reveal the real personal details only when you explicitly ask, gated by your own capabilities.

**Improvements**
- The Abilities screen was rebuilt for accessibility throughout — full keyboard and screen-reader support, with clearer focus and contrast.
- The three core tools an assistant uses to discover and run abilities can no longer be switched off and repair themselves on load, so a connection never breaks after an update.

**Developer**
- New extension points on the Abilities screen let add-ons plug straight in — this powers Albert Premium's per-role and per-user permission rules.
- New `albert/privacy/*` filters let add-ons protect their own fields — the WooCommerce add-on uses these to strip payment and card data.

### 1.2.0

Albert now understands the WordPress block editor — a big step up in how AI assistants read and write your content.

**Features**
- Block editor support — assistants work with real WordPress blocks instead of raw HTML, reading a post as a clean, structured outline and composing new content with proper blocks (headings, paragraphs, lists, quotes, images, buttons, columns, groups).
- Edit one block at a time — change, add, move, or remove a single block without disturbing the rest of the page.
- Classic editor support — classic-editor sites, posts, and pages are read and saved as HTML.
- Built-in guidance — ships a playbook and reference data that teach connected assistants how your site's blocks work.

**Improvements**
- Cleaner content, fewer errors — Albert validates blocks as they're written and steers the assistant to fix mistakes, avoiding the block editor's "unexpected content" warnings.
- Assistants only use the blocks your site (and the connected user) are allowed to use.
- Long posts are paged automatically, so big content is never cut off mid-way when an assistant reads it.
- Safer updates — abilities added by a plugin update now start switched off; you decide what to turn on.

### 1.1.1

A bug-fix release.

**Fixes**
- OAuth discovery endpoints (`/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server`) are now reachable when the request arrives with a trailing slash, avoiding a redirect loop or 404 on hosts that add a trailing slash at the edge.

**Credits**
- Reported by [Marinus Klasen](https://profiles.wordpress.org/mklasen/).

### 1.1.0

A major admin redesign, new activity logging, and a stack of reliability fixes.

**Features**
- Unified abilities page — one filterable list of every registered ability, whether it comes from WordPress core, WooCommerce, or any third-party plugin. Search, filter by category or supplier, read/write/delete at a glance.
- Instant per-row save — toggles save immediately. No Save Changes button, no lost work.
- Activity logging — a dashboard widget showing the most recent ability execution, and a "Last run" line on every ability.
- Plain-language annotation chips — each ability is labelled Read, Write, or Delete with accessible tooltips (replaces the developer-facing "Destructive / Idempotent / Readonly" terms).
- List / paginated view toggle — preference persisted server-side, no flash of content on load.

**Fixes**
- Ability categories now register at the default hook priority, preventing collisions with WordPress core's built-in categories on WP 6.9+.
- Restored a missing `user` category that Users abilities depend on.
- The `password` field on `core/users/create` is now correctly flagged as required in its input schema.
- Fixed `Logger::log_execution` visibility so extenders can hook it cleanly.
- Consolidated REST namespace handling to a single `Plugin::REST_NAMESPACE` constant.

**Improvements**
- Accessibility — keyboard-reachable chip tooltips, WCAG 2.2 AA contrast on all chip tones, debounced aria-live stats announcements during search, and focus indicators on pagination buttons and filter selects.

**Developer**
- New `albert/abilities/suppliers` filter so third-party plugins can register branded supplier names for the filter dropdown.
- Comprehensive automated test suite — input validation, output schema, permission, and per-parameter coverage on every ability. Auto-discovers abilities from `src/Abilities/` so new abilities are tested without touching the test files.
- CI matrix now covers PHP 8.1–8.4, WordPress 6.9 and latest, and WooCommerce 10.5/10.6/latest.
- Removed redundant manual `empty()` input validation from every ability — WordPress core's `WP_Ability::validate_input()` already enforces the schema.
- Unified internal settings API.
- Removed: the deferred-save bulk form, category-grouped card layout, per-category subpages, and the "CORE" / "ALBERT" uppercase source badges.

### 1.0.1

A bug-fix release.

**Fixes**
- OAuth route namespace mismatch (`albert-ai-butler/v1` vs `albert/v1`) that caused connection failures when clients followed the discovery spec. All endpoints now use `albert/v1`, consolidated to a single `Plugin::REST_NAMESPACE` constant.

**Developer**
- New `albert/rest_namespace` filter for sites with namespace collisions.

### 1.0.0 - 2025-01-23

Initial release.

**Features**
- Full OAuth 2.0 server implementation.
- MCP (Model Context Protocol) integration.
- Core WordPress abilities (Posts, Pages, Users, Media, Taxonomies).
- Admin interface for managing abilities and OAuth sessions.
- Secure authentication and authorization.

**Developer**
- Extensible ability system built on the WordPress Abilities API.
