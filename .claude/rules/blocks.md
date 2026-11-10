---
paths:
  - "src/Blocks/**"
  - "tests/**/Blocks/**"
  - "tests/Editor/**"
  - "skills/block-editor.md"
---

# Blocks

How Albert turns block specs and block markup into the HTML the editor stores.

## Why templates are hand-written
- A block's `save()` runs in JavaScript only. No PHP API produces its saved HTML, so each `tpl_*()` in `BlockSerializer` mirrors one block's `save()`.
- Wrapper classes come from `WP_Block_Supports`, the inline style from `wp_style_engine_get_styles()` over the `style` attribute. Do not use render-time output: it makes font sizes fluid, which the editor flags as invalid.
- An attribute that neither a template nor a registered support writes must fail the write with `block_validation_failed`. Never save a block the editor will reject.
- Block markup in `content` is stored as sent. Never rebuild it from attributes: that loses what only `save()` can write.

## The editor check is the only proof of validity
Unit and integration tests compare strings Albert itself wrote. Only the editor knows whether a block is valid. Run the check after any change to a template, `BlockSupports` or the serializer's output:

```bash
WP_CLI="herd php ~/bin/wp-cli.phar" npm run test:editor
```

- It runs locally only, never in CI: it needs a running site, WP-CLI and Chromium.
- A new template or attribute gets a case in `tests/Editor/cases.php`.
- It creates a throwaway administrator and deletes it at the end. If it reports it could not, remove the user by hand.
