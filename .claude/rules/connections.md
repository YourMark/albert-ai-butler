---
paths:
  - "src/Admin/Connections.php"
  - "src/Admin/Connections/**"
  - "src/Admin/ConnectPage.php"
  - "src/Admin/Rest/ConnectController.php"
  - "assets/js/admin-connect.js"
  - "assets/js/admin-connections.js"
  - "assets/claude-desktop/**"
  - "tools/claude-desktop/**"
  - "src/OAuth/AllowedUsers.php"
  - "src/OAuth/Server/DomainGuard.php"
  - "src/Cron/AllowedUserExpiry.php"
---

# Connections

## Connections screen (1.4.0)

`Admin\Connections` is where an owner learns whether an assistant can reach this
site, how to set one up, who may authorise one, and what is connected.

**Location orders, never hides (1.5.0).** `Admin\Connections\SiteLocation`
guesses whether the MCP endpoint is public or only reachable on this computer
(local suffixes such as `.test`, private and loopback IPs, single-label hosts,
or `WP_ENVIRONMENT_TYPE` `local` while the endpoint is the site's own address).
The guess reads as one ("Detected: …"), and only an administrator can save the
answer for the site (`albert_site_location`): it is the site's answer, and a
wrong one would mislead everybody else who connects. It decides the
order of the apps and marks Claude on the web and ChatGPT as needing a public
site on a local one, with the reason and an alternative. It never removes an
app: an earlier version (`Support\HostClassifier`) hid guides by host and that
confused people more than a guide that fails.

**Setup content is data, not markup.** `Admin\Connections\ClientSetupGuides`
holds the curated list (Claude, Claude Code, Cursor, VS Code, ChatGPT, Other
apps with Codex, Windsurf, Gemini CLI, Cline and the pinned `mcp-remote` Node
bridge) as numbered steps with their one action inline (an install link or a
command to copy), quiet requirement notes, and manual setup, all built from the
live endpoint. `Admin\Connections\GuidePanel` is the one renderer, so the
Connections screen and the connect dialog cannot drift. No credential-bundle export of any kind: a file
holding a permanent plaintext credential is a step back from tokens that are
scoped, expiring and revocable.

**Claude Desktop on a local site gets an install file (1.5.0).** Its connectors
run on Anthropic's servers, so `Admin\Connections\ClaudeDesktopBundle` serves a
`.mcpb` built per download: a manifest with this site's MCP URL and
`NODE_USE_SYSTEM_CA=1` (plus `NODE_EXTRA_CA_CERTS` when
`LocalCertificate` finds Herd, Valet or mkcert's root), and
`assets/claude-desktop/bridge.mjs`, a minified esbuild bundle of mcp-remote
pinned at `BRIDGE_VERSION`, built from `tools/claude-desktop/` (`npm run build`
there). No secret is in the file; the bridge signs in with OAuth. The MCP SDK
inside mcp-remote refuses to send the token request over plain http to anything
but localhost, deliberately and with no override, so on such sites the guide
withholds the download and says to turn on https rather than offering a file
that can never connect. Do not patch that check out of the bundle.

**One guided dialog, two entry points (1.5.0).** `Admin\Connections\ConnectDialog`
asks where the site runs (saved site-wide through `ConnectController`, so it is
asked once), which app, then shows that app's `GuidePanel` steps and waits. It
says "connected" only when `GET albert/v1/connect/status?since=` reports a
client this user approved after the dialog opened: an authorization code issued
since then, redeemed for a live token (`ClientRepository::firstConnectionSince()`).
A refresh issues no code, so an older connection refreshing never counts, and
nobody else's connection is ever reported. The
approval page posts on a `BroadcastChannel('albert-connect')` so the dialog
checks at once instead of at its next 3 s poll. The numbered steps happen in
other apps and are never ticked off. The Dashboard's connect step is per user
(an administrator whose agency connected still has their own to do), and once
setup is complete a header button keeps the dialog reachable. The Connections
layout is fixed in every state: connect card, then the two lists; the old
per-app accordion is gone, the dialog holds that content.

**Anyone on the list can see how to connect (1.5.0).** `albert_connect`
(`Admin\Connections\ConnectCapability`, a `user_has_cap` grant) is held by
administrators and by everyone `AllowedUsers::is_allowed()` says yes to, whatever
their role, so it follows the list live and an expired invitation grants nothing.
It gates `Admin\ConnectPage` (the dialog, the MCP URL and the person's own
connections with a Revoke that drops only their tokens,
`ClientRepository::revokeUserTokens()`), the dialog's REST routes and the Claude
Desktop download. Nothing else: logs, settings, abilities and other people's
connections stay `manage_options`, and so does saying where the site runs: a
non-administrator sees it but cannot change it. Administrators keep Connections; for them the Connect page has no
menu item and forwards to Connections (removing the menu item instead makes
WordPress refuse the page), and "Copy setup link" in Who may connect hands a
non-administrator a link that opens the dialog on arrival.

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
