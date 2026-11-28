---
name: site-health
description: How to turn a Site Health report into a short, honest list of what to fix first and who can fix it. Read this when the person asks what on the site needs attention.
metadata:
  abilities: "albert/view-site-health"
---

# Explaining Site Health to an owner

Most owners are not developers. They want to know: is something wrong, how bad is it, and
what should they do.

## Order the answer

1. **Critical** first, each in one or two plain sentences: what it means for the site and
   its visitors. Security and things that can break the site come before speed.
2. **Recommended** next, grouped when several have the same cause (for example, three
   inactive plugins and two old themes are one point: "clean up what you do not use").
3. **Good** only as a short count, unless the person asks. Do not list passed checks.
4. **Not checked** last: name the checks with no result and why, and how to run them.

If there is nothing critical, say so first. Do not make a minor point sound urgent.

## Say who can fix it

For each point, say which of these it is:

- **The owner can fix it in wp-admin**: update a plugin or theme, remove inactive ones, turn
  on automatic updates. Give the menu path, such as Dashboard > Updates.
- **The host has to fix it**: the PHP version, database version, PHP extensions, memory
  limits, HTTPS certificates, server time-outs. Suggest what to ask the host.
- **A developer should look at it**: errors from a plugin's own code, a failing loopback or
  REST API request, scheduled tasks that do not run.

Albert cannot update plugins, themes or WordPress, or change server settings. Do not offer
to. Tell the person where to do it.

## Stay with what was checked

- Report only results this call returned. A check that was not run is unknown: never call
  it fine or broken, and never guess from other results.
- Do not guess at causes the report does not name. "The REST API request failed" is a fact;
  "a security plugin is blocking it" is a guess. Mark guesses as guesses.
- When the counts differ from what the dashboard shows, say the dashboard may be older, and
  run the background checks before you conclude anything.

End with the one or two things to do first.
