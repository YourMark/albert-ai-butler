# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## About This Plugin

Albert is a WordPress plugin that exposes WordPress functionality to AI assistants through the MCP (Model Context Protocol). It provides:

- **Abilities API**: Register and expose WordPress operations as AI-callable tools
- **OAuth 2.0 Server**: Full OAuth implementation for secure AI assistant authentication
- **MCP Integration**: Connect AI assistants (Claude Desktop, etc.) to WordPress

## System Requirements

- **PHP**: 8.2+ (8.3+ recommended)
- **WordPress**: 6.9+
- **Database**: MySQL 8.0+ or MariaDB 10.5+
- **HTTPS**: Required for OAuth
- **WooCommerce**: 10.4+ (optional, for WooCommerce abilities)

## Directory Structure

```
albert/
├── albert.php                       # Main plugin bootstrap
├── composer.json                       # PSR-4 autoloading & dependencies
├── CLAUDE.md                           # This file
├── README.md                           # GitHub documentation
├── readme.txt                          # WordPress.org format
├── DEVELOPER_GUIDE.md                  # Developer documentation
│
├── src/                                # Source code (Albert\)
│   ├── Abstracts/
│   │   └── BaseAbility.php             # Base class for all abilities
│   │
│   ├── Contracts/
│   │   └── Interfaces/
│   │       ├── Ability.php             # Ability interface
│   │       └── Hookable.php            # Hook registration interface
│   │
│   ├── Core/
│   │   ├── Plugin.php                  # Main singleton, bootstraps everything
│   │   └── AbilitiesManager.php        # Registers abilities with WordPress
│   │
│   ├── Admin/
│   │   ├── Abilities.php               # Abilities admin page
│   │   ├── Settings.php                # Plugin settings page
│   │   └── UserSessions.php            # OAuth sessions management
│   │
│   ├── Abilities/
│   │   └── WordPress/
│   │       ├── Posts/
│   │       │   ├── ListPosts.php       # core/posts/list
│   │       │   ├── Create.php          # core/posts/create
│   │       │   ├── Update.php          # core/posts/update
│   │       │   └── Delete.php          # core/posts/delete
│   │       ├── Pages/
│   │       │   ├── ListPages.php       # core/pages/list
│   │       │   ├── Create.php          # core/pages/create
│   │       │   ├── Update.php          # core/pages/update
│   │       │   └── Delete.php          # core/pages/delete
│   │       ├── Users/
│   │       │   ├── ListUsers.php       # core/users/list
│   │       │   ├── Create.php          # core/users/create
│   │       │   ├── Update.php          # core/users/update
│   │       │   └── Delete.php          # core/users/delete
│   │       ├── Media/
│   │       │   ├── UploadMedia.php     # core/media/upload
│   │       │   └── SetFeaturedImage.php # core/media/set-featured-image
│   │       └── Taxonomies/
│   │           ├── ListTaxonomies.php  # core/taxonomies/list
│   │           ├── ListTerms.php       # core/terms/list
│   │           ├── CreateTerm.php      # core/terms/create
│   │           ├── UpdateTerm.php      # core/terms/update
│   │           └── DeleteTerm.php      # core/terms/delete
│   │
│   ├── MCP/
│   │   └── Server.php                  # MCP protocol handler
│   │
│   ├── OAuth/
│   │   ├── Database/
│   │   │   └── Installer.php           # Creates OAuth database tables
│   │   ├── Endpoints/
│   │   │   ├── OAuthController.php     # /oauth/authorize, /oauth/token
│   │   │   ├── OAuthDiscovery.php      # .well-known endpoints
│   │   │   ├── AuthorizationPage.php   # User consent UI
│   │   │   ├── ClientRegistration.php  # Dynamic client registration
│   │   │   └── Psr7Bridge.php          # PSR-7 ↔ WordPress conversion
│   │   ├── Entities/
│   │   │   ├── AccessTokenEntity.php
│   │   │   ├── AuthCodeEntity.php
│   │   │   ├── ClientEntity.php
│   │   │   ├── RefreshTokenEntity.php
│   │   │   ├── ScopeEntity.php
│   │   │   └── UserEntity.php
│   │   ├── Repositories/
│   │   │   ├── AccessTokenRepository.php
│   │   │   ├── AuthCodeRepository.php
│   │   │   ├── ClientRepository.php
│   │   │   ├── RefreshTokenRepository.php
│   │   │   └── ScopeRepository.php
│   │   └── Server/
│   │       ├── AuthorizationServerFactory.php
│   │       ├── ResourceServerFactory.php
│   │       ├── KeyManager.php          # RSA key management
│   │       └── TokenValidator.php      # Validates Bearer tokens
│
├── assets/
│   ├── css/
│   │   └── admin-settings.css
│   └── js/
│       └── admin-settings.js
│
├── tests/
│   ├── bootstrap.php
│   ├── bootstrap-unit.php
│   ├── TestCase.php
│   ├── Unit/
│   │   └── SampleTest.php
│   └── Integration/
│       ├── PluginTest.php
│       └── AbilitiesManagerTest.php
│
├── .claude/
│   └── media-upload-discussion.md      # Ongoing discussion notes
│
└── vendor/                             # Composer dependencies (gitignored)
```

