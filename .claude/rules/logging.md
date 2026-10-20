---
paths:
  - "src/Logging/**"
  - "src/MCP/ToolCallObserver.php"
  - "src/OAuth/Server/ConnectionContext.php"
  - "tests/**/Logging/**"
---

# Logging

## 5. Logging (`src/Logging/`)
Minimal ability execution logging for the Free tier. When Premium is active it takes over the same shared table with richer columns and time-based retention.

**Hook used:** `albert/abilities/after_execute` (Albert hook, fires on both success and failure, covers WP_Error results). `ObservabilityHandler` also captures MCP-level errors that never reach a BaseAbility.

**Filter:** `albert/logging/enabled` (bool, default `true`) means "Free's DB writers are active". Premium returns `false` from this filter to suppress Free's writes and take over logging itself. Returning `false` does **not** disable logging globally: Premium's own writers still run. The `albert/logging/ability_failed` notification action fires regardless of this value so Premium always receives failure signals — but, since 1.4.0, only for a genuine `error`; a `success` or a `warning` never fires it.

**Three outcomes, not two (1.4.0+).** `WP_Error` is the only channel an ability
has for saying "no", so a genuine fault, a deliberate refusal and a truthful
negative answer all arrive in an identical wrapper. `Logging\Outcome` is the one
place that tells them apart. Every value answers the one question a column
called `status` asks — *what happened to this run?*

| Status | Meaning | Visible word |
|---|---|---|
| `success` | The ability ran and answered. Includes empty results, null, "it does not exist" and "there was nothing to do" | Success |
| `warning` | Blocked by policy. Nothing broke; the site said no on purpose (permission refused, ability switched off) | Blocked |
| `error` | The site could not do what was asked: something broke, timed out, or was malformed | Failed |

A truthful "no" **is** a `success`. *Succeeded* and *failed* describe the run;
*not found* describes the answer, and mixing the two kinds in one column is what
made an earlier `no_match` value read as ambiguous. `warning` earns the place
`no_match` did not: *blocked* is the same kind of statement as *succeeded*.
Nothing is lost — `error_code` is still stored, so
`status = 'success' AND error_code IS NOT NULL` still finds the burst of
`product_not_found` from somebody enumerating IDs.

**The test for the next `*_not_found` code: is the thing advertised to the
caller?** Abilities, tools and skills are enumerated to the assistant, so naming
a missing one is a client bug (`error`). Posts, users, terms, orders, products,
media and sessions are not, so asking whether one exists is a fair question
(`success`).

