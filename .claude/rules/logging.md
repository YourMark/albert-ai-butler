---
paths:
  - "src/Logging/**"
  - "src/MCP/ToolCallObserver.php"
  - "src/OAuth/Server/ConnectionContext.php"
  - "tests/**/Logging/**"
---

# Logging

Free writes a short log to `{prefix}albert_ability_log` (schema in `Logging\Installer`). Premium takes over the same table with more columns and time-based retention.

## What gets a row
Log what the site did or refused, not the assistant working out the input.

| Call | Row | Why |
|---|---|---|
| Succeeded | `success` | This is the usage: Recent activity, Last used. |
| Denied, held for approval, switched off | `warning` | The site's rules did their job; the owner wants to see the attempt. |
| Callback error, exception, output rejected | `error` | The site or a plugin broke, and only its owner can fix it. |
| Input rejected (normalise, schema, a plugin's own input check) | none | Nothing ran and no rule of the site applied. The assistant gets the reason and retries, so the follow-up call is the row. A row would push real errors out of Free's 2 per status and fire `ability_failed`. |

A recurring rejection points at a bad schema or description. That is a developer's diagnosis, not usage or an outage, so it belongs in Premium's log if anywhere.

## Who writes
One writer per call.
- `ExecutionObserver` writes the row for every ability call over an Albert connection, any plugin's. It opens on `wp_ability_invoked` and ends on the first of core's stage filters that holds an error (short-circuit, normalise, input, permission, execute, output) or on `wp_after_execute_ability`, so a success is only logged after output validation. Core has no single "finally" hook; these filters are the only places every exit still shows its `WP_Error`. It passes the stage, so a denial is a `warning` whatever code the callback chose. The input stage closes without a row, whatever the code.
- Over MCP a denial never reaches `execute()`: the adapter runs `execute-ability`'s permission check first, and that checks the inner ability. The observer keeps the inner denial and logs it once `execute-ability`'s own permission result confirms the call was refused for it.
- `Logger` also listens on `albert/abilities/after_execute` and stands down while the observer has that call open. What is left to it: Albert's abilities called without a connection, and the refusals Albert fires that hook for before an ability is invoked (`DisabledAbilities`, upload links).
- `ObservabilityHandler` records MCP requests that failed before any ability was involved, such as a call to a tool that does not exist. Calls through the meta-tools are tagged with the meta-tool and skipped.
- `ToolCallObserver` only rewrites the validation message inside `execute-ability`'s result. Albert's server lists only the three meta-tools.
- `ExecutionLogMarker` is unused by Free; Premium still uses it.
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
