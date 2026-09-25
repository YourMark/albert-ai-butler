# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## About This Plugin

Albert is a WordPress plugin that exposes WordPress functionality to AI assistants through the MCP (Model Context Protocol). It provides:

- **Abilities API**: Register and expose WordPress operations as AI-callable tools
- **OAuth 2.0 Server**: Full OAuth implementation for secure AI assistant authentication
- **MCP Integration**: Connect AI assistants (Claude Desktop, etc.) to WordPress

## System Requirements

- **PHP**: 8.1+ (8.3+ recommended)
- **WordPress**: 6.9+
- **Database**: MySQL 8.0+ or MariaDB 10.5+
- **HTTPS**: Required for OAuth
- **Permalinks**: any structure except Plain (`index.php` structures work; see `ServerMetadata::url()`)
- **WooCommerce**: 10.4+ (optional, for WooCommerce abilities)

## Directory Structure

```
albert-ai-butler/
├── albert-ai-butler.php             # Main plugin bootstrap
├── composer.json                       # PSR-4 autoloading & dependencies
├── CLAUDE.md                           # This file
├── README.md                           # GitHub documentation
├── readme.txt                          # WordPress.org format
├── DEVELOPER_GUIDE.md                  # Developer documentation
│
├── src/                                # Source code (Albert\)
│   ├── Abstracts/
│   │   └── BaseAbility.php             # Base class for all abilities
│   │
│   ├── Contracts/
│   │   └── Interfaces/
│   │       ├── Ability.php             # Ability interface
│   │       └── Hookable.php            # Hook registration interface
│   │
│   ├── Core/
│   │   ├── Plugin.php                  # Main singleton, bootstraps everything
│   │   ├── AbilitiesManager.php        # Registers abilities with WordPress
│   │   ├── AbilitiesRegistry.php       # Source map, category grouping, per-ability source lookup
│   │   └── AnnotationPresenter.php     # Annotation → chip DTO mapping for the admin UI
│   │
│   ├── Admin/
│   │   ├── AbilitiesPage.php           # Unified flat-list abilities page (Core/ACF/Woo merged)
│   │   ├── Connections.php             # Allowed users & active connections
│   │   ├── Settings.php                # Plugin settings page
│   │   └── UserSessions.php            # OAuth sessions management
│   │
│   ├── Abilities/
│   │   ├── WooCommerce/
│   │   │   ├── FindProducts.php        # albert/woo-find-products
│   │   │   ├── ViewProduct.php         # albert/woo-view-product
│   │   │   ├── FindOrders.php          # albert/woo-find-orders
│   │   │   ├── ViewOrder.php           # albert/woo-view-order
│   │   │   ├── FindCustomers.php       # albert/woo-find-customers
│   │   │   └── ViewCustomer.php        # albert/woo-view-customer
│   │   └── WordPress/
│   │       ├── Posts/
│   │       │   ├── FindPosts.php       # albert/find-posts
│   │       │   ├── ViewPost.php        # albert/view-post
│   │       │   ├── Create.php          # albert/create-post
│   │       │   ├── Update.php          # albert/update-post
│   │       │   └── Delete.php          # albert/delete-post
│   │       ├── Pages/
│   │       │   ├── FindPages.php       # albert/find-pages
│   │       │   ├── ViewPage.php        # albert/view-page
│   │       │   ├── Create.php          # albert/create-page
│   │       │   ├── Update.php          # albert/update-page
│   │       │   └── Delete.php          # albert/delete-page
│   │       ├── Users/
│   │       │   ├── FindUsers.php       # albert/find-users
│   │       │   ├── ViewUser.php        # albert/view-user
│   │       │   ├── Create.php          # albert/create-user
│   │       │   ├── Update.php          # albert/update-user
│   │       │   └── Delete.php          # albert/delete-user
│   │       ├── Media/
│   │       │   ├── FindMedia.php       # albert/find-media
│   │       │   ├── ViewMedia.php       # albert/view-media
│   │       │   ├── UploadMedia.php     # albert/upload-media
│   │       │   ├── CreateUploadLink.php # albert/create-upload-link
│   │       │   └── SetFeaturedImage.php # albert/set-featured-image
│   │       └── Taxonomies/
│   │           ├── FindTaxonomies.php  # albert/find-taxonomies
│   │           ├── FindTerms.php       # albert/find-terms
│   │           ├── ViewTerm.php        # albert/view-term
│   │           ├── CreateTerm.php      # albert/create-term
│   │           ├── UpdateTerm.php      # albert/update-term
│   │           └── DeleteTerm.php      # albert/delete-term
│   │
│   ├── Context/                        # Agent context (doc 21)
│   │   ├── ContextSettings.php         # Owner's choices: option + albert/context/* filters
│   │   ├── SiteContext.php             # Assembles the structured array (the API)
│   │   ├── PayloadRenderer.php         # Renders it to the wire text (the format)
│   │   ├── Payload.php                 # The two discovery fields + the screen preview
│   │   ├── SkillIndex.php              # Conditional one-line-per-skill index
│   │   ├── Symbols.php                 # Third-party plugin detection by symbol
│   │   └── Readers/                    # Environment, DesignTokens, ContentModel, Commerce
│   │
│   ├── Media/                           # Shared media handling + upload links (doc 32)
│   │   ├── MimeAllowlist.php           # Shared MIME allowlist, used by Path A + Path B
│   │   ├── AttachmentImporter.php      # On-disk file -> attachment; the tail both paths share
│   │   ├── AttachmentResponse.php      # The attachment shape both paths return
│   │   ├── TempFile.php                # Delete-if-present for abandoned uploads
│   │   └── UploadLinks/
│   │       ├── UploadLinkService.php     # Mint/redeem/finalize domain logic
│   │       └── UploadLinkController.php  # POST /media/uploads redemption endpoint
│   │
│   ├── MCP/
│   │   └── Server.php                  # MCP protocol handler
│   │
│   ├── OAuth/
│   │   ├── Database/
│   │   │   └── Installer.php           # Creates OAuth database tables
│   │   ├── Endpoints/
│   │   │   ├── OAuthController.php     # /oauth/authorize, /oauth/token
│   │   │   ├── OAuthDiscovery.php      # .well-known endpoints
│   │   │   ├── AuthorizationPage.php   # User consent UI
│   │   │   ├── ClientRegistration.php  # Dynamic client registration
│   │   │   └── Psr7Bridge.php          # PSR-7 ↔ WordPress conversion
│   │   ├── Entities/
│   │   │   ├── AccessTokenEntity.php
│   │   │   ├── AuthCodeEntity.php
│   │   │   ├── ClientEntity.php
│   │   │   ├── RefreshTokenEntity.php
│   │   │   ├── ScopeEntity.php
│   │   │   └── UserEntity.php
│   │   ├── Repositories/
│   │   │   ├── AccessTokenRepository.php
│   │   │   ├── AuthCodeRepository.php
│   │   │   ├── ClientRepository.php
│   │   │   ├── RefreshTokenRepository.php
│   │   │   └── ScopeRepository.php
│   │   └── Server/
│   │       ├── AuthorizationServerFactory.php
│   │       ├── ResourceServerFactory.php
│   │       ├── KeyManager.php          # RSA key management
│   │       └── TokenValidator.php      # Validates Bearer tokens
│
├── assets/
│   ├── css/
│   │   └── admin-settings.css
│   └── js/
│       └── admin-settings.js
│
├── tests/
│   ├── bootstrap.php
│   ├── bootstrap-unit.php
│   ├── TestCase.php
│   ├── Unit/
│   │   └── SampleTest.php
│   └── Integration/
│       ├── PluginTest.php
│       └── AbilitiesManagerTest.php
│
├── .claude/
│   └── media-upload-discussion.md      # Ongoing discussion notes
│
└── vendor/                             # Composer dependencies (gitignored)
```

