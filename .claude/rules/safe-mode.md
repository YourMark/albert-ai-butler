---
paths:
  - "src/SafeMode/**"
  - "src/Execution/**"
  - "src/Admin/Approvals.php"
  - "assets/js/admin-approvals.js"
  - "src/Cron/PendingActionSweep.php"
  - "tests/**/SafeMode/**"
  - "tests/**/Execution/**"
---

# Safe mode

Holds destructive assistant calls until a person approves them in Albert > Approvals. On by default: a safety default that ships off protects nobody.

## The gate
- `Interceptor` binds WordPress 7.1's `wp_pre_execute_ability`, which fires before validation, the permission check and the callback. Below 7.1 the feature is inert, and the Settings field, the Approvals screen and the Dashboard each say so. Never let an unprotected site show an empty queue as if it were protected.
- Only calls with an Albert connection (`ConnectionContext`) are held. WP-CLI, direct PHP and other plugins pass. Never describe safe mode as general write protection.
- One MCP `tools/call` fires `wp_pre_execute_ability` twice (the adapter's `execute-ability` wrapper, then the real ability). `Execution\InterceptorDecision` says which firing is real; anything else on that hook must use it too.
- The queue holds the input as captured, before core normalises it. Docblocks say "captured", not "resolved".

## What is held (either axis is enough)
1. The annotation: `destructive !== false`. Never `=== true`: an undeclared ability must be held, or the gate becomes fail-open.
2. `RiskPolicy`: Albert's high-risk abilities by name, and any call (third-party too) that grants an administrator-capability role or writes a high-risk option. The option lookup ignores bare `name` and `key`; they are ordinary words.

## Option tiers
| Tier | Options | Behaviour |
|---|---|---|
| 1. Albert's controls | `albert_safe_mode`, `albert_safe_mode_ttl_minutes`, `albert_disabled_abilities`, `albert_allowed_users`, `albert_privacy_mode`, OAuth keys | Refused by `ConnectionGuard`, never approvable |
| 2. High-risk WordPress | `siteurl`, `home`, `admin_email`, `default_role`, `users_can_register`, `template`, `stylesheet`, `active_plugins`, `blog_public`, `permalink_structure` | Held |
| 3. Everything else | | Passes |

- Tier 1 is only for self-defeating writes: approving "turn safe mode off" destroys the thing asking. `siteurl` is dangerous but not circular, so it is held, not refused.
- Match names the way the database does (`OptionNames`, which asks MySQL under the column's collation). A collation ignores case, accents, width and invisible characters, so `albért_safe_mode` writes the real row. No PHP normalisation reproduces that; `OptionNamesTest` checks the matcher against real lookups.
- Over a connection, every WordPress route to a tier 1 option is refused before it writes: updates keep the stored value, deletes fail at `delete_option()`'s row lookup (so no cache says "deleted"), and an add under another spelling is dropped at the `query` filter. When the database cannot compare names, the write is refused. Only the OAuth keys may be created (they are minted lazily); any other control option is created only at its secure default (`ConnectionGuard::SECURE_DEFAULT`), an add-on's not at all. A call naming one is refused before staging. An approved run keeps its connection, so the guard still applies.
- A write around the option API (`$wpdb`, `REPLACE`) is undone by `OptionRestorer` after the ability, the tool call or the request ends. It cannot see SQL with a run-time table name, or undo anything if the process is killed.
- Refusals fire `albert/safe_mode/option_write_blocked` and log as `warning`; a restore logs as `error`, because the change was briefly real.

## Deciding and running
- An administrator decides any row; anyone else only their own, and only while the ability's `check_permissions()` still passes for them, checked at decision time. Approve and reject share that check. This is not four-eyes review; never call it that.
- Approval runs the captured input server-side as the requester, never the approver. An `ApprovalTicket` bound to that ability and input (10 s) lets the one run past the gate.
- `Repository::claim()` is a conditional UPDATE, so one row cannot run twice. A single-process test proves the clause, not atomicity; name tests accordingly.
- The fingerprint sorts keys at every depth (models reorder JSON keys between retries) but keeps list order (two orders are two calls).
- A hold logs as `warning` via `Outcome::HELD_CODE`, on its own branch, not in `POLICY_CODES`: those are final, a hold is undecided. Logging holds as errors is what gets a safety feature switched off.
- A successful run stores no payload; only an error's code and message. `InputPresenter` masks secrets for display only; it is not a control.

## Storage and settings
- `albert_pending_actions`, UTC. `PendingActionSweep` expires lapsed rows, fails claims stale for an hour, and deletes decided rows after 30 days. Without it a lapsed row stays `pending` and drops out of both lists.
- `albert_safe_mode` and `albert_safe_mode_ttl_minutes` (default 60, 5 minutes to a week) go through `Settings\Value`. The window is short because approving an old `update-post` overwrites later edits. `Gate::means_off()` accepts booleans, for `define( 'ALBERT_SAFE_MODE', false )`.
