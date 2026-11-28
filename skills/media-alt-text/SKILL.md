---
name: media-alt-text
description: How to write alt text that fits each place an image is used, when an image should have none, and what to do when you have not seen it. Read this before setting alt text on any image.
metadata:
  abilities: "albert/find-media albert/view-media albert/update-media albert/view-post albert/view-page albert/edit-post-block albert/edit-page-block"
---

# Alt text through Albert

Alt text is for people who cannot see the image. It depends on the place, not the file. The
same photo can be decorative on the home page, carry information in an article and be the
only label of a link in a menu. Write it per placement.

## Start with where the image is used

`albert/view-media` returns `used_in`: every post, page or pattern that shows the file. For
each placement it gives the block `path`, the alt text there, whether the image is a `link`,
its `caption`, the text `before` and `after` it and the nearest `heading`.

- The alt text in a block is what visitors get. The media library's alt text
  (`albert/update-media`) is only the default for new placements and for featured images.
- Change one placement with `albert/edit-post-block` or `albert/edit-page-block`. Read the
  post first, send the same block back at its `path` and change only `alt` (or
  `isDecorative`). Leave the rest of the block as it was.
- A placement in a template part or a theme cannot be edited from here. Tell the person
  where it is.

## Decide what the image does in that place

- **Decorative** (a background, a divider, or a picture that repeats what the text next to it
  already says): set `isDecorative` and leave alt empty. An empty alt is correct, not a gap.
- **A link or a button**: describe where it goes or what it does ("Shop the spring range"),
  not what it looks like.
- **Informative**: say what the reader needs from it here, in one short sentence. Do not
  start with "Image of".
- **Text in the image** (a poster, a screenshot of a quote): the alt text is that text.
- **A logo**: the organisation's name. When it links home, the name is enough.
- **A chart or diagram**: a short alt that names what it shows, plus the key numbers in the
  text or caption next to it. A long explanation does not belong in alt text.
- **With a caption** that already describes it: do not repeat the caption. Add only what
  the caption leaves out, or nothing.

There is no right length. Use the site's own words: a product name, a place, a colour the
shop uses.

## You have probably not seen the image

Unless the person shared the picture with you, you have only its file name, title, caption
and the text around each placement. That is not enough to describe what it shows.

- Say so before you write anything: "I can't see this image. From the page it looks like ...".
- Ask the person what the image shows, or offer drafts marked as guesses for them to check.
- Never invent details such as colours, people or text you cannot know.

## Sweeps

`albert/find-media` with `missing_alt_text` lists files whose library alt is empty. That
includes decorative images, which are correct as they are, so check each placement before
counting it as missing. Work in small batches, say what you changed per page, and list the
images you left for the person because you could not tell what they show.