## Architecture Overview

### Core Components

#### 1. Plugin Bootstrap (`src/Core/Plugin.php`)
Singleton that initializes all components:
- Registers admin pages (Abilities, Settings, Sessions)
- Initializes OAuth endpoints
- Registers MCP server
- Registers abilities on `init` hook

#### 2. Abilities System
Abilities are WordPress operations exposed to AI assistants.

**BaseAbility** (`src/Abstracts/BaseAbility.php`):
- Abstract class all abilities extend
- Defines: `$id`, `$label`, `$description`, `$input_schema`, `$output_schema`
- Implements `register_ability()` to register with WordPress
- Abstract `execute(array $args)` method for implementation

**AbilitiesManager** (`src/Core/AbilitiesManager.php`):
- Collects and registers all abilities
- Calls `wp_register_ability()` for each enabled ability

**Creating a New Ability:**
```php
namespace Albert\Abilities\WordPress\Example;

use Albert\Abstracts\BaseAbility;
use WP_Error;

class MyAbility extends BaseAbility {
    public function __construct() {
        $this->id          = 'core/example/my-ability';
        $this->label       = __( 'My Ability', 'albert' );
        $this->description = __( 'Description of what it does.', 'albert' );
        $this->category    = 'core';
        $this->group       = 'example';

        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'param1' => [
                    'type'        => 'string',
                    'description' => 'Parameter description',
                ],
            ],
            'required'   => [ 'param1' ],
        ];

        $this->meta = [
            'mcp' => [ 'public' => true ],
        ];

        parent::__construct();
    }

    public function check_permission(): bool {
        return current_user_can( 'edit_posts' );
    }

    public function execute( array $args ): array|WP_Error {
        // Implementation
        return [ 'result' => 'success' ];
    }
}
```

Then register in `Plugin::register_abilities()`:
```php
$this->abilities_manager->add_ability( new MyAbility() );
```

#### 3. OAuth 2.0 Server
Full OAuth 2.0 implementation using `league/oauth2-server`.

**Endpoints:**
| Endpoint | Purpose |
|----------|---------|
| `GET /wp-json/albert/v1/oauth/authorize` | Authorization request |
| `POST /wp-json/albert/v1/oauth/authorize` | User consent submission |
| `POST /wp-json/albert/v1/oauth/token` | Token exchange |
| `POST /wp-json/albert/v1/oauth/register` | Dynamic client registration |
| `GET /.well-known/oauth-authorization-server` | Server metadata (RFC 8414) |
| `GET /wp-json/albert/v1/oauth/metadata` | Alternative metadata endpoint |

**Token Validation:**
```php
use Albert\OAuth\Server\TokenValidator;

// In a REST endpoint permission callback:
$user = TokenValidator::validate_request( $request );
if ( is_wp_error( $user ) ) {
    return $user;
}
wp_set_current_user( $user->ID );
```

#### 4. MCP Server (`src/MCP/Server.php`)
Handles MCP protocol communication with AI assistants. Authenticated via OAuth.

### Current Abilities

| ID | Description | Group |
|----|-------------|-------|
| `core/posts/list` | List posts with filters | posts |
| `core/posts/create` | Create a new post | posts |
| `core/posts/update` | Update existing post | posts |
| `core/posts/delete` | Delete a post | posts |
| `core/pages/list` | List pages | pages |
| `core/pages/create` | Create a page | pages |
| `core/pages/update` | Update a page | pages |
| `core/pages/delete` | Delete a page | pages |
| `core/users/list` | List users | users |
| `core/users/create` | Create a user | users |
| `core/users/update` | Update a user | users |
| `core/users/delete` | Delete a user | users |
| `core/media/upload` | Sideload media from URL | media |
| `core/media/set-featured-image` | Set post featured image | media |
| `core/taxonomies/list` | List taxonomies | taxonomies |
| `core/terms/list` | List taxonomy terms | taxonomies |
| `core/terms/create` | Create a term | taxonomies |
| `core/terms/update` | Update a term | taxonomies |
| `core/terms/delete` | Delete a term | taxonomies |

## Development Commands

```bash
# Install dependencies
composer install

# Check coding standards
composer phpcs

# Auto-fix coding standards
composer phpcbf

# Run tests
composer test

# Activate plugin
wp plugin activate albert
```

## Development Guidelines

### Code Standards
- Follow WordPress Coding Standards (enforced by PHPCS)
- Use PHP 7.4+ type declarations
- Implement `Hookable` interface for components with hooks

### JavaScript
- **Never use jQuery** - use vanilla ES6+ JavaScript
- Use module pattern for organization
- Use `fetch` API for HTTP requests

### Security
- Validate and sanitize all input
- Use capability checks (`current_user_can()`)
- Use nonces for form submissions
- OAuth tokens for API authentication

### Version Control
- **Never commit without explicit request**
- **Never bump version without approval**
- Run `composer phpcs` before committing

## Ongoing Work

See `.claude/media-upload-discussion.md` for discussion about:
- MCP binary transport limitations
- Local file upload challenges
- Potential solutions for media uploads from AI assistants
