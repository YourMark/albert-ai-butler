=== Albert - The AI Butler ===
Contributors: albertai, mark-jansen
Tags: ai assistant, chatgpt, claude, ai, mcp
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.6.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The easiest, safest way to connect Claude, ChatGPT and other AI assistants to WordPress, built on WordPress's own MCP Adapter.

== Description ==

## Let your AI assistant actually run your WordPress site

You already ask ChatGPT or Claude to plan posts, rewrite copy and answer questions about your business. Then you paste the result into WordPress by hand, and fix the formatting.

Albert removes that step. Connect your AI assistant to your site once, and it writes, edits and files your content directly, in real WordPress blocks, inside limits you set. No copy and paste, no plugin-specific chat window, no code.

**The easiest and safest way to use the WordPress MCP Adapter.** The MCP Adapter is WordPress's own way for AI assistants to reach a site, and on its own it leaves setup, sign-in and permissions to you. Albert adds them: a guided setup, a proper sign-in, a list of who may connect, and your approval before anything is deleted.

* **Deletions wait for you.** Safe mode is on from the start. Permanently deleting content, creating or changing a user account, or changing a key site setting waits for your approval, and the assistant cannot approve its own request. Ordinary edits to posts and pages go ahead without approval.
* **Signed in, never handed a password.** Assistants connect with OAuth: short-lived tokens you can revoke, and no password or API key ever given to an AI company.
* **No middleman.** Your assistant talks to your site directly. Albert has no servers of its own in between.
* **No tracking.** Albert sends nothing about your site or your usage anywhere.
* **Personal data hidden by default.** Whatever your assistant looks up, from any plugin, email addresses, phone numbers and IP addresses are masked. Albert's own user, customer and order tools mask names and postal addresses too. New sites start on Strict.

---

### Connect in three steps

Copy your site's connection URL, paste it into your assistant, approve the request. That is the whole setup, and it is the same for every assistant.

Not sure where to paste it? Click Connect an assistant, tell Albert where your site runs and which app you use, and it shows that app's exact steps. It tells you the moment the connection works, and what to ask first. Sites that only run on your computer work too, and Claude Desktop gets a one-file install.

---

### Albert speaks block editor

Most AI plugins hand WordPress a wall of HTML and leave you to clean it up. Albert does not.

* **Real blocks, not raw HTML.** Headings, lists, quotes, images, buttons, columns and groups come back as proper blocks you can edit like anything else.
* **One block at a time.** Change, add, move or remove a single paragraph without your assistant rewriting the whole page.
* **Built to avoid "this block contains unexpected content".** For WordPress's own common blocks (paragraphs, headings, lists, quotes, images, buttons, columns, groups and a few more), Albert writes the markup the editor expects and corrects the assistant before anything is saved. Blocks from other plugins can still need a look in the editor.
* **Classic editor too.** On a classic site, content is read and saved as HTML instead. Both work.

---

### Your assistant knows your site

An assistant that does not know your site invents things: brand colours you never chose, a tone that is not yours, categories that do not exist.

* **Write your instructions once.** Your tone, your vocabulary, what to leave alone. Albert gives them to your assistant whenever it asks what your site can do, as guidance, not as rules Albert enforces. To stop it touching something, switch that action off.
* **Your site describes itself.** Language, timezone, post types, taxonomies and, with WooCommerce, shop settings go along too, plus theme colours and fonts, but only when your theme genuinely declares them.
* **See exactly what is sent**, and switch off any part an assistant does not need.
* **Text never changes what it may do.** Albert tells your assistant that your instructions and your site's content are information, not orders. Whatever any text says, your switches, your WordPress roles and safe mode still decide what it can do.

---

### You decide what it may do

Not every guest needs a key to every room.

