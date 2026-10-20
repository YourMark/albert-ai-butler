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

## Safe mode (1.5.0)

Holds destructive assistant-initiated calls until a person approves them in
`Albert > Approvals`. **On by default.** A safety default that ships off protects
nobody who never finds the setting.

**A real gate, not advice.** `SafeMode\Interceptor` binds WordPress 7.1's
`wp_pre_execute_ability` short-circuit filter, which core fires *before*
normalisation, validation, the permission check and the execute callback. Return
anything but the sentinel and none of that runs. Below 7.1 the filter never
fires, so the whole feature is inert, and that is disclosed in three places
rather than left to imply otherwise: the Settings field locks with the version
named, the Approvals screen says so instead of showing an empty queue, and the
Dashboard carries a non-dismissible attention item. An empty queue on an
unprotected site is the most convincing false reassurance the feature can give.

**Only assistant-initiated calls.** A request without an Albert OAuth connection
(`ConnectionContext`) passes untouched, so WP-CLI, direct PHP and other plugins
are unaffected. Safe mode is not general write-protection, and should not be
described as one. Note for doc 16: a local stdio connection that does not set a
`ConnectionContext` will silently not be gated.

**The staged input is captured, not resolved.** Core fires the filter before it
normalises, so what the queue holds is raw. The security property is unaffected,
approval replays what the server captured rather than anything the model can
resupply, but the word matters and the docblocks say *captured*.

**Two axes decide what is held, and either is enough.**

1. **The annotation**, `destructive !== false`. Never `=== true`. An ability that
   has not declared itself is held, because an unknown that might delete data is
   exactly what a person should see first. Do not weaken this: it inverts the
   property from fail-safe to fail-open.
2. **`SafeMode\RiskPolicy`**, because the annotation misses what actually
   compromises a site. `update-user` is annotated non-destructive, yet changing
   an administrator's email is account takeover. A name list covers Albert's own
   high-risk abilities; input-pattern rules hold *any* call, third-party
   included, that grants an administrator-capability role or writes a high-risk
   option. The option lookup deliberately excludes bare `name` and `key`: they
   are ordinary words, and `create-term { name: "home" }` was being held.

**Three tiers of option protection**, worth keeping straight:

| Tier | Options | Behaviour |
|---|---|---|
| Albert's own controls | `albert_safe_mode`, `albert_safe_mode_ttl_minutes`, `albert_disabled_abilities`, `albert_allowed_users`, `albert_privacy_mode`, OAuth key material | Refused outright by `ConnectionGuard`, never approvable |
| High-risk WordPress options | `siteurl`, `home`, `admin_email`, `default_role`, `users_can_register`, `template`, `stylesheet`, `active_plugins`, `blog_public`, `permalink_structure` | Held for approval |
| Everything else | all other options | Passes through |

