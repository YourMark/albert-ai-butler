=== AI Bridge for WordPress ===
Contributors: markjansen
Tags: ai, mcp, oauth, claude, automation, api
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to AI assistants with secure OAuth 2.0 authentication and the Model Context Protocol (MCP).

== Description ==

AI Bridge for WordPress is the secure bridge between your WordPress site and AI assistants like Claude. Using industry-standard OAuth 2.0 and the Model Context Protocol (MCP), AI Bridge enables AI assistants to safely interact with your WordPress content, users, and media.

= Key Features =

* **Full OAuth 2.0 Server**: Industry-standard authentication for AI assistants
* **MCP Integration**: Native support for the Model Context Protocol
* **Core WordPress Abilities**: Manage posts, pages, users, media, and taxonomies
* **Secure by Default**: All operations require proper authentication and authorization
* **Extensible API**: Developers can add custom abilities for any functionality
* **Modern Architecture**: Built with PHP 7.4+ type safety and WordPress coding standards

= What is MCP? =

The Model Context Protocol (MCP) is an open standard that enables AI assistants to securely connect to external data sources and tools. AI Bridge implements MCP to allow AI assistants like Claude to interact with your WordPress site in a standardized, secure way.

= Use Cases =

* **Content Management**: Let AI assistants help you create, update, and organize WordPress content
* **Workflow Automation**: Automate complex content workflows with AI assistance
* **Media Management**: AI-assisted media uploads and organization
* **User Management**: Safely delegate user management tasks to AI assistants
* **Custom Integrations**: Extend WordPress functionality with custom AI-accessible abilities

= Included Abilities =

**Posts:**
* List posts with advanced filtering
* Create new posts
* Update existing posts
* Delete posts

**Pages:**
* List pages
* Create new pages
* Update existing pages
* Delete pages

**Users:**
* List users with role filtering
* Create new users
* Update user information
* Delete users

**Media:**
* Upload media from URL (sideloading)
* Set featured images

**Taxonomies:**
* List all taxonomies
* List taxonomy terms
* Create new terms
* Update existing terms
* Delete terms

= Security =

AI Bridge takes security seriously:

* All API requests require OAuth 2.0 authentication
* Per-user authorization with WordPress capability checks
* All operations respect WordPress permissions
* Secure RSA key management for token signing
* HTTPS required for OAuth flows
* No sensitive data stored in plain text

= For Developers =

AI Bridge is built for extensibility:

* Clean, modern PHP architecture with full type safety
* PSR-4 autoloading via Composer
* Comprehensive developer documentation
* Hookable architecture with WordPress filters and actions
* Easy to add custom abilities
* Full internationalization support

Learn more at [aibridgewp.com](https://aibridgewp.com)

== Installation ==

= From WordPress.org =

1. Install AI Bridge for WordPress through the WordPress plugin directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **AI Bridge** in your admin menu
4. Configure your settings and connect your AI assistant

= Manual Installation =

1. Download the plugin files
2. Upload the `ai-bridge` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Navigate to **AI Bridge** in your admin menu

= Connecting Claude Desktop =

1. In WordPress, go to **AI Bridge > Settings**
2. Note your site's OAuth endpoint URL
3. In Claude Desktop, add an MCP server configuration pointing to your WordPress site
4. Authorize Claude when prompted
5. Claude can now interact with your WordPress site

Full setup guide available at [aibridgewp.com/docs](https://aibridgewp.com/docs)

== Frequently Asked Questions ==

= What AI assistants are supported? =

AI Bridge works with any AI assistant that supports the Model Context Protocol (MCP), including Claude Desktop. As MCP adoption grows, more AI assistants will be supported.

= Is my data secure? =

Yes. AI Bridge uses OAuth 2.0, the same authentication standard used by Google, Facebook, and other major platforms. All operations require proper authentication and respect WordPress's built-in permission system.

= Do I need WooCommerce? =

No. AI Bridge works with WordPress core functionality. WooCommerce abilities will be available in a future extension.

= Can I add custom abilities? =

Yes! AI Bridge is designed for extensibility. Developers can easily add custom abilities to expose any WordPress functionality to AI assistants. See the documentation at [aibridgewp.com/docs](https://aibridgewp.com/docs)

= Does this work with multisite? =

AI Bridge is primarily designed for single-site installations. Multisite support is on our roadmap.

= What are the system requirements? =

* WordPress 6.9 or higher
* PHP 7.4 or higher (8.1+ recommended)
* MySQL 8.0+ or MariaDB 10.5+
* HTTPS (required for OAuth 2.0)

= Where can I get support? =

* Documentation: [aibridgewp.com/docs](https://aibridgewp.com/docs)
* Support Forum: [WordPress.org support forums](https://wordpress.org/support/plugin/ai-bridge/)
* GitHub: [Report issues](https://github.com/yourmark/ai-bridge/issues)

= How can I contribute? =

We welcome contributions! Visit our [GitHub repository](https://github.com/yourmark/ai-bridge) to report issues, submit pull requests, or contribute to documentation.

== Screenshots ==

1. AI Bridge dashboard showing enabled abilities
2. OAuth authorization screen for AI assistants
3. Settings page with external URL configuration
4. Active OAuth sessions management
5. Ability details and configuration

== Changelog ==

= 1.0.0 - 2025-01-23 =
* Initial release
* Full OAuth 2.0 server implementation
* MCP (Model Context Protocol) integration
* Core WordPress abilities: Posts, Pages, Users, Media, Taxonomies
* Admin interface for managing abilities and sessions
* Secure authentication and authorization
* Extensible ability system for developers
* Full internationalization support

== Upgrade Notice ==

= 1.0.0 =
Initial release of AI Bridge for WordPress. Connect your site to AI assistants securely using OAuth 2.0 and MCP.

== Privacy Policy ==

AI Bridge for WordPress does not collect, store, or transmit any user data to external servers. All authentication tokens are stored locally in your WordPress database. When you authorize an AI assistant, that assistant will have access to perform actions on your WordPress site according to the permissions you grant.

== Credits ==

Developed by Mark Jansen - Your Mark Media
Website: https://yourmark.nl
Plugin URL: https://aibridgewp.com

Built with:
* league/oauth2-server for OAuth 2.0 implementation
* WordPress Coding Standards
* Love for AI and automation