## Architecture Overview

### Core Components

#### 1. Plugin Bootstrap (`src/Core/Plugin.php`)
Singleton that initializes all components:
- Registers admin pages (Abilities, Settings, Sessions)
- Initializes OAuth endpoints
- Registers MCP server
- Registers abilities on `init` hook

#### 2. Abilities System
Abilities are WordPress operations exposed to AI assistants.

**BaseAbility** (`src/Abstracts/BaseAbility.php`):
- Abstract class all abilities extend
- Defines: `$id`, `$label`, `$description`, `$input_schema`, `$output_schema`
- Implements `register_ability()` to register with WordPress
- Abstract `execute(array $args)` method for implementation

**AbilitiesManager** (`src/Core/AbilitiesManager.php`):
- Collects and registers all abilities
- Calls `wp_register_ability()` for each enabled ability

**Creating a New Ability:**
```php
namespace Albert\Abilities\WordPress\Example;

use Albert\Abstracts\BaseAbility;
use WP_Error;

class MyAbility extends BaseAbility {
    public function __construct() {
        $this->id          = 'albert/my-ability';
        $this->label       = __( 'My Ability', 'albert' );
        $this->description = __( 'Description of what it does.', 'albert' );
        $this->category    = 'core';
        $this->group       = 'example';

        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'param1' => [
                    'type'        => 'string',
                    'description' => 'Parameter description',
                ],
            ],
            'required'   => [ 'param1' ],
        ];

        $this->meta = [
            'mcp' => [ 'public' => true ],
        ];

        parent::__construct();
    }

    public function check_permission(): bool {
        return current_user_can( 'edit_posts' );
    }

    public function execute( array $args ): array|WP_Error {
        // Implementation
        return [ 'result' => 'success' ];
    }
}
```

Then register in `Plugin::register_abilities()`:
```php
$this->abilities_manager->add_ability( new MyAbility() );
```

### Extensibility Hooks

Albert provides hooks for addon plugins or themes to register custom abilities, add admin pages, and observe ability execution.

#### Registering Custom Abilities (`albert/abilities/register`)

**Action**: Fires after built-in abilities are registered on the `init` hook. Addons (or themes via `functions.php`) hook here to register their own abilities by extending `BaseAbility` directly, the same pattern built-in abilities use.

```php
// In an addon plugin or theme functions.php:
add_action( 'albert/abilities/register', function ( $manager ) {
    $manager->add_ability( new MyCustomAbility() );
} );
```

The `$manager` parameter is the `AbilitiesManager` instance. Custom abilities extend `Albert\Abstracts\BaseAbility` and implement `execute()` and `check_permission()`. They flow through the same admin UI, enabled/disabled toggle, and `guarded_execute()` pipeline as built-in abilities.

This works from any context that loads before `init`:
- **Addon plugins**: The recommended approach for distributing abilities.
- **Theme `functions.php`**: Works because themes load before the `init` hook fires.
- **Must-use plugins**: Also supported.

#### Execution Hooks

All execution hooks are wrapped in try/catch: observer errors never break ability execution.

**`albert/abilities/before_execute`** (action): Fires before any ability executes. Useful for logging, rate limiting, or audit trails.

```php
add_action( 'albert/abilities/before_execute', function ( string $ability_id, array $args, int $user_id ) {
    // Log, validate, track, etc.
}, 10, 3 );
```

**`albert/abilities/before_execute/{ability_id}`** (action): Fires before a specific ability executes. The ability ID is appended to the hook name (e.g. `albert/abilities/before_execute/albert/create-post`).

