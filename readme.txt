=== Albert - Your AI Butler for WP ===
Contributors: markjansen
Tags: ai, mcp, oauth, claude, chatgpt
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI assistants like Claude Desktop and ChatGPT to your WordPress site in minutes. Secure OAuth 2.0 and MCP built in.

== Description ==

Albert is the easiest way to connect AI assistants to your WordPress site. It turns your site into an MCP server — giving tools like **Claude Desktop** and **ChatGPT** direct, secure access to your content, users, and media through the **Model Context Protocol**.

No configuration files, no command line, no developer knowledge needed. Copy the endpoint URL, paste it into your AI assistant, authorize, and you're connected.

= Effortless MCP connection =

Albert provides a ready-to-use MCP endpoint the moment you activate the plugin. The endpoint URL is displayed on your dashboard and on the Connections page — copy it, paste it into any MCP-compatible AI assistant, and authorize through the browser. The entire flow is handled by Albert's built-in OAuth 2.0 server, so there are no API keys to manage, no secrets to store, and no manual token handling. Sessions refresh automatically and persist for up to 30 days.

Supported assistants include **Claude Desktop**, **ChatGPT**, and any other tool that implements the Model Context Protocol. The connection process is the same for all of them.

= Abilities Manager =

Albert ships with an admin interface that gives you full control over what your AI assistant is allowed to do. The Abilities page organizes all available actions into content type groups — Posts, Pages, Users, Media, Taxonomies — each with separate **read** and **write** toggles.

Write abilities are disabled by default. You decide exactly which operations to enable, and you can change this at any time. Every action your AI assistant takes also respects the WordPress capability system, so it can never exceed the permissions of the authorized user.

When WooCommerce is active, a dedicated WooCommerce Abilities page appears with additional groups for Products, Orders, and Customers.

The abilities system is extensible — developers can register custom abilities using the WordPress Abilities API to expose any functionality to AI assistants.

= 25+ built-in abilities =

Albert includes a comprehensive set of abilities for WordPress core:

**Posts** — Find, view, create, update, and delete posts with advanced filtering and pagination.

**Pages** — Find, view, create, update, and delete pages.

**Users** — Find, view, create, update, and delete users with role filtering.

**Media** — Find and view media items, upload media from URLs (sideloading), and set featured images.

**Taxonomies** — List all taxonomies, find/view/create/update/delete terms across any taxonomy.

**WooCommerce** (when active) — Search and view products (by name, SKU, status, price, popularity), browse orders, and look up customers.

= Connections & session management =

The Connections page lets administrators control who can connect AI assistants. Add allowed users, view all active connections with the client name and authorized user, and manage sessions individually. You can disconnect a single connection (the client reconnects automatically) or end an entire session (the client must re-authorize). A "Disconnect All" option is available when multiple connections are active.

= Secure by design =

* Full OAuth 2.0 server with PKCE support — no passwords or API keys shared
* RSA key signing for access tokens
* Time-limited tokens with automatic refresh
* All operations respect WordPress capabilities and user roles
* HTTPS required for all OAuth flows
* Per-user authorization — admins control who can connect

= For Developers =

* Modern PHP 8.2+ with strict type safety
* PSR-4 autoloading via Composer
* Hookable architecture with WordPress filters and actions
* Register custom abilities with the WordPress Abilities API
* Full internationalization support

Learn more at [albertwp.com](https://albertwp.com)

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
2. Upload the `albert` folder to `/wp-content/plugins/`
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

Full setup guide available at [albertwp.com/docs](https://albertwp.com/docs)

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

Yes. Developers can register custom abilities using the WordPress Abilities API to expose any functionality to AI assistants. See the documentation at [albertwp.com/docs](https://albertwp.com/docs).

= Does this work with multisite? =

Albert is designed for single-site installations. Multisite support is on the roadmap.

= What are the system requirements? =

* WordPress 6.9 or higher
* PHP 8.2 or higher (8.3+ recommended)
* MySQL 8.0+ or MariaDB 10.5+
* HTTPS (required for OAuth 2.0)

= Where can I get support? =

* Documentation: [albertwp.com/docs](https://albertwp.com/docs)
* Support Forum: [WordPress.org support forums](https://wordpress.org/support/plugin/albert/)
* GitHub: [Report issues](https://github.com/yourmark/albert/issues)

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
Plugin URL: https://albertwp.com

Built with:
* league/oauth2-server for OAuth 2.0 implementation
* Model Context Protocol (MCP) for AI assistant connectivity
* WordPress Coding Standards
