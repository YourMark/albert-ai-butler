# Extended Abilities

![Requires PHP](https://img.shields.io/badge/Requires%20PHP-8.2+-blue)
![Requires WordPress](https://img.shields.io/badge/Requires%20WordPress-6.9+-blue)
![Tested up to](https://img.shields.io/badge/Tested%20up%20to-6.9-blue)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green)

**Give your WordPress installation superpowers with the abilities API for AI assistants.**

## Description

Extended Abilities provides a powerful API that extends WordPress, WooCommerce, and other plugins with custom abilities that can be used by AI assistants. This plugin acts as a bridge between your WordPress site and AI-powered tools, enabling them to interact with and control various aspects of your website through a unified abilities interface.

Think of abilities as superpowers that you can grant to AI assistants - from managing content and products to handling complex workflows. The abilities API provides a standardized way for AI assistants to:

- Discover available actions they can perform on your site
- Execute those actions with proper authentication and authorization
- Receive structured responses they can understand and act upon
- Extend WordPress and WooCommerce functionality in AI-friendly ways

## Requirements

- **WordPress**: 6.9 or higher
- **PHP**: 7.4 or higher (8.1+ recommended)
- **WooCommerce**: 10.4 or higher (if WooCommerce integration is used)
- **MySQL**: 8.0+ or MariaDB 10.5+

## Installation

### Via WordPress Plugin Directory

1. Install through the WordPress plugin directory
2. Activate the plugin through the 'Plugins' menu in WordPress

### Manual Installation

1. Download the plugin files
2. Upload the `extended-abilities` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress

## Development

### Setup Development Environment

1. Clone this repository
2. Navigate to the plugin directory:
   ```bash
   cd wp-content/plugins/extended-abilities
   ```
3. Install dependencies:
   ```bash
   composer install
   ```

### Code Standards

This plugin follows WordPress Coding Standards. Check your code:

```bash
composer phpcs
```

Automatically fix code standards issues:

```bash
composer phpcbf
```

### Running Tests

```bash
composer test
```

## Architecture

The plugin uses a modern, modular architecture:

- **Singleton Pattern**: Main Plugin class ensures single instance
- **Hookable Interface**: Clean hook registration pattern
- **Component System**: Modular, extensible components
- **PSR-4 Autoloading**: Composer-based autoloading
- **Type Safety**: Full PHP type declarations

See `CLAUDE.md` for detailed architectural documentation.

## License

This plugin is licensed under GPL v2 or later.

## Credits

Developed by Mark Jansen - Your Mark Media
Website: https://yourmark.nl

## Changelog

### 0.0.1 - 2025-12-10
- Initial alpha release
- Core abilities API
- WordPress core abilities (Posts, Pages, Users, Media)
- Settings page with tab navigation
- Hookable interface pattern
- Component system
- Composer support
