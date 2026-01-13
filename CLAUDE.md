# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# About this plugin

This is a plugin for production environments and will be public. The purpose of this plugin is to extend the abilities of WordPress, WooCommerce and other plugins with the abilities API.

## System Requirements & Environment

### Required Specifications
- **PHP Version**: 7.4 or greater (prefer PHP 8.1+ for optimal performance)
- **Database**: MySQL 8.0+ OR MariaDB 10.5+
- **Web Server**: Apache with mod_rewrite module enabled
- **Protocol**: HTTPS support required for all production environments
- **WordPress**: Requires at least 6.9
- **WooCommerce**: If the WooCommerce option is used, it should work with at least WooCommerce 10.4

### Development Environment Standards
- **Local Server**: Laravel Herd with Nginx
- **PHP Version**: Differs for testing, but should be at least 7.4
- **Debug Settings**: Enable WP_DEBUG and WP_DEBUG_LOG in development
- **CLI Tools**: Use wp-cli for WordPress operations
- **Code Standards**: PHPCS with WordPress coding standards (required)

## Common Development Commands

### WordPress CLI (WP-CLI)
```bash
# Plugin management
wp plugin list                    # List installed plugins
wp plugin activate [plugin-name]  # Activate a plugin
wp plugin deactivate [plugin-name] # Deactivate a plugin

# Theme management  
wp theme list                     # List installed themes
wp theme activate [theme-name]    # Activate a theme

# Database operations
wp db export backup.sql           # Export database
wp db import backup.sql           # Import database

# WordPress updates
wp core update                    # Update WordPress core
wp plugin update --all            # Update all plugins
```

### Code Quality & Validation
```bash
# WordPress Coding Standards (PHPCS)
phpcs --standard=WordPress [file/directory]           # Check coding standards
phpcbf --standard=WordPress [file/directory]          # Fix coding standards

# Plugin/theme validation
wp plugin path [plugin-name]                          # Get plugin directory path
wp theme path [theme-name]                            # Get theme directory path
```

### Local Development with Valet
```bash
valet park                        # Park current directory for Valet
valet restart                     # Restart Valet services
valet links                       # List all Valet sites
valet secure claudecode           # Enable HTTPS for this site
```

### Plugin Development Workflow
```bash
# Activate/deactivate plugins during development
wp plugin activate header-notice-procedural
wp plugin deactivate header-notice-procedural
wp plugin activate header-notice-oop
wp plugin deactivate header-notice-oop

# Test plugin functionality
wp option get hnp_notice_title    # Check procedural plugin options
wp option list --search="header*" # List all header notice options
```

### Debugging Commands
```bash
# WordPress debug log monitoring
tail -f wp-content/debug.log      # Monitor debug log in real-time
wp config get WP_DEBUG            # Check debug mode status
wp config set WP_DEBUG true       # Enable debug mode
```

## Development Philosophy

### Keep It Simple (KISS Principle)
- **NEVER over-complicate solutions** - choose the simplest approach that works
- Break complex problems into simple, manageable steps
- Use WordPress native functions before custom solutions
- Prefer readable code over clever code
- Document the "why" behind complex decisions

### Step-by-Step Approach
- Always break tasks into small, actionable steps
- Complete one step fully before moving to the next
- Test each step individually when possible
- Provide clear explanations for each implementation decision

## WordPress Core Principles

### Fundamental Rules
- **NEVER modify WordPress core files** - use hooks, filters, and the Plugin API instead
- **ALWAYS follow WordPress coding standards** - use PHPCS with WordPress ruleset
- **Keep solutions simple** - don't over-engineer or add unnecessary complexity
- Prioritize security, performance, and accessibility in all implementations
- Use WordPress native functions over custom solutions when available

### Plugin API & Extensibility
- Utilize WordPress' robust Plugin API for all customizations
- Reference the Plugin Developer Handbook for best practices
- Create child themes instead of modifying parent themes directly
- Use WordPress hooks (actions/filters) for all functionality extensions

## Extended Abilities Plugin Architecture

The Extended Abilities plugin uses a modern, scalable object-oriented architecture designed for production use and extensibility.

### High-Level Architecture
This plugin provides an abilities API for AI assistants to interact with WordPress, WooCommerce, and other plugins through a unified interface.

**Core Design Principles:**
- Modern PHP 7.4+ with full type declarations
- PSR-4 autoloading via Composer
- Singleton pattern for plugin initialization
- Hookable interface for clean, consistent hook registration
- Component-based architecture for modularity and extensibility
- No frontend components (backend/admin only)

