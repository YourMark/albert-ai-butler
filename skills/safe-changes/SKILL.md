---
name: safe-changes
description: How to change or delete things on a live site without surprises, and what to say when a change is held for approval. Read this before any update or delete.
metadata:
  abilities: "albert/update-post albert/update-page albert/update-media albert/update-term albert/update-pattern albert/update-menu-item albert/update-user albert/delete-post albert/delete-page albert/delete-media albert/delete-comment albert/delete-term albert/delete-pattern albert/delete-menu albert/delete-user"
---

# Changing a live site safely

Every change you make is live at once, for every visitor. Work so that the person always
knows what you are about to do, and what you did.

## Before a change

- **Use the smallest tool.** Fix a word with a text replace, one block with a block edit,
  one field with an update that sends only that field. Rewrite a whole post only when asked.
- **Confirm the target.** Read the item by its id right before you change it. An id from an
  older list may now be something else.
- **Confirm the scope.** When a request could mean one item or many ("remove the old
  posts"), say how many you found and list them. Wait for a yes before you change more than
  a few.
- **Ask before anything that cannot be undone.** Say plainly that it is permanent.

## Trash first

Posts, pages, patterns and comments go to the trash unless you send `force`. The trash is
the undo: the person can restore from wp-admin. Do not send `force` unless the person asked
for a permanent delete in those words.

Media files, terms, menus and users have no trash. Their delete is permanent. Before you
delete one, say what depends on it: a deleted image leaves broken images in posts, a deleted
category is taken off every post that had it, a deleted user hands their posts to someone else.

## Held is not done

On many sites, safe mode holds deletes and other risky changes until a person approves them
in wp-admin. A held call fails with a message saying it was not performed and is queued for
approval, with a reference and a link. When that happens:

- Say the change was **not made**, and that it waits for approval. Give the link.
- Do not retry. The same call returns the same held request.
- Do not reach the same result another way, such as emptying a post instead of deleting it.
  The hold is the site owner's choice.
- Do not do the next steps that depend on it. Say what you will do once it is approved.

## Personal data

Some results come back masked, such as `j***@e***.com` or a name replaced by a number. A
masked value is a stand-in, not the real value. Never write one back into a field.

## After the change

End with a short list of what changed: each item by its title and id, what you changed, and
what is held or failed. Do not report a held or failed change as done.
