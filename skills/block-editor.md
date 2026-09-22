---
name: block-editor
description: How to read and write WordPress block-editor content with Albert's structured block format. Read this before creating or editing posts and pages.
requires: block_editor
---

# Working with the WordPress block editor through Albert

Albert lets you edit WordPress posts and pages as **structured blocks** instead of
raw HTML. WordPress content is made of blocks (paragraphs, headings, images, columns,
and so on). When you send Albert a list of block specs, Albert serializes them into
correct block-editor markup for you — you never hand-write block comments or inner HTML.

Always prefer the structured `blocks` field over a raw `content` string. The exception
is the classic editor (see "Classic-editor sites" at the end).

This guide covers composition: what a block spec looks like and how to build one. The
argument-level detail for any single ability lives on that ability: call
`get-ability-info` before using one and read its `instructions`. The two are meant to be
read together, and only this guide is worth reading end to end.

## Know which blocks you may use

Before composing blocks, call `albert/list-block-types` to see the block types you
may use on this site — the list reflects your permissions, so it only contains blocks
you are allowed to save. Use `albert/get-block-type` to inspect a single block's
attributes. Composing a block that is not in your allowed set returns a
`block_validation_failed` error naming it, and nothing is saved.

Allowed-block enforcement applies only to the structured `blocks` field. The raw
`content` string path is not allow-list-enforced, so prefer `blocks` whenever you want
the allowed set honoured.

The `albert://blocks/types` resource returns the site-wide (post-less) allowed list. For
a specific target, the `list-block-types` tool with a `post_type` is authoritative, since
some sites allow different blocks per post type.

## Block spec shape

Every block is an object with three keys:

```json
{
  "name": "core/paragraph",
  "attributes": { },
  "innerBlocks": [ ]
}
```

- `name` — the registered block type, e.g. `core/paragraph`. Always namespaced.
- `attributes` — the block's settings (heading level, image URL, etc.). Optional.
- `innerBlocks` — child blocks, for container blocks like columns or lists. Optional.

Use only block names that `albert/list-block-types` reports as available to you — that
is how you know what is registered and allowed on this site. Do not invent names, and do
not use a block missing from that list: either case comes back as a
`block_validation_failed` error naming the block, so you can fix it and retry. If you need
arbitrary HTML that has no matching block, use `core/html` and put the markup in its
`html` attribute.

Text attributes (`content`, a button's `text`, a quote's `citation`) accept inline HTML —
`<strong>`, `<em>`, `<a href="…">`, `<code>` — which Albert sanitizes. Use them for inline
emphasis and for links inside text.

## Examples

Paragraph:

```json
{ "name": "core/paragraph", "attributes": { "content": "Hello world." } }
```

Heading with a level:

```json
{ "name": "core/heading", "attributes": { "level": 2, "content": "Section title" } }
```

Image (a media library reference — include the attachment `id`, its `url`, and `alt`):

```json
{ "name": "core/image", "attributes": { "id": 123, "url": "https://your-site.com/wp-content/uploads/2026/06/cat.jpg", "alt": "A grey cat" } }
```

Button (buttons live inside a `core/buttons` container):

```json
{
  "name": "core/buttons",
  "innerBlocks": [
    { "name": "core/button", "attributes": { "text": "Read more", "url": "https://example.com" } }
  ]
}
```

List (a `core/list` wraps `core/list-item` children):

```json
{
  "name": "core/list",
  "innerBlocks": [
    { "name": "core/list-item", "attributes": { "content": "First item" } },
    { "name": "core/list-item", "attributes": { "content": "Second item" } }
  ]
}
```

For a numbered list, add `"ordered": true` to the `core/list` attributes (it renders as `<ol>`).

Nested layout — two columns, each holding a paragraph:

```json
{
  "name": "core/columns",
  "innerBlocks": [
    {
      "name": "core/column",
      "innerBlocks": [
        { "name": "core/paragraph", "attributes": { "content": "Left side." } }
      ]
    },
    {
      "name": "core/column",
      "innerBlocks": [
        { "name": "core/paragraph", "attributes": { "content": "Right side." } }
      ]
    }
  ]
}
```

## Images

`core/image` is a reference to the **media library**, not an arbitrary URL. An image
block needs both the attachment `id` and its `url` (plus `alt`):

