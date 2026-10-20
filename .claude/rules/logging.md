---
paths:
  - "src/Logging/**"
  - "src/MCP/ToolCallObserver.php"
  - "src/OAuth/Server/ConnectionContext.php"
  - "tests/**/Logging/**"
---

# Logging

Free writes a short log to `{prefix}albert_ability_log` (schema in `Logging\Installer`). Premium takes over the same table with more columns and time-based retention.

## Who writes
- `Logger` listens on `albert/abilities/after_execute`. `ObservabilityHandler` records MCP-level failures that never reach an ability. `ToolCallObserver` (on `mcp_adapter_tool_call_result`) catches input the Abilities API rejected before the callback, rewrites the error into one the model can act on, and fires `after_execute` so it logs normally.
- `ExecutionLogMarker` stops one call logging twice. Any new observer must check it.
- `albert/logging/enabled` false means "Free does not write"; Premium sets it to write itself. It does not turn logging off.
- `albert/logging/ability_failed` fires whatever that filter says, and only for an `error`.
- Free keeps 2 rows per `(ability_name, status)`, pruned on insert, only while Free is the writer.

## Three outcomes
`Logging\Outcome` classifies every row, in `Repository::insert()`, so every writer (Free, Premium, a third party) gets the same answer.

| Status | Meaning | Shown as |
|---|---|---|
| `success` | The ability ran and answered, including "not found" and "nothing to do" | Success |
| `warning` | Refused on purpose: permission, ability switched off, held for approval | Blocked |
| `error` | Something broke, timed out or was malformed | Failed |

- A truthful "no" is a `success`. `error_code` is still stored, so enumeration bursts stay findable.
- For a new `*_not_found` code, ask: is the thing advertised to the caller? A missing ability, tool or skill is a client bug (`error`). A missing post, user, term, order or other record is a fair question (`success`).
- `failure_stage` `permission` is always `warning`. Without a stage, the code decides (see the constants in `Outcome`). `token_already_used`, `link_already_used`, `invalid_taxonomy` and `rest_*` stay `error` on purpose: each has a reading that is not benign. When unsure, leave it `error`: a wrong failure that shouts beats a real one that whispers.
- `success` forces `failure_stage` to NULL; `warning` keeps it.
- `albert/logging/outcome` lets third-party abilities correct the classification; values outside the three are ignored.

## Context columns
- `Repository::insert()` ignores unknown `$context` keys, so one add-on build works against older and newer Free. Never make it strict.
- `privacy_mode` is stamped at write time because the setting can change later.
- `client_id` / `client_name` come from `ConnectionContext`, set by `TokenValidator`. That is the "who" for MCP calls; a real end-user IP is not available.
