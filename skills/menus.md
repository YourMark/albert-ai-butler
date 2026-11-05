---
name: menus
description: How to find the menu visitors actually see, and change it without editing an unused one. Read this before changing any menu.
---

# Changing a site's menus through Albert

WordPress has two kinds of menu, and a site can hold both at once:

- **Classic menus**, shown in the menu locations a classic theme registers ("Primary", "Footer").
- **Navigation menus** (`wp_navigation` posts), shown by the navigation block in a block
  theme's header template part.

A block theme does not show classic menus. A site that switched to a block theme often still
has its old classic menus, and changing one of those changes nothing visitors see. Never
report a menu change as done until you know visitors see that menu.

## Find the visible menu first

Always start with `albert/find-menus`. Read:

- **`summary`** and **`header`**: what the header shows. `header.type` is `block` (a navigation
  menu), `classic` (a classic menu), `classic_copy` (a classic menu WordPress will copy into a
  new navigation menu the first time the header is shown), `inline` (links kept in the template
  part itself), `page_list` (the published pages, listed automatically) or `none`.
- **`visible`** and **`shown_in`** on every menu: whether visitors see it, and where.
- **`theme.block_theme`**: which kind of site this is.

When `header.fallback` is true, the navigation block names no menu and WordPress shows the most
recently published navigation menu. Publishing a new navigation menu would change the header.

Then call `albert/view-menu` with the menu's `id` and `type` to see its items.

## Classic menus

Use these only for a menu whose `visible` is true, or one the person is about to show with
`albert/assign-menu-location`:

- `albert/add-menu-item`, `albert/update-menu-item`, `albert/move-menu-item`: build and arrange
  items. Item ids come from `view-menu`.
- `albert/remove-menu-item`: removes one item; its sub-items move up a level. Held for approval.
- `albert/create-menu`, `albert/assign-menu-location`, `albert/delete-menu`. Deleting a menu is
  held for approval and cannot be undone.

Every classic write returns `visible`. When it is false the result carries a `notice`: tell the
person in plain words that visitors will not see the change, and why.

## Navigation menus (block themes)

Albert can find and read navigation menus but cannot change them yet. When the visible menu is a
navigation menu, say so, show its items from `view-menu`, and point the person to
Appearance > Editor > Navigation. Do not edit a classic menu instead.

## Rules

- Find the visible menu before any change. Never edit a menu whose `visible` is false unless the
  person asked for that exact menu, knowing it is not shown.
- On a block theme, do not create classic menus or assign locations to change the header: the
  header does not read them.
- Say which menu you changed by name and where it appears, so the person can check it.