Two signals classify, and the stage wins: a `failure_stage` of `permission` is
always a `warning`, even under core's generic `ability_invalid_permissions`. For
writers with no stage (Free's own `Logger`) the error code decides — anything
ending `_permission_denied` plus `ability_invalid_permissions`, `ability_disabled`,
`capability_revoked`, `rest_forbidden` and `forbidden` are `warning`; anything
ending `_not_found` plus `not_found`, `invalid_post` and `invalid_attachment` are
`success`, minus the API-surface exclusions (`albert_ability_not_found`,
`albert_premium_ability_not_found`, `ability_not_found`, `tool_not_found`,
`skill_not_found`); everything else is `error`. `token_already_used`,
`link_already_used`, `invalid_taxonomy` and the `rest_*` codes are deliberately
left as `error` — each has a second reading that is not benign. Erring toward
`error` is the safe direction: a misclassified failure that shouts beats a real
failure that whispers.

`success` has `failure_stage` forced to NULL — the column is called
*failure*_stage and an answer is not a failure, which is also what makes
Premium's "Failed at" badge correctly never render for those rows. `warning`
**keeps** its stage: it genuinely stopped at `permission`, and that is worth
showing. Neither fires `albert/logging/ability_failed`.

Classification happens in `Repository::insert()`, the same place `privacy_mode`
is stamped, so **every** writer — Free's `Logger`, Free's `ObservabilityHandler`,
Premium's own logger, a third party calling the repository directly — lands on
the same value for the same error.

**Filter:** `albert/logging/outcome` — `( string $status, string $ability_name, WP_Error $error ): string`.
Fires only for failed invocations. Third-party abilities have no reason to follow
Albert's naming conventions; this is their way out. Return `success` for a
negative answer Albert reads as a fault, `warning` for a refusal it cannot
recognise, or `error` for a code it recognises wrongly. A returned value outside
`success|warning|error` is ignored.

**Schema (DB_VERSION 1.2.0, option `albert_logging_db_version`):**
| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `ability_name` | `VARCHAR(191)` | Ability identifier |
| `user_id` | `BIGINT UNSIGNED` | `get_current_user_id()`, 0 if unauthenticated |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |
| `status` | `VARCHAR(20)` | `'success'`, `'warning'` or `'error'` (1.4.0+ for `warning`). Classified centrally by `Logging\Outcome` via `Repository::insert()`; see *Three outcomes* below. No schema change was needed — the column never constrained the value |
| `error_code` | `VARCHAR(100)` | WP_Error code, NULL on success |
| `error_message` | `LONGTEXT` | WP_Error message (1.2.0+), NULL on success. Captured by both Free and Premium loggers; rides in the `$context` array, surfaced in Premium's Activity Log expandable error detail |
| `failure_stage` | `VARCHAR(20)` | Where the invocation died (1.4.0+): `short_circuit`, `input`, `permission`, `execute`, `output`. NULL on `success`, kept on `warning` and `error`. This is the column that lets the log answer *why* a call failed rather than only *that* it did |
| `duration_ms` | `INT UNSIGNED` | Execution ms; NULL unless Premium populates |
| `user_agent` | `TEXT` | Client UA; NULL unless Premium populates |
| `privacy_mode` | `VARCHAR(20)` | The anonymisation mode in force when the row was written (1.4.0+): `strict`, `balanced` or `off`. Stamped centrally by `Repository::insert()` from `PrivacyMode::resolve()` unless the caller passes one, because it cannot be recomputed at render time — the setting may have changed since, and a row read back under today's mode would misreport how its own payload was treated |
| `input` | `LONGTEXT` | JSON input payload; capped by default; NULL unless Premium populates |
| `output` | `LONGTEXT` | JSON success result payload (1.2.0+); capped by default; NULL on error / unless Premium populates |
| `client_id` | `VARCHAR(80)` | OAuth client id of the calling connection (1.2.0+); NULL for non-MCP calls / unless Premium populates |
| `client_name` | `VARCHAR(255)` | Snapshotted OAuth client name at call time (1.2.0+); NULL for non-MCP calls / unless Premium populates |

**Indexes:** `ability_created (ability_name, created_at)`, `ability_status (ability_name, status, created_at)`, `status_stage (status, failure_stage, created_at)`

**Retention:** Free hard-codes 2 records per `(ability_name, status)` partition, pruned on insert, only when `albert/logging/enabled` is true (i.e. Free is the writer). Premium uses time-based retention (default 90 days) via `Cron/LogCleanup`.

**Components:**
- `Installer.php`: Creates/upgrades `{$wpdb->prefix}albert_ability_log` table (dbDelta, idempotent)
- `Repository.php`: CRUD operations, bulk fetch, auto-prune; `insert()` accepts optional `$context` array for the rich columns.
  Unknown context keys are ignored, deliberately: that is what lets one add-on build write good rows against both an older
  and a newer Free. `ip_address`, `referrer` and `request_id` were dropped in 1.4.0 with no deprecation wrapper for exactly
  that reason — an add-on still sending them degrades silently instead of erroring
- `Outcome.php`: classifies a result into `success` / `warning` / `error`; owns the `albert/logging/outcome` filter
- `Logger.php`: Hooks `albert/abilities/after_execute`; gated by `albert/logging/enabled` filter; fires `albert/logging/ability_failed` before the gate, and only for a classified `error`
- `ObservabilityHandler.php`: MCP-level error recorder; gated by same filter
- `ExecutionLogMarker.php`: request-scoped dedup marker; set by the loggers when they write a row, checked by the observers so a single call never logs twice

**Failure capture (1.2.0+):** Failures that happen *before* the ability runs are now logged too.
Input rejected by the WordPress Abilities API (`WP_Ability::execute()` validates `input_schema`
*before* the registered callback, so `guarded_execute`/`after_execute` never fire) is caught by
`MCP/ToolCallObserver` on the adapter's `mcp_adapter_tool_call_result` filter. It (1) rewrites the
verbose `ability_invalid_input` error into an actionable message for the LLM (e.g. *"Missing
required parameter: `title`."*) and never returns a blank/"unknown error"; and (2) fires
`albert/abilities/after_execute` for the failure so it logs through the normal path (status `error`,
`error_code`, `error_message`, `input`, connection identity). The `ExecutionLogMarker` keeps an
ability that *did* execute from being logged twice. `ObservabilityHandler` (Free + Premium) now also
captures the error message (from the adapter's `failure_reason` tag) and is dedup-guarded by the same
marker, covering permission/transport/unknown failures the adapter surfaces via `record_event`.

**Connection identity (1.2.0+):** `OAuth/Server/ConnectionContext` is a request-scoped holder set by `TokenValidator::validate_request()` when a Bearer token is validated. It records the OAuth `client_id` (and lazily resolves a snapshot `client_name`) so Premium's logger can attribute each row to the connection that made the call. Public accessors `ConnectionContext::client_id()` / `client_name()` are how add-ons read it; true end-user IPs are not obtainable for MCP calls (requests originate from the assistant's servers), so the OAuth connection is the meaningful "who" signal.

**Payload capture (Premium, 1.2.0+):** Premium captures `input` and `output` (success result only). Both are byte-capped by default (`Logger::DEFAULT_PAYLOAD_LIMIT`, 65535) with a `…[truncated, N more characters]` marker; truncated payloads render raw. Filters: `albert/premium/logging/full_capture` (bool, default false: store uncapped; the Activity Log page shows a warning when active) and `albert/premium/logging/payload_limit` (int bytes).

**Admin surfaces:**
- Dashboard widget: "Recent Activity" showing most recent executions with status pills
- Abilities page: "Last run" line in each ability's expanded details with status pill + upsell when Premium is not active