```php
add_action( 'albert/abilities/before_execute/albert/create-post', function ( array $args, int $user_id ) {
    // Runs only before the albert/create-post ability.
}, 10, 2 );
```

**`albert/abilities/after_execute`** (action): Fires after any ability executes. Receives the result (array or WP_Error).

```php
add_action( 'albert/abilities/after_execute', function ( string $ability_id, array $args, $result, int $user_id ) {
    // Log result, send notifications, etc.
}, 10, 4 );
```

**`albert/abilities/after_execute/{ability_id}`** (action): Fires after a specific ability executes. The ability ID is appended to the hook name (e.g. `albert/abilities/after_execute/albert/woo-find-products`).

```php
add_action( 'albert/abilities/after_execute/albert/create-post', function ( array $args, $result, int $user_id ) {
    // Runs only after the albert/create-post ability.
}, 10, 3 );
```

#### Admin Submenu Pages (`albert/admin/submenu_pages`)

**Filter**: Addon plugins can add pages to the Albert admin menu. Fires at `admin_menu` priority 15 (after abilities pages, before Settings at priority 20).

```php
add_filter( 'albert/admin/submenu_pages', function ( array $pages ) {
    $pages[] = [
        'slug'       => 'my-addon-settings',  // Required.
        'callback'   => 'render_my_page',      // Required, callable.
        'page_title' => 'My Addon',            // Optional (defaults to slug).
        'menu_title' => 'My Addon',            // Optional (defaults to slug).
        'capability' => 'manage_options',       // Optional (default: manage_options).
        'position'   => 100,                   // Optional (default: 100).
    ];
    return $pages;
} );
```

#### Unified AbilitiesPage (1.1+, rebuilt on DataViews in 1.3)

Since 1.1, the Core / ACF / WooCommerce admin pages are merged into a single `Albert → Abilities` page. Every registered ability appears as a row in a flat, filterable list, and custom abilities registered via `albert/abilities/register` appear in the same list automatically.