### Directory Structure
```
extended-abilities/
├── extended-abilities.php         # Main plugin bootstrap file
├── composer.json                  # Composer configuration with PSR-4 autoloading
├── README.md                      # GitHub documentation (development)
├── readme.txt                     # WordPress.org plugin repository format
├── LICENSE                        # GPL v2 license
├── .gitignore                     # Git ignore rules
├── CLAUDE.md                      # This file - AI assistant guidance
├── src/                           # Source code (PSR-4: ExtendedAbilities\)
│   ├── Contracts/
│   │   └── Interfaces/
│   │       └── Hookable.php       # Interface for hook registration
│   ├── Core/
│   │   └── Plugin.php             # Main plugin singleton class
│   └── Admin/                     # Admin-specific components
├── assets/
│   ├── css/                       # Stylesheets
│   └── js/                        # JavaScript files
├── languages/                     # Translation files (.pot, .po, .mo)
└── vendor/                        # Composer dependencies (gitignored)
```

### Core Architecture Components

#### 1. Main Plugin File (`extended-abilities.php`)
The bootstrap file that:
- Defines plugin constants (VERSION, PLUGIN_FILE, PLUGIN_DIR, PLUGIN_URL, PLUGIN_BASENAME)
- Loads Composer autoloader
- Initializes the Plugin singleton on `plugins_loaded` hook
- Registers activation/deactivation hooks
- Handles initialization errors gracefully with admin notices

#### 2. Plugin Singleton (`src/Core/Plugin.php`)
The main plugin class using singleton pattern:
- **Singleton Pattern**: Ensures only one instance exists via `get_instance()`
- **Component Registration**: Manages hookable components via `add_component()` and `register_components()`
- **Hook Registration**: Automatically calls `register_hooks()` on all registered components
- **Internationalization**: Loads text domain for translations
- **Lifecycle Hooks**: Provides `activate()` and `deactivate()` static methods
- **Extensibility**: Provides action hooks for external extension

**Key Methods:**
- `get_instance()`: Returns singleton instance
- `init()`: Initializes plugin and registers components
- `load_textdomain()`: Loads translation files
- `register_components()`: Register all plugin components (override or filter)
- `add_component(Hookable $component)`: Add a component dynamically
- `activate()`: Plugin activation callback
- `deactivate()`: Plugin deactivation callback

#### 3. Hookable Interface (`src/Contracts/Interfaces/Hookable.php`)
Interface that all components must implement:
```php
interface Hookable {
    public function register_hooks(): void;
}
```

**Purpose:**
- Provides consistent pattern for registering WordPress hooks across all components
- Components implement this interface and define all their hooks in `register_hooks()`
- Main Plugin class automatically calls `register_hooks()` on each registered component

**Example Component:**
```php
namespace ExtendedAbilities\Admin;

use ExtendedAbilities\Contracts\Interfaces\Hookable;

class Settings implements Hookable {
    public function register_hooks(): void {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_settings_page(): void {
        // Implementation
    }

    public function register_settings(): void {
        // Implementation
    }
}
```

### Extensibility & Hooks

The plugin provides several WordPress action hooks for extensibility:

#### Plugin Lifecycle Hooks
- `extended_abilities_initialized` - Fires after plugin initialization
- `extended_abilities_activated` - Fires on plugin activation
- `extended_abilities_deactivated` - Fires on plugin deactivation

#### Component Registration Hook
- `extended_abilities_register_components` - Allows external code to register additional components

**Example Usage:**
```php
// Register a custom component from another plugin or theme
add_action( 'extended_abilities_register_components', function( $plugin ) {
    $plugin->add_component( new My_Custom_Ability() );
} );
```

### Composer Configuration

The plugin uses Composer for autoloading and dependency management:

**PSR-4 Autoloading:**
- Namespace: `ExtendedAbilities\`
- Base directory: `src/`

**Development Dependencies:**
- PHPUnit for unit testing
- PHP_CodeSniffer with WordPress Coding Standards
- WPCS for WordPress-specific rules

**Composer Scripts:**
- `composer phpcs` - Check coding standards
- `composer phpcbf` - Fix coding standards automatically
- `composer test` - Run PHPUnit tests

### Adding New Components

To add new functionality:

1. **Create a component class** in appropriate namespace (e.g., `src/Admin/`)
2. **Implement Hookable interface** with `register_hooks()` method
3. **Register the component** in `Plugin::register_components()` or via the `extended_abilities_register_components` filter
4. **Add any necessary assets** to `assets/css/` or `assets/js/`

**Example:**
```php
// In src/Admin/AbilitiesManager.php
namespace ExtendedAbilities\Admin;

use ExtendedAbilities\Contracts\Interfaces\Hookable;

