=== Albert - The AI Butler ===
Contributors: albertai, mark-jansen
Tags: ai, mcp, oauth, claude, chatgpt
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

At your service — Albert connects AI assistants to your WordPress site so they can manage content, handle tasks, and keep things running smoothly.

== Description ==

Every well-run site deserves a proper butler. Albert stands at the door of your WordPress site, ready to welcome AI assistants like Claude and ChatGPT and put them to work.

= An open door for AI assistants =

Install Albert, grant an AI assistant access, and it can start managing your site — writing and editing posts, organizing media, moderating comments, and handling day-to-day tasks. No custom code, no complicated setup. Albert takes care of the introductions.

= A well-stocked service tray =

Albert presents AI assistants with a curated set of abilities — the tasks they're permitted to carry out on your site. When plugins like WooCommerce or Advanced Custom Fields are active, Albert extends the service with additional abilities tailored to each one.

= The butler manages the household =

Not every guest needs access to every room. From the admin panel, you decide exactly which abilities are on the tray and which stay behind closed doors. You remain in charge — Albert just makes sure your instructions are followed.

= Proper credentials at the door =

Every AI assistant must present proper credentials before Albert lets them in. Connections are secure, scoped, and fully under your control.

== Installation ==

= From WordPress.org =

1. Install Albert through the WordPress plugin directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Albert > Connections** and add yourself as an allowed user
4. Copy the MCP endpoint URL from the dashboard
5. Add the URL to Claude Desktop, ChatGPT, or another MCP-compatible assistant
6. Authorize when prompted — you're connected

= Manual Installation =

1. Download the plugin files
2. Upload the `albert-ai-butler` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Follow the setup steps above

= Connecting Claude Desktop =

1. In WordPress, go to **Albert > Connections** and add yourself as an allowed user
2. Copy the MCP endpoint URL from the Albert dashboard
3. In Claude Desktop, go to Settings > MCP Servers and add a new server
4. Paste the endpoint URL and save
5. Claude will open a browser window to authorize — log in and approve
6. Claude Desktop can now work with your WordPress site

= Connecting ChatGPT =

1. In WordPress, go to **Albert > Connections** and add yourself as an allowed user
2. Copy the MCP endpoint URL from the Albert dashboard
3. In ChatGPT, connect to the MCP endpoint using the URL
4. Authorize when prompted
5. ChatGPT can now work with your WordPress site

Full setup guide available at [Documentation](https://github.com/YourMark/albert-ai-butler/wiki)

== Frequently Asked Questions ==

= What AI assistants are supported? =

Albert works with **Claude Desktop**, **ChatGPT**, and any AI assistant that supports the Model Context Protocol (MCP). The connection process is the same for all: copy the endpoint URL, paste it into your assistant, and authorize.

= How hard is it to set up? =

Three steps: add yourself as an allowed user, copy the MCP endpoint URL, and paste it into your AI assistant. No technical knowledge required.

= Is my data secure? =

Yes. Albert uses OAuth 2.0 — the same standard used by Google, GitHub, and other major platforms. Your AI assistant receives a time-limited access token that automatically refreshes. No passwords are shared. All operations respect WordPress's built-in capability and role system, and you control exactly which abilities are enabled.

= What abilities are included? =

Albert ships with 25+ abilities covering WordPress core:

* **Posts** — Find, view, create, update, and delete posts
* **Pages** — Find, view, create, update, and delete pages
* **Users** — Find, view, create, update, and delete users
* **Media** — Find, view, upload media, and set featured images
* **Taxonomies** — Find taxonomies, find/view/create/update/delete terms

When WooCommerce is active, additional abilities are available for products, orders, and customers.

= Can I control what my AI assistant is allowed to do? =

Yes. The abilities page lets you toggle read and write permissions per content type. Write abilities are disabled by default — you choose exactly what to enable. All actions also respect WordPress user capabilities, so your AI assistant can never do more than the authorized user could do manually.

= Do I need WooCommerce? =

No. Albert works with WordPress core out of the box. WooCommerce abilities appear automatically when WooCommerce is active.

= Can I add custom abilities? =

Yes. Developers can register custom abilities using the WordPress Abilities API to expose any functionality to AI assistants. See the documentation at [GitHub](https://github.com/YourMark/albert-ai-butler/wiki).

= Does this work with multisite? =

Albert is designed for single-site installations. Multisite support is on the roadmap.

= What are the system requirements? =

* WordPress 6.9 or higher
* PHP 8.2 or higher (8.3+ recommended)
* MySQL 8.0+ or MariaDB 10.5+
* HTTPS (required for OAuth 2.0)

= Where can I get support? =

* Documentation: [GitHub Wiki](https://github.com/YourMark/albert-ai-butler/wiki)
* Support Forum: [WordPress.org support forums](https://wordpress.org/support/plugin/albert/)
* GitHub: [Report issues](https://github.com/YourMark/albert-ai-butler/issues)

== Screenshots ==

1. Albert dashboard with setup checklist and status overview
2. Abilities page — toggle read and write permissions per content type
3. Connections page — manage allowed users and active AI assistant connections
4. An active connection with Claude Desktop

== Changelog ==

= 1.0.0 =
Initial release.

* **MCP server** — Turns your WordPress site into an MCP endpoint. Copy the URL, paste it into Claude Desktop, ChatGPT, or any MCP-compatible assistant, authorize, and you're connected. No configuration files or developer setup needed.
* **OAuth 2.0 server** — Full authentication server with PKCE support, RSA-signed access tokens, automatic token refresh, and sessions that persist up to 30 days.
* **Abilities Manager** — Admin interface to toggle read and write permissions per content type. Write abilities disabled by default. All actions respect WordPress capabilities.
* **25+ WordPress abilities** — Posts, Pages, Users, Media, and Taxonomies with find, view, create, update, and delete operations.
* **WooCommerce abilities** — Products, Orders, and Customers when WooCommerce is active.
* **Connections management** — Control which users can connect AI assistants. View active connections, disconnect individual sessions, or end entire sessions with token revocation.
* **Dashboard** — Setup checklist, status overview, active connection count, and recent activity feed.
* **Extensible** — Register custom abilities with the WordPress Abilities API. Hookable architecture with filters and actions.

== Upgrade Notice ==

= 1.0.0 =
Initial release. Connect Claude Desktop, ChatGPT, and other MCP-compatible AI assistants to your WordPress site.

== Privacy Policy ==

Albert does not collect, store, or transmit any user data to external servers. All authentication tokens are stored locally in your WordPress database. When you authorize an AI assistant, that assistant will have access to perform actions on your WordPress site according to the permissions you grant. You control which abilities are enabled and can revoke any session at any time.

== Credits ==

Developed by Mark Jansen - Your Mark Media
Website: https://yourmark.nl
Plugin URL: https://github.com/YourMark/albert-ai-butler

Built with:
* league/oauth2-server for OAuth 2.0 implementation
* Model Context Protocol (MCP) for AI assistant connectivity
* WordPress Coding Standards