Since 1.3 the screen is a React app built on `@wordpress/dataviews` (source in `assets/src/abilities/`, compiled to `assets/build/` via `npm run build`). `src/Admin/AbilitiesPage.php` only renders the mount point (`#albert-abilities-root`) and enqueues the bundle; all data flows over REST (`src/Admin/Rest/AbilitiesController.php`: `GET /albert/v1/abilities`, per-ability and bulk `POST`s). Search/filter/sort/pagination are client-side via `filterSortAndPaginate`; per-row and bulk enable/disable save instantly (no Save Changes button), and clicking a row opens a detail fly-in with add-on seams (`albert.abilities.permissions_section` to replace the Permissions section, `albert.abilities.panel_sections` to append generic sections). The legacy server-rendered list and its `wp_ajax_albert_toggle_ability` / view-mode AJAX were removed in 1.3. If the build is missing (a dev checkout that hasn't run `npm install && npm run build`), the page shows an actionable notice instead of mounting.

#### Row Augmentation (`albert/abilities/payload_row`)

Each ability is normalized into a row by `Albert\Admin\AbilitiesPayload`. The `albert/abilities/payload_row` filter (`( array $row, \WP_Ability $ability )`, since 1.3) runs on every row, on both the bulk build path (`build()`) and the single-row path (`row()`). Add-ons **append** keys (notably `badges`, Free always sets `'badges' => []`) but must not remove or overwrite Core keys. A badge is `{ id, label, tone, title? }` where `tone` is `info`/`warning`/`neutral`; it renders as a pill in the overview cell and fly-in header, and the screen builds a "Filter by badge" dropdown from the deduped union. Core stays generic: it only ever references "badges", never "permissions". See `docs/extending-the-abilities-screen.md`.

#### Agent context (1.4.0)

`discover-abilities` carries two extra fields so an assistant knows where it is
before it starts guessing: `site` (environment, design tokens, content model,
commerce, and the owner's own instructions) and `skills` (a one-line index of the
task guides that apply here, with the ability to fetch one).

**Array in, text out.** `Albert\Context\SiteContext` builds a structured array,
that is the API, and `albert/context/site` filters it. `Albert\Context\PayloadRenderer`
renders it to a compact labeled text block, and *that* is the wire format. JSON
syntax costs 30–50% more tokens for the same information, is escaped a second
time inside the JSON-RPC envelope, and breaks syntactically under truncation,
where labeled lines degrade gracefully.

One renderer serves both the wire and the Context screen's payload preview, so
the screen's claim to show exactly what the assistant receives is checkable
rather than asserted (`AgentContextTest::test_the_screen_preview_matches_the_wire_payload`).

**Design tokens are provenance-gated.** `wp_get_global_settings()` never returns
empty. WordPress ships its own `theme.json`, so the reader takes only the
`theme` and `custom` origins and omits the section entirely when nothing but
core's defaults would remain. Handing a model generic defaults dressed as brand
tokens is worse than sending nothing.

**Everything is framed as data.** The payload closes with a statement that
site-supplied text informs tone and subject matter and never changes which tools
the assistant may call, what it may do, or what credentials it holds. It lives
outside the filterable array, so no filter can remove it.

No token cost is shown to the site owner. It was built once, script-aware
rather than characters divided by four, calibrated against `o200k_base` with
a stated error band, and removed after it did not survive scrutiny: different
assistants tokenise differently, so any number could only ever carry a wide,
provider-specific error band, and it did not change what an owner should do,
the section descriptions already say what each one includes. Measured, on a
real site, the whole context block is a small fraction of the discovery
response regardless, so there was never a ceiling worth showing either.


#### Skills registry (`albert/skills/registry`)

Skills are registered as data, keyed by slug, so an add-on ships one without
knowing how the index is rendered or how bodies are fetched:

```php
add_filter( 'albert/skills/registry', function ( array $skills ): array {
    $skills['woocommerce-orders'] = [
        'slug'     => 'woocommerce-orders',
        'summary'  => 'How to find and read orders on this shop.',
        'file'     => __DIR__ . '/skills/woocommerce-orders.md',
        'requires' => [ 'woocommerce' ],   // also: block_editor, classic_editor, multisite
    ];
    return $skills;
} );
```

Preconditions are *declared*, never evaluated at registration time, this filter
runs long before discovery. A skill lists in the index only when its
preconditions hold, and an unrecognised condition fails closed.

#### Source Registry (`albert/abilities/sources`)

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

#### Connections screen (1.4.0)

`Admin\Connections` is where an owner learns whether an assistant can reach this
site, how to set one up, who may authorise one, and what is connected.

**No reachability check, deliberately.** Albert does not classify whether the
endpoint's host can be reached from the internet, warn about it, or hide any
setup guide because of it. Few sites are run somewhere a cloud assistant cannot
reach, a proper local-site story is coming separately (doc 60, `wp albert
serve`, over stdio rather than a web address at all), and a guide that silently
disappears is more confusing than one that simply fails to connect against a
site the internet cannot reach. An earlier version of this screen did both
(`Support\HostClassifier` classified the host, and `render_setup_card()` used
it to drop guides); both were cut for the same reason.

**Setup content is data, not markup.** `Admin\Connections\ClientSetupGuides`
holds the curated list (Claude Desktop/claude.ai, Claude Code, Cursor, VS Code,
ChatGPT, generic) with steps, config path, a copyable snippet built from the
live endpoint, and a deeplink where the client publishes one. A future CLI docs
page renders the same array. No credential-bundle export of any kind: a file
holding a permanent plaintext credential is a step back from tokens that are
scoped, expiring and revocable.

**Connections group by client, not by token.** A client refreshes hourly, so a
token-keyed list shows one assistant several times and calls each a connection.
Revoke has two depths behind one confirm dialog: access token only (the client
reconnects itself within the hour) or access + refresh (it must be approved
again).

**Labels, and why every one carries a byline.** A connection's name is
self-reported by the connecting client, and in practice every Claude Desktop
connection registers as literally "Claude". The owner's own label is the only
thing that reliably tells two rows apart, so every row carries an always-visible
"+ Name this connection" / "Edit" affordance, never hover-only. Because a label
is a display name one administrator writes onto somebody else's connection, on
the screen where an owner judges what looks trustworthy (CWE-451), three things
are non-negotiable: the app-reported name and "authorised by" stay on the row
beside any label, every label renders its own "Labelled by {user} on {date}"
from the `label_set_by` / `label_set_at` columns, and the label is escaped at
every render site, which is why one `render_connection_row()` produces all five
of them (title, filter index, checkbox name, dialog attribute, edit field).

**The screen renders identically at any row count.** Filter and Select are
always present. Bulk power is a mode somebody enters, not a threshold the data
crosses: an earlier pass revealed checkboxes past a row count, which reads as a
bug. Row checkboxes join the bulk form through HTML's `form=` attribute rather
than being wrapped in it, because each row also carries its own label-editing
form and forms cannot nest.

**Domain-change suspension is recorded, not enforced.** `OAuth\Server\DomainGuard`
still records the `home_url()` host on the client when authorisation completes,
so the history exists on the day the control ships. Nothing reads it to refuse a
request: enforcement, the admin notice and the re-confirm flow are deferred
(tracked in the Albert project's planning docs, not the repo). Half-shipping a control that can strand a
live connection mid-migration is worse than not shipping it.

**One picker, two entry points.** `Admin\Connections\UserPickerModal` renders
the "who may approve an assistant" dialog and enqueues its script; the
Connections card's "Add user" button and the Dashboard checklist's "Choose users"
button both open that one dialog. It is multi-select (an agency onboarding a
site adds three editors at once), with the chosen people shown as removable
chips rather than a count, and somebody already on the list shown muted with an
"Already allowed" tag instead of a checkbox.

**Submitting the picker behaves differently depending on where it was opened
from, on purpose.** From the Connections screen, the picker's own JS submits
via `fetch()`: `Connections::handle_add_allowed_users()` runs the exact same
validated write either way, but for this caller it renders
`render_allowed_users_body()` (the same method a full page load uses, so
there is only ever one place that markup is escaped) and returns it as JSON.
The JS swaps it into `[data-albert-userlist-body]`, closes the dialog, and
briefly highlights the new row (`.albert-user-item--new`, reduced-motion
guarded) instead of reloading. From the Dashboard checklist there is no
allowed-users list on that page to update, so the same form falls through to
a native submit and the original redirect-with-notice behaviour, detected by
`UserPicker.canInsertInline()` checking for that container rather than
trusting which screen it thinks it's on. The two paths share one PHP method;
only the last few lines (JSON vs. redirect) differ, gated by
`is_ajax_request()` reading a header the JS sets explicitly (this is
`admin-post.php`, not `admin-ajax.php`, so `wp_doing_ajax()` is never true
here regardless of transport). One gotcha worth remembering if this pattern
gets reused elsewhere: the form has `<input name="action">` (WordPress's own
admin-post.php routing field), and `HTMLFormElement`'s `[OverrideBuiltins]`
means that field silently shadows the built-in `.action` property, turning
`form.action` into the input element rather than the submit URL.
`form.getAttribute('action')` is unaffected and is what the JS actually uses.

**Removing an allowed user updates both cards, not just the one the link
lives on.** Unlike adding a user, removing one calls
`Settings::revoke_user_tokens()`, which revokes every token that person
holds on every client: a connection they were the sole authoriser of
disappears from "Connected assistants," and one they co-authorised loses
their name from its "Authorised by" line. `AllowedUserRemoval` in
`admin-connections.js` intercepts the "Remove" link's click (same
`is_ajax_request()` detection, same JSON-vs-redirect split in
`handle_remove_allowed_user()` as adding a user), and the response carries
three pieces rendered by the exact methods a full page load would use:
`render_allowed_users_body()`, `render_connections_body()`, and
`render_connections_count_badge()`. A narrower response, updating only the
allowed-users list, would leave "Connected assistants" silently stale until
the next reload. "Disconnect all" stays in the DOM always now (rendered with
a bare `hidden` attribute rather than conditionally omitted) so the JS only
ever has to toggle it, never create or remove it: the count can only fall
through this action, never rise, so that is the only direction it needs to
handle.

**The allowed-users picker never enumerates the user table.** It searches core's
`/wp/v2/users` with explicit `search_columns[]` (`name`, `username`, `email`,
`id`; left alone `WP_User_Query` switches columns based on what the term
*looks* like, so the same person is found or not found depending on how you typed
it), plus `capabilities[]` from the
`albert/connections/allowed_user_capability` filter (default `edit_posts`) and
`per_page=20`. Removing an allowed user revokes their tokens immediately, because
the allowed list is otherwise only checked when an authorisation *starts*.

`search`/`roles`/`capabilities` on that route all require `list_users`. The screen
is `manage_options`, so administrators are fine; if a narrower capability is ever
allowed to manage connections, the picker degrades and needs revisiting.

**The picker suggests people before anybody types.** An empty search box used
to show a bare prompt; it now shows up to ten candidates, administrators first
(the likeliest approvers), then alphabetically, with anyone already allowed
filtered out rather than shown as a wall of "Already allowed" badges. Two
bounded requests (`roles[]=administrator`, then a general page if the first
did not fill the quota), never a full listing, so this is still the same
search-shaped querying as above, just with an empty `search` term.

**Test connection is a deferred seam.** The button is rendered and disabled, with
a note saying checks arrive later. Wiring it to something that only looks like a
check would be worse than an empty seam: a green tick nobody earned reads as
proof.

#### 3. OAuth 2.0 Server
Full OAuth 2.0 implementation using `league/oauth2-server`.

**Endpoints:**
| Endpoint | Purpose |
|----------|---------|
| `GET /wp-json/albert/v1/oauth/authorize` | Authorization request |
| `POST /wp-json/albert/v1/oauth/authorize` | User consent submission |
| `POST /wp-json/albert/v1/oauth/token` | Token exchange |
| `POST /wp-json/albert/v1/oauth/register` | Dynamic client registration |
| `GET /.well-known/oauth-authorization-server` | Server metadata (RFC 8414) |
| `GET /wp-json/albert/v1/oauth/metadata` | Alternative metadata endpoint |

**Token Validation:**
```php
use Albert\OAuth\Server\TokenValidator;

// In a REST endpoint permission callback:
$user = TokenValidator::validate_request( $request );
if ( is_wp_error( $user ) ) {
    return $user;
}
wp_set_current_user( $user->ID );
```

#### 4. MCP Server (`src/MCP/Server.php`)
Handles MCP protocol communication with AI assistants. Authenticated via OAuth.

#### 5. Logging (`src/Logging/`)
Minimal ability execution logging for the Free tier. When Premium is active it takes over the same shared table with richer columns and time-based retention.

**Hook used:** `albert/abilities/after_execute` (Albert hook, fires on both success and failure, covers WP_Error results). `ObservabilityHandler` also captures MCP-level errors that never reach a BaseAbility.

**Filter:** `albert/logging/enabled` (bool, default `true`) means "Free's DB writers are active". Premium returns `false` from this filter to suppress Free's writes and take over logging itself. Returning `false` does **not** disable logging globally: Premium's own writers still run. The `albert/logging/ability_failed` notification action fires regardless of this value so Premium always receives failure signals — but, since 1.4.0, only for a genuine `error`; a `success` or a `warning` never fires it.

**Three outcomes, not two (1.4.0+).** `WP_Error` is the only channel an ability
has for saying "no", so a genuine fault, a deliberate refusal and a truthful
negative answer all arrive in an identical wrapper. `Logging\Outcome` is the one
place that tells them apart. Every value answers the one question a column
called `status` asks — *what happened to this run?*

| Status | Meaning | Visible word |
|---|---|---|
| `success` | The ability ran and answered. Includes empty results, null, "it does not exist" and "there was nothing to do" | Success |
| `warning` | Blocked by policy. Nothing broke; the site said no on purpose (permission refused, ability switched off) | Blocked |
| `error` | The site could not do what was asked: something broke, timed out, or was malformed | Failed |

A truthful "no" **is** a `success`. *Succeeded* and *failed* describe the run;
*not found* describes the answer, and mixing the two kinds in one column is what
made an earlier `no_match` value read as ambiguous. `warning` earns the place
`no_match` did not: *blocked* is the same kind of statement as *succeeded*.
Nothing is lost — `error_code` is still stored, so
`status = 'success' AND error_code IS NOT NULL` still finds the burst of
`product_not_found` from somebody enumerating IDs.

**The test for the next `*_not_found` code: is the thing advertised to the
caller?** Abilities, tools and skills are enumerated to the assistant, so naming
a missing one is a client bug (`error`). Posts, users, terms, orders, products,
media and sessions are not, so asking whether one exists is a fair question
(`success`).

Two signals classify, and the stage wins: a `failure_stage` of `permission` is
always a `warning`, even under core's generic `ability_invalid_permissions`. For
writers with no stage (Free's own `Logger`) the error code decides — anything
ending `_permission_denied` plus `ability_invalid_permissions`, `ability_disabled`,
`capability_revoked`, `rest_forbidden` and `forbidden` are `warning`; anything
ending `_not_found` plus `not_found`, `invalid_post` and `invalid_attachment` are
`success`, minus the API-surface exclusions (`albert_ability_not_found`,
`albert_premium_ability_not_found`, `ability_not_found`, `tool_not_found`,
`skill_not_found`); everything else is `error`. `token_already_used`,
`link_already_used`, `invalid_taxonomy` and the `rest_*` codes are deliberately
left as `error` — each has a second reading that is not benign. Erring toward
`error` is the safe direction: a misclassified failure that shouts beats a real
failure that whispers.

`success` has `failure_stage` forced to NULL — the column is called
*failure*_stage and an answer is not a failure, which is also what makes
Premium's "Failed at" badge correctly never render for those rows. `warning`
**keeps** its stage: it genuinely stopped at `permission`, and that is worth
showing. Neither fires `albert/logging/ability_failed`.

Classification happens in `Repository::insert()`, the same place `privacy_mode`
is stamped, so **every** writer — Free's `Logger`, Free's `ObservabilityHandler`,
Premium's own logger, a third party calling the repository directly — lands on
the same value for the same error.

**Filter:** `albert/logging/outcome` — `( string $status, string $ability_name, WP_Error $error ): string`.
Fires only for failed invocations. Third-party abilities have no reason to follow
Albert's naming conventions; this is their way out. Return `success` for a
negative answer Albert reads as a fault, `warning` for a refusal it cannot
recognise, or `error` for a code it recognises wrongly. A returned value outside
`success|warning|error` is ignored.

**Schema (DB_VERSION 1.2.0, option `albert_logging_db_version`):**
| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `ability_name` | `VARCHAR(191)` | Ability identifier |
| `user_id` | `BIGINT UNSIGNED` | `get_current_user_id()`, 0 if unauthenticated |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |
| `status` | `VARCHAR(20)` | `'success'`, `'warning'` or `'error'` (1.4.0+ for `warning`). Classified centrally by `Logging\Outcome` via `Repository::insert()`; see *Three outcomes* below. No schema change was needed — the column never constrained the value |
| `error_code` | `VARCHAR(100)` | WP_Error code, NULL on success |
| `error_message` | `LONGTEXT` | WP_Error message (1.2.0+), NULL on success. Captured by both Free and Premium loggers; rides in the `$context` array, surfaced in Premium's Activity Log expandable error detail |
| `failure_stage` | `VARCHAR(20)` | Where the invocation died (1.4.0+): `short_circuit`, `input`, `permission`, `execute`, `output`. NULL on `success`, kept on `warning` and `error`. This is the column that lets the log answer *why* a call failed rather than only *that* it did |
| `duration_ms` | `INT UNSIGNED` | Execution ms; NULL unless Premium populates |
| `user_agent` | `TEXT` | Client UA; NULL unless Premium populates |
| `privacy_mode` | `VARCHAR(20)` | The anonymisation mode in force when the row was written (1.4.0+): `strict`, `balanced` or `off`. Stamped centrally by `Repository::insert()` from `PrivacyMode::resolve()` unless the caller passes one, because it cannot be recomputed at render time — the setting may have changed since, and a row read back under today's mode would misreport how its own payload was treated |
| `input` | `LONGTEXT` | JSON input payload; capped by default; NULL unless Premium populates |
| `output` | `LONGTEXT` | JSON success result payload (1.2.0+); capped by default; NULL on error / unless Premium populates |
| `client_id` | `VARCHAR(80)` | OAuth client id of the calling connection (1.2.0+); NULL for non-MCP calls / unless Premium populates |
| `client_name` | `VARCHAR(255)` | Snapshotted OAuth client name at call time (1.2.0+); NULL for non-MCP calls / unless Premium populates |

**Indexes:** `ability_created (ability_name, created_at)`, `ability_status (ability_name, status, created_at)`, `status_stage (status, failure_stage, created_at)`

**Retention:** Free hard-codes 2 records per `(ability_name, status)` partition, pruned on insert, only when `albert/logging/enabled` is true (i.e. Free is the writer). Premium uses time-based retention (default 90 days) via `Cron/LogCleanup`.

**Components:**
- `Installer.php`: Creates/upgrades `{$wpdb->prefix}albert_ability_log` table (dbDelta, idempotent)
- `Repository.php`: CRUD operations, bulk fetch, auto-prune; `insert()` accepts optional `$context` array for the rich columns.
  Unknown context keys are ignored, deliberately: that is what lets one add-on build write good rows against both an older
  and a newer Free. `ip_address`, `referrer` and `request_id` were dropped in 1.4.0 with no deprecation wrapper for exactly
  that reason — an add-on still sending them degrades silently instead of erroring
- `Outcome.php`: classifies a result into `success` / `warning` / `error`; owns the `albert/logging/outcome` filter
- `Logger.php`: Hooks `albert/abilities/after_execute`; gated by `albert/logging/enabled` filter; fires `albert/logging/ability_failed` before the gate, and only for a classified `error`
- `ObservabilityHandler.php`: MCP-level error recorder; gated by same filter
- `ExecutionLogMarker.php`: request-scoped dedup marker; set by the loggers when they write a row, checked by the observers so a single call never logs twice

**Failure capture (1.2.0+):** Failures that happen *before* the ability runs are now logged too.
Input rejected by the WordPress Abilities API (`WP_Ability::execute()` validates `input_schema`
*before* the registered callback, so `guarded_execute`/`after_execute` never fire) is caught by
`MCP/ToolCallObserver` on the adapter's `mcp_adapter_tool_call_result` filter. It (1) rewrites the
verbose `ability_invalid_input` error into an actionable message for the LLM (e.g. *"Missing
required parameter: `title`."*) and never returns a blank/"unknown error"; and (2) fires
`albert/abilities/after_execute` for the failure so it logs through the normal path (status `error`,
`error_code`, `error_message`, `input`, connection identity). The `ExecutionLogMarker` keeps an
ability that *did* execute from being logged twice. `ObservabilityHandler` (Free + Premium) now also
captures the error message (from the adapter's `failure_reason` tag) and is dedup-guarded by the same
marker, covering permission/transport/unknown failures the adapter surfaces via `record_event`.

**Connection identity (1.2.0+):** `OAuth/Server/ConnectionContext` is a request-scoped holder set by `TokenValidator::validate_request()` when a Bearer token is validated. It records the OAuth `client_id` (and lazily resolves a snapshot `client_name`) so Premium's logger can attribute each row to the connection that made the call. Public accessors `ConnectionContext::client_id()` / `client_name()` are how add-ons read it; true end-user IPs are not obtainable for MCP calls (requests originate from the assistant's servers), so the OAuth connection is the meaningful "who" signal.

**Payload capture (Premium, 1.2.0+):** Premium captures `input` and `output` (success result only). Both are byte-capped by default (`Logger::DEFAULT_PAYLOAD_LIMIT`, 65535) with a `…[truncated, N more characters]` marker; truncated payloads render raw. Filters: `albert/premium/logging/full_capture` (bool, default false: store uncapped; the Activity Log page shows a warning when active) and `albert/premium/logging/payload_limit` (int bytes).

**Admin surfaces:**
- Dashboard widget: "Recent Activity" showing most recent executions with status pills
- Abilities page: "Last run" line in each ability's expanded details with status pill + upsell when Premium is not active

### Current Abilities

| ID | Description | Group |
|----|-------------|-------|
| `albert/find-posts` | Find posts with filters | posts |
| `albert/view-post` | View a single post | posts |
| `albert/create-post` | Create a new post | posts |
| `albert/update-post` | Update existing post | posts |
| `albert/delete-post` | Delete a post | posts |
| `albert/edit-post-block` | Replace one block in a post by path | posts |
| `albert/add-post-block` | Insert one block into a post (before/after/inside) | posts |
| `albert/remove-post-block` | Delete one block from a post by path | posts |
| `albert/move-post-block` | Reorder one block within a post by path | posts |
| `albert/find-pages` | Find pages | pages |
| `albert/view-page` | View a single page | pages |
| `albert/create-page` | Create a page | pages |
| `albert/update-page` | Update a page | pages |
| `albert/delete-page` | Delete a page | pages |
| `albert/edit-page-block` | Replace one block in a page by path | pages |
| `albert/add-page-block` | Insert one block into a page (before/after/inside) | pages |
| `albert/remove-page-block` | Delete one block from a page by path | pages |
| `albert/move-page-block` | Reorder one block within a page by path | pages |
| `albert/find-users` | Find users | users |
| `albert/view-user` | View a single user | users |
| `albert/create-user` | Create a user | users |
| `albert/update-user` | Update a user | users |
| `albert/delete-user` | Delete a user | users |
| `albert/find-media` | Find media items | media |
| `albert/view-media` | View a single media item | media |
| `albert/upload-media` | Sideload media from URL | media |
| `albert/create-upload-link` | Mint a short-lived, single-use link that accepts an uploaded file | media |
| `albert/set-featured-image` | Set post featured image | media |
| `albert/find-taxonomies` | Find taxonomies | taxonomies |
| `albert/find-terms` | Find taxonomy terms | terms |
| `albert/view-term` | View a single term | terms |
| `albert/create-term` | Create a term | terms |
| `albert/update-term` | Update a term | terms |
| `albert/delete-term` | Delete a term | terms |
| `albert/woo-find-products` | Search/list WooCommerce products | products |
| `albert/woo-view-product` | View a single product | products |
| `albert/woo-find-orders` | Search/list WooCommerce orders | orders |
| `albert/woo-view-order` | View a single order | orders |
| `albert/woo-find-customers` | Search/list WooCommerce customers | customers |
| `albert/woo-view-customer` | View a single customer | customers |
| `albert/get-skill` | Read one task guide's full text by slug | skills |
| `albert/list-block-types` | List block types the current user may use | blocks |
| `albert/get-block-type` | View a single block type's attributes/supports | blocks |
| `albert/find-patterns` | Find block patterns (registered + user) | patterns |
| `albert/view-pattern` | View a single pattern's block markup | patterns |
| `albert/create-pattern` | Save composed blocks as a user pattern | patterns |
| `albert/update-pattern` | Edit a user pattern (registered are read-only) | patterns |
| `albert/delete-pattern` | Delete a user pattern (registered are read-only) | patterns |

## Development Commands

```bash
# Install dependencies
composer install

# Check coding standards
composer phpcs

# Auto-fix coding standards
composer phpcbf

# Run tests
composer test

# Activate plugin
wp plugin activate albert
```

## Bundled MCP adapter (shared, not scoped)

`wordpress/mcp-adapter` (and its dependency `wordpress/php-mcp-schema`) provide the
`WP\MCP\*` namespace. Other plugins bundle the same package — WooCommerce does, and there is
a standalone MCP Adapter plugin — so more than one copy on a site is normal and expected.

**Albert does not scope it, and must not.** The adapter is loaded unscoped and shared, via
Jetpack Autoloader. That is upstream's supported model and the only one that works.

### Why scoping was removed

Albert used to scope both packages with Mozart into `Albert\Vendor\WP\MCP\`. That looks
like the safe choice and is the opposite. Mozart rewrites class names; it does not rewrite
WordPress, and the adapter coordinates through three things Mozart cannot touch:

- the hook name `mcp_adapter_init`, a literal string,
- the default server id `mcp-adapter-default-server`, hard-coded,
- the REST route of the same name.

So a scoped copy is a *second class firing the same global action*. Both copies'
`DefaultServerFactory::create` stay hooked — different class names, so WordPress keeps both —
and each writes to its own singleton with the same id. The second write returns
`duplicate_server_id`, no server registers, and `/albert/v1/mcp` answers `401 rest_forbidden`,
which is also what a healthy install answers when handed no token. Nothing outside the site
distinguishes them, so the symptom sends people to debug authentication.

[WordPress/mcp-adapter#172](https://github.com/WordPress/mcp-adapter/issues/172) describes this
and was closed `NOT_PLANNED`: prefixed copies are unsupported, and guarding `DefaultServerFactory`
alone "would not make the other shared hooks, filters, routes, and identifiers safe."

### How it works now

- Both packages are ordinary **`require`** entries, and **`automattic/jetpack-autoloader` is a
  root requirement** — it generates nothing when pulled in only transitively, which is the whole
  reason it has to be declared here.
- `config.allow-plugins` sets `automattic/jetpack-autoloader` to **`true`**. It was `false` under
  Mozart; if it ever goes back to `false`, the manifests stop being generated and the plugin
  silently loses its adapter.
- `albert-ai-butler.php` requires **`vendor/autoload_packages.php`**, Jetpack's entry point, not
  Composer's `vendor/autoload.php`. It calls WordPress functions, which is safe in a plugin file
  but *not* before WordPress loads — which is why the test bootstraps still use
  `vendor/autoload.php`.
- Jetpack publishes each plugin's copy with its version in
  `vendor/composer/jetpack_autoload_classmap.php` and loads a single newest copy site-wide. One
  class, one singleton, one `mcp_adapter_init` firing.
- Albert's code references bare **`WP\MCP\…`**. Never reintroduce `Albert\Vendor\`.

### Consequences worth knowing

- **Albert may run against another plugin's newer copy.** That is the accepted trade-off of the
  shared model, and the reason `Albert\MCP\AdapterStatus` feature-detects the classes Albert
  calls rather than checking a version number. If any are missing,
  `Albert\MCP\AdapterHealth` reports it in an admin notice and in Site Health, naming the path
  the loaded copy came from.
- **`vendor/wordpress/` ships.** `.distignore` must not exclude it — that exclusion existed only
  because scoping made the unscoped copy dangerous. Shipping it is what lets Albert take part in
  the negotiation.
- **Bumping the adapter:** `composer update wordpress/mcp-adapter`. Nothing to regenerate; the
  lock and the Jetpack manifests are rebuilt by Composer's own hook.
- **Verify a shared install:** exactly one adapter class, one firing, both servers present.

```bash
wp eval '
$loaded = array_filter( get_declared_classes(), fn( $c ) => str_ends_with( $c, "\\MCP\\Core\\McpAdapter" ) );
echo implode( ", ", $loaded ), "\n";'
```

## Development Guidelines

### Code Standards
- Follow WordPress Coding Standards (enforced by PHPCS)
- Use PHP 7.4+ type declarations
- Implement `Hookable` interface for components with hooks

### JavaScript
- **Never use jQuery** - use vanilla ES6+ JavaScript
- Use module pattern for organization
- Use `fetch` API for HTTP requests

### Security
- Validate and sanitize all input
- Use capability checks (`current_user_can()`)
- Use nonces for form submissions
- OAuth tokens for API authentication

### Version Control
- **Never commit without explicit request**
- **Never bump version without approval**
- **Version bumps only happen in release branches**, never on `development`, feature branches, or `main`
- Run `composer phpcs` before committing

## Known Compatibility Issues

### WooCommerce mcp-adapter timing bug (admin pages)

**Affected versions:** WooCommerce 10.4+ (ships `wordpress/mcp-adapter`)

**Symptom:** `_doing_it_wrong` notices for `mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, and `mcp-adapter/execute-ability` on Albert admin pages when WooCommerce is active.

**Root cause:** The mcp-adapter's `DefaultServerFactory` hooks `register_default_abilities()` on `wp_abilities_api_init`, a one-shot action. On Albert admin pages, `wp_get_abilities()` fires that action during page render (before `rest_api_init`). WooCommerce then preloads REST data via `Settings::add_component_settings()` → `rest_preload_api_request()`, which triggers `rest_api_init` → `McpAdapter::init()` → `mcp_adapter_init` → `DefaultServerFactory::create()`. The factory calls `wp_get_ability()` for its three tools, but they were never registered because `wp_abilities_api_init` already fired. The upstream fix would be for `maybe_create_default_server()` to check `did_action('wp_abilities_api_init')` and call `register_default_abilities()` directly if the action already fired.

**Our fix:** `Plugin::init()` only calls `McpAdapter::instance()` when `! is_admin()`. REST API requests (`/wp-json/...`) have `is_admin() === false`, so the adapter initializes normally. Admin pages skip initialization entirely, avoiding the timing conflict. The adapter is not needed on admin pages: Albert only needs it for serving MCP REST endpoints.

**If this breaks in the future:** The fix relies on `is_admin()` being `false` for REST API requests. If WordPress changes this behavior, `McpAdapter::instance()` may need to be deferred differently. Check `wp-includes/load.php` for `is_admin()` definition.

## Ongoing Work