Both lists are matched the way the database matches option names
(`SafeMode\OptionNames`, which asks MySQL under the column's own collation).
A typical collation ignores case, accents, width and zero-width or control
characters and treats `ß` as `ss`, so `update_option( 'albért_safe_mode', 'off' )`
writes the real `albert_safe_mode` row while missing every hook keyed by the
exact name. No PHP normalisation reproduces that, which is why the first version
of this check, `strtolower( trim() )`, let accented spellings through.
`OptionNamesTest` inserts several hundred characters into the name and checks
the matcher agrees with an actual lookup on every one.

Tier 1 is reserved for *self-defeating* writes, not merely dangerous ones:
approving "turn safe mode off" destroys the mechanism doing the asking, so there
is no coherent way to consent to it. `siteurl` is dangerous but not circular, and
refusing it would overrule an owner on their own site.

**An assistant cannot change these.** Every route through WordPress is refused
before it writes; a write around WordPress is undone afterwards, with the limits
in the last point. Over a connection:

- An update keeps the stored value (`sanitize_option_{$option}`, `pre_update_option`).
  Only the OAuth key material may be created; a control option is created at its
  secure default, an add-on's protected option not at all.
- A delete is refused at `delete_option()`'s row lookup, so WordPress finds no
  row and returns before it touches its caches. Refusing only the DELETE would
  leave the option cached as deleted, which a persistent object cache keeps;
  that DELETE is still dropped as a second layer, with the cache cleared after.
- An add under another spelling is dropped at the `query` filter: WordPress has
  no filter to refuse it, but fires `add_option` just before, and the guard arms
  the one statement that follows. When the database cannot compare names, the
  write is refused.
- A call naming a protected option is refused before it can be staged
  (`RiskPolicy::protected_option()`), so it never reaches the Approvals queue.
- An approved run executes under the connection that asked for it, so the guard
  still applies: approving a call is not approving everything it touches.
- A write that skips the option API (a plugin's own `$wpdb` call, `REPLACE`)
  makes `SafeMode\OptionRestorer` read the protected rows just before it runs,
  and put back anything changed once the ability finishes, the tool call ends
  or the request shuts down (`albert/safe_mode/option_restored`, `false` when it
  could not be undone or checked). It cannot see SQL that builds the table name
  at run time or undo anything if the process is killed before shutdown, and
  another request could read the changed value until the restore.

Refusals fire `albert/safe_mode/option_write_blocked` with the operation
(`write` or `delete`) and log as a `warning`; a restore logs as an `error`,
because for a moment the change was real.

**Who may decide: you may decide what you could have done yourself.** An
administrator decides any row; anybody else decides only their own, and only
while the ability's own `check_permissions()` still passes for them. Evaluated at
approval time, not staging time, so a row can stop being decidable while it
waits. Approve and reject share one check, deliberately: a blocked row expires on
its own or an administrator clears it, and one rule is easier to trust than two
on a destructive surface. Self-approval is not a hole, the requester is a person
and the assistant holds a token and cannot reach wp-admin, but this is **not**
four-eyes review and must not be described as such.

**Approval runs the call server-side**, with the input captured at stage time and
as the user it was going to run as, never the approving admin. The ability's own
permission check still applies. A one-shot `ApprovalTicket`, bound to the exact
ability and input with a 10 second TTL, lets that single execution past the gate.

**A claim happens before a run.** `Repository::claim()` is a conditional UPDATE,
so two tabs or a proxy retry cannot run one staged action twice. A single-process
test can only prove the clause, not atomicity, and the test is named accordingly.

**Retries are de-duplicated by fingerprint**, which sorts keys at every depth
before hashing: models reorder JSON keys between turns and `wp_json_encode()`
preserves insertion order, so without that the same retried call staged twice.
List order is left alone, because two orderings are two different calls.

**A hold is logged as Blocked, not Failed.** `Outcome::HELD_CODE`
(`albert_awaiting_approval`) classifies on its own branch rather than joining
`POLICY_CODES`: that set means "you may not" and "this is switched off", both
final, where this means *not yet decided*. Recording it as an error painted the
Dashboard activity card red and fired `albert/logging/ability_failed` every time
the gate worked, which is the pressure that gets a safety feature switched off.

**Nothing is stored that does not need to be.** A successful run records no
payload: `guarded_execute()` returns the unredacted result, so
`sensitive_output_keys` never applied here and every approved `create-user` was
writing its one-time `password_reset_url` to the table in the clear. Only the
error shape (code and message) is kept. `InputPresenter` masks secret-looking
keys **for display only**, and its docblock is explicit that this is a
convenience rather than a control.

**Storage.** New `albert_pending_actions` table, UTC throughout.
`Cron\PendingActionSweep` expires lapsed rows, fails claims stale for an hour off
`decided_at`, and deletes decided rows after 30 days. Without the sweep a lapsed
row stayed `pending` and vanished from both lists, which is an audit hole in a
feature whose whole claim is auditable approval.

**Settings.** `albert_safe_mode` (on/off) and `albert_safe_mode_ttl_minutes`
(default 60, clamped 5 minutes to a week), both through `Settings\Value`. The
window is an hour rather than a day because the real cost is drift: approving an
hours-old `update-post` overwrites whatever was edited since. `Gate::means_off()`
accepts booleans, because `define( 'ALBERT_SAFE_MODE', false )` is what a
developer writes and safe mode is Albert's first boolean-shaped setting.
