---
name: media-alt-text
description: How to write alt text that is true, when an image should have none, and how to sweep a site for images without it. Read this before setting alt text on any image.
metadata:
  abilities: "albert/find-media albert/view-media albert/update-media albert/edit-post-block albert/edit-page-block"
---

# Alt text through Albert

## You have probably not seen the image

`albert/view-media` returns the file's address, title and size, not the picture. Unless the
image itself is in this conversation (the person attached it, or your client opened the URL
and showed it to you), you do not know what it shows. A file name like `IMG_2041.jpg` or a
title like "hero" is not a description.

When you have not seen it, say so. Then offer one of these:

- The person tells you what the image shows, and you write the alt text from that.
- You draft alt text from the context (the post around it, the caption), mark each one as a
  guess, and save only what the person confirms.

Never save alt text for an image you have not seen as if you had.

## Good alt text

- Say what the image adds to the page, not everything in it. A product photo names the
  product; a chart gives its main finding.
- Write it in the language of the page it sits on.
- Text in the image goes in the alt text, word for word, when it matters.
- An image that is a link: describe where the link goes, such as "Contact us".
- Leave out the site name, keywords and "photo of", unless the photo style matters.

## Decorative images

Some images add nothing a reader would miss: a divider, a background texture, a photo next to
a heading that already says the same. Give them empty alt text, so screen readers skip them.
Ask the person when you are not sure. Empty is not the same as missing: missing alt text
makes some screen readers read the file name aloud.

`find-media` with `missing_alt_text` lists empty and missing alike, so a decorative image
comes back in every sweep. Keep a list of the ones the person called decorative, and skip
them.

## The library and the page

The library's alt text (set with `albert/update-media`) is only where new image blocks start.
An image block already in a post keeps its own copy. To change what visitors get on a page,
also edit that block: read the post or page with `"format": ["blocks"]`, change only the
`alt="..."` inside the image block's `html`, and send it back with `edit-post-block` or
`edit-page-block`. Fix every image in one post in a single call with `edits`.

To find where an image is used, `albert/search-content` with its file name usually finds the
posts and pages whose blocks point to it.

## Sweeps

1. Run `find-media` with `missing_alt_text` and tell the person the `total`.
2. Work in pages of 10 to 20. Show the alt text you plan for each image, with its id and
   title, and wait for a yes before saving.
3. Skip images you cannot describe and say which ones.
4. End with a list: what you set, what you skipped, and which posts still show old alt text
   in their own blocks.
