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

- **`summary`** and, on a block theme, **`header`**: what the header shows. `header.type` is
  `block` (a navigation menu), `inline` (links kept in the template part itself) or `none`.
- **`visible`** and **`shown_in`** on every menu: whether visitors see it, and where. On a classic
  theme that is its menu locations and widgets; which location sits in the header is up to the
  theme, so `header` is always `none` there.
- **`theme.block_theme`**: which kind of site this is.

When `header.fallback` is true, the navigation block names no menu. With `type` `block` it shows
the most recently published navigation menu, so publishing a new one would change the header. With
`type` `none` no navigation menu exists yet: the first time the header is shown, WordPress copies
the classic menu `summary` names into a new navigation menu, or makes one listing the pages when
there is no classic menu.

With `header.type` `inline` the links sit in the template part itself, and no menu holds them.
Albert cannot change those: point the person to Appearance > Editor.

Then call `albert/view-menu` with the menu's `id` as `menu_id` and `menu_type`: `classic` for one
from `classic_menus`, `block` for one from `navigation_menus`.

## Changing items

The same abilities change both kinds of menu. Use them only on a menu whose `visible` is true, or
one the person is about to show with `albert/assign-menu-location`:

- `albert/add-menu-item`: a link to a page or post (`item_type` `post_type` with `object_id`), a
  category or term (`taxonomy`), a post type archive, or any URL (`custom` with `title` and `url`).
  Page and term links keep following the page when its address changes.
- `albert/update-menu-item`: the label, address, tooltip or new-tab setting. Only what you send changes.
- `albert/move-menu-item`: a new parent, a new position, or both. Sub-items move along.
- `albert/remove-menu-item`: removes one item; its sub-items move up a level. Held for approval.

A **classic** item is named by its `menu_item_id`, and a new parent by `parent_item_id`, the item
ids `view-menu` returns. A **navigation** item is named by `menu_type: "block"`, `menu_id` and its
`path` from `view-menu`, such as `[1, 0]`, and a new parent by `parent_path` (`[]` is the top
level). Adding the first item under a link turns it into a submenu, and removing the last one turns
it back, as in the Site Editor. Paths shift after every change: use the `items` the result returns
for the next call, not an earlier read.

A navigation menu can hold a **page list**, which shows every published page by itself. `view-menu`
lists its pages as its children, marked `automatic`. To rename, hide or move one of them, target
that child's path: Albert first turns the list into separate links, as the Site Editor does, and the
result's `notice` says that new pages will no longer appear in the menu by themselves. Tell the
person. Other blocks in a navigation menu, such as a search box, can be moved or removed but not
changed.

## Classic menus only

- `albert/create-menu` makes an empty menu; `albert/assign-menu-location` then shows it in a
  location, and `albert/unassign-menu-location` empties one. Both return `previous_menu`, so a
  location change is one call to undo.
- `albert/delete-menu`: held for approval and cannot be undone.

Every write returns `visible`. When it is false, or something else needs saying, the result
carries a `notice`: tell the person in plain words.

## Rules

- Find the visible menu before any change. Never edit a menu whose `visible` is false unless the
  person asked for that exact menu, knowing it is not shown.
- On a block theme, change the navigation menu `header` names. Do not create classic menus or
  assign locations to change the header: the header does not read them.
- Say which menu you changed by name and where it appears, so the person can check it.
