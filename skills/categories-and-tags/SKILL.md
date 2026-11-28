---
name: categories-and-tags
description: How to file posts under the categories and tags a site already has, without creating near-duplicates, and how to add, rename or remove terms safely. Read this before tagging, categorising or changing any term.
metadata:
  abilities: "albert/find-taxonomies albert/find-terms albert/view-term albert/create-term albert/update-term albert/delete-term albert/add-post-terms albert/remove-post-terms"
---

# Categories and tags

Categories are the site's few main sections. Tags are finer topics across them. Both are
part of how visitors and search engines find content, so a messy list is hard to fix later.

## Reuse before you create

Before adding any term to a post, list what exists with `albert/find-terms` (up to 100 per
page; read every page on a big site). Then:

- Use an existing term when one means the same thing. Check for other spellings, singular
  and plural ("Recipe", "Recipes"), other languages, and abbreviations ("SEO", "Search engine
  optimisation").
- Prefer terms with a high `count`: those are the ones the site really uses.
- A tag name you send to `albert/add-post-terms` that does not exist yet is **created**. Only
  send a new name on purpose. Say which tags will be new before you add them.
- Do not create a new category without asking. Categories shape the site's structure.
- Two to five tags per post is usually enough. A tag used on one post only is clutter.

Custom taxonomies (such as a product type or a location) are listed by
`albert/find-taxonomies`. `add-post-terms` and `remove-post-terms` work on categories and
tags only.

## Adding and removing on a post

- `add-post-terms` keeps the terms a post already has. `remove-post-terms` takes off only
  what you send.
- A post always has one category. To move a post, add the new category first, then remove
  the old one. If you remove the last one, WordPress puts back the default category and the
  result says so.
- Read the result's `terms` and tell the person the post's final categories and tags.

## Renaming and deleting terms

- `albert/update-term` renames a term everywhere it is used. Changing its slug changes its
  archive address, so old links to it stop working. Ask first.
- `albert/delete-term` is permanent: terms have no trash. The term comes off every post that
  had it, and its archive page is gone. Check its `count` with `albert/view-term` and tell
  the person how many posts it is on before you delete it.
- To merge two near-duplicate terms, add the one you keep to each post of the other, then
  delete the other. `albert/find-posts` can list a category's posts, but not a tag's: for
  tags, ask the person to use the tag's post count link in wp-admin, or merge by hand there.
