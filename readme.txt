=== Albert - Connect AI Assistants to WordPress ===
Contributors: albertai, mark-jansen
Tags: ai assistant, chatgpt, claude, ai, mcp
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.4.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ChatGPT and Claude, connected in three steps. Your AI assistant knows your site, writes real blocks, and only does what you allow

== Description ==

## Let your AI assistant actually run your WordPress site

You already ask ChatGPT or Claude to plan posts, rewrite copy and answer questions about your business. Then you paste the result into WordPress by hand, and fix the formatting.

Albert removes that step. Connect your AI assistant to your site once, and it writes, edits and files your content directly, in real WordPress blocks, inside limits you set. No copy and paste, no plugin-specific chat window, no code.

<!-- TODO: add social proof once you have it. Active installs, star rating, or a named site. One line near the top converts better than anything else on this page. -->

---

### Connect in three steps

Copy your site's connection URL, paste it into your assistant, approve the request. That is the whole setup, and it is the same for every assistant.

Albert walks you through it per assistant, with the exact steps for the one you use, so there is nothing to look up.

---

### Albert speaks block editor

Most AI plugins hand WordPress a wall of HTML and leave you to clean it up. Albert does not.

* **Real blocks, not raw HTML.** Headings, lists, quotes, images, buttons, columns and groups come back as proper blocks you can edit like anything else.
* **One block at a time.** Change, add, move or remove a single paragraph without your assistant rewriting the whole page.
* **No "this block contains unexpected content".** Albert checks blocks as they are written and corrects the assistant before the content reaches your post.
* **Classic editor too.** On a classic site, content is read and saved as HTML instead. Both work.

---

### Your assistant knows your site

An assistant that does not know your site invents things: brand colours you never chose, a tone that is not yours, categories that do not exist.

* **Write your instructions once.** Your tone, your vocabulary, anything it must never touch. Albert sends them with every conversation.
* **Your site describes itself.** Language, timezone, theme colours and fonts, post types, taxonomies and shop settings go along too, but only when your theme genuinely declares them.
* **See exactly what is sent**, and switch off any part an assistant does not need.
* **Your text is information, never orders.** Content from your site can shape subject matter and tone. It can never change what an assistant is allowed to do.

---

### You decide what it may do

Not every guest needs a key to every room.

* **Every action listed and labelled** Read, Write or Delete, with its own switch. Changes save instantly.
* **Write and delete start switched off.** You turn on exactly what you want, and nothing else.
* **Your WordPress roles still apply.** An assistant can never do more than the person who approved it.
* **New actions arrive switched off**, so a plugin update never quietly widens what an assistant can reach.
* **Revoke any connection at any time.**

---

### Nothing destructive happens without you

Switched on by default. When your assistant asks to delete a post, change someone's role, or rewrite a foundational setting, the action does not run. It waits for you.

* **Held, not logged after the fact.** The request is stopped before it happens, not reported once it has.
* **You see what it would actually change**, resolved as it is now: "Delete the post 'Pricing' (published)", not a line of code. If the page changed since the request, you are told before you approve.
* **Approve what you could do yourself.** Editors approve their own requests; administrators approve anyone's. Nobody gains reach they did not already have.
* **The assistant cannot approve its own work**, cannot switch safe mode off, and cannot touch the settings that control it.
* **Requests expire** if nobody decides, so an old intent cannot be approved against today's site.

Needs WordPress 7.1. On older versions Albert tells you plainly that it cannot hold anything, rather than letting the switch imply otherwise.

---

### Personal data stays private

Albert hides your visitors' and customers' details, names, email addresses, phone numbers and postal addresses, before anything reaches an assistant.

Choose **Strict**, **Balanced** or **Off**. New sites start on Strict. Reveal the real details only when you ask, and only if your own account is allowed to see them.

---

### See everything that happened

* **Every call is recorded**: which action, which assistant, which user, how long it took.
* **Blocked is not Failed.** A request your own rules refused reads differently from one that broke.
* **Needs your attention** surfaces standing problems on the dashboard, and stays quiet when there are none.

