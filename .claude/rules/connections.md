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

## Setup guides and the connect dialog
- `SiteLocation` only orders the apps and marks those that need a public site. Never hide an app: hiding guides by host confused people more than a guide that fails.
- Only an administrator saves where the site runs (`albert_site_location`). It is the site's answer, shown to everyone who connects.
- Setup content is data in `ClientSetupGuides`, rendered only by `GuidePanel`, so the Connections screen and the dialog cannot drift.
- Never offer a file that holds a credential. Tokens are scoped, expiring and revocable; a file is none of those.
- The Claude Desktop `.mcpb` holds no secret; the bridge signs in with OAuth. The MCP SDK in `mcp-remote` refuses plain http to anything but localhost, so on such sites the guide withholds the download and asks for https. Never patch that check out of the bundle. Rebuild the bridge from `tools/claude-desktop/` (`npm run build`).
- The dialog never ticks off its numbered steps (they happen in other apps). The approval page posts on `BroadcastChannel('albert-connect')` so the dialog checks at once instead of at its next poll. The Dashboard's connect step is per user.
- The dialog says "connected" only when `ClientRepository::firstConnectionSince()` finds a code this user approved since the dialog opened, redeemed for a live token. A refresh issues no code, so an older connection never counts, and nobody else's is ever reported.

## Who sees what
- `albert_connect` (`ConnectCapability`, a `user_has_cap` grant) follows `AllowedUsers::is_allowed()` live. It gates the Connect page, its REST routes and the Claude Desktop download, nothing else. Logs, settings, abilities, other people's connections and the site location stay `manage_options`.
- For administrators the Connect page has no menu item and forwards to Connections. Removing the page instead makes WordPress refuse it.

## Connection rows
- Group by client, not by token: a client refreshes hourly, so a token list shows one assistant many times.
- Revoke has two depths behind one confirm: access token only (the client reconnects within the hour) or access and refresh (it must be approved again).
- A label is text one administrator writes onto someone else's connection (CWE-451). Keep the app-reported name and "authorised by" beside it, show "Labelled by" from `label_set_by` / `label_set_at`, and render every place a label appears through `render_connection_row()` so it is escaped once.
- The screen looks the same at any row count. Bulk select is a mode the owner enters, not something a row count switches on.
- Row checkboxes join the bulk form with `form=`, because each row has its own label form and forms cannot nest.
- "Disconnect all" is always rendered, with `hidden` when there is nothing to disconnect, so the JS only toggles it.
- `DomainGuard` records the host a client was authorised on. Nothing enforces it yet; do not refuse requests on it without the notice and re-confirm flow.

## Allowed users
- Add and remove return JSON to the screen's `fetch()` and redirect otherwise, from one handler. Detect it with `is_ajax_request()` (a header the JS sets): this is `admin-post.php`, so `wp_doing_ajax()` is never true.
- The JSON carries markup from the same `render_*` methods a page load uses. Removing a person revokes their tokens, so return the connections list and count too, not only the user list.
- One picker (`UserPickerModal`) serves the Connections screen and the Dashboard. It is multi-select; someone already allowed is shown muted, not as a checkbox.
- The Dashboard has no list to update; `UserPicker.canInsertInline()` checks for the container and falls back to a normal submit.
- The form has `<input name="action">`, which shadows `form.action`. Read `form.getAttribute('action')`.
- The picker never lists the whole user table. It searches `/wp/v2/users` with explicit `search_columns[]` (otherwise `WP_User_Query` picks columns from what the term looks like), `capabilities[]` from `albert/connections/allowed_user_capability`, and `per_page`. Suggestions before typing are two bounded requests, administrators first, leaving out people already allowed.
- Those parameters need `list_users`. Fine while the screen is `manage_options`; revisit if that ever narrows.
- Removing a person revokes all their tokens. `TokenValidator` also checks the list on every request, and a password change or reset revokes too (`OAuth\PasswordChange`).