- To use or change an image, find it with `albert/find-media` (search by title,
  filename, caption, or description) and use the `id` and `url` it returns.
- **When updating a post, never change the `id` of an existing image.** Read the current
  blocks first and keep each image block's `id` and `url` exactly as they are, unless the
  user explicitly asks for a different image.
- A bare external `url` with no `id` works but is discouraged — it is not linked to the
  media library.

## Links and buttons

Links use a plain **URL** — there is no id-based link reference (unlike images). This applies
to `core/button` (its `url` attribute) and to `<a href>` links inside text attributes.

- For an **internal** page or post, use its real **permalink** as the URL. Look it up with
  `albert/find-posts` / `albert/view-post` (or the `*-page` abilities) — they return a
  `permalink`; use that. Never guess a slug or put a post id in a link.
- For an **external** link, use the full absolute URL.

## Workflow

1. **Read** the existing content first with `albert/view-post` (or `albert/find-posts`
   to locate it). Pass `"format": ["blocks"]` so you get the structured tree back.
2. **Check** which blocks you may use with `albert/list-block-types` before composing
   anything new (use `albert/get-block-type` for a block's attributes).
3. **Edit** the structured blocks: change attributes, add or remove blocks, reorder them.
4. **Write** with `albert/create-post` (new) or `albert/update-post` (existing), sending
   your block specs in the `blocks` field.

For pages, use the equivalent `albert/*-page` abilities.

## Patterns

A pattern is ready-made, valid block markup already on this site: a header, a hero,
a call to action, a whole page section. Before composing a complex layout from
scratch, look for a pattern and reuse it. It is also the reliable way to place
blocks Albert cannot regenerate perfectly on its own (Cover, Media & Text): a
pattern's markup is known-valid.

- **Discover:** `albert/find-patterns` (filter by `search` or `category`) lists
  summaries; `albert/view-pattern` returns one pattern's block markup by `name`.
- **Reuse as a copy (default):** pass the returned `content` as your `blocks` or
  `content`, or adapt it. The copy is independent; editing it never changes the
  source. Works for any pattern.
- **Reuse synced (shared):** only user patterns with `syncStatus: "synced"`.
  Insert by reference with `<!-- wp:block {"ref":ID} /-->` using the pattern's
  `id`. There is one source, so editing the pattern updates every place it is
  used. Use it for a block repeated across the site, such as a CTA or a footer note.
- **`source`:** `registered` patterns (theme or plugin) are always copies and
  read-only; `user` patterns are the ones an owner can edit.

**Prefer a pattern over building new** for a full page or section, an on-brand
layout the site already ships, or any Cover / Media & Text layout where a pattern
keeps the markup valid.

**Saving, editing, deleting a pattern:** when a layout is worth reusing across
pages, save it with `albert/create-pattern` (send the `blocks` the same way you
build a post body). By default it is an unsynced copy-on-insert template; set
`synced: true` for one source that updates everywhere it is used. Edit one with
`albert/update-pattern` (blocks, title, sync status or categories) and remove one
with `albert/delete-pattern`. Create, update and delete work on **user patterns
only**: registered theme/plugin patterns are read-only and return an error.

## Granular block edits — change ONE block at a time

`create-*` and `update-*` replace the **whole** post body. For a long post, or when
you only need to change one block, that is wasteful and risks degrading blocks you
did not mean to touch. Instead use the granular block-edit abilities, which change
exactly one block and preserve every other block **byte-for-byte**:

- `albert/edit-post-block` — replace one block with a new spec.
- `albert/add-post-block` — insert one block before/after another, or inside it.
- `albert/remove-post-block` — delete one block.
- `albert/move-post-block` — reorder one block among its siblings.

(Use the `albert/*-page-block` equivalents for pages.)

Each of these takes the block's `path`: its position in the tree, from a fresh read,
plus an `expect` guard so a post that changed under you is rejected rather than
overwritten. The exact arguments differ per operation and each ability carries its own
instructions: call `get-ability-info` on the one you are about to use and follow what it
says there. That is where the detail is kept current.

**When to use which:** prefer granular ops for large posts or single-block changes;
use whole-content `update-*` when you are rewriting most of the body. Granular block
edits apply to block-editor targets only — on a classic post they return
`classic_editor_no_block_edits` (use `update-post` with HTML instead).

## Rules

- Prefer `blocks` over a raw `content` string.
- Use only block names from `albert/list-block-types`; a block you are not allowed to use
  returns a `block_validation_failed` error. Use `core/html` for raw HTML; never invent names.
- Do not hand-write block comments (`<!-- wp:... -->`) or inner HTML. Send attributes
  and `innerBlocks` and let Albert serialize the markup.
- Put child blocks in `innerBlocks`, not in attributes.

## Self-correction

A write may report problems:

- **`block_issues`** — a list of non-fatal warnings (the content still saved). Read each
  message, decide whether it matters, fix the offending block specs, and write again if needed.
- **`block_validation_failed`** — a fatal error; nothing was saved. Read the messages, correct
  the block specs they point to (usually an unknown block name or a malformed attribute), and retry.

Do not ignore these. Read the message text and fix the specific block it names.

## Reading formats

`format` is an **array** of format names, so you can request several at once — for
example `"format": ["blocks", "plaintext"]`. A scalar string is ignored and the
default format is returned instead, so always send an array, e.g. `"format": ["blocks"]`
or `"format": ["markdown"]`.

Choose the format(s) that fit the task:

- `["blocks"]` — the structured tree. Use this when you intend to edit and write back.
- `["content"]` — the raw stored block markup (block comments included). Use only when you
  need the exact stored string.
- `["plaintext"]` — text with all markup stripped. Use for summarizing or analyzing wording.
- `["html"]` — rendered front-end HTML. Use to see what a visitor sees.
- `["markdown"]` — a Markdown rendering. Use for a compact, readable overview.

## Large posts are paginated by block

Ordinary posts are returned **whole** in a single call. Only when a post is large
enough to risk the assistant's tool-result size limit do `albert/view-post` and
`albert/view-page` return a **window of top-level blocks** instead. When a window
is applied, every representation you request (`content`, `blocks`, `plaintext`,
`html`, `markdown`) reflects **only that window**, so the slices stay consistent
with each other.

Each result carries a `_meta` object:

- `total_blocks` — how many top-level blocks the post has.
- `offset` / `limit` — the window that was applied.
- `returned_blocks` — how many blocks this response contains.
- `truncated` — `true` if more blocks remain, or a text field was byte-capped.
- `next_offset` — present only when more blocks remain; the `offset` to request next.
- `note` — a short, actionable summary.

When `_meta.truncated` is `true`, look at `_meta.next_offset`:

- **If `next_offset` is present**, more blocks remain — re-request the same ability
  with `"offset": <next_offset>` (and optionally a `"limit"`). Keep going until
  `_meta.truncated` is `false`.
- **If `next_offset` is absent** (but `truncated` is still `true`), the block window
  was complete but a text representation was **byte-capped**. Request a smaller
  `"limit"`, or ask for a single representation (e.g. only `plaintext`), to get the
  full text.

To read a specific section, pass `offset`/`limit` directly. The `find-*` abilities
don't take `offset`/`limit` (they page at the post level), but each item may carry a
`truncated: true` flag when its text was capped — call `view-post` / `view-page` for
that item's full, paginated content.

## Classic-editor sites

Some posts and pages use the **classic editor** instead of the block editor. The choice can
be made per post type or even per individual post, so always check before writing.

Every read ability exposes two signals on each post or page:

- **`editor`** — `"block"` or `"classic"`. This is the editor the target uses.
- **`has_blocks`** — whether the stored content currently contains block markup. It can differ
  from `editor` (for example, block content saved on a post later switched to classic), so
  treat `editor` as the instruction for how to write.

When `editor` is `"classic"`, block specs do not apply: send plain **HTML in the `content`
field**, not `blocks`. If you send `blocks` to a classic target, the write fails with a
`classic_editor_blocks_unsupported` error and nothing is saved. The allowed-block list does
not apply to classic targets either — any valid HTML is accepted (WordPress sanitizes it per
your permissions).

When `editor` is `"block"`, write structured `blocks` as described above.

So the workflow is: read the target first, check its `editor` field, then write `blocks` for a
block target or HTML in `content` for a classic target.
