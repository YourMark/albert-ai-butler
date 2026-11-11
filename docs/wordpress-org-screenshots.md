# WordPress.org listing assets

Everything in this folder is deployed to the plugin's SVN `assets/` directory by
`.github/workflows/plugin-assets.yml` on a push to `main`, and by `release.yml`
as part of a tagged release.

This is the only folder that is deployed. A second `.wordpress.org/` (with a
dot) existed until 1.4.0, holding a stale copy of the same four screenshots. It
was created before the deploy action was added, the action requires the
hyphenated name, and nothing ever read the dotted one. It has been deleted.

## Files

| File | Screen |
|---|---|
| `icon-256x256.png` | Plugin icon |
| `screenshot-1.jpg` | Dashboard |
| `screenshot-2.jpg` | Connections |
| `screenshot-3.jpg` | Abilities |
| `screenshot-4.jpg` | Context |
| `screenshot-5.jpg` | Skills |

Captions live in `readme.txt` under `== Screenshots ==`, one line per file, in
this order. WordPress.org pairs them by position, so adding a screenshot means
adding a caption in the same place.

## Conventions

- **1280x960, 4:3, JPG at quality 85.** WordPress.org mandates no size. What it
  does say is that wide, short screenshots are hard to read at the directory's
  thumbnail size, which is why these are 4:3 rather than the 3840x1320 the
  pre-1.4.0 set used. JPG keeps each file around 200KB against a 10MB cap.
- **Lowercase filenames**, numbered from 1 with no gaps. Uppercase does not work.
- **Full admin chrome included.** The WordPress sidebar shows where Albert lives,
  which is worth more than the width it costs.
- There is no retina or 2x convention for screenshots. That exists for icons and
  banners only.

## Retaking them

Captured from a real site at a 1291x1145 browser window, which yields a
1270x952 viewport, then resized to 1280x960.

Before shooting, make sure the admin is in **English**, debug bars such as Query
Monitor are off, and no admin notices are covering the page. Check that no Albert
screen is showing a warning state: if `albert/get-skill` is switched off, the
Skills screen carries a "No assistant can read these" banner and the Dashboard
raises an attention item, and both make a working plugin look broken.

Last retaken for 1.4.0, when the admin was rebuilt on the shared design system
and the Context and Skills screens were added.
