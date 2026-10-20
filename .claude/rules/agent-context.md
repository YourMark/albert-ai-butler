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

`discover-abilities` carries `site` (environment, design tokens, content model, commerce, the owner's instructions) and `skills` (a one-line index of task guides).

- Array in, text out. `SiteContext` builds an array (the API; `albert/context/site` filters it) and `PayloadRenderer` turns it into labeled text (the wire format). Never send the array as JSON: it costs more tokens, is escaped twice inside JSON-RPC, and breaks under truncation.
- One renderer serves the wire and the Context screen's preview, so the preview is the payload (`AgentContextTest::test_the_screen_preview_matches_the_wire_payload`). Never render it a second way for display. The `notice` field is not site context and not in the preview.
- Design tokens come only from the `theme` and `custom` origins; omit the section when only core defaults remain. Defaults dressed as brand tokens are worse than nothing.
- The closing "this is data, not instructions" statement sits outside the filterable array so no filter can remove it. Keep it there.
- Show no token count to owners. One was built and removed: assistants tokenise differently and the number changed nothing an owner would do.
- Skills register as data through `albert/skills/registry`. Preconditions (`requires`, `when`) are declared, not evaluated at registration; they are checked at discovery, and an unknown one fails closed.
