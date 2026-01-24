# Scope
I am building a plugin for WordPress. This plugin focusses on a simple way for site owners to connect their WordPress installation with the WordPress MCP adapter to an AI assistant such as Claude Desktop (or maybe claude code), ChatGPT and hopefully later others that support MCP connections.
In this plugin I will be adding abilities using the WordPress Abilities API.

# Current situation
The plugin is currently in a basic state of alpha with the following functionality:
## Settings page:
- Manage users that can connect to the MCP
- Connect Claude or ChatGPT with a custom connecitor using OAuth.
- Managing OAuth sessions

## Abilities
These are listed on a separate abilities page. Abilities can be turned on and off. Meaning if an ability is turned off, it can not be discovered by an assistant via the MCP server.
- Managing posts and pages (CRUD)
- Listing posts and pages
- Managing users (CRUD)
- Media:
    -  Uploading media from a direct URL - since assistants do not have the ability to send binary
    - Setting a featured image.
- Taxonomies:
    - CRUD on terms
    - Listing of taxonomies

# Ideas
The idea for this plugin is to make it easy for users with just a few clicks to connect their website to an LLM assistant. Leveraging the power of an AI assistant and its abilities and WordPress. A few ideas are / can be:
- Connecting Google Drive for external writers
- SEO analysis (by using a popular plugin like Yoast SEO or even better: plugin agnostic)
- WooCommerce integration - Very important for web shop owners who then can manage some tasks very easily from a few commands. Think about:
    - Refunding an order
    - Create a coupon
    - ... etc etc
- Abilities are preferably plugin agnostic, but in some cases will have to be

# Monetizing
Of course I want to monetize this. I will be offering a free version of the plugin with basic abilities. But on top of that I want to create addons with more complex but useful extensions.

# Skills
Use the skills you have available for product research, creating an MVP and market research

# Relevant documentation
Abilities API: https://github.com/WordPress/abilities-api/tree/trunk/docs

# Analyse
Besides using your best abilities and skills to create a plan for this plugin, I want you to research and analyse the following:
- What abilities can be added in a free version of this plugin - Think of basic, but handy tasks
- What abilities can be added as premium functions
- Create both lists for WordPress core, but also for the following plugins
    - Advanced Custom Fields
    - Easy Digital Downloads
    - Yoast SEO
    - WooCommerce (very important)
- Or should we not focus on plugin-connections but make a package for example for metadata and detect whether ACF or other plugins are enabled and write abilities for a list of plugins we support for this?
- How can we monetize this idea. What extensions and how much could be charged for this.

# Plan
Based on the above, create a plan. Do's and don't's. What users benefit from this plugin. Generate ideas for abilities. Give a plan for monetization, an MVP and for further development.

Ask questions where you need more information if you need it.