=== Albert - Connect AI Assistants to WordPress ===
Contributors: albertai, mark-jansen
Tags: ai assistant, chatgpt, claude, ai, mcp
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.5.0
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

= What is in the Claude Desktop install file? =

Claude Desktop cannot reach a site that only runs on your computer through its usual connectors, so for a local site Albert offers a small install file instead. It holds your site's address and a copy of [mcp-remote](https://github.com/geelen/mcp-remote) (MIT licence), which Claude Desktop runs on its own built-in Node.js. There is no password or key inside: Claude still asks you to approve the connection in your browser. The bridge is built from `tools/claude-desktop/` in [Albert's source](https://github.com/YourMark/albert-ai-butler).

= Does it cost anything? =

Albert is free. [Albert Premium](https://albertwp.com) is optional and adds per-role and per-user permission rules, custom post type support, and a full activity log with filters and retention.

= Do I need WooCommerce? =

No. Albert works with WordPress core out of the box. WooCommerce actions appear automatically when WooCommerce is active.

= Can I add my own actions? =

Yes. Register them with the WordPress Abilities API and they appear on the Abilities screen alongside Albert's own. See [albertwp.com/docs](https://albertwp.com/docs/).

= Does this work with multisite? =

Albert is built for single-site installations. Multisite support is on the roadmap.

= What are the requirements? =

WordPress 6.9 or higher, PHP 8.1 or higher (8.3+ recommended), MySQL 8.0+ or MariaDB 10.5+, HTTPS (OAuth 2.0 requires it), and any permalink setting other than Plain.

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
* **Connect an assistant, step by step.** One button on the Dashboard and on Connections opens a short guide: where your site runs, which app you use, then the exact steps for that app. It tells you the moment the connection really works, because Albert sees it arrive, and then suggests a few first things to ask. If nothing arrives, it lists the usual reasons.
* **Everyone you allow can connect, whatever their role.** People on your list of who may connect an assistant now get a Connect page under Albert, with the same step-by-step guide and their own connected assistants, which they can disconnect themselves. They see nothing else: no logs, no settings, nobody else's connections. Next to each person on the list, Send setup link emails them a link that opens the guide straight away, or copies it for you to send yourself. When you add people you can tick Email them how to connect.

**Improvements**

* When someone who isn't on the list of people who may connect an assistant tries to connect one, the page now says who can fix it and how. An administrator who isn't on the list gets a button that opens Connections, where they can add themselves.
* After you approve an assistant, the page now says so and takes you back to your app, with a link in case that doesn't happen.
* The MCP Adapter, the part Albert uses to talk to AI assistants, is now its own plugin on WordPress.org. Albert uses that plugin when it is active, and asks you to install it with one click on its screens, on the Plugins screen and in Site Health. Albert still includes its own copy for now, but a coming update will rely on the plugin. Where plugin installs are switched off on your site, or you cannot install plugins yourself, Albert tells you who needs to act.
* The setup steps for each assistant are rewritten as short numbered steps, with the one button or command each step needs right inside it. Claude gets an Add to Claude button that opens Claude with your site already filled in, and there are new steps for Codex, Windsurf, Gemini CLI and Cline, plus a bridge for apps that cannot connect to an address on their own.
* Albert now recognises a site that only runs on your computer (a local site), lists the apps that work with it first, and explains why Claude on the web and ChatGPT cannot reach it, instead of showing steps that would fail.
* On a site that only runs on your computer, Claude Desktop now connects with a small install file made for your site: download it, open it, and approve in your browser. Nothing else to install. It needs the site on https or on localhost.
* Installing the MCP Adapter plugin is now a recommendation on the Dashboard's setup list rather than a step that held everything else up, since Albert works with its own copy.
* The Dashboard's "Connect an AI assistant" step is about you: it ticks off when you have connected an assistant yourself, and a Connect an assistant button stays on the Dashboard after setup.

**Fixes**

* Asking your assistant to build a page with a layout block that holds other content (such as a Cover block wrapped around a heading and text) could silently save the block empty, losing everything inside it. These blocks now keep their contents.
* In the rare case where a block's content genuinely can't be reproduced, the assistant is now told clearly, instead of the block being saved empty as if it had worked.
* Pages your assistant builds with blocks no longer raise a WordPress debug notice each time they are shown. Pages built before this update stop once they are saved again in the editor.
* Asking your assistant to change a layout block's settings, such as a group's background, could remove everything inside it. The contents now stay, unless the assistant explicitly replaces them.
* Apps that sign in with their own kind of link, such as Cursor, could not connect: every attempt ended in "Invalid Request". They now connect.
* Long text in the list of people who may connect an assistant now wraps instead of being cut off.
* The Dashboard's Albert for WooCommerce recommendation now opens the add-on's own page.
* When another plugin switched off the MCP adapter's built-in tools (AI Workflow Automation does this), assistants could sign in but saw no tools at all. Albert now adds the three tools it needs when they are missing, without replacing anyone else's.
* Claude Code could not connect on hosts such as SiteGround that answer part of the sign-in discovery themselves: it stopped with "Dynamic Client Registration rejected" before the approval screen. It now connects.
* After about twenty connection attempts, including ones never finished and apps you had disconnected, no new assistant could connect at all and nothing said why. Only connections that still work count towards the limit now, old unused ones are cleaned up, and the Dashboard tells you if the limit is ever reached.

**Security**

* Albert now closes an unused extra way in. An AI assistant that held a WordPress application password could previously reach your site's tools without going through Albert's sign-in, the list of people you allow to connect, or the approval screen — and it left no record under Connections. That entry point is switched off, so assistants must connect the approved way.
* Your assistant can no longer pick or change a WordPress password. Creating a user previously meant the assistant invented a password and sent it to your site, which also left it sitting in the AI provider's record of the conversation. Now your site generates the password itself and hands back a one-time link for the new person to set their own. Changing an existing user's password is refused outright: that account's owner can reset it from the login screen, and nobody else needs to.
* When you deny an app, or its sign-in fails, Albert now sends the app's own reference back to it safely encoded, so a crafted value can no longer add extra parameters to the address the app is sent back to.
* Albert now refuses to register an app whose return address contains spaces or hidden control characters.

**Developer**

* The bundled MCP adapter moves to 0.7.0 and becomes a fallback: when the MCP Adapter plugin is active it supplies the adapter, and Albert's copy in `lib/mcp-adapter/` is offered to Jetpack Autoloader only when it is not (`MCP\AdapterSource`). `discover-abilities` gains a `notice` field asking the assistant to pass the install request on, sent only to users who can act on it. New `albert/mcp/adapter_notice` filter; return `''` to send none.
* Safe mode holds destructive assistant-initiated calls at WordPress 7.1's `wp_pre_execute_ability` short-circuit, so a held call never reaches normalisation, the permission check or the execute callback. Only requests carrying an Albert OAuth connection are gated: WP-CLI, direct PHP and other plugins are untouched. Two axes decide what is held, and either is enough: `annotations.destructive !== false` (an unannotated ability is held, never waved through) and `SafeMode\RiskPolicy`, which holds any call whose input grants an administrator-capability role or writes a high-risk option, including from abilities Albert has never heard of. Staged calls live in the new `albert_pending_actions` table with the input captured server-side, so approval replays what the server holds rather than anything the model can resupply. `Execution\InterceptorDecision` resolves the MCP double-fire once for any interceptor on that filter. New settings `albert_safe_mode` (on/off, accepts booleans from a constant) and `albert_safe_mode_ttl_minutes` (default 60, clamped 5 minutes to a week), both resolved through `Settings\Value`. A held call is logged as a `warning`, not an `error`: `Outcome::HELD_CODE` classifies it on its own branch, so the gate doing its job neither reddens the Dashboard nor fires `albert/logging/ability_failed`. `SafeMode\AuditTrail` records approvals, rejections, expiries, abandoned runs and refused writes to Albert's own control options and restores of ones changed directly in the database, each as both an action and an activity-log row. `Cron\PendingActionSweep` expires lapsed rows, fails claims stale for an hour, and deletes decided rows after 30 days. New hooks: `albert/safe_mode/high_risk_abilities`, `high_risk_options`, `protected_options`, `exempt_abilities`, `retention_days`, `display_input`, `held`, `approved`, `rejected`, `expired`, `abandoned`, `option_write_blocked`, `option_restored`, and `albert/approvals/view_capability`.
* Albert now neutralises the MCP adapter's built-in default server (`mcp-adapter-default-server`), which exposed every public ability through the adapter's default transport permission (`current_user_can( 'read' )`), bypassing Albert's OAuth flow, the allowed-users list and the consent screen, and left no Connections row. The server is left created — the `mcp_adapter_create_default_server` off switch would also unregister the `mcp-adapter/*` meta-tool abilities Albert's own server depends on — but its tools, resources and prompts are emptied through the `mcp_adapter_default_server_config` filter, so it can execute nothing. Applied whenever Albert's MCP integration is active, not gated on which shared copy of the adapter is loaded, since the abilities are global. New `albert/mcp/disable_default_server` filter (default true) opts out.
* `BaseAbility::check_rest_permission()` no longer carries a dead regex branch for pattern routes — a `preg_match()` against the REST route table's keys that could never match. The behaviour it fell through to is now explicit: a single-object route such as `/wp/v2/posts/(?P<id>[\d]+)` is gated by the ability's declared capability at this pre-execution stage, because its endpoint permission callback needs a target id that is not known yet; the exact per-object check still runs at execution via `rest_do_request()`. Plain collection routes still delegate to their own permission callback. No behaviour change.
* `albert/create-user` no longer accepts a `password` input. It is gone from the schema, so a schema-validating client cannot send one; the password is generated with `wp_generate_password()` at execution time and never disclosed. The result carries a one-time `password_reset_url`, declared in `sensitive_output_keys` so every `after_execute` observer sees `[redacted]` while the caller gets the real link. No notification email is sent, deliberately: `wp_new_user_notification()` mints a reset key of its own, and whichever key is issued second invalidates the first, so emailing would either kill the returned link or return a dead one. `albert/update-user` likewise drops `password` from its schema and refuses a supplied one at execution with `password_change_refused`. The Abilities API does not forbid unrecognised keys, and refusing at execution means the attempt is logged rather than swallowed as a validation rejection, which `ToolCallObserver` deliberately does not record. Both abilities gained `instructions` annotations so a model is told at call time rather than learning by rejection.
* New read-only pattern abilities `albert/find-patterns` and `albert/view-pattern`. They expose theme- and plugin-registered patterns (`WP_Block_Patterns_Registry`) and user `wp_block` patterns: find returns summaries filterable by search term and category, view returns one pattern's block markup by name. Backed by `Albert\Blocks\PatternCatalog`; each pattern carries a `source` of `registered` or `user`, plus a `syncStatus` and, for user patterns, an `id`, so a synced pattern can be reused by reference (`core/block`) rather than copied. `albert/create-pattern` composes blocks through the serializer and stores them as a wp_block pattern, synced or unsynced. `albert/update-pattern` and `albert/delete-pattern` edit and remove user patterns (registered patterns are read-only).
* Adds `BlockSchema::markup_sourced_attributes()` and `BlockSchema::text_sourced_attributes()`, which report which of a block's attributes are stored in its saved markup, read from the block registry.
* New `albert_connect` capability, granted to administrators and to everyone on the allowed list for as long as they are on it (`Admin\Connections\ConnectCapability`). It gates the Connect page, the connect dialog's REST routes (`albert/v1/connect/status`, `albert/v1/connect/location`) and the Claude Desktop download.
* The authorize endpoint no longer runs `redirect_uri` through `esc_url_raw()`, which emptied schemes WordPress does not know, such as `cursor://anysphere.cursor-mcp/oauth/callback`. The URI is kept as sent and must exactly match a registered one. Dynamic client registration refuses a redirect URI containing whitespace or a control character, and denials and OAuth errors return `state` URL-encoded.
* The server metadata now declares `subject_types_supported` and `id_token_signing_alg_values_supported` (from `Jwks::ALGORITHM`), which clients validating the `openid-configuration` document as OpenID Connect metadata require. Albert still issues no ID tokens and offers no `openid` scope.
* The client registration cap counts live clients plus those registered in the last 24 hours (`ClientRepository::count_live_clients()`). The nightly token cleanup deletes clients older than 7 days with no tokens and no sign-in in progress; new filter `albert/oauth/stale_client_days`, 0 keeps them. A refusal at the cap fires `albert/oauth/registration_rejected` with `client_limit_reached`.

**Credits**

* [David Beentjes](https://github.com/unlocdavid) for helping fix the lost block contents.
* [Rodolfo Melogli](https://profiles.wordpress.org/businessbloomer/) for reporting the Claude Code connection failure on SiteGround.
* [Sébastien](https://profiles.wordpress.org/seb94100/) for reporting assistants seeing no tools when another plugin switched off the MCP adapter's built-in tools.

= 1.4.2 =
Fixes AI assistants that could not connect to some sites.

**Improvements**

* AI assistants can't connect when your permalinks (Settings &rarr; Permalinks) are set to "Plain". Albert's Dashboard now tells you when that's the case, with a link to change it. Any other permalink setting works.

**Fixes**

* Claude Code and some other AI assistants stopped at the sign-in step with an error about your site's sign-in details. They now find everything they need and connect.
* AI assistants such as ChatGPT could not connect to sites whose page addresses include /index.php/ (for example example.com/index.php/about/). Albert now uses addresses that work with your permalink setting.

**Developer**

* The authorization server metadata now advertises `jwks_uri`, served from a new `albert/v1/oauth/jwks` REST route that publishes the token signing key's public half as an RFC 7517 JSON Web Key Set. Clients that validate the metadata before authenticating (Claude Code's MCP SDK requires `jwks_uri` to be a string, though RFC 8414 marks it optional) previously failed discovery outright.
* Discovery now answers the RFC 8414 §3.1 and RFC 9728 §3.1 canonical path-insertion URLs (`/.well-known/oauth-authorization-server/<issuer path>` and `/.well-known/oauth-protected-resource/<resource path>`), not only the OIDC append form. These are root `.well-known` URLs, so on hosts that intercept a root `/.well-known/` the mid-path append form remains the one that works.
* Every URL Albert advertises (issuer, endpoints, `resource_metadata`, the MCP endpoint under `albert/mcp/external_url`, `ClientRegistration::get_endpoint_url()`) now routes through `index.php` when the permalink structure does, and uses `rest_get_url_prefix()` instead of a hard-coded `wp-json`. New `ServerMetadata::url()`. Pretty-permalink sites get the same URLs as before, so existing connections are unaffected.
* `ServerMetadata::base_url()` now delegates to `Server::get_base_url()`, so `albert/mcp/external_url` is read once per request and validated in one place.

**Credits**

* [Marinus Klasen](https://profiles.wordpress.org/mklasen/) for reporting the Claude Code sign-in problem.
* [Sébastien](https://wordpress.org/support/users/seb94100/) for reporting the /index.php/ connection problem, with the logs that pinpointed it.

= 1.4.1 =
Fixes AI assistants failing to connect, including on managed hosts (such as SiteGround and Servebolt) that handle the sign-in discovery address themselves.

**Fixes**

* AI assistants using the newest sign-in method could fail to connect. They connect again now.
* AI assistants could not sign in on some managed hosts (such as SiteGround and Servebolt) that answer part of the sign-in discovery themselves. Albert now serves it where those hosts pass it through, so sign-in works with no manual setup.

**Developer**

* Discovery is now RFC 9728 / RFC 8414 conformant: `resource_metadata` in `WWW-Authenticate`, one Protected Resource Metadata source listing the issuer, and the issuer served at a mid-path `.well-known` URL that hosts intercepting a root `/.well-known/` leave alone. Adds the `albert_oauth_discovery` Site Health test.
* The issuer is now a path, so a connected client that re-runs discovery re-authorises once; live connections keep working.

= Earlier versions =

Releases before 1.4.1 are listed in `changelog.txt`, bundled with the plugin.

== Upgrade Notice ==

= 1.5.0 =
Adds safe mode, which holds destructive actions for your approval, and closes a way an assistant with an application password could reach your site's tools without Albert's sign-in. Recommended for everyone.

= 1.4.2 =
Fixes Claude Code and other AI assistants that could not connect, including on sites whose page addresses include /index.php/. Recommended if an assistant could not connect.

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
