---
name: restoring-versions
description: How to find the right earlier version of a post or page, show what would change, and restore it without losing later work. Read this before restoring any revision.
metadata:
  abilities: "albert/find-revisions albert/view-revision albert/restore-revision"
---

# Restoring an earlier version

## Find the right version

1. List the revisions with `albert/find-revisions`. The newest is the current version.
2. Use each revision's date, author and changed fields to find the save the person means
   ("before Tuesday", "before Anna's edit"). Autosaves are marked; they are unsaved work,
   not a version someone chose to keep.
3. Read the candidate with `albert/view-revision`, `compare_to` `current`. That shows what
   a restore would change on the live page. When the diff is cut short, give the person the
   wp-admin link to see the rest.

## Show the change before you restore

Tell the person, in plain words, what the restore would do: which text comes back, and
which later changes would be lost. A restore puts back the whole title, content and
excerpt of that revision. Every edit made after it disappears from the page, including
good ones.

When the person wants to undo only one change, do not restore. Edit that part instead with
a block edit or a text replace, and leave the rest of the page as it is.

Restore only after the person picks the revision and says yes.

## What a restore does

- It is saved as a new revision, so it can be undone: restore the revision that was
  current before it.
- On a published post or page, the restored version is live at once.
- Only the title, content and excerpt change. The featured image, categories, tags, status,
  slug and other settings stay as they are now. Say so when the person expects them back.
- A restore can be refused: for content in the trash, when the site has revisions turned
  off for it, or when the old version holds a block the site no longer allows.
- On a site that keeps only a few revisions, the restore can push the oldest one out.

## Held for approval

Restores are often held until a person approves them in wp-admin. A held restore has not
happened. Say so, give the link, and do not retry or rebuild the old version by hand to get
around the hold.

## Afterwards

Tell the person which version you restored (its date and author) and the `changed_fields`.
To undo it later, restore the revision just before `new_revision_id`.