class AbilitiesManager implements Hookable {
    public function register_hooks(): void {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'rest_api_init', array( $this, 'register_api_routes' ) );
    }

    public function add_menu(): void { /* ... */ }
    public function register_api_routes(): void { /* ... */ }
}

// In src/Core/Plugin.php register_components() method:
if ( is_admin() ) {
    $this->add_component( new \ExtendedAbilities\Admin\AbilitiesManager() );
}
```

## Code Quality Standards

### Security Requirements (All Plugins)
- Validate and sanitize ALL user input
- Use prepared statements for database queries
- Implement proper user capability checks
- Never trust data from $_GET, $_POST, or $_REQUEST without validation
- Use WordPress security functions (wp_nonce_field, current_user_can, etc.)

### Performance Standards (All Plugins)
- Implement WordPress object caching for expensive operations
- Use transients for temporary data storage
- Optimize database queries to avoid performance bottlenecks
- Minimize HTTP requests through proper asset management

### JavaScript Standards (All Plugins)
- **NEVER use jQuery** - always use modern vanilla JavaScript
- Use ES6+ features (const/let, arrow functions, template literals, destructuring)
- Organize code using the module pattern for separation of concerns
- Use async/await for asynchronous operations (fetch, clipboard API, etc.)
- Use event delegation where appropriate for dynamic content
- Use `document.querySelectorAll()` and `element.closest()` for DOM traversal
- Use native `FormData` and `fetch` API for AJAX requests
- No jQuery dependency in `wp_enqueue_script()` calls for admin scripts

**Example Module Pattern:**
```javascript
const MyModule = {
    init() {
        this.bindEvents();
    },

    bindEvents() {
        document.addEventListener( 'click', ( e ) => {
            if ( e.target.closest( '.my-button' ) ) {
                this.handleClick( e );
            }
        } );
    },

    async handleClick( e ) {
        const response = await fetch( '/api/endpoint' );
        const data = await response.json();
        // Handle response
    },
};

// Initialize when DOM is ready
if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', () => MyModule.init() );
} else {
    MyModule.init();
}
```

## Testing & Deployment

### Quality Assurance
- Test across multiple WordPress versions
- Validate with WordPress coding standards tools (PHPCS)
- Use staging environments for testing
- Implement proper backup strategies

### Documentation Requirements
- Document all custom hooks and filters
- Maintain README files for each plugin
- Include installation and configuration instructions
- Document any third-party dependencies

## Resources & Support

### Primary Resources
- **WordPress Codex**: Comprehensive WordPress documentation
- **Plugin Developer Handbook**: Plugin development best practices

### Support Channels
- **HelpHub**: Encyclopedia of all things WordPress
- **WordPress Blog**: Latest updates and news
- **WordPress Planet**: News aggregator for WordPress blogs
- **WordPress Support Forums**: Community support and troubleshooting
- **WordPress IRC**: Real-time chat support (#wordpress on irc.libera.chat)

### Development Tools & Standards
- **PHPCS**: Required for all code - use WordPress coding standards ruleset
- **Xdebug**: Available for debugging (PHP 8.1)
- **WP-CLI**: For WordPress management and automation
- **Valet**: Local development server with Nginx
- **Code Validation**: Always run PHPCS before committing code

## Development Workflow for Extended Abilities

### Initial Setup
1. Run `composer install` to install development dependencies
2. Activate the plugin via WP-CLI or WordPress admin
3. Begin adding components by implementing the Hookable interface

### Adding New Features
1. Create a new class in the appropriate namespace (`src/Admin/`, `src/Core/`, etc.)
2. Implement the `Hookable` interface
3. Register the component in `Plugin::register_components()`
4. Write tests for the new component (recommended)
5. Run `composer phpcs` to check coding standards
6. Run `composer phpcbf` to auto-fix any issues

### Testing the Plugin
```bash
# Activate plugin
wp plugin activate extended-abilities

# Check for errors
wp plugin list
tail -f wp-content/debug.log

# Test functionality
# Add your specific test commands here
```

### Version Control
- The `.gitignore` file excludes vendor/, IDE files, and build artifacts
- **NEVER commit changes unless explicitly asked by the user** - only make changes and stage them, let the user decide when to commit
- **NEVER bump version numbers without being asked** - version changes require explicit user approval
- When asked to commit, use descriptive commit messages
- Keep the CLAUDE.md file updated when architecture changes

## Final Notes

- This plugin uses a modern OOP architecture with component-based design
- All components must implement the Hookable interface for consistency
- Follow WordPress coding standards at all times
- Use the Composer scripts for quality assurance before committing
- Document any new hooks or filters you add for extensibility
- Keep security, performance, and maintainability as top priorities