---

### Works with the assistant you already use

Claude Desktop, claude.ai, Claude Code, ChatGPT, Cursor, VS Code, or anything else that speaks the Model Context Protocol. Albert turns your site into an MCP server, so the same connection URL works everywhere and you are never locked to one vendor.

Connections use OAuth 2.0 with short-lived, self-refreshing tokens. No password is ever shared with an AI company.

---

### What your assistant can do

* **Posts and pages**: find, view, create, update and delete, block by block.
* **Media**: browse the library, set featured images, and receive files your assistant sends you directly.
* **Users and taxonomies**: find and manage users, categories, tags and custom terms.
* **WooCommerce**: look up products, orders and customers when WooCommerce is active.
* **Anything you build**: register your own actions with the WordPress Abilities API.

---

### Go further with Albert Premium

[Albert Premium](https://albertwp.com) adds per-role and per-user permission rules, your own custom post types, and a full activity log with filters and retention.

Read the setup guide at [albertwp.com/docs](https://albertwp.com/docs/).

Every well-run site deserves a proper butler. Albert checks credentials at the door, and shows the guest only the rooms you opened.

== Installation ==

= Automatic installation =

1. Go to **Plugins > Add New** in your WordPress dashboard.
2. Search for **Albert**.
3. Click **Install Now**, then **Activate**.
4. Go to **Albert > Connections** and add yourself as an allowed user.
5. Copy the connection URL and paste it into your AI assistant.
6. Approve the request when prompted. You are connected.

= Manual installation =

1. Download the plugin ZIP from WordPress.org.
2. Go to **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP and click **Install Now**, then **Activate**.
4. Follow steps 4 to 6 above.

= After activating =

Write and delete actions start switched off. Go to **Albert > Abilities** and switch on what you want your assistant to be able to do. Everything else works out of the box.

== Frequently Asked Questions ==

= Which AI assistants can I connect? =

Claude Desktop, claude.ai, Claude Code, ChatGPT, Cursor, VS Code, and any other assistant that supports the Model Context Protocol. The connection steps are the same for all of them, and Albert shows the exact steps for the one you pick.

= Do I need to know anything technical? =

No. Copy a URL, paste it into your assistant, approve the request. There are no config files, no API keys to generate and no code.

= Can my AI assistant break my site? =

It can only do what you switched on. Write and delete actions start off, new actions added by an update also start off, and every action still obeys your WordPress roles, so an assistant can never do more than the person who approved it. You can revoke a connection at any time, and the activity log shows everything that was done.

= Will the content look right in the block editor? =

Yes. Albert writes real WordPress blocks, headings, lists, images, columns and the rest, not a wall of HTML pasted into one paragraph. It validates blocks as they are written, so you do not get the "this block contains unexpected content" warning. Classic editor sites are supported too.

= Does my AI assistant see my visitors' and customers' personal data? =

Not by default. Albert hides names, email addresses, phone numbers and postal addresses before anything is sent. Choose Strict, Balanced or Off, and reveal the real details only when you ask.

= How does Albert know anything about my site? =

You write instructions once, and Albert sends them with every conversation along with your language, timezone, theme colours and fonts, content types and shop settings. You can preview exactly what is sent and switch off any part of it.

= Is my AI assistant connection secure? =

Yes. Connections use OAuth 2.0, the same standard as Google and GitHub. Your assistant gets a short-lived token that refreshes itself, and no password is ever shared. Revoke any connection from the Connections screen.

= Does it cost anything? =

Albert is free. [Albert Premium](https://albertwp.com) is optional and adds per-role and per-user permission rules, custom post type support, and a full activity log with filters and retention.

= Do I need WooCommerce? =

No. Albert works with WordPress core out of the box. WooCommerce actions appear automatically when WooCommerce is active.

= Can I add my own actions? =

Yes. Register them with the WordPress Abilities API and they appear on the Abilities screen alongside Albert's own. See [albertwp.com/docs](https://albertwp.com/docs/).

= Does this work with multisite? =

Albert is built for single-site installations. Multisite support is on the roadmap.

= What are the requirements? =

WordPress 6.9 or higher, PHP 8.1 or higher (8.3+ recommended), MySQL 8.0+ or MariaDB 10.5+, and HTTPS, which OAuth 2.0 requires.

= Where do I get support? =

Documentation at [albertwp.com/docs](https://albertwp.com/docs/), or the [WordPress.org support forums](https://wordpress.org/support/plugin/albert/).

== Screenshots ==

1. The Albert dashboard: setup status, how many abilities are switched on, recent AI assistant activity, and anything that needs a decision from you
2. The Connections screen: your MCP endpoint, who is connected, who may connect, and step-by-step setup for Claude, ChatGPT, Cursor and the rest
3. The Abilities screen: every action an AI assistant can take, labelled Read, Write or Delete, each with its own instant-save switch
4. The Context screen: write instructions for your assistant and see exactly what Albert tells it about your site, section by section
5. The Skills screen: the task guides Albert ships, so you can read the exact guidance a connected assistant follows

== Changelog ==

= 1.5.0 =
Adds safe mode, which holds destructive actions for your approval, and closes a way an AI assistant could reach your site's tools without going through Albert's approved sign-in.

**Features**

* **Safe mode: nothing destructive happens without you.** Switched on by default. When your assistant asks to delete a post, change someone's role, or rewrite a foundational setting, the action is held and waits for your approval in wp-admin. You see what it would actually change, resolved as it is now rather than as a line of code, and you are warned if the page has been edited since the request was made. Editors approve their own requests, administrators approve anyone's, and nobody can approve something they could not have done themselves. The assistant cannot approve its own work and cannot switch safe mode off. Requests expire if nobody decides, so a stale intent is never applied to today's site. Needs WordPress 7.1; on older versions Albert says plainly that it cannot hold anything instead of letting the switch imply otherwise.
* Your assistant can now browse the block patterns your theme and plugins provide, and your own saved patterns, and reuse one as the starting point for a new page instead of building the layout from scratch. It can also save a layout as a reusable pattern of its own.

**Security**

* Albert now closes an unused extra way in. An AI assistant that held a WordPress application password could previously reach your site's tools without going through Albert's sign-in, the list of people you allow to connect, or the approval screen — and it left no record under Connections. That entry point is switched off, so assistants must connect the approved way.
* Your assistant can no longer pick or change a WordPress password. Creating a user previously meant the assistant invented a password and sent it to your site, which also left it sitting in the AI provider's record of the conversation. Now your site generates the password itself and hands back a one-time link for the new person to set their own. Changing an existing user's password is refused outright: that account's owner can reset it from the login screen, and nobody else needs to.

**Developer**

* Safe mode holds destructive assistant-initiated calls at WordPress 7.1's `wp_pre_execute_ability` short-circuit, so a held call never reaches normalisation, the permission check or the execute callback. Only requests carrying an Albert OAuth connection are gated: WP-CLI, direct PHP and other plugins are untouched. Two axes decide what is held, and either is enough: `annotations.destructive !== false` (an unannotated ability is held, never waved through) and `SafeMode\RiskPolicy`, which holds any call whose input grants an administrator-capability role or writes a high-risk option, including from abilities Albert has never heard of. Staged calls live in the new `albert_pending_actions` table with the input captured server-side, so approval replays what the server holds rather than anything the model can resupply. `Execution\InterceptorDecision` resolves the MCP double-fire once for any interceptor on that filter. New settings `albert_safe_mode` (on/off, accepts booleans from a constant) and `albert_safe_mode_ttl_minutes` (default 60, clamped 5 minutes to a week), both resolved through `Settings\Value`. A held call is logged as a `warning`, not an `error`: `Outcome::HELD_CODE` classifies it on its own branch, so the gate doing its job neither reddens the Dashboard nor fires `albert/logging/ability_failed`. `SafeMode\AuditTrail` records approvals, rejections, expiries, abandoned runs and refused writes to Albert's own control options, each as both an action and an activity-log row. `Cron\PendingActionSweep` expires lapsed rows, fails claims stale for an hour, and deletes decided rows after 30 days. New hooks: `albert/safe_mode/high_risk_abilities`, `high_risk_options`, `protected_options`, `exempt_abilities`, `retention_days`, `display_input`, `held`, `approved`, `rejected`, `expired`, `abandoned`, `option_write_blocked`, `option_delete_detected`, and `albert/approvals/view_capability`.
* Albert now neutralises the MCP adapter's built-in default server (`mcp-adapter-default-server`), which exposed every public ability through the adapter's default transport permission (`current_user_can( 'read' )`), bypassing Albert's OAuth flow, the allowed-users list and the consent screen, and left no Connections row. The server is left created — the `mcp_adapter_create_default_server` off switch would also unregister the `mcp-adapter/*` meta-tool abilities Albert's own server depends on — but its tools, resources and prompts are emptied through the `mcp_adapter_default_server_config` filter, so it can execute nothing. Applied whenever Albert's MCP integration is active, not gated on which shared copy of the adapter is loaded, since the abilities are global. New `albert/mcp/disable_default_server` filter (default true) opts out.
* `BaseAbility::check_rest_permission()` no longer carries a dead regex branch for pattern routes — a `preg_match()` against the REST route table's keys that could never match. The behaviour it fell through to is now explicit: a single-object route such as `/wp/v2/posts/(?P<id>[\d]+)` is gated by the ability's declared capability at this pre-execution stage, because its endpoint permission callback needs a target id that is not known yet; the exact per-object check still runs at execution via `rest_do_request()`. Plain collection routes still delegate to their own permission callback. No behaviour change.
* `albert/create-user` no longer accepts a `password` input. It is gone from the schema, so a schema-validating client cannot send one; the password is generated with `wp_generate_password()` at execution time and never disclosed. The result carries a one-time `password_reset_url`, declared in `sensitive_output_keys` so every `after_execute` observer sees `[redacted]` while the caller gets the real link. No notification email is sent, deliberately: `wp_new_user_notification()` mints a reset key of its own, and whichever key is issued second invalidates the first, so emailing would either kill the returned link or return a dead one. `albert/update-user` likewise drops `password` from its schema and refuses a supplied one at execution with `password_change_refused`. The Abilities API does not forbid unrecognised keys, and refusing at execution means the attempt is logged rather than swallowed as a validation rejection, which `ToolCallObserver` deliberately does not record. Both abilities gained `instructions` annotations so a model is told at call time rather than learning by rejection.
* New read-only pattern abilities `albert/find-patterns` and `albert/view-pattern`. They expose theme- and plugin-registered patterns (`WP_Block_Patterns_Registry`) and user `wp_block` patterns: find returns summaries filterable by search term and category, view returns one pattern's block markup by name. Backed by `Albert\Blocks\PatternCatalog`; each pattern carries a `source` of `registered` or `user`, plus a `syncStatus` and, for user patterns, an `id`, so a synced pattern can be reused by reference (`core/block`) rather than copied. `albert/create-pattern` composes blocks through the serializer and stores them as a wp_block pattern, synced or unsynced. `albert/update-pattern` and `albert/delete-pattern` edit and remove user patterns (registered patterns are read-only).

= 1.4.1 =
Fixes AI assistants failing to connect, including on managed hosts (such as SiteGround and Servebolt) that handle the sign-in discovery address themselves.

**Fixes**

* AI assistants using the newest sign-in method could fail to connect. They connect again now.
* AI assistants could not sign in on some managed hosts (such as SiteGround and Servebolt) that answer part of the sign-in discovery themselves. Albert now serves it where those hosts pass it through, so sign-in works with no manual setup.

**Developer**

* Discovery is now RFC 9728 / RFC 8414 conformant: `resource_metadata` in `WWW-Authenticate`, one Protected Resource Metadata source listing the issuer, and the issuer served at a mid-path `.well-known` URL that hosts intercepting a root `/.well-known/` leave alone. Adds the `albert_oauth_discovery` Site Health test.
* The issuer is now a path, so a connected client that re-runs discovery re-authorises once; live connections keep working.

= 1.4.0 =

Release date: 2026-09-03

Albert 1.4.0 tells connected assistants what your site actually is, so they stop guessing. Assistants can also send you files directly, and every admin screen has been rebuilt on one design system. Day-one support for WordPress 7.1.

#### Features

* New **Albert &rarr; Context** screen. Write instructions for connected assistants, and send your site's language, timezone, theme colours and fonts, content types and shop settings with every conversation.
* Preview exactly what is sent, and switch off any section an assistant does not need.
* Text from your site (your instructions, post content, product descriptions) is now marked as information rather than orders, so it can never change what an assistant is allowed to do.
* Assistants can send you a file directly. Where Albert could previously only fetch media from a web address, an assistant that already has the file now requests a single-use upload link and posts the bytes straight to your media library.
* New size limit for those uploads under **Albert &rarr; Settings &rarr; Uploads**.
* New **Albert &rarr; Skills** screen, listing the task guides Albert and its add-ons ship. Guides tell an assistant how to handle a job on your site, such as writing blocks correctly. Open one to read the exact guidance an assistant follows.
* Assistants now read those guides themselves. Each is offered by name at the start of a conversation, and only when it applies to your site.
* New Site Health check that reports when the MCP endpoint is not registered, instead of failing with a 401 that looks like an authentication problem.

#### Enhancements

* Every Albert screen has been rebuilt: Dashboard, Settings, Connections, Abilities and the two new screens now share one design system, one set of controls and one visual language.
* Albert picks up the admin colour scheme from your WordPress profile instead of always being blue.
* A row of links across the top of every Albert screen replaces trips back to the sidebar.
* The Connections screen now shows the endpoint, who is connected, who may connect, and setup steps per assistant. You can name or rename a connection inline.
* Choosing who may connect is a searchable picker rather than a dropdown listing every user, so it stays fast on large sites.
* You can name a connection at the moment you approve it.
* Invitations nobody accepts now expire, connections approved but never used are dropped, and idle connections can be expired on a schedule. All configurable, all logged.
* A call Albert refused now reads as **Blocked** rather than Failed. A tool that ran and truthfully answered "there is no such post" counts as a success.
* An assistant that uses a wrong parameter name is now told which name it used and which names the tool takes. Previously the wrong name was silently discarded and the assistant could get a successful answer that ignored half its request.
* An expired session now tells the client its token expired, instead of a bare refusal indistinguishable from never having connected.
* A failed connection records which of six reasons it actually was, rather than one generic message for all of them.
* Many tools carry a short note about the mistake that is easiest to make with them. Assistants read these only when they use the tool.
* New installs start on Strict privacy. Existing sites keep their setting.
* Settings are now sanitised on the way into the database, so a value written by code or WP-CLI is checked exactly like one typed into the form.
* A setting fixed in code shows as read-only and names what owns it, rather than appearing editable and being silently overwritten.
* The Abilities and Skills screens switch to a stacked list on narrower screens, so nothing is hidden behind a sideways scrollbar on a laptop or tablet. Pick a layout yourself and it stays picked.
* Accessibility pass across the admin: contrast on faint borders and switches, keyboard reachability, focus after dismissing an item, touch target sizes and screen reader announcements.
* On WordPress 7.1, server-only details are stripped from tool descriptions before they reach an assistant. This covers tools added by other plugins too.

#### Bugfixes

* Fixes the block editor guide never reaching your site. It has been in the source since 1.2.0 but was left out of every release package, so assistants have been writing blocks without the guidance the 1.2.0 release described. The Skills screen is what made it visible, by counting the guides out loud.
* Fixes the Abilities table scrolling sideways on smaller screens. Below roughly 1450px the columns overflowed, and the on/off switch sits at the right-hand end, so the one control the screen exists for was the first thing to disappear.
* Fixes assistants being unable to read product categories, or any category or tag set without an explicit REST base. Albert asked the wrong question and reported them as unavailable.
* Fixes `albert/find-taxonomies` and `albert/find-terms` failing every call on WordPress 7.1, which made taxonomy discovery impossible.
* Fixes Albert's MCP endpoint failing to register when another plugin bundles its own copy of the MCP library. Albert now shares one copy, so both endpoints work.
* Fixes data migrations being skipped after a deactivate, update, reactivate cycle, which left the update permanently marked as done without having run.
* Fixes the dashboard reporting that all abilities were enabled, because the total and the enabled figure were the same number counted twice.
* Fixes privacy mode not saving, because the screen and the save routine disagreed about the option name.
* Fixes a privacy mode set in code showing as editable.
* Fixes the nightly cleanup hiding connections that still worked. A connection idle overnight disappeared from the admin while continuing to call the site, so it could not be revoked.
* Fixes connections being counted elsewhere by a looser rule with no expiry check.
* Fixes declared minimums and maximums not being enforced outside the browser, and negative numbers losing their sign instead of being clamped.
* Fixes the activity list fade permanently covering its last row.
* Fixes "ability not found" notices on the dashboard.
* Fixes the `.well-known` discovery document not advertising the authentication method Albert issues desktop clients with.
* Fixes a rejected endpoint override filter falling back silently. The endpoint card now says where the address comes from.

#### Security

* Revoking a single session now revokes that session's refresh token. It previously ended only the access token, so the assistant reconnected on its own within the hour while the screen said the session had been revoked. Revoking all sessions was never affected.
* `albert/upload-media` now checks file types against the current user's own allowed types instead of the site-wide default. An `unfiltered_upload` capability can no longer widen what it accepts.
* Failed database writes when issuing OAuth authorisation codes, access tokens and refresh tokens are no longer ignored. All three now fail immediately instead of surfacing later as an unrelated error.
* `WWW-Authenticate` is now sent on every 401 from the MCP endpoint. Previously an expired token skipped the header entirely.

#### Developer

* New `Albert\Context` module, with filters `albert/context/enabled`, `albert/context/instructions`, `albert/context/sections`, `albert/context/site` and `albert/context/skills`.
* New `albert/skills/registry` filter registers a skill as data, with declared preconditions. A skill is offered only when its preconditions hold, and an unrecognised condition fails closed.
* New `albert/get-skill` ability returns a guide's Markdown body by slug, gated on `edit_posts`.
* New `albert/create-upload-link` ability and `POST|PUT /albert/v1/media/uploads` endpoint, backed by the reusable `Core\Tokens\TokenService`. New `albert/media/upload_link_max_bytes` filter.
* Abilities' "Supplier" is renamed to "Source": `AbilitiesRegistry::get_sources()` and `albert/abilities/sources`. The old method, filter and payload keys still work, deprecated rather than removed.
* New `albert/abilities/invoked` action, relayed from WordPress 7.1's `wp_ability_invoked`. Fires for every invocation whatever the outcome, including denied and short-circuited calls. Inert below 7.1.
* New `Albert\Logging\Outcome` classifies every logged outcome as `success`, `warning` or `error`. The ability log gains `failure_stage` and `privacy_mode`, and drops `ip_address`, `referrer` and `request_id`.
* New `albert/logging/api_surface_codes` filter, so an add-on's own not-found codes classify correctly.
* Settings API: add-ons can name their own card, attach a unit with `suffix`, add detail with `info`, and declare `min` and `max` once to drive both the control and the sanitiser. New filters `albert/settings/value/{option}`, `albert/settings/value_source/{option}` and `albert/settings/validator/{option}`. `show_in_rest` now works.
* New dashboard filters: `albert/dashboard/stats`, `albert/dashboard/attention`, `albert/dashboard/suggestions`, `albert/dashboard/recommendations` and `albert/dashboard/show_resources`.
* New OAuth filters `albert/oauth/access_token_ttl`, `albert/oauth/refresh_token_ttl` and `albert/oauth/auth_code_ttl`, plus an `albert/oauth/token_request_failed` action carrying the specific failure reason.
* Every object-typed input schema now registers with `additionalProperties => false`, set once in `BaseAbility::prepare_input_schema()`, so add-ons inherit it.
* New design system. `albert-tokens.css` holds colour, spacing, type, radius and motion in `oklch()`; `albert-primitives.css` holds shared components. Add-ons declare `albert-primitives` as a stylesheet dependency.
* **Breaking for add-ons.** The 56 value-named custom properties in `admin-settings.css` (`--albert-primary`, `--albert-font-lg`) are replaced by role-named ones (`--albert-color-accent`, `--albert-font-size-section-title`) with no alias layer. Albert Premium Service ships the matching migration and must be updated alongside this release.
* The bundled MCP adapter moves to 0.6.1 and now loads unscoped through Jetpack Autoloader instead of being prefixed with Mozart. Albert, WooCommerce and the standalone MCP Adapter plugin share one adapter and each keep their own server. Mozart, `vendor-prefixed/` and the `Albert\Vendor\` namespace are gone.
* WordPress 7.1 support is feature-detected in `Albert\Support\WpCompat`. Tool schemas pass through `wp_prepare_json_schema_for_client()`, `is_mcp_public()` adopts the `meta.mcp.public ?? meta.public` precedence, and `get_all_raw()` reads the registry directly so a third-party filter cannot make Albert lose track of a registered ability.
* Object-typed input schemas no longer claim their default is an array.
* Stylesheets and scripts are versioned on file modification time, so a change during a release cycle is never served stale.
* CI compiles and lints the admin screens on every pull request, and adds WordPress 7.0 and trunk rows.

#### Credits

* [Marinus Klasen](https://github.com/mklasen) for the idea behind direct file uploads.
* [Jonathan de Jong](https://github.com/jonathan-dejong) for reporting the taxonomy failures, the output schema failures and the MCP adapter conflict.

= 1.3.1 =
A security update for how AI assistants connect.

**Security**

* Connecting an assistant now requires the exact web address it will return you to. The old catch-all that accepted any address is gone, and every connection request is matched against that exact address.
* The approval screen now shows where the assistant will send you, and how recently the app was set up, so an unexpected request is easier to spot.
* Apps that connect through their own link (such as some desktop assistants) are checked more strictly and secured without relying on a shared secret.
* Stricter checking of return addresses, a limit on how many a single app can register, and a cap on the total number of connected apps.

**Developer**

* New `albert/oauth/allowed_redirect_schemes` filter to restrict redirect URI schemes to an explicit allowlist.

= 1.3.0 =
Two headline changes: the Abilities screen has been rebuilt from scratch, and personal data is now kept private from AI assistants automatically.

**Features**

* Rebuilt Abilities screen — a fast, modern list with search, filter by category or supplier, sort, and pagination, matching the rest of the WordPress admin.
* Switch abilities on or off instantly, one at a time or in bulk — no Save button.
* A detail panel on every ability showing its inputs, the permission it needs, and when it last ran.
* Privacy mode — personal data (names, email addresses, phone numbers, and postal addresses) is redacted from AI results before it leaves your site. Choose Strict, Balanced, or Off to control how much is hidden.
* Reveal the real personal details only when you explicitly ask, gated by your own WordPress capabilities.

**Improvements**

* The Abilities screen was rebuilt for accessibility throughout — full keyboard and screen-reader support, with clearer focus states and contrast.
* The three core tools an assistant uses to discover and run abilities can no longer be switched off, and repair themselves on load, so a connection never breaks after an update.

**Developer**

* New extension points on the Abilities screen let add-ons plug straight in — this powers Albert Premium's per-role and per-user permission rules.
* New `albert/privacy/*` filters let add-ons protect their own fields — the WooCommerce add-on uses these to strip payment and card data.

= 1.2.0 =
Albert now understands the WordPress block editor — a big step up in how AI assistants read and write your content.

**Features**

* Block editor support — assistants work with real WordPress blocks instead of raw HTML. They read a post as a clean, structured outline and compose new content with proper blocks: headings, paragraphs, lists, quotes, images, buttons, columns, and groups.
* Edit one block at a time — change, add, move, or remove a single block without disturbing the rest of the page. No more rewriting a whole post to fix one paragraph.
* Classic editor support — content on classic-editor sites is read and saved as HTML, so Albert works whichever editor you use.
* Built-in guidance — Albert ships a playbook and reference data that teach connected assistants how your site's blocks work, so they produce better content out of the box.

**Improvements**

* Cleaner content, fewer errors — Albert validates blocks as they're written and steers the assistant to correct mistakes, so you avoid the block editor's "this block contains unexpected content" warnings.
* Assistants only use the blocks your site, and the connected user, are actually allowed to use.
* Long posts are paged automatically, so big content is never cut off mid-way when an assistant reads it.
* Safer updates — abilities added by a plugin update now start switched off. An update will never silently expand what an AI assistant can do on a site you've already set up.

= 1.1.1 =
A bug-fix release.

**Fixes**

* OAuth discovery endpoints (`/.well-known/oauth-protected-resource`, `/.well-known/oauth-authorization-server`) are now reachable when the request arrives with a trailing slash. Some hosts add one at the edge, after which WordPress's canonical redirect would strip it again, producing a redirect loop or a 404. The endpoints now respond identically with or without the slash.

**Credits**

* Reported by [Marinus Klasen](https://profiles.wordpress.org/mklasen/).

= Earlier versions =

Releases before 1.1.1 are listed in `changelog.txt`, bundled with the plugin.

== Upgrade Notice ==

= 1.4.1 =
Fixes AI assistants failing to connect, including on managed hosts (such as SiteGround and Servebolt) that were blocking the sign-in step. Recommended if any assistant could not connect.

= 1.4.0 =
Assistants now know what your site is, can send you files directly, and every admin screen has been rebuilt. Fixes taxonomy reads that were failing outright. Multisite: reconnect your assistants once after updating.

= 1.3.1 =
A security update. Connecting an AI assistant now requires an exact return address, the approval screen shows where you are being sent, and app connections are checked more strictly. Recommended for all sites.

= 1.3.0 =
The Abilities screen has been rebuilt from scratch — search, filter, instant and bulk on/off, and a detail panel for every ability. And personal data (names, emails, phone numbers, addresses) is now hidden from AI assistants automatically, with a setting to control how strict that is.

= 1.2.0 =
Adds full block-editor support (read, write, and edit individual blocks), classic-editor handling, automatic paging for long posts, and safer defaults — newly added abilities now start switched off. Your existing enabled/disabled settings are preserved.

= 1.1.1 =
Fixes OAuth discovery endpoints when the request URL has a trailing slash. Recommended for sites where the host or CDN adds a trailing slash to .well-known URLs.

= 1.1.0 =
Redesigned abilities page, new activity logging, and several reliability fixes. Existing enabled / disabled settings are preserved; no migration needed.

= 1.0.1 =
Fixes a connection failure caused by mismatched OAuth endpoint namespaces. Recommended for all users.

= 1.0.0 =
Initial release. Connect Claude Desktop, ChatGPT, and other MCP-compatible AI assistants to your WordPress site.

== Privacy Policy ==

Albert does not collect, store, or transmit any user data to external servers. All authentication tokens are stored locally in your WordPress database. When you authorize an AI assistant, that assistant will have access to perform actions on your WordPress site according to the permissions you grant. You control which abilities are enabled and can revoke any session at any time.

== Credits ==

Developed by Mark Jansen - Your Mark Media
Website: https://yourmark.nl
Plugin URL: https://wordpress.org/plugins/albert/

Built with:
* league/oauth2-server for OAuth 2.0 implementation
* Model Context Protocol (MCP) for AI assistant connectivity
* WordPress Coding Standards