* **Every action listed and labelled** Read, Write or Destructive, with its own switch. Changes save instantly.
* **Write and delete start switched off.** You turn on exactly what you want, and nothing else.
* **Your WordPress roles still apply.** An assistant can never do more than the person who approved it.
* **New actions arrive switched off** once you have saved your choices, so a plugin update never quietly widens what an assistant can reach.
* **You choose who may connect.** Only the people on your list can approve an assistant. Each gets their own Connect page, and Send setup link emails them a link to the steps.
* **Revoke any connection at any time.**

---

### Deletions wait for you

Switched on by default. When your assistant asks to permanently delete a post, create or change a user account, or rewrite a foundational setting, the action does not run. It waits for you. Ordinary edits to posts and pages go ahead without approval.

* **Held, not logged after the fact.** The request is stopped before it happens, not reported once it has.
* **You see what it would actually change**, resolved as it is now: "Delete the post 'Pricing' (published)", not a line of code. If the page changed since the request, you are told before you approve.
* **Approve what you could do yourself.** Each person approves their own requests, as long as they may still do what was asked; administrators approve anyone's. Nobody gains reach they did not already have.
* **The assistant cannot approve its own work**, cannot switch safe mode off, and cannot touch the settings that control it.
* **Requests expire** if nobody decides, so an old intent cannot be approved against today's site.

---

### Personal data stays private

Whatever your assistant looks up, Albert hides email addresses, phone numbers and IP addresses before the answer leaves your site. That includes what WordPress itself and your other plugins return. Albert's own user, customer and order tools also hide names and postal addresses. Email addresses inside your content, such as a post, are left as they are.

Choose **Strict**, **Balanced** or **Off**. New sites start on Strict. On Balanced, anyone allowed to use a tool can ask it for the real details.

---

### See what happened

* **Recent activity on the dashboard**: which action ran, who ran it, when, and how it ended. The Abilities screen shows when each action last ran. Albert Premium keeps a full log.
* **Blocked is not Failed.** A request your own rules refused reads differently from one that broke.
* **Needs your attention** surfaces standing problems on the dashboard, and stays quiet when there are none.

---

### Works with the assistant you already use

Claude Desktop, claude.ai, Claude Code, ChatGPT, Cursor, VS Code, or anything else that speaks the Model Context Protocol. Albert serves your site to them through WordPress's own MCP Adapter, so the same connection URL works everywhere and you are never locked to one vendor.

Connections use OAuth 2.0 with short-lived, self-refreshing tokens. No password is ever shared with an AI company.

---

### What your assistant can do

* **Posts and pages**: find, view, create, schedule, update and delete, block by block, or change one word without rewriting the page.
* **Search**: everything on your site in one go, including templates, patterns and terms.
* **Revisions**: see what each save changed, and restore an earlier version.
* **Menus**: find the menu visitors actually see, on classic and block themes, and change it.
* **Comments**: find, reply to and moderate comments, in batches.
* **Media**: browse the library, fill in missing alt text, set featured images, and receive files your assistant sends you directly.
* **Users and taxonomies**: find and manage users, categories, tags and custom terms.
* **Block patterns**: find and reuse the patterns from your theme, plugins and library, insert them by name, and save new ones.
* **Site Health**: run WordPress's checks and get each result with the link to fix it.
* **WooCommerce**: look up products, orders and customers when WooCommerce is active.
* **Anything you build**: register your own actions with the WordPress Abilities API.

---

### Works with the plugins you already use

Albert is not limited to its own abilities. Plugins that offer abilities to AI assistants through the WordPress Abilities API show up on Albert's Abilities screen, where you choose which ones your assistant may use over the same connection. Many popular plugins already do:

* **SEO**: Yoast SEO, Rank Math, All in One SEO, SEOPress, SiteSEO, SureRank.
* **Forms**: Gravity Forms, WPForms, Fluent Forms, Formidable Forms.
* **Page builders**: Elementor, Ultimate Addons for Elementor, Premium Addons for Elementor, SiteOrigin Page Builder.
* **Custom fields**: Advanced Custom Fields (ACF), Secure Custom Fields, Meta Box.
* **E-commerce**: WooCommerce, plus [Albert for WooCommerce](https://albertwp.com/add-ons/albert-for-woocommerce/) for orders, products, coupons and reports.
* **Search and analytics**: Search & Filter Pro, Koko Analytics Pro, PixelYourSite.
* **Performance and media**: Imagify, ShortPixel, SpeedyCache.
* **Security, backups and activity**: Jetpack, Akismet (once you enable its MCP access), BackWPup, Simple History.

What each plugin lets your assistant do is up to that plugin. You decide which of its abilities are switched on, and the assistant only ever acts with the permissions of the person who approved the connection.

---

### Go further with Albert Premium

[Albert Premium](https://albertwp.com) adds per-role and per-user permission rules, your own custom post types, and a full activity log with filters and retention.

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

It can only do what you switched on. Write and delete actions start off, new actions added by an update also start off once you have saved your choices, and every action still obeys your WordPress roles, so an assistant can never do more than the person who approved it. You can revoke a connection at any time, and the dashboard shows recent activity.

= Will the content look right in the block editor? =

Yes. Albert writes real WordPress blocks, headings, lists, images, columns and the rest, not a wall of HTML pasted into one paragraph. For WordPress's own common blocks it writes the markup the editor expects, so you do not get the "this block contains unexpected content" warning. Blocks from other plugins can still need a look. Classic editor sites are supported too.

= Does my AI assistant see my visitors' and customers' personal data? =

Not by default. Whatever it looks up, from Albert or any other plugin, Albert hides email addresses, phone numbers and IP addresses before anything is sent, and Albert's own user, customer and order tools hide names and postal addresses too. Choose Strict, Balanced or Off; on Balanced, your assistant can ask for the real details when you need them.

= How does Albert know anything about my site? =

You write instructions once, and Albert gives them to your assistant whenever it asks what your site can do, along with your language, timezone, theme colours and fonts, content types and shop settings. They guide the assistant; they are not rules Albert enforces. You can preview exactly what is sent and switch off any part of it.

= Is my AI assistant connection secure? =

Yes. Connections use OAuth 2.0, the same standard as Google and GitHub. Your assistant gets a short-lived token that refreshes itself, and no password is ever shared. Revoke any connection from the Connections screen.

= What is in the Claude Desktop install file? =

Claude Desktop cannot reach a site that only runs on your computer through its usual connectors, so for a local site Albert offers a small install file instead. It holds your site's address and a copy of [mcp-remote](https://github.com/geelen/mcp-remote) (MIT licence), which Claude Desktop runs on its own built-in Node.js. There is no password or key inside: Claude still asks you to approve the connection in your browser. The bridge is built from `tools/claude-desktop/` in [Albert's source](https://github.com/YourMark/albert-ai-butler).

= Does it cost anything? =

Albert is free. [Albert Premium](https://albertwp.com) is optional and adds per-role and per-user permission rules, custom post type support, and a full activity log with filters and retention.

= Do I need WooCommerce? =

No. Albert works with WordPress core out of the box. WooCommerce actions appear automatically when WooCommerce is active.

= Can I add my own actions? =

Yes. Register them with the WordPress Abilities API and they appear on the Abilities screen alongside Albert's own. See the [Abilities API documentation](https://developer.wordpress.org/apis/abilities-api/).

= Does this work with multisite? =

Albert is built for single-site installations. Multisite support is on the roadmap.

= What are the requirements? =

WordPress 7.1 or higher, PHP 8.2 or higher (8.3+ recommended), MySQL 8.0+ or MariaDB 10.5+, HTTPS (OAuth 2.0 requires it), and any permalink setting other than Plain.

= Where do I get support? =

On the [WordPress.org support forums](https://wordpress.org/support/plugin/albert-ai-butler/).

== Screenshots ==

1. The Albert dashboard: setup status, how many abilities are switched on, recent AI assistant activity, and anything that needs a decision from you
2. The Connections screen: your MCP endpoint, who is connected, who may connect, and step-by-step setup for Claude, ChatGPT, Cursor and the rest
3. The Abilities screen: every action an AI assistant can take, labelled Read, Write or Destructive, each with its own instant-save switch
4. The Context screen: write instructions for your assistant and see exactly what Albert tells it about your site, section by section
5. The Skills screen: the task guides Albert ships, so you can read the exact guidance a connected assistant follows

== Changelog ==

= 1.7.0 =

**Improvements**

* The connection guides match how each app connects today. ChatGPT now uses Add custom MCP server instead of Developer mode, which ChatGPT removed, and says which plans can add one. Claude uses its new Customize menu and warns when the site's address has its own port number.

= 1.6.0 =
Your assistant can now manage menus, comments and revisions, search your whole site and report Site Health. Requires WordPress 7.1 and PHP 8.2.

**Features**

* **Menus.** Your assistant finds the menu visitors actually see, on classic and block themes, and adds, renames, moves or removes its items. When a change lands on a menu nobody sees, it says so.
* **Comments.** Find, read and reply to comments, and approve, hold back, mark as spam, trash or delete up to 50 at once. Product reviews are left alone.
* **Revisions.** See what each save of a post, page, template or menu changed, and restore an earlier version. A restore is saved as a new revision, so it can be undone.
* **Search everything.** One search across posts, pages, other content types, synced patterns, template parts and terms, including drafts and private items the signed-in user may read.
* **Site Health.** Your assistant runs WordPress's Site Health checks and reports each result as the Site Health screen does, most urgent first, with the link to fix it.
* **Alt text.** Find images without alt text, and write the alt text, title, caption and description of any media file. Media files can also be deleted.
* **Scheduling and post details.** Schedule a post or page, and set its slug, author, parent, order, template, comments, sticky status and format.
* **Change a word, not the page.** Replace a price, a name or a link without rewriting the page. Your assistant must say how often the text occurs, so it never changes more than intended.
* **Insert a pattern by name.** Your assistant places one of your block patterns into a page or post in one step, exactly as your site stores it.
* **Categories and tags.** See a post's categories and tags, and add or remove one without touching the others.
* **Staging sites.** A setting in your site's code can switch abilities on for staging, development and local copies, so copying your live site over them no longer turns everything off.
* **Allowed users from the command line.** `wp albert allowed-users` lists, adds and removes the people who may connect an assistant.

New write and delete actions start switched off. With safe mode on, replies, comment moderation, permanent deletes, menu removals and revision restores wait for your approval.

**Improvements**

* Albert now requires WordPress 7.1 and PHP 8.2. Sites on older versions keep Albert 1.5.1. Safe mode now works on every site Albert runs on.
* The Abilities screen sorts actions into Read, Write and Destructive. Removing a block, and creating or changing a user, now count as Destructive and wait for your approval.
* An action from another plugin that does not say whether it changes anything is labelled Not declared instead of Write. Safe mode still holds it.
* Moving a post, page or pattern to the trash no longer waits for approval, since you can restore it. Permanent deletes still do.
* Several block edits on one page are saved together, as one revision. If one fails, nothing is saved.
* An edit that would drop a tag another plugin added, such as a ThirstyAffiliates link, is refused until you agree.
* A block Albert can only guess, such as an image without an address, is refused with what to send instead, rather than saved broken.
* Deleting a user now always asks who receives their content, and an assistant can no longer delete the account it is connected as.
* The Abilities screen no longer shows a "Required capability". It was a guess, and wrong in places.
* The Context screen says that changes apply from the next conversation.
* Albert is about 2 MB smaller, and ordinary page views no longer load its abilities.

**Fixes**

* Privacy mode covered only Albert's own user, customer and order tools. It now masks personal data in everything your assistant calls through Albert, whichever plugin returns it.
* Switching an ability off removed it from your whole site, so other plugins using it broke. The switch now applies only to assistants connected through Albert.
* Paragraphs, headings, lists, buttons and quotes lost tags other plugins add, such as ThirstyAffiliates links. Text is now saved as the editor saves it.
* Blocks with colours, spacing, borders or extra styling could open with "This block contains unexpected or invalid content". They now open cleanly.
* Copied blocks lost bold text, links, images and their layout, and a group sent with its contents was saved empty. Blocks are now saved exactly as sent.
* Markdown was saved as one paragraph with the marks left in. Albert now refuses it and asks for blocks.
* Deleting a category or tag always failed. It is now deleted permanently, and your assistant is told so first.
* Moving a post or page to the trash was reported as a permanent delete.
* A negative ID, such as -5, could change the item with ID 5. IDs below 1 are now refused.
* Removing categories or tags from a post did nothing, and a category or tag could be left off without a word. Both work now, and one that cannot be used is refused with the reason.
* User searches returned empty email addresses and roles, the wrong username, and a jumbled result when asked for one user.
* Searching WooCommerce orders between two dates missed orders in that period.
* A deleted user stayed on the list of people who may connect an assistant. They are now taken off it.
* Send setup link now offers to email anyone on the list who has an address, and renews an expired invitation.
* Recent activity now shows other plugins' actions, shows refusals as Blocked, and shows malformed answers as Failed.
* The user picker now says why it is empty when a plugin blocks the user list or your role may not list users.
* The Dashboard's Documentation link opened another plugin's page.

**Security**

* Changing or resetting a person's password now disconnects every assistant connected as them.
* Reading a WooCommerce product now needs the right to edit products, as searching them already did.

**Developer**

* Error codes no longer carry an `albert_` prefix, and every not-found error ends in `_not_found`. For example `albert_permission_denied` is now `permission_denied`, and `albert/set-featured-image` returns `post_not_found` and `media_not_found` instead of `invalid_post` and `invalid_attachment`.
* Removed: support for WordPress 6.9 and 7.0, with the `abilities_api_init` and `abilities_api_categories_init` hooks.
* Removed: the `NAMESPACE` constants of `OAuthController` and `ClientRegistration`. Use `Plugin::rest_namespace()`.
* Switching an ability off no longer unregisters it. Albert's MCP server hides and refuses it, and a call over an Albert connection is refused on any route. `albert/abilities/disabled_list` now also applies to Albert's own abilities.
* `albert/abilities/register` fires on `wp_abilities_api_init` instead of `init`, only on requests that read the abilities registry.
* New: the `ALBERT_ENVIRONMENT_ABILITIES` constant and `albert/abilities/environment_policy` filter, the `albert/privacy/personal_keys` and `albert/safe_mode/should_hold` filters, and the `albert/allowed_users/added` and `albert/allowed_users/removed` actions. `albert/connections/allowed_user_capability` now also decides who may be added.
* New `Annotations::destructive()`. An Abilities screen row's `operation` is `destructive` (was `delete`) or `undeclared` (was `write`), and the badge class `albert-op-badge--delete` is now `albert-op-badge--destructive`.
* On Balanced, every ability gets an optional `reveal_personal_data` input, and `discover-abilities` carries a `privacy` field.
* Deprecated: the `albert/abilities/required_capability` filter, `AbilitiesRegistry::resolve_required_capability()`, `AnnotationPresenter::heuristic_chips()`, and the `categories` and `tags` inputs of `albert/update-post`.
* New abilities: `albert/find-menus`, `albert/view-menu`, `albert/create-menu`, `albert/delete-menu`, `albert/add-menu-item`, `albert/update-menu-item`, `albert/move-menu-item`, `albert/remove-menu-item`, `albert/assign-menu-location`, `albert/unassign-menu-location`, `albert/find-comments`, `albert/view-comment`, `albert/reply-to-comment`, `albert/moderate-comment`, `albert/delete-comment`, `albert/find-revisions`, `albert/view-revision`, `albert/restore-revision`, `albert/search-content`, `albert/view-site-health`, `albert/update-media`, `albert/delete-media`, `albert/replace-post-text`, `albert/replace-page-text`, `albert/add-post-terms` and `albert/remove-post-terms`.
* Changed abilities: post and page create and update accept `slug`, `date`, `author`, `template` and `comment_status`, plus `sticky` and `format` for posts and `parent` and `menu_order` for pages. The block-edit abilities accept `edits` and `remove_tags`, and the add-block abilities accept `pattern`. Block trees include each leaf block's saved `html`. `albert/delete-user` requires `reassign`, `albert/delete-term` always deletes permanently, and the delete abilities return `deleted: false` when they trash.

**Credits**

* [Marinus Klasen](https://profiles.wordpress.org/mklasen/) for reporting Markdown saved as one paragraph, and that categories and tags could not be read or removed.

= 1.5.1 =
Security release. Closes a way someone could get an assistant connection to your site by tricking a person into approving it, and a number of smaller leaks. Update now.

**Security**

* **The approval screen can no longer be fooled about where access goes.** A sign-in address written to look like a trusted assistant could send the approval to someone else. Albert now refuses such addresses, also for apps that registered before this update. The approval screen shows the destination's site name prominently, with the full address underneath, and the approval is tied to that app and address.
* **Every assistant must prove it is the app that started the sign-in.** This was required for desktop and command-line apps only; it now applies to all of them. Claude, ChatGPT, Cursor and the other common assistants already do this.
* **Removing someone from the list of who may connect now takes effect at once.** Their assistants stop working straight away, including ones that were still signing in, and their upload links stop working too.
* **Access is checked against what Albert actually issued.** An access key is only accepted when it matches the person, the app, and a connection that is still live.
* **Password-protected posts and pages stay protected.** An assistant only sees their content, and only finds them by searching their text, when the person who connected it may edit them, as in WordPress itself. Password-protected block patterns are hidden the same way.
* **Tools you switch off stay off.** A specially crafted request could still reach tools switched off on the Abilities screen, also through an add-on's screen. Albert now recognises an assistant's request before anything else, and on WordPress 7.1 and later also refuses switched-off tools at the moment they would run.
* **Unpublished products stay private.** Viewing and finding WooCommerce products shows drafts, pending, private products and disabled variations only to people who may see them in WooCommerce. Viewing orders also needs WooCommerce's right to read private orders.
* **Media follows the post it belongs to.** Files attached to someone else's private or draft post are no longer shown, and files can only be attached to posts you may edit, both when uploading and through an upload link.
* **An assistant can no longer change anyone's email address,** including your own, just as it could never change a password. Change it on your profile instead.
* **Creating a user no longer hands the assistant a password-reset link.** WordPress emails the new person their own link to set a password.
* **Editing blocks in a post you may not edit now answers "not found"** without revealing anything about that post.
* **Safe mode holds more risky changes for your approval,** including changes to user roles, scheduled tasks, upload folders, a pending admin email change and the fallback security keys.
* **More of Albert's own settings are out of an assistant's reach,** including the site description it is given, how long connections and invitations last, the upload size limit and Albert's internal version markers.
* **Terms and categories follow WordPress's rights.** New tags and pattern categories are only created by people allowed to assign them, a refused save leaves no stray tags, and viewing a term works only for taxonomies WordPress exposes to apps.
* **On multisite, people from other sites in the network are no longer shown.**
* **A flood of unfinished sign-ups can no longer lock real assistants out.** Only connected apps count toward the limit; unfinished sign-ups have their own, larger limit and the oldest makes room.
* **Albert's sign-in pages can no longer be shown inside another website,** and unexpected errors no longer show internal details.

**Improvements**

* The "MCP endpoint is switched off" notice now shows only to people who can manage plugins, and no longer shows a server path. The sign-in warning shows only on Albert's screens and the Plugins screen.
* Uninstalling also removes the saved site location and Albert's cached data.
* The bundled MCP Adapter is updated to 0.7.1.

**Fixes**

* Deleting a user who owns content now asks who should take it over, instead of failing. A user without content is deleted straight away.
* Apps whose sign-in address uses three slashes or an underscore (such as `myapp:///callback`) can register again, and local `http://[::1]` addresses are accepted.

**Developer**

* New `Albert\Core\RestRequest`, so add-ons can recognise the route WordPress dispatches and an assistant's request. `albert/abilities/is_management_context` can no longer make an assistant's request a management context.
* `albert/create-user` returns `invitation_sent` instead of `password_reset_url`. `albert/delete-user` accepts `reassign`. Read abilities return `protected` for password-protected items.
* New filter `albert/oauth/max_pending_clients` (default 100). `albert/oauth/max_clients` now counts connected apps only.
* Removed: `BlockConverter`'s unused image sideloading (`$sideload_images`, `get_created_attachment_ids()`, `assign_parent_to_attachments()`).
* The translation template is now `languages/albert-ai-butler.pot`.

= Earlier versions =

Older releases are listed in `changelog.txt`, bundled with the plugin.

== Upgrade Notice ==

= 1.6.0 =
Requires WordPress 7.1 and PHP 8.2. Error codes lost their albert_ prefix, so check any code that compares them.

= 1.5.1 =
Security release: closes a way an approval could be sent to someone else, and stops several leaks to connected assistants. Update now.

== Privacy Policy ==

Albert runs entirely on your own site. It has no servers of its own and sends no data about your site or your usage to its makers or anyone else. The requests it makes are listed under External services below.

**What reaches your assistant.** Only the site context you choose to send, and the results of the actions you have switched on, for the person who approved the connection, within their WordPress permissions. Before a result leaves your site, Albert masks the personal details it recognises according to your privacy mode: Strict, Balanced or Off. That includes results from WordPress itself and other plugins' actions. New sites start on Strict. What your assistant's provider then does with that text is covered by their own privacy policy.

**What Albert keeps, in your own database:**

* **Connections**: the apps you approved and their tokens. Access tokens last an hour and refresh tokens 30 days; expired tokens are deleted daily, and apps that never finish connecting are removed after a week. Revoke any connection at any time.
* **Activity**: for each action, the two most recent results of each kind, with who ran it and any error message. No inputs or outputs are stored. Albert Premium keeps a fuller log, including what was sent and returned, for 90 days by default.
* **Safe mode requests**: a held request, with the input the assistant sent, until you decide or it expires (after an hour by default). Decided requests are deleted after 30 days.
* **Your settings and instructions**: what you choose on Albert's screens.

Uninstalling Albert removes its tables and settings. On a multisite network this currently covers the main site only.

== External services ==

Albert has no service of its own. It makes these requests, and only these:

* **The AI assistant you connect.** Your assistant's app (for example Claude or ChatGPT) signs in to your site and sends requests to it. What Albert returns goes to that assistant's provider, under their own terms and privacy policy. You choose the provider by connecting it; nothing is sent until you approve a connection.
* **Files your assistant asks to upload from a web address.** When an assistant uploads media by URL, your site downloads that file from the address the assistant gave, through WordPress's own safe download functions.
* **Embeds.** When an assistant writes a bare link to a video or post into content, WordPress's own oEmbed looks it up at that provider (for example YouTube), exactly as it does when you paste a link in the editor.
* **Site Health.** Albert checks that your site's own sign-in address can be reached, by requesting it from your own site.

== Source code ==

The admin screens' JavaScript is compiled from `assets/src/` with `npm run build`, and the Claude Desktop bridge from `tools/claude-desktop/`. Both are in [Albert's source](https://github.com/YourMark/albert-ai-butler).

== Credits ==

Developed by Mark Jansen, Your Mark Media
Website: https://yourmark.nl
Plugin URL: https://wordpress.org/plugins/albert-ai-butler/

Built with:
* league/oauth2-server for OAuth 2.0 implementation
* Model Context Protocol (MCP) for AI assistant connectivity
* WordPress Coding Standards
