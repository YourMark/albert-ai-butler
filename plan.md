# Albert - Development Plan

**Status**: Active Development - Pre-Launch
**Version**: 1.0.0-alpha
**Last Updated**: January 24, 2026

---

## Project Overview

Albert is a plugin that enables site owners to connect their WordPress installation to AI assistants (Claude Desktop, ChatGPT, etc.) via the Model Context Protocol (MCP). The plugin uses OAuth 2.0 for secure authentication and the WordPress Abilities API to expose WordPress operations as AI-callable tools.

---

## Current Status

### ✅ Completed (Phase 1-3)

#### **Core Infrastructure**
- ✅ Complete rebranding from "Albert" to "Albert"
- ✅ OAuth 2.0 server implementation (RFC 6749, RFC 7591, RFC 8414)
- ✅ MCP server integration with WordPress Abilities API
- ✅ Database schema: 4 OAuth tables (clients, access_tokens, refresh_tokens, auth_codes)
- ✅ REST API endpoints: `/wp-json/albert/v1/*`
- ✅ OAuth discovery: `/.well-known/oauth-authorization-server`

#### **Admin Interface**
- ✅ **Dashboard Page** - Primary landing page with:
  - MCP endpoint URL with copy-to-clipboard
  - Quick setup guide
  - Status overview (OAuth server, MCP endpoint, connections, abilities)
  - Recent activity feed
  - Professional 2-column card layout

- ✅ **Abilities Page** - Toggle 19 core abilities on/off:
  - Posts (list, create, update, delete)
  - Pages (list, create, update, delete)
  - Users (list, create, update, delete)
  - Media (upload from URL, set featured image)
  - Taxonomies (list taxonomies, list/create/update/delete terms)

- ✅ **Connections Page** - Manage active AI assistant connections:
  - Card-based grid layout
  - Connection details (app name, user, connected time, expiration)
  - Disconnect individual or all connections
  - Beautiful empty state

- ✅ **Settings Page** - Configure MCP and OAuth:
  - Connection URL management
  - Allowed users management
  - OAuth configuration

#### **Menu Structure**
- ✅ Top-level menu at position 80 (after Settings)
- ✅ Icon: `dashicons-networking`
- ✅ Submenus: Dashboard → Abilities → Connections → Settings

#### **Code Quality**
- ✅ PSR-4 autoloading
- ✅ WordPress Coding Standards compliant (PHPCS clean)
- ✅ PHPStan level 5 passing
- ✅ Unit and integration tests
- ✅ PHP 8.2+ requirement
- ✅ WordPress 6.9+ requirement

---

## Phase 4: WordPress.org Preparation (Next)

### Overview
Prepare all assets, documentation, and verification for WordPress.org submission.

### Tasks

#### **Task #14: Verify Slug Availability** ✅ **COMPLETE**
- ✅ Searched WordPress.org for "albert" slug
- ✅ No existing plugins with this name
- ✅ No naming conflicts or similar names found
- **Result**: Slug is **available** for use

#### **Task #15: Create Plugin Banner** 🎨 (Design Phase)
- **Specs**: 1544x500px PNG/JPG
- **Design**: Clean, modern, professional
- **Content**: "Albert" + tagline
- **Method**: SVG design → convert to PNG
- **Note**: Can be designed using SVG code

#### **Task #16: Create Plugin Icon** 🎨 (Design Phase)
- **Specs**: 256x256px and 128x128px PNG
- **Design**: Network/connection theme icon
- **Style**: Matches dashicons-networking aesthetic
- **Method**: SVG design → convert to PNG
- **Note**: Can be designed using SVG code

#### **Task #17: Create Screenshots** ✅ **COMPLETE**
- ✅ Dashboard page captured (1590x773px)
- ✅ Abilities page captured (1590x773px)
- ✅ Connections page captured with empty state (1590x773px)
- ✅ Settings page captured (1590x773px)
- ✅ Documentation created: `.wordpress.org/SCREENSHOTS.md`
- **Status**: 4 core screenshots captured and documented
- **Optional**: Authorization flow, active connections (can be added later)

#### **Task #18: Test Fresh Installation** 🧪 (Ready)
- Test on clean WordPress install
- Verify database table creation
- Check for PHP errors/warnings
- Test complete OAuth flow
- Verify all admin pages render
- Test activation/deactivation

#### **Task #19: Setup Domain & Landing Page** 🌐 (Requires Purchase)
- **Primary domain**: albertwp.com
- **Redirects**: albert4wp.com, albertforwp.com
- **Content**:
  - Hero section
  - Feature highlights
  - Quick setup guide
  - Screenshots/demo
  - Documentation links
  - Support information

#### **Task #20: Prepare Submission Package** 📦 (Ready)
- Validate readme.txt
- Prepare assets for SVN
- Final code review
- WordPress.org guidelines compliance check
- GPL license verification

---

## Core Abilities (v1.0)

### Posts (4 abilities)
- `core/posts/list` - List posts with filters
- `core/posts/create` - Create new post
- `core/posts/update` - Update existing post
- `core/posts/delete` - Delete post

### Pages (4 abilities)
- `core/pages/list` - List pages
- `core/pages/create` - Create new page
- `core/pages/update` - Update existing page
- `core/pages/delete` - Delete page

### Users (4 abilities)
- `core/users/list` - List users
- `core/users/create` - Create new user
- `core/users/update` - Update user
- `core/users/delete` - Delete user

### Media (2 abilities)
- `core/media/upload` - Upload media from URL (sideload)
- `core/media/set-featured-image` - Set post featured image

