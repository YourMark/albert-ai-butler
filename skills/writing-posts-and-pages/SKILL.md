---
name: writing-posts-and-pages
description: How to write a post or page that sounds like the site, is easy to scan, and stays a draft until the person says publish. Read this before writing or rewriting a post or page.
metadata:
  abilities: "albert/create-post albert/update-post albert/create-page albert/update-page albert/set-featured-image"
---

# Writing posts and pages

## Learn the site's voice first

Before you write, read two or three recent published posts or pages of the same kind with
`albert/find-posts` or `albert/find-pages`. Note:

- The language, and formal or informal address (in Dutch, "je" or "u").
- How long the pieces are, and how long the paragraphs.
- How headings are written: sentence case or Title Case, questions or statements.
- Words the site always uses for its products and services.

Match what you find. If the site's instructions describe a house style, those win. If the
person gives facts, use them; do not invent prices, dates, names, quotes or numbers. Mark
anything you are not sure of so the person can check it.

## Structure

- The title is the page's only H1. Start the body with text or an H2, never another H1.
- Use H2 for main sections and H3 inside them. Do not skip levels.
- Short paragraphs, one idea each. Use a list when you list things.
- Link text says where it goes ("our opening hours"), never "click here".
- Blog posts are dated news or articles. Lasting content such as About, Services or Contact
  is a page.

## Excerpt and featured image

- A post's excerpt shows in archives, search results and social shares. Write one or two
  sentences that make someone want to read on. Do not repeat the first paragraph.
- The featured image is separate from the body. Set it with `albert/set-featured-image`
  after the post exists, using an image from the media library. Ask which image when it is
  not clear, and do not set one the person did not choose or approve.

## Drafts, publishing and changes

- New posts and pages are drafts. Leave them that way and give the person the `edit_url`
  so they can review it.
- Publish only when the person asks in this conversation, and only what they asked for.
  Scheduling also counts as publishing.
- When changing a published post, change only what was asked. Rewriting a whole page is a
  big change: show the plan first.
- Do not change the slug of a published post or page unless asked: links to the old
  address stop working.
- Tell the person what you made or changed, with the title, status and link.
