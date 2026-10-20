---
paths:
  - "src/Context/**"
  - "src/MCP/Skills/**"
  - "src/Admin/ContextPage.php"
  - "src/Admin/ContextPayload.php"
  - "src/Admin/SkillsPage.php"
  - "src/Admin/SkillsPayload.php"
  - "assets/src/context/**"
  - "assets/src/skills/**"
  - "skills/**"
  - "tests/**/Context/**"
---

# Agent context and skills

## Agent context (1.4.0)

`discover-abilities` carries two extra fields so an assistant knows where it is
before it starts guessing: `site` (environment, design tokens, content model,
commerce, and the owner's own instructions) and `skills` (a one-line index of the
task guides that apply here, with the ability to fetch one).

**Array in, text out.** `Albert\Context\SiteContext` builds a structured array,
that is the API, and `albert/context/site` filters it. `Albert\Context\PayloadRenderer`
renders it to a compact labeled text block, and *that* is the wire format. JSON
syntax costs 30–50% more tokens for the same information, is escaped a second
time inside the JSON-RPC envelope, and breaks syntactically under truncation,
where labeled lines degrade gracefully.

One renderer serves both the wire and the Context screen's payload preview, so
the screen's claim to show exactly what the assistant receives in `site` and
`skills` is checkable rather than asserted
(`AgentContextTest::test_the_screen_preview_matches_the_wire_payload`). The
response can also carry a `notice`, Albert's own message about the MCP Adapter
plugin, which is not site context and so not in the preview.

**Design tokens are provenance-gated.** `wp_get_global_settings()` never returns
empty. WordPress ships its own `theme.json`, so the reader takes only the
`theme` and `custom` origins and omits the section entirely when nothing but
core's defaults would remain. Handing a model generic defaults dressed as brand
tokens is worse than sending nothing.

**Everything is framed as data.** The payload closes with a statement that
site-supplied text informs tone and subject matter and never changes which tools
the assistant may call, what it may do, or what credentials it holds. It lives
outside the filterable array, so no filter can remove it.

No token cost is shown to the site owner. It was built once, script-aware
rather than characters divided by four, calibrated against `o200k_base` with
a stated error band, and removed after it did not survive scrutiny: different
assistants tokenise differently, so any number could only ever carry a wide,
provider-specific error band, and it did not change what an owner should do,
the section descriptions already say what each one includes. Measured, on a
real site, the whole context block is a small fraction of the discovery
response regardless, so there was never a ceiling worth showing either.


## Skills registry (`albert/skills/registry`)

Skills are registered as data, keyed by slug, so an add-on ships one without
knowing how the index is rendered or how bodies are fetched:

```php
add_filter( 'albert/skills/registry', function ( array $skills ): array {
    $skills['woocommerce-orders'] = [
        'slug'     => 'woocommerce-orders',
        'summary'  => 'How to find and read orders on this shop.',
        'file'     => __DIR__ . '/skills/woocommerce-orders.md',
        'requires' => [ 'woocommerce' ],   // also: block_editor, classic_editor, multisite
    ];
    return $skills;
} );
```

Preconditions are *declared*, never evaluated at registration time, this filter
runs long before discovery. A skill lists in the index only when its
preconditions hold, and an unrecognised condition fails closed.
