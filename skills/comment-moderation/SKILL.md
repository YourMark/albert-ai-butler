---
name: comment-moderation
description: How to sort waiting comments into approve, spam and trash, write replies that are safe to publish, and keep commenters' personal data out of the conversation. Read this before moderating or replying to comments.
metadata:
  abilities: "albert/find-comments albert/view-comment albert/reply-to-comment albert/moderate-comment albert/delete-comment"
---

# Moderating comments

## Sort first, then act in batches

1. List the waiting comments: `albert/find-comments` with `status` `hold`. Keep paging up to
   `total_pages`.
2. Sort each one into **approve**, **spam**, **trash** or **ask**. Read it in its thread with
   `albert/view-comment` when the meaning depends on what it answers.
3. Show the person the groups, with a short reason per comment, and wait for a yes.
4. Send each group in one call: `moderate-comment` for approve and spam, `delete-comment`
   for trash. Up to 50 IDs per call. One call per group means one approval, not fifty.

## Spam signals

One signal alone proves little. Several together usually mean spam:

- Praise that fits any post ("Great article, very informative!").
- Links to shops, loans, betting, crypto or adult sites, or many links at once.
- A keyword or a business name where a person's name should be (when names are shown).
- A language or topic that has nothing to do with the post.
- The same text on several posts.

Real comments can be short, critical or badly written. That is not spam. When in doubt, put
it in **ask**. Spam and trash can both be restored from wp-admin for a while. Use the
trash for comments that are not spam but should not be shown, such as off-topic or
duplicate comments. Never delete permanently (`force`) unless the person asks.

## Replies are public

A reply is published at once, under the connected user's name, and speaks for the site.

- Draft it, show it, and send it only after the person says yes.
- Write in the commenter's language, in the site's tone. Keep it short and friendly.
- Do not promise refunds, prices, dates or anything else the person did not confirm.
- Never put personal data in a reply: no emails, phone numbers, order details or addresses.
  Ask the commenter to use the contact form instead.
- Replying to a waiting comment approves it. Do not reply to spam.

## Personal data

Commenters' names, emails, websites and IP addresses may come back masked. Work from the
comment text; it is almost always enough. Do not ask for the real data unless the person
needs it for a task, and do not repeat it in your answers. A masked value is not the real
one: never write it into a reply or anywhere else.

## Held for approval

On many sites, approving, marking as spam, replying and permanent deletes are held for a
person to approve in wp-admin. A held call has not happened yet. Say so, give the link,
and do not retry.
