# WordPress.org Screenshots

This document tracks screenshots captured for the WordPress.org plugin submission.

## Requirements
- **Format**: PNG or JPG
- **Size**: 1280x800px or larger (recommended)
- **Quantity**: 5-8 screenshots
- **Naming**: screenshot-1.png, screenshot-2.png, etc.

## Captured Screenshots

### Screenshot 1: Dashboard Page (Featured)
- **File**: screenshot-1.png (to be saved)
- **Browser ID**: ss_2624041x0
- **Size**: 1590x773px
- **URL**: https://wc2025.test/wp-admin/admin.php?page=albert
- **Shows**:
  - MCP endpoint URL with copy button
  - Quick setup guide (4 steps)
  - Status overview (OAuth Server, MCP Endpoint, Active Connections, Enabled Abilities)
  - Recent activity section (empty state)
  - Professional 2-column card layout
  - WordPress admin sidebar showing Albert menu

### Screenshot 2: Abilities Page
- **File**: screenshot-2.png (to be saved)
- **Browser ID**: ss_6970ftezu
- **Size**: 1590x773px
- **URL**: https://wc2025.test/wp-admin/admin.php?page=albert-abilities
- **Shows**:
  - 19 core abilities organized by category
  - Categories: Posts, Pages, Users, Media, Taxonomies, Terms
  - Enable/disable toggles for each ability
  - Ability descriptions
  - Save Changes button
  - Category tabs for easy navigation

### Screenshot 3: Connections Page (Empty State)
- **File**: screenshot-3.png (to be saved)
- **Browser ID**: ss_7082foi85
- **Size**: 1590x773px
- **URL**: https://wc2025.test/wp-admin/admin.php?page=albert-connections
- **Shows**:
  - Empty state with icon
  - "No Active Connections" message
  - Call-to-action: "View Setup Instructions" button
  - Clean, professional card design
  - Helpful messaging for first-time users

### Screenshot 4: Settings Page
- **File**: screenshot-4.png (to be saved)
- **Browser ID**: ss_8043paeje
- **Size**: 1590x773px
- **URL**: https://wc2025.test/wp-admin/admin.php?page=albert-settings
- **Shows**:
  - MCP Server section with Connection URL
  - Authentication section with Allowed Users
  - User selection dropdown
  - Add User button
  - Empty state message
  - 2-column layout

## Additional Screenshots Needed

### Screenshot 5: Active Connection (Optional)
- **Status**: Not captured yet
- **Requires**: Creating a test OAuth connection
- **Shows**: Connections page with active AI assistant connections
- **Content**: Connection cards showing app name, user, connected time, expiration

### Screenshot 6: OAuth Authorization Flow (Optional)
- **Status**: Not captured yet
- **Requires**: Initiating OAuth flow from AI assistant
- **Shows**: WordPress authorization consent screen
- **Content**: App requesting permissions, approve/deny buttons

### Screenshot 7: Admin Menu (Optional)
- **Status**: Can be extracted from existing screenshots
- **Shows**: WordPress admin sidebar with Albert menu expanded
- **Content**: Top-level menu icon, submenu items (Dashboard, Abilities, Connections, Settings)

## How to Save Screenshots

### Option 1: From Browser (Manual)
1. Open Chrome DevTools
2. Find the screenshot IDs listed above
3. Right-click and save each image

### Option 2: Take New Screenshots (Recommended)
```bash
# Using macOS screencapture
# 1. Open the page in browser
# 2. Resize window to desired size
# 3. Run:
screencapture -w screenshot-1.png

# Or use browser screenshot tool (Cmd+Shift+5 on macOS)
```

### Option 3: Automated with Screenshot Tool
- Use browser automation to capture each page
- Save directly to .wordpress.org/ directory
- Ensure consistent sizing (1280x800px or larger)

## Processing Checklist

- [ ] Save all 4 captured screenshots as PNG files
- [ ] Rename to screenshot-1.png through screenshot-4.png
- [ ] Verify size is 1280x800px or larger
- [ ] Optimize file size (use ImageOptim or similar)
- [ ] Add captions to readme.txt
- [ ] Consider adding 1-2 more screenshots (active connections, OAuth flow)

## Screenshot Descriptions (for readme.txt)

```
== Screenshots ==

1. Dashboard page showing MCP endpoint, quick setup guide, and status overview
2. Abilities page with 19 toggleable WordPress operations organized by category
3. Connections page showing empty state with helpful call-to-action
4. Settings page for configuring MCP endpoint and allowed users
```

## Notes

- All screenshots show the plugin in a clean WordPress 6.4+ environment
- Dark admin sidebar provides good contrast
- Professional design with card-based layouts
- Consistent branding throughout
- Screenshots demonstrate the complete admin interface
- Empty states are included to show first-time user experience