### Taxonomies (5 abilities)
- `core/taxonomies/list` - List available taxonomies
- `core/terms/list` - List terms in taxonomy
- `core/terms/create` - Create new term
- `core/terms/update` - Update existing term
- `core/terms/delete` - Delete term

**Total: 19 core abilities**

---

## Monetization Strategy

### Free Version (v1.0)
**Plugin**: Albert (WordPress.org)
- All 19 core abilities
- OAuth 2.0 authentication
- Unlimited AI connections
- Dashboard and admin interface
- Community support (WordPress.org forums)

### Premium Extensions (Future)

#### **E-commerce Pack** - €79/year
**Target**: WooCommerce store owners
- Order management (view, update, refund)
- Product management (create, update, inventory)
- Customer management
- Coupon creation
- Sales analytics
- Revenue reporting
- Low stock alerts

#### **SEO Pack** - €49/year
**Target**: Content creators, agencies
- SEO analysis (Yoast/RankMath integration)
- Keyword research integration
- Content optimization suggestions
- Meta data management
- Schema markup management
- Sitemap management

#### **Content Pack** - €39/year
**Target**: Publishers, bloggers
- Advanced Custom Fields integration
- Bulk content operations
- Content scheduling
- Revision management
- Content templates
- Translation management (WPML/Polylang)

#### **Automation Pack** - €59/year
**Target**: Developers, power users
- Webhook triggers
- Scheduled tasks
- Conditional logic
- Multi-step workflows
- External API integrations
- Google Drive/Dropbox sync

---

## Technical Architecture

### Plugin Structure
```
albert/
├── albert.php              # Bootstrap
├── src/
│   ├── Core/
│   │   ├── Plugin.php         # Singleton, initialization
│   │   └── AbilitiesManager.php
│   ├── Admin/
│   │   ├── Dashboard.php      # Landing page
│   │   ├── Abilities.php      # Toggle abilities
│   │   ├── Connections.php    # Manage sessions
│   │   └── Settings.php       # Configuration
│   ├── Abilities/
│   │   └── WordPress/         # Core abilities
│   ├── OAuth/
│   │   ├── Database/          # Schema installer
│   │   ├── Endpoints/         # REST controllers
│   │   ├── Entities/          # OAuth entities
│   │   ├── Repositories/      # Data access
│   │   └── Server/            # OAuth server factory
│   └── MCP/
│       └── Server.php         # MCP protocol handler
├── assets/
│   ├── css/
│   │   └── admin-settings.css
│   └── js/
│       ├── admin-settings.js
│       └── admin-dashboard.js
└── tests/
```

### Database Schema
- `wp_albert_oauth_clients` - Registered OAuth clients
- `wp_albert_oauth_access_tokens` - Active access tokens
- `wp_albert_oauth_refresh_tokens` - Refresh tokens
- `wp_albert_oauth_auth_codes` - Authorization codes

---

## Development Guidelines

### Code Standards
- WordPress Coding Standards (enforced by PHPCS)
- PHP 8.2+ type declarations
- PSR-4 autoloading
- PHPStan level 5
- All strings translatable
- Proper escaping/sanitization

### JavaScript
- **No jQuery** - Vanilla ES6+ only
- Module pattern
- Fetch API for HTTP requests

### Security
- OAuth 2.0 for authentication
- Capability checks (`manage_options`)
- Nonce verification
- Input validation and sanitization
- Prepared SQL statements

### Version Control
- **Never commit without explicit user request**
- Run `composer phpcs` before committing
- Semantic versioning (MAJOR.MINOR.PATCH)

---

## Future Roadmap

### v1.1 (Q2 2026)
- Performance optimizations
- Additional core abilities (comments, menus)
- Improved error handling
- Activity logging

### v1.2 (Q3 2026)
- First premium extension (E-commerce Pack)
- License management system
- Auto-updates for premium extensions

### v2.0 (Q4 2026)
- Multi-site support
- Role-based ability permissions
- API rate limiting
- Advanced analytics dashboard

---

## Support & Documentation

### Resources
- **Website**: https://albertwp.com (planned)
- **Documentation**: https://albertwp.com/docs (planned)
- **Support**: WordPress.org forums (free), priority email (premium)
- **GitHub**: Issues and feature requests

### Target Audience
- **Primary**: WordPress site owners who want to use AI assistants to manage their sites
- **Secondary**: Developers building AI-powered WordPress tools
- **Premium**: WooCommerce store owners, content publishers, agencies

---

## Notes

### Design Assets (Phase 4)
- **SVG Design Capability**: Banner and icons can be designed as SVG code
- **Conversion**: SVG → PNG using CloudConvert, Inkscape, or ImageMagick
- **Style**: Modern, clean, professional tech/SaaS aesthetic
- **Colors**: WordPress blue (#2271b1), white, subtle grays

### WordPress.org Submission
- Plugin follows all WordPress.org guidelines
- GPL v2+ licensed
- No "phone home" behavior
- No external dependencies without disclosure
- Tested with latest WordPress version

---

## Status Summary

| Component | Status | Notes |
|-----------|--------|-------|
| Core Plugin | ✅ Complete | Ready for release |
| Admin UI | ✅ Complete | Dashboard, Abilities, Connections, Settings |
| OAuth Server | ✅ Complete | RFC compliant |
| MCP Integration | ✅ Complete | Fully functional |
| Documentation | 🔄 In Progress | readme.txt needs final review |
| Design Assets | ⏳ Pending | Banner and icons to be created |
| Testing | ✅ Complete | PHPCS clean, tests passing |
| WordPress.org | ⏳ Pending | Phase 4 tasks |

**Next Milestone**: Complete Phase 4 and submit to WordPress.org
